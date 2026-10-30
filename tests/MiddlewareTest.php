<?php
declare(strict_types=1);

namespace Panda\ThinkThrottle\Tests;

use Panda\ThinkThrottle\Decision;
use Panda\ThinkThrottle\Exception\StorageUnavailable;
use Panda\ThinkThrottle\Limit;
use Panda\ThinkThrottle\Middleware\Throttle;
use Panda\ThinkThrottle\RateLimiter;
use Panda\ThinkThrottle\Store;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use think\App;
use think\Request;

/** 实际 ThinkPHP 容器、请求和响应对象验证适配契约。 */
#[RunTestsInSeparateProcesses]
final class MiddlewareTest extends TestCase
{
    private App $app;
    private int $calls = 0;
    /** 初始化独立 ThinkPHP 容器。 @return void */
    protected function setUp(): void
    {
        $this->app = new App(dirname(__DIR__).'/');
        $this->app->config->set(['default'=>'file','stores'=>['file'=>['type'=>'File','path'=>sys_get_temp_dir().'/panda-throttle-middleware-cache/']]],'cache');
        $this->app->initialize();
    }
    /** 还原框架注册的全局处理器。 @return void */
    protected function tearDown(): void { restore_error_handler(); restore_exception_handler(); }
    /** 未启用、空规则和 OPTIONS 不访问 Redis。 @return void */
    public function testDisabledEmptyAndOptionsSkipStorage(): void
    {
        $middleware = $this->middleware(); $request = new Request();
        foreach ([['enabled'=>false],['enabled'=>true,'rules'=>[]]] as $options) self::assertSame(200,$middleware->handle($request,static fn()=>json([]),$options)->getCode());
        $request->setMethod('OPTIONS');
        self::assertSame(200,$middleware->handle($request,static fn()=>json([]),['enabled'=>true,'rules'=>[$this->rule()]])->getCode());
        self::assertSame(0,$this->calls);
    }
    /** 放行附加额度头，超限不执行业务。 @return void */
    public function testAllowAndDenyResponses(): void
    {
        $request = new Request();
        $allowed = $this->middleware()->handle($request,static fn()=>json(['value'=>'ok']),['enabled'=>true,'rules'=>[$this->rule()]]);
        self::assertSame('ok',$allowed->getData()['value']); self::assertSame(9,$allowed->getHeader('X-RateLimit-Remaining'));
        $denied = $this->middleware(false)->handle($request,static function () { throw new \RuntimeException('business must not run'); },['enabled'=>true,'rules'=>[$this->rule()]]);
        self::assertSame(429,$denied->getCode()); self::assertSame(2,$denied->getHeader('Retry-After'));
        self::assertSame('RATE_LIMITED',$denied->getData()['code']);
    }
    /** 自定义统一响应和动态规则可正常接入。 @return void */
    public function testCustomResponseAndResolver(): void
    {
        $response = $this->middleware(false)->handle(new Request(),static fn()=>json([]),['enabled'=>true,
            'rules'=>fn(Request $request)=>[$this->rule()], 'on_limit'=>static fn(Decision $decision,Request $request)=>json(['code'=>'APP_RATE_LIMIT','data'=>new \stdClass()],429)]);
        self::assertSame('APP_RATE_LIMIT',$response->getData()['code']); self::assertSame('no-store',$response->getHeader('Cache-Control'));
    }
    /** 存储故障默认拒绝，可显式选择降级。 @return void */
    public function testStorageFailurePolicy(): void
    {
        $middleware = $this->middleware(unavailable:true); $request = new Request(); $options = ['enabled'=>true,'rules'=>[$this->rule()]];
        self::assertSame(503,$middleware->handle($request,static fn()=>json([]),$options)->getCode());
        self::assertSame(200,$middleware->handle($request,static fn()=>json(['degraded'=>true]),$options+['failure_mode'=>'allow'])->getCode());
        $custom = $middleware->handle($request,static fn()=>json([]),$options+['on_unavailable'=>static fn($request,$error,$next)=>json(['code'=>'APP_UNAVAILABLE'],503)]);
        self::assertSame('APP_UNAVAILABLE',$custom->getData()['code']);
    }
    /** 错误回调不会被当成允许访问。 @return void */
    public function testInvalidCallbackFails(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->middleware(false)->handle(new Request(),static fn()=>json([]),['enabled'=>true,'rules'=>[$this->rule()],'on_limit'=>static fn()=>true]);
    }
    /** 创建测试规则。 @return Limit 规则 */
    private function rule(): Limit { return new Limit('test','one',10,10,1000); }
    /** 创建可控存储。 @param bool $allowed 是否放行 @param bool $unavailable 是否故障 @return Throttle 中间件 */
    private function middleware(bool $allowed=true,bool $unavailable=false): Throttle
    {
        $store = new class($allowed,$unavailable,fn()=>++$this->calls) implements Store {
            /** 注入测试判定。 @param bool $allowed 放行 @param bool $unavailable 故障 @param \Closure $count 计数 */
            public function __construct(private bool $allowed,private bool $unavailable,private \Closure $count) {}
            /** 返回固定决策。 @param array $limits 规则 @return Decision 判定 */
            public function consume(array $limits): Decision
            {
                ($this->count)(); if ($this->unavailable) throw new StorageUnavailable('Unavailable');
                return new Decision($this->allowed,'test',10,$this->allowed ? 9 : 0,$this->allowed ? 0 : 1500,2000,(int)(microtime(true)*1000));
            }
        };
        return new Throttle(new RateLimiter($store),$this->app->config);
    }
}
