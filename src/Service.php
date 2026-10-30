<?php
declare(strict_types=1);

namespace Panda\ThinkThrottle;

/** ThinkPHP 自动发现，仅注册延迟工厂，不在启动或安装时访问 Redis。 */
final class Service extends \think\Service
{
    /** 注册独立存储和限流入口。 @return void */
    public function register(): void
    {
        $this->app->bind(Store::class, function (): Store {
            $config = (array) $this->app->config->get('think_throttle', []);
            return new RedisStore(static fn () => RedisConnection::connect((array) ($config['redis'] ?? [])),
                (string) ($config['prefix'] ?? 'panda:throttle'), (string) ($config['key_secret'] ?? ''));
        });
        $this->app->bind(RateLimiter::class, fn () => new RateLimiter($this->app->make(Store::class)));
    }
}
