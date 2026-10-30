<?php
declare(strict_types=1);

namespace Panda\ThinkThrottle;

use Closure;
use InvalidArgumentException;
use Panda\ThinkThrottle\Exception\StorageUnavailable;
use Redis;
use Throwable;

/** Redis TIME + Lua 实现多规则原子令牌桶；不依赖应用节点时钟。 */
final class RedisStore implements Store
{
    private ?Redis $connection = null;
    private string $script;

    /**
     * 接收现有专用连接或延迟连接工厂，不自动连接 Redis。
     * @param Redis|Closure $redis 原始未序列化连接或无参数工厂
     * @param string $prefix 项目与环境命名空间
     * @param string $keySecret 可选 HMAC 密钥，配置时至少三十二字节
     */
    public function __construct(private readonly Redis|Closure $redis, private readonly string $prefix = 'panda:throttle',
        private readonly string $keySecret = '')
    {
        if (!preg_match('/^[a-zA-Z0-9:_-]{1,128}$/D', $prefix) || ($keySecret !== '' && strlen($keySecret) < 32)) {
            throw new InvalidArgumentException('Invalid throttle prefix or HMAC secret');
        }
        $this->script = (string) file_get_contents(__DIR__.'/lua/token_bucket.lua');
    }

    /**
     * 一次脚本执行完成整组规则的判定和写入，NOSCRIPT 时重发脚本。
     * @param array<Limit> $limits 限流规则
     * @return Decision 限流结果
     */
    public function consume(array $limits): Decision
    {
        if ($limits === [] || count($limits) > 16) throw new InvalidArgumentException('Provide 1 to 16 limits');
        $keys = []; $arguments = [];
        foreach ($limits as $limit) {
            if (!$limit instanceof Limit) throw new InvalidArgumentException('Every rule must be a Limit');
            $keys[] = $this->key($limit);
            array_push($arguments, $limit->capacity, $limit->refillTokens, $limit->intervalMs, $limit->cost, $limit->ttlMs());
        }
        if (count(array_unique($keys)) !== count($keys)) throw new InvalidArgumentException('Duplicate limits are not allowed');
        try {
            $redis = $this->connection ??= ($this->redis instanceof Closure ? ($this->redis)() : $this->redis);
            $payload = array_merge($keys, $arguments);
            $redis->clearLastError();
            $reload = false;
            try { $result = $redis->evalSha(sha1($this->script), $payload, count($keys)); }
            catch (\RedisException $exception) {
                if (!str_contains($exception->getMessage(), 'NOSCRIPT')) throw $exception;
                $reload = true;
                $result = false;
            }
            if ($result === false && ($reload || str_contains((string) $redis->getLastError(), 'NOSCRIPT'))) {
                $redis->clearLastError();
                $result = $redis->eval($this->script, $payload, count($keys));
            }
            if (!is_array($result) || count($result) !== 7 || !isset($limits[(int)$result[2]-1])) throw new \RuntimeException('Invalid script response');
            return new Decision((int)$result[0] === 1, $limits[(int)$result[2]-1]->id, (int)$result[3],
                (int)$result[4], (int)$result[1], (int)$result[5], (int)$result[6]);
        } catch (Throwable) {
            $this->connection = null;
            throw new StorageUnavailable('Rate limit storage is unavailable');
        }
    }

    /**
     * 规则参数纳入桶键，key 不明文保存；不使用客户端可任意变化的幂等键。
     * @param Limit $limit 规则
     * @return string Redis 键
     */
    private function key(Limit $limit): string
    {
        $value = json_encode([$limit->id, $limit->key, $limit->capacity, $limit->refillTokens, $limit->intervalMs], JSON_THROW_ON_ERROR);
        $hash = $this->keySecret === '' ? hash('sha256', $value) : hash_hmac('sha256', $value, $this->keySecret);
        return $this->prefix.':'.$hash;
    }
}
