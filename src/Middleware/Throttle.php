<?php
declare(strict_types=1);

namespace Panda\ThinkThrottle\Middleware;

use Closure;
use InvalidArgumentException;
use Panda\ThinkThrottle\Decision;
use Panda\ThinkThrottle\Exception\StorageUnavailable;
use Panda\ThinkThrottle\Limit;
use Panda\ThinkThrottle\RateLimiter;
use think\Config;
use think\Request;
use think\Response;

/** ThinkPHP 适配；规则、身份与统一响应均由应用配置提供。 */
final class Throttle
{
    /** 注入延迟限流入口。 @param RateLimiter $limiter 限流器 @param Config $config 应用配置 */
    public function __construct(private readonly RateLimiter $limiter, private readonly Config $config) {}

    /**
     * 执行规则，超限返回429；存储故障按明确策略返回503或降级。
     * @param Request $request 当前请求
     * @param Closure $next 后续处理
     * @param array $options 当前层覆盖选项
     * @return Response 业务或限流响应
     */
    public function handle(Request $request, Closure $next, array $options = []): Response
    {
        $settings = array_replace((array) $this->config->get('think_throttle', []), $options);
        if ($request->isOptions() || empty($settings['enabled'])) return $next($request);
        $rules = $settings['rules'] ?? [];
        if (is_callable($rules)) $rules = $rules($request);
        if (!is_array($rules) || !array_is_list($rules)) throw new InvalidArgumentException('Throttle rules must be a list');
        if ($rules === []) return $next($request);
        foreach ($rules as $rule) if (!$rule instanceof Limit) throw new InvalidArgumentException('Throttle rules must contain Limit objects');
        try { $decision = $this->limiter->consume(...$rules); }
        catch (StorageUnavailable $exception) {
            if (is_callable($settings['on_unavailable'] ?? null)) return $this->response(($settings['on_unavailable'])($request, $exception, $next));
            if (($settings['failure_mode'] ?? 'deny') === 'allow') return $next($request);
            return json(['code' => 'RATE_LIMITER_UNAVAILABLE', 'msg' => 'Service temporarily unavailable', 'data' => new \stdClass()], 503)
                ->header(['Retry-After' => 1, 'Cache-Control' => 'no-store']);
        }
        if (!$decision->allowed) {
            $response = is_callable($settings['on_limit'] ?? null)
                ? $this->response(($settings['on_limit'])($decision, $request))
                : json(['code' => 'RATE_LIMITED', 'msg' => 'Too many requests', 'data' => new \stdClass()], 429);
            return $response->header($decision->headers() + ['Cache-Control' => 'no-store']);
        }
        return $next($request)->header($decision->headers());
    }

    /** 回调必须返回 HTTP 响应，不将错误配置静默视为放行。 @param mixed $response 回调结果 @return Response 响应 */
    private function response(mixed $response): Response
    {
        if (!$response instanceof Response) throw new InvalidArgumentException('Throttle callback must return a Response');
        return $response;
    }
}
