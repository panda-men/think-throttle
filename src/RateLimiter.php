<?php
declare(strict_types=1);

namespace Panda\ThinkThrottle;

use InvalidArgumentException;

/** 与 HTTP、认证和数据库无关的限流入口。 */
final class RateLimiter
{
    /** 注入原子存储。 @param Store $store 限流存储 */
    public function __construct(private readonly Store $store) {}

    /**
     * 多条规则一起通过才扣除额度，最多十六条，避免无界脚本。
     * @param Limit ...$limits 当前操作的限流规则
     * @return Decision 放行或等待信息
     */
    public function consume(Limit ...$limits): Decision
    {
        if ($limits === [] || count($limits) > 16) throw new InvalidArgumentException('Provide 1 to 16 limits');
        return $this->store->consume($limits);
    }
}
