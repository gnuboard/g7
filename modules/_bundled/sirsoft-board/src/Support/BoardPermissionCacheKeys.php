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

    /**
     * 게시판 모델 캐시 키 (슬러그 ⇒ Board|false)
     *
     * 게시판 단위 스코프(`manager`·`admin.manage` 의 `resource_route_key = board`)를
     * 판정할 때 슬러그로 게시판 모델을 한 번만 읽도록 요청 단위로 담아 둔다.
     * 권한 캐시와 함께 비운다(게시판을 지우고 다시 만든 테스트에서 낡은 모델이 남지 않게).
     */
    public const BOARD_MODEL = '_board_model_cache';

    /**
     * 비밀글 스레드 노드 캐시 키 ([boardId][postId] ⇒ 노드 요약)
     */
    public const SECRET_THREAD_NODES = '_board_secret_thread_nodes';

    /**
     * 비밀글 스레드 경로 판정 메모 키 (uid|grant 해시|postId ⇒ bool)
     */
    public const SECRET_THREAD_VERDICTS = '_board_secret_thread_verdicts';

    /**
     * 회원 답변 존재 여부 메모 키 ([boardId][userId] ⇒ bool)
     */
    public const SECRET_THREAD_MEMBER_REPLY = '_board_secret_thread_member_reply';

    /**
     * 비밀글 열람 토큰 요청 단위 override·레지스트리 메모 키
     */
    public const SECRET_VIEW_TOKEN = '_board_secret_view_token';

    /**
     * 읽기 스코프 「소유역할」 판정 메모 키 (열람자|작성자 ⇒ 역할 공유 여부)
     */
    public const READ_SCOPE_ROLE_PEERS = '_board_read_scope_role_peers';
}
