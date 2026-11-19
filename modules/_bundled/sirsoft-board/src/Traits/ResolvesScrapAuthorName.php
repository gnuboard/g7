<?php

namespace Modules\Sirsoft\Board\Traits;

use App\Models\User;
use Illuminate\Http\Request;
use Modules\Sirsoft\Board\DisplayName\BoardDisplayNames;

/**
 * 스크랩과 반응 목록의 원글(또는 댓글) 작성자명 해석 Trait
 *
 * 사용자 카드(PostScrapResource, UserReactionResource)와 관리자 목록(AdminPostScrapResource, AdminReactionResource)이
 * 같은 규칙으로 작성자명을 낸다. 규칙을 Resource 마다 복제하면 탈퇴 익명화나 표시 이름 설정이 한쪽에만 걸려
 * 관리자 화면에만 실명이 남는 식으로 갈린다.
 */
trait ResolvesScrapAuthorName
{
    /**
     * 원글 작성자명을 해석합니다 (회원 글은 그 화면의 표시 이름, 비회원 글은 입력한 작성자명).
     *
     * 사용자 화면은 그 글이 속한 게시판의 표시 이름 설정을, 관리자 화면은 관리자 영역 설정을 따른다.
     * 탈퇴한 회원의 글은 다른 게시판 API 와 같이 「탈퇴한 사용자」 문구다(삭제된 원글이라도 예외 없이 적용).
     *
     * @param  mixed  $post  원글 또는 댓글 모델 (소프트 삭제된 상태 포함 가능)
     * @return string|null 작성자명
     */
    protected function resolveAuthorName($post): ?string
    {
        $displayNames = app(BoardDisplayNames::class);

        return $displayNames->authorOf($post, $displayNames->areaFor());
    }

    /**
     * 원글 작성자의 실명 보조 값을 해석합니다 (관리자 화면이고 회원 조회 권한이 있을 때만, 표시 이름과 다를 때만).
     *
     * @param  mixed  $post  원글 또는 댓글 모델
     * @param  string|null  $displayName  같은 응답에 실은 작성자명
     * @param  Request|null  $request  HTTP 요청
     * @return string|null 실명 (싣지 않으면 null)
     */
    protected function resolveAuthorRealName($post, ?string $displayName, ?Request $request = null): ?string
    {
        $user = $post->user_id && $post->relationLoaded('user') ? $post->getRelation('user') : null;

        return app(BoardDisplayNames::class)->realName($user instanceof User ? $user : null, $displayName, $request);
    }
}
