<?php

namespace Modules\Sirsoft\Board\Http\Requests;

use App\Extension\HookManager;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Board\Enums\PostStatus;
use Modules\Sirsoft\Board\Http\Requests\Concerns\ValidatesAttachmentCount;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Rules\BlockedKeywordsRule;

/**
 * 게시글 수정 요청 폼 검증
 */
class UpdatePostRequest extends FormRequest
{
    use ValidatesAttachmentCount;

    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * @return bool 항상 true (권한은 미들웨어에서 검증)
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

        // 금지 키워드 목록 가져오기
        $blockedKeywords = $board->blocked_keywords ?? [];

        $rules = [
            'title' => [
                'sometimes',
                'required',
                'string',
                'min:'.($board->min_title_length ?? 2),
                'max:'.($board->max_title_length ?? 200),
                new BlockedKeywordsRule($blockedKeywords),
            ],
            'content' => [
                'sometimes',
                'required',
                'string',
                'min:'.($board->min_content_length ?? 10),
                'max:'.($board->max_content_length ?? 10000),
                new BlockedKeywordsRule($blockedKeywords),
            ],
            'content_mode' => ['nullable', 'string', 'in:text,html'],
            'category' => ['nullable', 'string', 'max:50'],
            'is_notice' => ['nullable', 'boolean'],
            'is_secret' => ['nullable', 'boolean'],
            'status' => ['nullable', 'string', Rule::in(PostStatus::values())],
            'attachment_ids' => ['nullable', 'array'],
            'attachment_ids.*' => ['integer', 'min:1'],
            // temp_key는 게시판 존재 여부와 무관하게 항상 허용
            'temp_key' => ['nullable', 'string', 'max:64'],
        ];

        // 훅: 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 필터 제공
        return HookManager::applyFilters('sirsoft-board.post.update_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => __('sirsoft-board::validation.post.title.required'),
            'title.min' => __('sirsoft-board::validation.post.title.min'),
            'title.max' => __('sirsoft-board::validation.post.title.max'),
            'content.required' => __('sirsoft-board::validation.post.content.required'),
            'content.min' => __('sirsoft-board::validation.post.content.min'),
            'content.max' => __('sirsoft-board::validation.post.content.max'),
            'category.max' => __('sirsoft-board::validation.post.category.max'),
            'status.in' => __('sirsoft-board::validation.post.status.in'),
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
            'title' => __('sirsoft-board::validation.attributes.post.title'),
            'content' => __('sirsoft-board::validation.attributes.post.content'),
            'content_mode' => __('sirsoft-board::validation.attributes.post.content_mode'),
            'category' => __('sirsoft-board::validation.attributes.post.category'),
            'is_notice' => __('sirsoft-board::validation.attributes.post.is_notice'),
            'is_secret' => __('sirsoft-board::validation.attributes.post.is_secret'),
            'status' => __('sirsoft-board::validation.attributes.post.status'),
        ];
    }

    /**
     * 필드 단위 규칙으로 판정할 수 없는 첨부 총합을 검증합니다.
     *
     * 수정은 이미 연결된 첨부와 합산해 판정합니다.
     *
     * @param  Validator  $validator  검증기
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $board = Board::where('slug', $this->route('slug'))->first();
            $postId = $this->route('id');
            $this->validateAttachmentTotal($validator, $board, is_numeric($postId) ? (int) $postId : null);
        });
    }
}
