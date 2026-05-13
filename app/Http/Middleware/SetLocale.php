<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * 들어오는 요청을 처리하고 사용자 언어를 설정합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  Closure  $next  다음 미들웨어 또는 요청 핸들러
     * @return Response HTTP 응답
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->determineLocale($request);
        App::setLocale($locale);

        return $next($request);
    }

    /**
     * 사용자의 언어 설정을 결정합니다.
     *
     * 우선순위:
     * 1. 로그인한 사용자의 언어 설정 (users.language)
     * 2. 브라우저 Accept-Language 헤더
     * 3. 시스템 기본값 (config('app.locale'))
     *
     * 참고: 프론트엔드(localStorage g7_locale)에서 언어를 관리하므로
     *       서버는 사용자 DB 설정만 우선 적용합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return string 결정된 언어 코드
     */
    private function determineLocale(Request $request): string
    {
        $supportedLocales = config('app.supported_locales', ['ko', 'en']);

        // 1. 인증된 사용자의 언어 설정 확인 (최우선)
        $user = $this->resolveUser($request);
        if ($user && $user->language) {
            $userLocale = $user->language;
            if (in_array($userLocale, $supportedLocales)) {
                return $userLocale;
            }
        }

        // 2. Accept-Language 헤더 확인 (브라우저 기본 언어)
        $acceptLanguage = $request->header('Accept-Language');
        if ($acceptLanguage) {
            $locale = $this->parseAcceptLanguage($acceptLanguage);
            if (in_array($locale, $supportedLocales)) {
                return $locale;
            }
        }

        // 3. 기본값 반환
        return config('app.locale', 'ko');
    }

    /**
     * 요청에서 사용자를 가져옵니다.
     *
     * 이 미들웨어는 auth:sanctum보다 먼저 실행되므로
     * Auth::check()가 작동하지 않을 수 있습니다.
     * 따라서 Bearer 토큰을 직접 파싱하여 사용자를 가져옵니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return \App\Models\User|null 사용자 또는 null
     */
    private function resolveUser(Request $request): ?\App\Models\User
    {
        // 이미 인증된 경우 (세션 기반 인증)
        if (Auth::check()) {
            return Auth::user();
        }

        // Bearer 토큰이 있는 경우 (API 토큰 인증)
        $bearerToken = $request->bearerToken();
        if ($bearerToken) {
            $token = PersonalAccessToken::findToken($bearerToken);
            if ($token && $token->tokenable instanceof \App\Models\User) {
                return $token->tokenable;
            }
        }

        return null;
    }

    /**
     * Accept-Language 헤더를 파싱하여 선호 언어를 추출합니다.
     *
     * @param  string  $acceptLanguage  Accept-Language 헤더 값
     * @return string 추출된 언어 코드 (기본값: config('app.locale'))
     */
    private function parseAcceptLanguage(string $acceptLanguage): string
    {
        $supportedLocales = config('app.supported_locales', ['ko', 'en']);
        $languages = explode(',', $acceptLanguage);

        foreach ($languages as $language) {
            $lang = trim(explode(';', $language)[0]);

            // 언어-지역 형태에서 언어만 추출 (예: ko-KR -> ko)
            if (strpos($lang, '-') !== false) {
                $lang = explode('-', $lang)[0];
            }

            if (in_array($lang, $supportedLocales)) {
                return $lang;
            }
        }

        return config('app.locale', 'ko');
    }
}
