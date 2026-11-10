<?php

namespace App\Support;

/**
 * 코어가 쓰는 필수 브라우저 저장 키 선언
 *
 * 쿠키 동의 플러그인은 동의 전 방문자의 브라우저에서 「필수」 목록 밖의 저장 키를 지운다. 코어 엔진이 쓰는 키 중
 * 방문자 동의와 무관하게 있어야 하는 키를 여기에 선언하고, 동의 플러그인이 이 목록을 제공 목록에 더한다
 * (코어는 동의 플러그인을 모른다 — 의존 방향 유지. 템플릿 `necessary_storage` 선언과 같은 통로).
 *
 * 형식은 템플릿 선언과 같다 — 저장소 종류(`localStorage`·`sessionStorage`·`cookie`) → 키 목록(끝 `*` 는 앞부분 매칭).
 * 운영자가 지울 수 없는 코어 선언이라 동의 플러그인 설정 목록(카탈로그)에 넣지 않는다.
 *
 * @since 7.1.5
 */
final class CoreNecessaryStorage
{
    /**
     * 비회원 최근 검색어 키 — 엔진 `G7Core.searchHistory` 가 쓰는 localStorage JSON (`{v, autoSave, items}`).
     * 방문자가 검색창에 직접 입력한 검색어만 담고 사이트 밖으로 보내지 않는다(로그인하면 회원 목록으로 옮기고 지운다).
     */
    public const SEARCH_HISTORY_KEY = 'g7_search_history';

    /**
     * 코어 필수 저장 키를 저장소 종류별로 반환합니다.
     *
     * @return array{localStorage: array<int, string>, sessionStorage: array<int, string>, cookie: array<int, string>} 저장소 종류 → 키 목록
     */
    public static function keys(): array
    {
        return [
            'localStorage' => [self::SEARCH_HISTORY_KEY],
            'sessionStorage' => [],
            'cookie' => [],
        ];
    }
}
