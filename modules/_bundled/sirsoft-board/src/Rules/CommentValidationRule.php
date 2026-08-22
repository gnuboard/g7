<?php

namespace Modules\Sirsoft\Board\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Modules\Sirsoft\Board\Enums\PostStatus;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Support\SecretContentGate;

/**
 * 댓글/대댓글 작성 시 검증 규칙
 *
 * 검증 대상에 따라 다음을 검증합니다:
 * - post_id: 게시글의 블라인드/삭제 상태, 비밀글 열람 권한
 * - parent_id: 부모 댓글의 블라인드/삭제 상태, 부모 게시글의 비밀글 열람 권한,
 *   게시판 max_comment_depth 초과 여부
 *
 * 비밀글 게이트는 요청 단계의 조기 차단이며 최종 관문은 CommentService 다(이중 방어).
 */
class CommentValidationRule implements ValidationRule
{
    /**
     * CommentValidationRule 생성자
     *
     * @param  string  $slug  게시판 슬러그
     * @param  string  $validationType  검증 타입 ('post' 또는 'parent_comment')
     * @param  int|null  $postId  상위 게시글 ID (parent_comment 검증 시 부모 댓글의 소속 게시글 확인용)
     */
    public function __construct(
        private string $slug,
        private string $validationType = 'post',
        private ?int $postId = null
    ) {}

    /**
     * 검증 규칙을 실행합니다.
     *
     * @param  string  $attribute  속성명
     * @param  mixed  $value  검증할 값
     * @param  Closure  $fail  실패 콜백
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (empty($value)) {
            return;
        }

        if ($this->validationType === 'post') {
            $this->validatePost($value, $fail);
        } elseif ($this->validationType === 'parent_comment') {
            $this->validateParentComment($value, $fail);
        }
    }

    /**
     * 게시글 검증
     *
     * @param  mixed  $postId  게시글 ID
     * @param  Closure  $fail  실패 콜백
     */
    private function validatePost(mixed $postId, Closure $fail): void
    {
        $board = Board::where('slug', $this->slug)->first();
        $post = $board
            ? Post::where('board_id', $board->id)->withTrashed()->find($postId)
            : null;

        if (! $post) {
            $fail(__('sirsoft-board::validation.comment.post_id.not_found'));

            return;
        }

        if ($post->status === PostStatus::Blinded) {
            $fail(__('sirsoft-board::validation.comment.post_id.blinded'));

            return;
        }

        if ($post->status === PostStatus::Deleted || $post->deleted_at !== null) {
            $fail(__('sirsoft-board::validation.comment.post_id.deleted'));

            return;
        }

        if (! $this->canWriteChildOf($post, $board)) {
            $fail(__('sirsoft-board::validation.comment.post_id.secret'));
        }
    }

    /**
     * 부모 댓글 검증
     *
     * @param  mixed  $parentId  부모 댓글 ID
     * @param  Closure  $fail  실패 콜백
     */
    private function validateParentComment(mixed $parentId, Closure $fail): void
    {
        $board = Board::where('slug', $this->slug)->first();
        $parentComment = $board
            ? Comment::where('board_id', $board->id)
                ->when($this->postId !== null, fn ($query) => $query->where('post_id', $this->postId))
                ->withTrashed()
                ->find($parentId)
            : null;

        if (! $parentComment) {
            $fail(__('sirsoft-board::validation.comment.parent_id.not_found'));

            return;
        }

        if ($parentComment->status === PostStatus::Blinded) {
            $fail(__('sirsoft-board::validation.comment.parent_id.blinded'));

            return;
        }

        if ($parentComment->status === PostStatus::Deleted || $parentComment->deleted_at !== null) {
            $fail(__('sirsoft-board::validation.comment.parent_id.deleted'));

            return;
        }

        // 부모 게시글의 비밀글 게이트 — 부모 댓글의 블라인드/삭제 검사와는 별개 축이다.
        // 대댓글도 비밀글의 하위 콘텐츠이므로 같은 열람 기준을 적용한다 (KVE-2026-2044).
        $parentPost = Post::where('board_id', $board->id)
            ->withTrashed()
            ->find($this->postId ?? $parentComment->post_id);

        if ($parentPost && ! $this->canWriteChildOf($parentPost, $board)) {
            $fail(__('sirsoft-board::validation.comment.post_id.secret'));

            return;
        }

        // 댓글 깊이 제한 검증
        if ($parentComment->depth + 1 > $board->max_comment_depth) {
            $fail(__('sirsoft-board::validation.comment.depth.exceeded', ['max' => $board->max_comment_depth]));
        }
    }

    /**
     * 부모 게시글의 하위 콘텐츠를 작성할 수 있는지 판정합니다.
     *
     * 판정 규칙은 읽기 게이트와 같은 SecretContentGate(SSoT)를 재사용한다.
     * board 관계를 붙여 두는 이유는 게이트의 슬러그 해석이 라우트에 없을 때
     * 관계로 폴백하기 때문이다(미로딩이면 fail-closed).
     *
     * @param  Post  $post  부모 게시글
     * @param  Board  $board  대상 게시판
     * @return bool 작성 가능 여부
     */
    private function canWriteChildOf(Post $post, Board $board): bool
    {
        if (! $post->relationLoaded('board')) {
            $post->setRelation('board', $board);
        }

        return app(SecretContentGate::class)->canWriteChild($post);
    }
}
