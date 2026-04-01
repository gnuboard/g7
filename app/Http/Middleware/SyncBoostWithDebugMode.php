<?php

namespace App\Http\Middleware;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 그누보드7 디버그 모드 설정에 따라 Laravel Boost의 browser-logs 기능을 동기화합니다.
 *
 * Laravel Boost의 InjectBoost 미들웨어는 앱 부팅 시점에 이미 등록되어 있으므로,
 * 런타임에 config 값을 변경해도 스크립트 주입을 막을 수 없습니다.
 *
 * 따라서 응답 후 처리 방식으로 디버그 모드가 false면
 * browser-logger 스크립트를 제거합니다.
 */
class SyncBoostWithDebugMode
{
    public function __construct(
        private ConfigRepositoryInterface $configRepository
    ) {}

    /**
     * 요청을 처리합니다.
     *
     * InjectBoost 미들웨어가 응답에 스크립트를 주입한 후,
     * 디버그 모드가 false면 해당 스크립트를 제거합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  Closure  $next  다음 미들웨어
     * @return Response HTTP 응답
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        // 그누보드7 디버그 모드 설정 조회
        $debugMode = $this->configRepository->get('debug.mode', false);

        // 디버그 모드가 활성화되어 있으면 스크립트 유지
        if ($debugMode) {
            return $response;
        }

        // HTML 응답이 아니면 처리하지 않음
        $contentType = $response->headers->get('content-type', '');
        if (! str_contains($contentType, 'html')) {
            return $response;
        }

        // 응답 본문에서 browser-logger 스크립트 제거
        $content = $response->getContent();
        if ($content && str_contains($content, 'browser-logger-active')) {
            $content = $this->removeBrowserLoggerScript($content);
            $response->setContent($content);
        }

        return $response;
    }

    /**
     * 응답에서 browser-logger 스크립트를 제거합니다.
     *
     * @param  string  $content  응답 본문
     * @return string 스크립트가 제거된 응답 본문
     */
    private function removeBrowserLoggerScript(string $content): string
    {
        // <script id="browser-logger-active" ...>...</script> 패턴 제거
        $pattern = '/<script[^>]*id=["\']browser-logger-active["\'][^>]*>[\s\S]*?<\/script>\s*/i';

        return preg_replace($pattern, '', $content);
    }
}
