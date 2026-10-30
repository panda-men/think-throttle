# panda-men/think-throttle

基于 **Redis Lua 原子令牌桶** 的 ThinkPHP 8 限流组件，支持 PHP 8.2～8.5。

多进程、多节点共享同一 Redis 时使用同一份额度。IP、用户、接收目标等规则可以一起检查：任一规则超限，整组不扣减令牌。核心不依赖 HTTP、登录方式或数据库，ThinkPHP 中间件负责适配。

## 安装

```bash
composer require panda-men/think-throttle:^0.1
```

要求 PHP 8.2+、phpredis 6.0+、ThinkPHP 8、单机 Redis 6.0+。首版不支持 Redis Cluster。默认不启用，不会自动修改路由或保护接口；ThinkPHP 自动发现服务并发布 `config/think_throttle.php`。未自动发布时执行 `php think service:discover`、`php think vendor:publish`。

## 直接使用核心

```php
use Panda\ThinkThrottle\Limit;
use Panda\ThinkThrottle\RateLimiter;
use Panda\ThinkThrottle\RedisConnection;
use Panda\ThinkThrottle\RedisStore;

$limiter = new RateLimiter(new RedisStore(
    static fn () => RedisConnection::connect([
        'host' => '127.0.0.1',
        'port' => 6379,
        'database' => 0,
        'connect_timeout' => 0.3,
        'read_timeout' => 0.3,
    ]),
    prefix: 'myapp:production:throttle',
    keySecret: $secretFromEnvironment, // 可选，配置时至少32字节
));

$decision = $limiter->consume(
    new Limit('user', (string) $verifiedUserId, capacity: 20, refillTokens: 60, intervalMs: 60000),
    new Limit('ip', $trustedClientIp, capacity: 100, refillTokens: 300, intervalMs: 60000),
);

if (!$decision->allowed) {
    // 由应用返回429，并附加 $decision->headers()。
}
```

`capacity` 为短时突发容量；`refillTokens / intervalMs` 为持续补充速率；`cost` 默认1。令牌桶不是严格的“滚动一小时最多N次”，长期业务额度应继续由业务层保证。规则参数改变会使用新桶；不同消费权重共享同一桶。一次支持1～16条规则，恢复全满的时间最多7天，禁止空标识、重复规则及无效容量。

## ThinkPHP 中间件

在 `config/think_throttle.php` 中设置：

```php
use Panda\ThinkThrottle\Decision;
use Panda\ThinkThrottle\Limit;
use think\Request;

return [
    'enabled' => true,
    'prefix' => 'myapp:production:throttle',
    'key_secret' => env('THROTTLE_KEY_SECRET', ''),
    'redis' => [
        'host' => env('THROTTLE_REDIS_HOST', '127.0.0.1'),
        'port' => (int) env('THROTTLE_REDIS_PORT', 6379),
        'password' => env('THROTTLE_REDIS_PASSWORD', null),
        'database' => 0,
        'connect_timeout' => 0.3,
        'read_timeout' => 0.3,
    ],
    'rules' => static fn (Request $request) => [
        new Limit('ip', $request->ip(), 30, 120, 60000),
    ],
    'failure_mode' => 'deny',
    'on_limit' => static fn (Decision $decision, Request $request) => json([
        'code' => 'RATE_LIMITED', 'msg' => '请求过于频繁，请稍后重试', 'data' => new stdClass(),
    ], 429),
];
```

然后在全局、应用或控制器中间件中显式添加 `Panda\ThinkThrottle\Middleware\Throttle::class`。无需新增业务路由或方法注解；`rules` 可按控制器动作返回不同规则，也可通过中间件第三个参数覆盖当前层选项。OPTIONS、关闭状态及空规则跳过存储访问。

按用户限流的中间件必须放在认证之后，从已校验身份获得用户ID。不要信任请求参数里的管理员编号，也不要拿可随意更换的 `Idempotency-Key` 当限流对象。反向代理环境应配置可信代理；不要直接信任任意 `X-Forwarded-For`。

## 响应与故障

- 超限：HTTP 429，`Retry-After` 为至少1秒的相对等待时间。
- 额度头：`X-RateLimit-Limit` 为展示规则的容量，`X-RateLimit-Remaining` 为剩余整令牌，`X-RateLimit-Reset` 为恢复全满的 Unix 秒。多条规则时展示最紧约束，不代表所有规则的额度。
- 默认 Redis 故障：HTTP 503、`RATE_LIMITER_UNAVAILABLE`，不会误报为正常超限。
- 可显式配置 `failure_mode => 'allow'`，或设置 `on_unavailable(Request, StorageUnavailable, Closure $next): Response` 按业务选择故障策略。登录、验证码、费用相关接口通常选择拒绝；普通查询可由应用选择降级并记录聚合告警。
- `on_limit(Decision, Request): Response` 用于统一 JSON 契约。组件自动附加限流头；应用的 CORS 需要暴露这些头。组件不会生成应用自己的 `request_id`。

每次请求都应受请求速率限制；幂等重放仍消耗请求额度。短信发送、创建任务等业务额度放在幂等处理之后，只在新执行时消费。限流不会替代授权、幂等、数据库约束或上游流量防护。

## Redis 运行要求

连接由工厂延迟创建，默认读写与连接超时均为0.3秒，可配置为大于0且不超过5秒。连接工厂支持 Redis ACL 用户名和密码。`RedisStore` 接收原始、未序列化的专用 `Redis` 连接，不能传入队列正在使用的事务、pipeline 或带自定义序列化/前缀的连接。

桶自动设置 TTL；拒绝不会续期。标识使用SHA-256；设置 `key_secret` 后使用HMAC，建议对手机号等低熵标识配置独立随机密钥。更换密钥、前缀或规则会重置相应额度。Redis 重启、数据丢失或淘汰也会重置额度，严格场景需配置持久化与 `noeviction`。Lua 保证并发原子执行，Redis 存储故障不保证写入回滚；应用按存储不可用处理，不能继续声明严格限额。脚本 O(规则数)，最多16条；检测到 `NOSCRIPT` 时自动补发脚本。

## 开发与测试

```bash
composer install
composer validate --strict
composer lint
THROTTLE_TEST_REDIS_PORT=6380 THROTTLE_TEST_REDIS_ISOLATED=1 composer test
```

请指定**专用测试 Redis**。测试只删除随机前缀键；设置 `THROTTLE_TEST_REDIS_ISOLATED=1` 才会执行 SCRIPT FLUSH 恢复测试。并发测试使用32个独立PHP进程竞争128次请求，验证10个令牌最多放行10次。未指定端口时跳过Redis测试，CI使用独立Redis服务。

项目采用MIT许可，版本遵循语义化版本。通过 Git 标签发布，无需在 `composer.json` 手工填写版本。
