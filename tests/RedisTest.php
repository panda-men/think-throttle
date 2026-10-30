<?php
declare(strict_types=1);

namespace Panda\ThinkThrottle\Tests;

use Panda\ThinkThrottle\Limit;
use Panda\ThinkThrottle\RateLimiter;
use Panda\ThinkThrottle\RedisConnection;
use Panda\ThinkThrottle\RedisStore;
use Panda\ThinkThrottle\Exception\StorageUnavailable;
use PHPUnit\Framework\TestCase;
use Redis;

/** 独立 Redis 上验证真实 Lua、多进程并发和故障，不使用 FLUSHDB。 */
final class RedisTest extends TestCase
{
    private Redis $redis;
    private string $prefix;
    private RateLimiter $limiter;

    /** 仅显式指定测试端口时连接，生成独立前缀。 @return void */
    protected function setUp(): void
    {
        if (!getenv('THROTTLE_TEST_REDIS_PORT')) self::markTestSkipped('Set THROTTLE_TEST_REDIS_PORT to an isolated Redis');
        $this->redis = RedisConnection::connect(['host'=>getenv('THROTTLE_TEST_REDIS_HOST') ?: '127.0.0.1',
            'port'=>(int)getenv('THROTTLE_TEST_REDIS_PORT'), 'connect_timeout'=>1,'read_timeout'=>2]);
        $this->prefix = 'throttle:test:'.bin2hex(random_bytes(8));
        $this->limiter = new RateLimiter(new RedisStore($this->redis,$this->prefix,str_repeat('test-hmac-',4)));
    }

    /** 只回收本次随机前缀，不清空共享数据库。 @return void */
    protected function tearDown(): void
    {
        if (!isset($this->redis)) return;
        foreach ($this->redis->keys($this->prefix.':*') as $key) $this->redis->del($key);
        $this->redis->close();
    }

    /** 容量允许突发，耗尽时给出等待时间和准确头部。 @return void */
    public function testBurstAndRetryHeaders(): void
    {
        $rule = new Limit('login','user:10000',2,2,3600000);
        self::assertTrue($this->limiter->consume($rule)->allowed);
        $second = $this->limiter->consume($rule);
        self::assertTrue($second->allowed); self::assertSame(0,$second->remaining);
        $denied = $this->limiter->consume($rule);
        self::assertFalse($denied->allowed); self::assertGreaterThan(1700000,$denied->retryAfterMs);
        self::assertGreaterThan(0,$denied->headers()['Retry-After']);
        self::assertGreaterThan(time(),$denied->headers()['X-RateLimit-Reset']);
    }

    /** 一个维度拒绝时，其他维度的额度没有被扣除。 @return void */
    public function testAtomicGroupDoesNotPartiallyConsume(): void
    {
        $user = new Limit('user','10000',3,1,3600000);
        $phone = new Limit('phone','13800138000',1,1,3600000);
        self::assertTrue($this->limiter->consume($phone)->allowed);
        self::assertFalse($this->limiter->consume($user,$phone)->allowed);
        foreach ([0,1,2] as $unused) self::assertTrue($this->limiter->consume($user)->allowed);
        self::assertFalse($this->limiter->consume($user)->allowed);
    }

    /** 身份与项目隔离，Redis 键不包含明文目标。 @return void */
    public function testSubjectNamespaceAndPrivacy(): void
    {
        $first = new Limit('sms','13800138000',1,1,3600000);
        self::assertTrue($this->limiter->consume($first)->allowed);
        self::assertTrue($this->limiter->consume(new Limit('sms','13900139000',1,1,3600000))->allowed);
        $other = new RateLimiter(new RedisStore($this->redis,$this->prefix.':other'));
        self::assertTrue($other->consume($first)->allowed);
        foreach ($this->redis->keys($this->prefix.':*') as $key) {
            self::assertStringNotContainsString('13800138000',$key);
            self::assertGreaterThan(0,$this->redis->pttl($key));
        }
    }

    /** 支持不同权重，同一规则参数变化使用独立桶。 @return void */
    public function testWeightedConsumptionAndRuleVersion(): void
    {
        $rule = new Limit('report','10000',10,1,3600000,3);
        foreach ([0,1,2] as $unused) self::assertTrue($this->limiter->consume($rule)->allowed);
        $denied = $this->limiter->consume($rule);
        self::assertFalse($denied->allowed); self::assertSame(1,$denied->remaining);
        self::assertTrue($this->limiter->consume(new Limit('report','10000',10,1,3600000,1))->allowed);
        self::assertFalse($this->limiter->consume(new Limit('report','10000',10,1,3600000,1))->allowed);
        self::assertTrue($this->limiter->consume(new Limit('report','10000',20,1,3600000,3))->allowed);
    }

