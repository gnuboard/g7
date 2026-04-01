<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Http\MaintenanceModeBypassCookie;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MaintenanceModePage
{
    /**
     * Maintenance 모드일 때 전용 정적 페이지를 렌더링합니다.
     *
     * DB, API, JS 에셋에 의존하지 않는 자체 완결 Blade 페이지를 반환합니다.
     * 메인터넌스 모드는 시스템 불안정 시(DB 마이그레이션, 코어 업데이트 등) 사용되므로
     * 외부 의존성 없이 동작해야 합니다.
     *
     * @param Request $request HTTP 요청
     * @param Closure $next 다음 미들웨어
     * @return Response HTTP 응답
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->isDownForMaintenance()) {
            return $next($request);
        }

        // secret 쿠키가 있으면 통과 (관리자 접근)
        if ($this->hasValidBypassCookie($request)) {
            return $next($request);
        }

        // API 요청은 JSON 응답
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'success' => false,
                'message' => __('maintenance.message'),
            ], 503);
        }

        // 로케일 감지 (SetLocale 미들웨어가 실행되지 않으므로 자체 감지)
        $locale = $this->detectLocale($request);
        app()->setLocale($locale);

        // 정적 메인터넌스 페이지 렌더링 (DB/API/JS 무의존)
        return response()->view('maintenance', [], 503);
    }

    /**
     * 유효한 bypass 쿠키가 있는지 확인합니다.
     *
     * @param Request $request HTTP 요청
     * @return bool
     */
    protected function hasValidBypassCookie(Request $request): bool
    {
        $downFilePath = storage_path('framework/down');

        if (! file_exists($downFilePath)) {
            return false;
        }

        $data = json_decode(file_get_contents($downFilePath), true);

        if (! isset($data['secret'])) {
            return false;
        }

        $cookie = $request->cookie('laravel_maintenance');

        if (! $cookie) {
            return false;
        }

        return MaintenanceModeBypassCookie::isValid($cookie, $data['secret']);
    }

    /**
     * 로케일을 감지합니다. SetLocale 미들웨어의 로직을 준용합니다.
     *
     * @param Request $request HTTP 요청
     * @return string 감지된 로케일
     */
    protected function detectLocale(Request $request): string
    {
        $supportedLocales = config('app.supported_locales', ['ko', 'en']);

        $acceptLanguage = $request->header('Accept-Language');
        if ($acceptLanguage) {
            $languages = explode(',', $acceptLanguage);
            foreach ($languages as $language) {
                $lang = trim(explode(';', $language)[0]);
                if (strpos($lang, '-') !== false) {
                    $lang = explode('-', $lang)[0];
                }
                if (in_array($lang, $supportedLocales)) {
                    return $lang;
                }
            }
        }

        return config('app.locale', 'ko');
    }
}
