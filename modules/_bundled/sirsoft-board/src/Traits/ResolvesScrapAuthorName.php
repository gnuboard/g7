<?php

namespace Modules\Sirsoft\Board\Traits;

use App\Enums\UserStatus;

/**
 * 스크랩 원글 작성자명 해석 Trait
 *
 * 사용자 카드(PostScrapResource)와 관리자 목록(AdminPostScrapResource)이 같은 규칙으로
 * 작성자명을 낸다. 규칙을 두 Resource 에 복제하면 탈퇴 익명화가 한쪽에만 걸려
 * 관리자 화면에만 탈퇴 회원의 실명이 남는 식으로 갈린다.
 */
trait ResolvesScrapAuthorName
{
    /**
     * 원글 작성자명을 해석합니다 (회원 글은 회원명, 비회원 글은 작성자명).
     *
     * 탈퇴한 회원의 글은 다른 게시판 API(PostResource·CommentResource·BoardRepository)와
     * 동일하게 익명화한다 — 삭제된 원글이라도 예외 없이 적용.
     *
     * @param  mixed  $post  원글 모델 (소프트 삭제된 상태 포함 가능)
     * @return string|null 작성자명
     */
    protected function resolveAuthorName($post): ?string
    {
        if ($post->relationLoaded('user') && $post->user) {
            $userStatus = UserStatus::tryFrom($post->user->status);

            return $userStatus === UserStatus::Withdrawn ? __('user.withdrawn_user') : $post->user->name;
        }

        return $post->author_name;
    }
}
