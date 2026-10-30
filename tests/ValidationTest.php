<?php
declare(strict_types=1);

namespace Panda\ThinkThrottle\Tests;

use Panda\ThinkThrottle\Limit;
use Panda\ThinkThrottle\RateLimiter;
use Panda\ThinkThrottle\RedisConnection;
use Panda\ThinkThrottle\RedisStore;
use Panda\ThinkThrottle\Exception\StorageUnavailable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** 输入边界和连接故障无需外部服务。 */
final class ValidationTest extends TestCase
{
    /** 验证非法规则。 @param array $arguments 规则参数 @return void */
    #[DataProvider('invalidLimits')]
    public function testInvalidLimit(array $arguments): void
    {
        $this->expectException(\InvalidArgumentException::class); new Limit(...$arguments);
    }
    /** 提供非法规则边界。 @return array 测试数据 */
    public static function invalidLimits(): array
    {
        return [[['','key',1,1,1000]], [['rule','',1,1,1000]], [['rule','key',0,1,1000]],
            [['rule','key',1,0,1000]], [['rule','key',1,1,0]], [['rule','key',1,1,1000,2]],
            [['rule','key',1000001,1,1000]], [['rule','key',1000000,1,86400000]],
            [['rule/unsafe','key',1,1,1000]], [['rule',str_repeat('a',1025),1,1,1000]]];
    }
    /** 不合理的连接超时必须拒绝，不创建无限阻塞连接。 @return void */
    public function testInvalidConnectionSettings(): void
    {
        $this->expectException(\InvalidArgumentException::class); RedisConnection::connect(['connect_timeout'=>0]);
    }
    /** 无界规则在连接前拒绝。 @return void */
    public function testBoundedRuleCount(): void
    {
        $limiter = new RateLimiter(new RedisStore(static function () { throw new \RuntimeException('must not connect'); }));
        $rules = array_fill(0,17,new Limit('too-many','one',1,1,1000));
        $this->expectException(\InvalidArgumentException::class); $limiter->consume(...$rules);
    }
    /** 不泄露原始连接异常、地址和密钥。 @return void */
    public function testConnectionFailureIsSafe(): void
    {
        $limiter = new RateLimiter(new RedisStore(static function () { throw new \RuntimeException('password=must-not-leak'); }));
        try { $limiter->consume(new Limit('test','one',1,1,1000)); self::fail('Expected unavailable storage'); }
        catch (StorageUnavailable $exception) { self::assertSame('Rate limit storage is unavailable',$exception->getMessage()); }
    }
}
