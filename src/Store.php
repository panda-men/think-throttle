<?php
declare(strict_types=1);

namespace Panda\ThinkThrottle;

/** 限流存储边界，便于应用隔离测试。 */
interface Store
{
    /** 原子校验并扣除一组规则。 @param array<Limit> $limits 规则 @return Decision 决策 */
    public function consume(array $limits): Decision;
}
