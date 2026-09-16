<?php

namespace Modules\Sirsoft\Board\Http\Resources;

use App\Enums\UserStatus;
use App\Http\Resources\BaseApiResource;
use App\Http\Resources\Traits\ExposesMemberContact;
use Illuminate\Http\Request;
use Modules\Sirsoft\Board\Enums\PostStatus;
use Modules\Sirsoft\Board\Services\ReactionService;
use Modules\Sirsoft\Board\Traits\ChecksBoardPermission;
use Modules\Sirsoft\Board\Traits\FormatsBoardDate;
use Modules\Sirsoft\Board\Traits\ResolvesScrapAuthorName;

/**
 * 관리자 반응 목록 행 리소스
 *
 * 사용자 카드(UserReactionResource)와 달리 "누가 무엇에 반응했는가" 가 행의 주어이므로
 * 회원 정보를 함께 싣는다. 이메일은 관리자 응답에서만 노출한다
 * (docs/api/README.md 「회원 이메일 노출 범위」).
 *
 * 삭제된 대상의 제목·발췌도 낸다 — 관리자는 "어떤 글/댓글에 달린 반응이 남아 있는지" 를
 * 알아야 정리할 수 있고, 상태는 배지가 구분한다. 탈퇴 회원 이름은 사용자 응답과 동일하게
 * 익명화한다.
 */
class AdminReactionResource extends BaseApiResource
{
    use ChecksBoardPermission;
    use ExposesMemberContact;
    use FormatsBoardDate;
    use ResolvesScrapAuthorName;

    /**
     * 댓글 발췌 길이.
     */
    private const EXCERPT_LENGTH = 80;

    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed> 변환된 관리자 반응 행
     */
    public function toArray(Request $request): array
    {
        $isComment = $this->target_type === ReactionService::TARGET_COMMENT;

        // 대상은 ReactionRepository::hydrateTargets() 가 setRelation 으로 채운다.
        $post = $this->resource->relationLoaded('post') ? $this->resource->getRelation('post') : null;
        $comment = $isComment && $this->resource->relationLoaded('comment')
            ? $this->resource->getRelation('comment')
            : null;
        $board = $this->relationLoaded('board') ? $this->board : null;
        $member = $this->relationLoaded('user') ? $this->user : null;
        $type = $this->relationLoaded('reactionType') ? $this->reactionType : null;

        $target = $isComment ? $comment : $post;
        $isDeleted = $this->resolveTargetDeleted($isComment, $post, $comment);
        $status = $target?->status instanceof PostStatus
            ? $target->status
            : PostStatus::tryFrom((string) ($target->status ?? ''));

        return [
            'id' => (int) $this->id,
            'reacted_at' => $this->created_at ? $this->formatCreatedAtFormat(
                $this->created_at,
                g7_module_settings('sirsoft-board', 'display.date_display_format', 'standard')
            ) : null,
            'created_at' => $this->formatDateTimeStringForUser($this->created_at),

            'target_type' => $this->target_type,
            'target_type_label' => __('sirsoft-board::admin.reactions.badges.'.($isComment ? 'comment' : 'post')),
            'target_id' => (int) $this->target_id,
            'post_id' => $isComment ? ($comment?->post_id === null ? null : (int) $comment->post_id) : (int) $this->target_id,
            'comment_id' => $isComment ? (int) $this->target_id : null,

            'member' => $member !== null ? $this->resolveMember($member, $request) : null,

            'board' => $board !== null ? [
                'id' => (int) $board->id,
                'slug' => $board->slug,
                'name' => $board->getLocalizedName(),
                'is_active' => (bool) $board->is_active,
                'use_reaction' => (bool) $board->use_reaction,
                'use_comment_reaction' => (bool) $board->use_comment_reaction,
            ] : null,

            // 제목은 언제나 글 제목 (댓글은 "어느 글의 댓글"이 맥락)
            'target_title' => $post?->title,
            'target_excerpt' => $isComment ? $this->buildExcerpt($comment) : null,
            'target_status' => $isDeleted ? PostStatus::Deleted->value : ($status?->value ?? PostStatus::Published->value),
            'target_status_label' => $isDeleted ? PostStatus::Deleted->label() : ($status?->label() ?? PostStatus::Published->label()),
            'is_target_deleted' => $isDeleted,

            'author_name' => $target !== null ? $this->resolveAuthorName($target) : null,

            'reaction_type' => $type === null ? null : [
                'id' => (int) $type->id,
                'code' => $type->code,
                'name' => $type->getLocalizedName(),
                'icon_name' => $type->getIconName(),
                'icon_url' => $type->getIconUrl(),
                'is_active' => (bool) $type->is_active,
            ],

            'abilities' => [
                'can_delete' => $this->checkModulePermission('reactions', 'manage'),
            ],
        ];
    }

    /**
     * 회원 정보를 응답용으로 해석합니다 (탈퇴 익명화 + 이메일 게이트).
     *
     * 이메일은 canExposeMemberEmail 게이트를 통과할 때만 싣는다. 이 Resource 는 관리자
     * 컨트롤러에서만 쓰이지만, 게이트를 거치지 않으면 나중에 사용자 경로가 이 Resource 를
     * 재사용하는 순간 회원 주소록이 그대로 새어나간다 — 판정은 한 곳(트레이트)에 맡긴다.
     *
     * @param  mixed  $member  회원 모델
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed> 회원 정보
     */
    private function resolveMember($member, Request $request): array
    {
        $isWithdrawn = UserStatus::tryFrom((string) $member->status) === UserStatus::Withdrawn;

        return [
            'uuid' => $member->uuid,
            'name' => $isWithdrawn ? __('user.withdrawn_user') : $member->name,
            'email' => $this->canExposeMemberEmail($request, (int) $member->id) ? $member->email : null,
            'is_withdrawn' => $isWithdrawn,
        ];
    }

    /**
     * 대상이 사라졌는지 판정합니다.
     *
     * @param  bool  $isComment  댓글 대상 여부
     * @param  mixed  $post  게시글(또는 댓글의 부모 글)
     * @param  mixed  $comment  댓글
     * @return bool 대상 소실 여부
     */
    private function resolveTargetDeleted(bool $isComment, $post, $comment): bool
    {
        if ($isComment) {
            if ($comment === null || $comment->deleted_at !== null) {
                return true;
            }

            return $post === null || $post->deleted_at !== null;
        }

        return $post === null || $post->deleted_at !== null;
    }

    /**
     * 댓글 본문 발췌를 만듭니다 (삭제된 댓글은 본문을 내지 않는다).
     *
     * @param  mixed  $comment  댓글
     * @return string|null 발췌
     */
    private function buildExcerpt($comment): ?string
    {
        if ($comment === null || $comment->deleted_at !== null) {
            return null;
        }

        $content = trim(strip_tags((string) $comment->content));

        return $content === '' ? null : mb_strimwidth($content, 0, self::EXCERPT_LENGTH, '…');
    }
}
