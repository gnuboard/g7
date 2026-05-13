<?php

namespace Modules\Sirsoft\Board\Http\Requests;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Board\Enums\PostStatus;
use Modules\Sirsoft\Board\Models\Board;

/**
 * 댓글 수정 요청 폼 검증
 */
class UpdateCommentRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $slug = $this->route('slug');
        $board = Board::where('slug', $slug)->first();

        if (! $board) {
            return [];
        }

        // 비회원 여부 확인 (request()->user()를 사용해야 PermissionMiddleware에서 설정한 사용자를 인식)
        $isGuest = ! $this->user();

        $rules = [
            'content' => [
                'required',
                'string',
                'min:'.($board->min_comment_length ?? 2),
                'max:'.($board->max_comment_length ?? 1000),
            ],
            'is_secret' => ['boolean'],
            'status' => ['nullable', 'string', Rule::in(PostStatus::values())],
            // 비회원인 경우 비밀번호 필수 (수정 권한 검증용)
            'password' => [$isGuest ? 'required' : 'nullable', 'string', 'min:4', 'max:20'],
        ];

        // 훅: 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 필터 제공
        return HookManager::applyFilters('sirsoft-board.comment.update_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'content.required' => __('sirsoft-board::validation.comment.content.required'),
            'content.min' => __('sirsoft-board::validation.comment.content.min'),
            'content.max' => __('sirsoft-board::validation.comment.content.max'),
            'password.required' => __('sirsoft-board::validation.comment.password.required'),
            'password.min' => __('sirsoft-board::validation.comment.password.min'),
        ];
    }

    /**
     * 검증할 필드의 이름을 커스터마이징
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'content' => __('sirsoft-board::attributes.comment.content'),
            'is_secret' => __('sirsoft-board::attributes.comment.is_secret'),
            'status' => __('sirsoft-board::attributes.comment.status'),
            'password' => __('sirsoft-board::attributes.comment.password'),
        ];
    }
}
