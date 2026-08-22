<?php

namespace Modules\Sirsoft\Board\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Modules\Sirsoft\Board\Enums\PostStatus;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Support\SecretContentGate;

/**
 * 답글 작성 시 부모 게시글 검증 규칙
 *
 * parent_id가 있을 때 다음을 검증합니다:
 * 1. 게시판의 답글 기능(use_reply) 활성화 여부
 * 2. 부모 게시글의 블라인드/삭제 상태
 * 2-1. 부모 게시글이 비밀글이면 원문 열람 권한 (SecretContentGate SSoT)
 * 3. 공지 게시글에는 답글 불가
 * 4. 게시판 설정의 max_reply_depth 초과 여부
 */
class ParentPostValidationRule implements ValidationRule
{
    /**
     * ParentPostValidationRule 생성자
     *
     * @param  string  $slug  게시판 슬러그
     */
    public function __construct(
        private string $slug
    ) {}

    /**
     * 검증 규칙을 실행합니다.
     *
     * @param  string  $attribute  속성명
     * @param  mixed  $value  검증할 값 (parent_id)
     * @param  Closure  $fail  실패 콜백
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // parent_id가 없으면 원글이므로 통과
        if (empty($value)) {
            return;
        }

        // 1. 게시판의 답글 기능 검증
        $board = Board::where('slug', $this->slug)->first();

        if (! $board) {
            $fail(__('sirsoft-board::validation.board.not_found'));

            return;
        }

        if (! $board->use_reply) {
            $fail(__('sirsoft-board::validation.post.reply_not_allowed'));

            return;
        }

        // 2. 부모 게시글의 블라인드/삭제 상태 검증
        $parentPost = Post::where('board_id', $board->id)->withTrashed()->find($value);

        if (! $parentPost) {
            $fail(__('sirsoft-board::validation.post.parent_id.not_found'));

            return;
        }

        if ($parentPost->status === PostStatus::Blinded) {
            $fail(__('sirsoft-board::validation.post.parent_id.blinded'));

            return;
        }

        if ($parentPost->status === PostStatus::Deleted || $parentPost->deleted_at !== null) {
            $fail(__('sirsoft-board::validation.post.parent_id.deleted'));

            return;
        }

        // 2-1. 비밀글 하위 쓰기 게이트 (KVE-2026-2044)
        // 답글도 비밀글의 하위 콘텐츠이므로 원문 열람과 같은 기준을 적용한다.
        // 판정 SSoT 는 읽기 게이트와 동일한 SecretContentGate 다.
        if (! $parentPost->relationLoaded('board')) {
            $parentPost->setRelation('board', $board);
        }

        if (! app(SecretContentGate::class)->canWriteChild($parentPost)) {
            $fail(__('sirsoft-board::validation.post.parent_id.secret'));

            return;
        }

        // 3. 공지 게시글에는 답글 불가
        if ($parentPost->is_notice) {
            $fail(__('sirsoft-board::validation.post.parent_id.notice_not_allowed'));

            return;
        }

        // 4. 답글 깊이 제한 검증
        if ($parentPost->depth + 1 > $board->max_reply_depth) {
            $fail(__('sirsoft-board::validation.post.parent_id.depth_exceeded', ['max' => $board->max_reply_depth]));
        }
    }
}