    /** 根据服务端时间补充，短时桶不永久锁死。 @return void */
    public function testRefillAndRedisClock(): void
    {
        $rule = new Limit('short','one',1,1,50);
        $decision = $this->limiter->consume($rule);
        $clock = $this->redis->time();
        self::assertEqualsWithDelta((int)$clock[0]*1000+(int)$clock[1]/1000,$decision->observedAtMs,1000);
        usleep(80000);
        self::assertTrue($this->limiter->consume($rule)->allowed);
    }

    /** 拒绝请求不延长桶寿命，过期后恢复容量。 @return void */
    public function testDenialDoesNotExtendTtl(): void
    {
        $rule = new Limit('expiry','one',1,1,3600000);
        $this->limiter->consume($rule);
        $key = $this->redis->keys($this->prefix.':*')[0];
        $before = $this->redis->pttl($key); usleep(20000);
        self::assertFalse($this->limiter->consume($rule)->allowed);
        self::assertLessThan($before,$this->redis->pttl($key));
    }

    /** Redis 脚本缓存清空后恢复，不重置已消费的桶。 @return void */
    public function testNoScriptRecovery(): void
    {
        if (getenv('THROTTLE_TEST_REDIS_ISOLATED')!=='1') self::markTestSkipped('SCRIPT FLUSH requires isolated Redis confirmation');
        $rule = new Limit('reload','one',2,1,3600000);
        $this->limiter->consume($rule);
        $this->redis->script('flush');
        self::assertTrue($this->limiter->consume($rule)->allowed);
        self::assertFalse($this->limiter->consume($rule)->allowed);
    }

    /** 错误类型或损坏状态不能静默重置额度。 @return void */
    public function testCorruptStateFailsClosed(): void
    {
        $rule = new Limit('corrupt','one',1,1,3600000); $this->limiter->consume($rule);
        $key = $this->redis->keys($this->prefix.':*')[0]; $this->redis->set($key,'wrong-type');
        $this->expectException(StorageUnavailable::class);
        $this->limiter->consume($rule);
    }

    /** 重复规则在脚本执行前拒绝。 @return void */
    public function testDuplicateRulesAreRejected(): void
    {
        $rule = new Limit('same','one',1,1,1000);
        $this->expectException(\InvalidArgumentException::class);
        $this->limiter->consume($rule,$rule);
    }

    /** ACL命名用户使用指定身份认证，不意外回落到default用户。 @return void */
    public function testNamedAclConnection(): void
    {
        $username = 'throttle-test-'.bin2hex(random_bytes(6));
        $this->redis->acl('SETUSER',$username,'on','>isolated-test-password','~'.$this->prefix.':*','+@all');
        try {
            $connection = RedisConnection::connect(['host'=>getenv('THROTTLE_TEST_REDIS_HOST') ?: '127.0.0.1',
                'port'=>(int)getenv('THROTTLE_TEST_REDIS_PORT'),'username'=>$username,'password'=>'isolated-test-password']);
            self::assertSame($username,$connection->acl('WHOAMI'));
            $limiter = new RateLimiter(new RedisStore($connection,$this->prefix));
            self::assertTrue($limiter->consume(new Limit('acl','one',1,1,60000))->allowed);
            $connection->close();
        } finally { $this->redis->acl('DELUSER',$username); }
    }

    /** 三十二个进程一百二十八次竞争，十个令牌最多放行十次。 @return void */
    public function testConcurrentWorkersRespectExactCapacity(): void
    {
        $directory = sys_get_temp_dir().'/panda-throttle-test-'.bin2hex(random_bytes(6)); mkdir($directory,0700);
        $path = $directory.'/context.json';
        file_put_contents($path,json_encode(['marker'=>'throttle-concurrency-v1','port'=>(int)getenv('THROTTLE_TEST_REDIS_PORT'),
            'host'=>getenv('THROTTLE_TEST_REDIS_HOST') ?: '127.0.0.1','prefix'=>$this->prefix]));
        $running = [];
        try {
            for ($i=0;$i<32;$i++) {
                $process = proc_open([PHP_BINARY,__DIR__.'/support/concurrent.php',$path,(string)$i],
                    [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
                $running[] = [$process,$pipes];
            }
            $deadline = microtime(true)+15;
            while (count(glob($directory.'/ready-*'))<32 && microtime(true)<$deadline) usleep(10000);
            self::assertCount(32,glob($directory.'/ready-*')); touch($directory.'/go');
            $allowed = 0;
            foreach ($running as [$process,$pipes]) {
                $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]); self::assertSame(0,proc_close($process),$error.$output);
                $allowed += json_decode($output,true,512,JSON_THROW_ON_ERROR)['allowed'];
            }
            $running = []; self::assertSame(10,$allowed);
        } finally {
            foreach ($running as [$process,$pipes]) if (is_resource($process)) { proc_terminate($process); proc_close($process); }
            foreach (glob($directory.'/*') as $file) unlink($file); rmdir($directory);
        }
    }
}
