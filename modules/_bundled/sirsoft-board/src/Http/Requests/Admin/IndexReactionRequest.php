<?php

namespace Modules\Sirsoft\Board\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Board\Http\Requests\Concerns\ProvidesFallbackRuleMessages;
use Modules\Sirsoft\Board\Models\ReactionType;

/**
 * 관리자 반응 목록 조회 요청 폼 검증
 *
 * 검색 대상·게시판·회원·반응 유형·대상 구분·반응일 범위·정렬 파라미터의 형식을 검증합니다.
 * 권한은 라우트 permission 미들웨어와 컨트롤러의 checkModulePermission 이 담당합니다.
 */
class IndexReactionRequest extends FormRequest
{
    use ProvidesFallbackRuleMessages;

    /**
     * 허용하는 검색 대상 값
     *
     * @var array<int, string>
     */
    public const SEARCH_FIELDS = ['all', 'target_title', 'target_content', 'board_name', 'member', 'author_name'];

    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인합니다.
     *
     * @return bool 항상 true (권한은 미들웨어·컨트롤러에서 검증)
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
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            // 관리자 목록 공통 규약 — 검색은 filters[0][field|value|operator] 로 전달된다.
            'filters' => ['nullable', 'array'],
            'filters.0.field' => ['nullable', 'string', Rule::in(self::SEARCH_FIELDS)],
            'filters.0.value' => ['nullable', 'string', 'max:255'],
            'filters.0.operator' => ['nullable', 'string'],
            'board_id' => ['nullable', 'integer', 'min:1'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'user_uuid' => ['nullable', 'string', 'uuid'],
            'reaction_type_id' => ['nullable', 'integer', Rule::exists(ReactionType::class, 'id')],
            'target_type' => ['nullable', 'string', 'in:post,comment'],
            'reacted_at_from' => ['nullable', 'date_format:Y-m-d'],
            'reacted_at_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:reacted_at_from'],
            'sort_order' => ['nullable', 'string', 'in:asc,desc'],
        ];
    }

    /**
     * 검증 실패 메시지
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'filters.0.field.in' => __('sirsoft-board::validation.reaction.search_field.in'),
            'target_type.in' => __('sirsoft-board::validation.reaction.target_type.in'),
            'reaction_type_id.exists' => __('sirsoft-board::validation.reaction.reaction_type_id.exists'),
        ];
    }
}
