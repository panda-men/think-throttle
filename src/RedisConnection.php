<?php
declare(strict_types=1);

namespace Panda\ThinkThrottle;

use InvalidArgumentException;
use Panda\ThinkThrottle\Exception\StorageUnavailable;
use Redis;
use Throwable;

/** 独立的短超时连接，不复用队列连接的超时和事务状态。 */
final class RedisConnection
{
    /**
     * 按需连接单机 Redis，支持密码和 ACL 账号。
     * @param array $config host/port/database/username/password/connect_timeout/read_timeout
     * @return Redis 已选库且不进行序列化的连接
     */
    public static function connect(array $config): Redis
    {
        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (int) ($config['port'] ?? 6379);
        $database = (int) ($config['database'] ?? 0);
        $connectTimeout = (float) ($config['connect_timeout'] ?? 0.3);
        $readTimeout = (float) ($config['read_timeout'] ?? 0.3);
        if ($host === '' || $port < 1 || $port > 65535 || $database < 0 || !is_finite($connectTimeout) ||
            !is_finite($readTimeout) || $connectTimeout <= 0 || $connectTimeout > 5 || $readTimeout <= 0 || $readTimeout > 5) {
            throw new InvalidArgumentException('Invalid throttle Redis connection settings');
        }
        try {
            $redis = new Redis();
            if (!@$redis->connect($host, $port, $connectTimeout)) throw new \RuntimeException('connect failed');
            $redis->setOption(Redis::OPT_READ_TIMEOUT, $readTimeout);
            $redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE);
            $password = $config['password'] ?? null;
            $username = (string) ($config['username'] ?? '');
            if (($username !== '' || ($password !== null && $password !== '')) && !$redis->auth($username !== '' ? [$username, (string) $password] : $password)) {
                throw new \RuntimeException('auth failed');
            }
            if (!$redis->select($database)) throw new \RuntimeException('select failed');
            return $redis;
        } catch (Throwable) {
            // 凭据、主机地址和原始认证错误不进入公开异常。
            throw new StorageUnavailable('Rate limit storage is unavailable');
        }
    }
}
