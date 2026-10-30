<?php
declare(strict_types=1);

namespace Panda\ThinkThrottle;

use InvalidArgumentException;

/** 一条令牌桶规则；每 intervalMs 毫秒补充 refillTokens 个令牌。 */
final readonly class Limit
{
    /**
     * 定义规则和可信限流对象，规则参数变化时自动使用新桶。
     * @param string $id 稳定规则名称
     * @param string $key 限流对象，由应用从可信身份或规范化目标生成
     * @param int $capacity 桶容量，允许的短时突发
     * @param int $refillTokens 每个周期补充量
     * @param int $intervalMs 补充周期，毫秒
     * @param int $cost 当前操作消耗量
     */
    public function __construct(public string $id, public string $key, public int $capacity,
        public int $refillTokens, public int $intervalMs, public int $cost = 1)
    {
        if (!preg_match('/^[a-zA-Z0-9_.:-]{1,64}$/D', $id) || $key === '' || strlen($key) > 1024) {
            throw new InvalidArgumentException('Limit id or subject is invalid');
        }
        if ($capacity < 1 || $capacity > 1000000 || $refillTokens < 1 || $refillTokens > 1000000 ||
            $intervalMs < 1 || $intervalMs > 86400000 || $cost < 1 || $cost > $capacity ||
            $capacity * ($intervalMs / $refillTokens) > 604800000) {
            throw new InvalidArgumentException('Limit capacity, refill, interval or cost is invalid');
        }
    }

    /** 空闲最多七天后桶必定恢复全满，可安全过期。 @return int 过期毫秒数 */
    public function ttlMs(): int { return (int) ceil($this->capacity * ($this->intervalMs / $this->refillTokens)) + 1000; }
}
