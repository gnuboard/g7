<?php

namespace Modules\Sirsoft\Board\Support;

/**
 * 게시판 권한 캐시의 요청 속성 키
 *
 * 권한 판정 결과와 권한 맵은 서로 다른 키에 담기지만 수명이 같다. 한쪽만 비우면
 * 권한을 바꾼 뒤에도 이전 권한 맵이 응답에 그대로 실리므로, 두 키를 한 곳에 모아
 * 무효화가 항상 함께 이루어지도록 한다.
 *
 * Trait 이 아니라 별도 클래스에 두는 이유: Trait 상수는 트레이트 이름으로 직접
 * 접근할 수 없어서, 트레이트의 static 메서드를 트레이트 이름으로 호출하는 경로
 * (`ChecksBoardPermission::clearPermissionCache()`)에서 `self::` 해석이 실패한다.
 */
final class BoardPermissionCacheKeys
{
    /**
     * 권한 판정 결과 캐시 키 (권한 식별자 ⇒ bool)
     */
    public const PERMISSION = '_board_permission_cache';

    /**
     * 게시판 권한 맵 캐시 키 (게시판·화면·사용자 ⇒ can_* 묶음)
     */
    public const ABILITIES = '_board_abilities_cache';
}
