<?php
declare(strict_types=1);

namespace Panda\ThinkThrottle\Exception;

/** Redis 不可用时交给应用明确选择拒绝或降级，不伪装成正常限流。 */
final class StorageUnavailable extends \RuntimeException {}
