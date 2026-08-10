<?php

namespace App\Http\Middleware\Concerns;

use Illuminate\Http\Request;

/**
 * SetLocale 미들웨어보다 앞서 실행되는 미들웨어를 위한 로케일 자체 감지.
 *
 * 메인터넌스 페이지·DB 자격증명 가드처럼 인증·세션·확장 로딩 이전에 응답을
 * 만들어야 하는 미들웨어는 SetLocale 이 아직 실행되지 않은 상태에서 동작한다.
 * 이 트레이트가 SetLocale 의 판정 로직을 준용해 Accept-Language 를 해석한다.
 */
trait DetectsRequestLocale
{
    /**
     * 요청 헤더에서 로케일을 감지합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return string 감지된 로케일 (지원 목록에 없으면 앱 기본 로케일)
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
