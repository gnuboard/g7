<?php

namespace App\Seo;

use Illuminate\Http\Request;

/**
 * SEO 렌더러 내부 호출 표식
 *
 * SEO 렌더러는 화면을 만들려고 레이아웃의 데이터 소스 API 를 서버 자기 자신에게 HTTP 로 부른다
 * (글 작성·수정 직후 캐시 재생성, 검색 봇 방문). 그 호출은 사람의 열람이 아니므로 받는 쪽이 조회수
 * 같은 열람 집계에서 뺄 수 있어야 한다. 헤더 값은 앱 키로 서명해 바깥 요청이 흉내 낼 수 없다.
 */
final class SeoRenderRequest
{
    /**
     * 내부 호출에 붙는 헤더 이름.
     */
    public const HEADER = 'X-G7-Seo-Render';

    /**
     * 앱 키로 만든 헤더 서명 값을 반환합니다.
     *
     * @return string HMAC-SHA256 서명
     */
    public static function signature(): string
    {
        return hash_hmac('sha256', 'g7-seo-render-datasource', (string) config('app.key'));
    }

    /**
     * 내부 호출에 붙일 헤더 묶음을 반환합니다.
     *
     * @return array<string, string> 헤더 이름 => 서명 값
     */
    public static function headers(): array
    {
        return [self::HEADER => self::signature()];
    }

    /**
     * 요청이 SEO 렌더러의 내부 호출인지 판정합니다 (서명이 맞을 때만 true).
     *
     * @param  Request|null  $request  판정할 요청 (생략 시 현재 요청)
     * @return bool 내부 렌더 호출이면 true
     */
    public static function matches(?Request $request = null): bool
    {
        $request ??= request();
        $value = (string) $request->headers->get(self::HEADER, '');

        return $value !== '' && hash_equals(self::signature(), $value);
    }
}
