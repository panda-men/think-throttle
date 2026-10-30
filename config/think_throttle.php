<?php
declare(strict_types=1);

return [
    // 安装后不自动保护任何接口；应用显式启用并设置规则。
    'enabled' => filter_var(env('THROTTLE_ENABLED', false), FILTER_VALIDATE_BOOL),
    'prefix' => env('THROTTLE_PREFIX', 'panda:throttle'),
    // 建议配置独立随机密钥，以 HMAC 隐藏手机号等低熵标识。
    'key_secret' => env('THROTTLE_KEY_SECRET', ''),
    'redis' => [
        'host' => env('THROTTLE_REDIS_HOST', '127.0.0.1'),
        'port' => (int) env('THROTTLE_REDIS_PORT', 6379),
        'database' => (int) env('THROTTLE_REDIS_DATABASE', 0),
        'username' => env('THROTTLE_REDIS_USERNAME', ''),
        'password' => env('THROTTLE_REDIS_PASSWORD', null),
        'connect_timeout' => 0.3,
        'read_timeout' => 0.3,
    ],
    // 数组或 callable(Request): array<Limit>，支持按控制器动作集中配置。
    'rules' => [],
    'failure_mode' => 'deny',
    'on_limit' => null,
    // callable(Request, StorageUnavailable, Closure $next): Response 可按接口选择降级。
    'on_unavailable' => null,
];
