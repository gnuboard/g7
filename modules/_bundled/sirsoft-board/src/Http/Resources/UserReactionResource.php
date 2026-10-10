<?php

namespace Modules\Sirsoft\Board\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Modules\Sirsoft\Board\Enums\PostStatus;
use Modules\Sirsoft\Board\Services\ReactionService;
use Modules\Sirsoft\Board\Traits\FormatsBoardDate;
use Modules\Sirsoft\Board\Traits\ResolvesScrapAuthorName;

/**
 * 마이페이지 「내 반응」 행 리소스
 *
 * 게시글 반응과 댓글 반응을 한 목록에 섞어 보여준다. 대상이 삭제됐거나 게시판이
 * 사라졌어도 행은 남는다 — 회원이 남긴 기록이므로 해제(회수)는 언제나 가능해야 한다
 * (PO 결정 R11). 다만 그 경우 이동 링크는 주지 않는다.
 */
class UserReactionResource extends BaseApiResource
{
    use FormatsBoardDate;
    use ResolvesScrapAuthorName;

    /**
     * 댓글 발췌 길이.
     */
    private const EXCERPT_LENGTH = 80;

    /**
     * 반응 행을 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return array<string, mixed> 변환된 배열 데이터
     */
    public function toArray(Request $request): array
    {
        $isComment = $this->target_type === ReactionService::TARGET_COMMENT;

        // 대상은 ReactionRepository::hydrateTargets() 가 setRelation 으로 채운다
        // (morph map 이 없어 with() 로는 타입을 구분할 수 없다). 채워지지 않았으면 null.
        $post = $this->resource->relationLoaded('post') ? $this->resource->getRelation('post') : null;
        $comment = $isComment && $this->resource->relationLoaded('comment')
            ? $this->resource->getRelation('comment')
            : null;
        $board = $this->board;

        $targetDeleted = $this->resolveTargetDeleted($isComment, $post, $comment);
        $author = $isComment ? $comment : $post;

        return [
            'id' => (int) $this->id,
            'target_type' => $this->target_type,
            'target_id' => (int) $this->target_id,

            // 댓글 반응도 이동은 부모 글 상세로 간다.
            'post_id' => $isComment ? ($comment?->post_id === null ? null : (int) $comment->post_id) : (int) $this->target_id,
            'comment_id' => $isComment ? (int) $this->target_id : null,

            // 제목은 언제나 글 제목이다 (댓글은 "어느 글의 댓글"인지가 맥락이다).
            'title' => $post?->title,
            'excerpt' => $isComment ? $this->buildExcerpt($comment) : null,

            'board' => $board === null ? null : [
                'slug' => $board->slug,
                'name' => $board->getLocalizedName(),
                'is_active' => (bool) $board->is_active,
                'use_reaction' => (bool) $board->use_reaction,
                'use_comment_reaction' => (bool) $board->use_comment_reaction,
            ],
            'board_slug' => $board?->slug,
            'board_name' => $board?->getLocalizedName(),
            'board_deleted' => $board === null,

            'reaction_type' => $this->reactionType === null ? null : [
                'id' => (int) $this->reactionType->id,
                'code' => $this->reactionType->code,
                'name' => $this->reactionType->getLocalizedName(),
                'icon_name' => $this->reactionType->getIconName(),
                'icon_url' => $this->reactionType->getIconUrl(),
            ],

            'author_name' => $author === null ? null : $this->resolveAuthorName($author),

            'target_deleted' => $targetDeleted,

            // 반응을 "다시 남길 수 있는" 상태인지 — 꺼진 게시판에서도 해제는 되지만
            // 버튼/링크 노출은 이 값으로 가른다.
            'reaction_enabled' => $board !== null
                && (bool) $board->is_active
                && (bool) $board->use_reaction
                && (! $isComment || (bool) $board->use_comment_reaction)
                && ! $targetDeleted,

            'reacted_at' => $this->formatDateTimeStringForUser($this->created_at),
        ];
    }

    /**
     * 대상이 사라졌거나 더 이상 열람할 수 없는 상태인지 판정합니다.
     *
     * 댓글 반응은 부모 글이 삭제돼도 볼 곳이 없으므로 삭제로 친다.
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

            // 부모 글이 사라지면 댓글에도 도달할 수 없다.
            return $post === null || $post->deleted_at !== null;
        }

        if ($post === null || $post->deleted_at !== null) {
            return true;
        }

        return $post->status !== PostStatus::Published;
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

        if ($content === '') {
            return null;
        }

        return mb_strimwidth($content, 0, self::EXCERPT_LENGTH, '…');
    }
}
