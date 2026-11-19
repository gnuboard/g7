<?php

namespace Plugins\Sirsoft\Sso\Drivers\Saml;

use App\Support\SiteUrl;
use Illuminate\Http\Request;
use OneLogin\Saml2\Utils;

/**
 * `onelogin/php-saml` 이 읽는 PHP 전역 값(`$_SERVER`, `$_GET`)을 한 호출 동안만 맞춰 둡니다.
 *
 * 라이브러리는 「지금 받은 주소」(Destination, Recipient 대조)를 `$_SERVER['REQUEST_URI']` 로, Redirect 바인딩 서명 대조 값을
 * `$_GET`·`$_SERVER['QUERY_STRING']` 으로 만든다. 요청 Host 나 프록시 헤더가 아니라 **사이트 주소(SiteUrl)** 기준으로 비교해야
 * Host 위조에 흔들리지 않으므로, 기준 주소는 `Utils::setBaseURL(SiteUrl)` 로 고정하고 경로는 이 IdP 의 고정 경로(ACS, SLS)를 쓴다.
 * 호출이 끝나면 전역 값을 원래대로 되돌린다.
 *
 * @since 1.0.0
 */
final class SamlEnvironment
{
    /**
     * 전역 값을 맞춘 채로 함수를 실행합니다.
     *
     * @template T
     *
     * @param  string  $path  이 메시지를 받는 사이트 경로 (예: `/plugins/sirsoft-sso/saml/{key}/acs`)
     * @param  Request|null  $request  Redirect 바인딩 쿼리를 넘길 요청 (서명 대조용)
     * @param  callable(): T  $fn  실행할 함수
     * @return T 함수 결과
     */
    public static function run(string $path, ?Request $request, callable $fn): mixed
    {
        $server = $_SERVER;
        $get = $_GET;

        Utils::setBaseURL(SiteUrl::base());
        $_SERVER['REQUEST_URI'] = (string) parse_url(SiteUrl::absolute($path), PHP_URL_PATH);
        $_SERVER['SCRIPT_NAME'] = $_SERVER['REQUEST_URI'];
        unset($_SERVER['PATH_INFO']);

        $query = $request !== null ? (string) $request->server('QUERY_STRING', '') : '';
        $_SERVER['QUERY_STRING'] = $query;
        $_GET = [];
        if ($query !== '') {
            parse_str($query, $parsed);
            $_GET = $parsed;
        } elseif ($request !== null && $request->isMethod('GET')) {
            $_GET = $request->query->all();
        }

        try {
            return $fn();
        } finally {
            $_SERVER = $server;
            $_GET = $get;
        }
    }
}
