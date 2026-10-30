<?php
declare(strict_types=1);

namespace Panda\ThinkThrottle;

/** 一次原子判定；拒绝时不扣除任何规则的令牌。 */
final readonly class Decision
{
    /**
     * 保存最紧约束对应的展示信息。
     * @param bool $allowed 是否放行
     * @param string $ruleId 约束规则名，不包含限流对象
     * @param int $limit 该规则的桶容量
     * @param int $remaining 当前可消费的整令牌数
     * @param int $retryAfterMs 所有规则足够本次消费还需等待的毫秒数
     * @param int $resetAfterMs 展示规则恢复全满还需等待的毫秒数
     * @param int $observedAtMs Redis 服务端时间
     */
    public function __construct(public bool $allowed, public string $ruleId, public int $limit, public int $remaining,
        public int $retryAfterMs, public int $resetAfterMs, public int $observedAtMs) {}

    /**
     * 生成响应头；Reset 为 Unix 秒，Retry-After 为相对秒。
     * @return array<string,int|string> 响应头
     */
    public function headers(): array
    {
        $headers = ['X-RateLimit-Limit' => $this->limit, 'X-RateLimit-Remaining' => $this->remaining,
            'X-RateLimit-Reset' => (int) ceil(($this->observedAtMs + $this->resetAfterMs) / 1000)];
        if (!$this->allowed) $headers['Retry-After'] = max(1, (int) ceil($this->retryAfterMs / 1000));
        return $headers;
    }
}
