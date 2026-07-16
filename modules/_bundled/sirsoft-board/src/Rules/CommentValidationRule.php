<?php

namespace Modules\Sirsoft\Board\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Modules\Sirsoft\Board\Enums\PostStatus;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Models\Post;

/**
 * 댓글/대댓글 작성 시 검증 규칙
 *
 * 검증 대상에 따라 다음을 검증합니다:
 * - post_id: 게시글의 블라인드/삭제 상태
 * - parent_id: 부모 댓글의 블라인드/삭제 상태, 게시판 max_comment_depth 초과 여부
 */
class CommentValidationRule implements ValidationRule
{
    /**
     * CommentValidationRule 생성자
     *
     * @param  string  $slug  게시판 슬러그
     * @param  string  $validationType  검증 타입 ('post' 또는 'parent_comment')
     */
    public function __construct(
        private string $slug,
        private string $validationType = 'post'
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
            ? Comment::where('board_id', $board->id)->withTrashed()->find($parentId)
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

        // 댓글 깊이 제한 검증
        if ($parentComment->depth + 1 > $board->max_comment_depth) {
            $fail(__('sirsoft-board::validation.comment.depth.exceeded', ['max' => $board->max_comment_depth]));
        }
    }
}
