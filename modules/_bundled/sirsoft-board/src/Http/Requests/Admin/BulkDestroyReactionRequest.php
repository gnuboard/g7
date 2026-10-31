<?php

namespace Modules\Sirsoft\Board\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Board\Http\Requests\Concerns\ProvidesFallbackRuleMessages;
use Modules\Sirsoft\Board\Models\Reaction;

/**
 * 관리자 반응 일괄 삭제 요청 폼 검증
 *
 * 마이페이지 선택 해제와 달리 여기서는 exists 를 건다 — 관리자는 전 회원의 반응을
 * 다루므로 "그 ID 가 존재하는가" 가 탐색 통로가 되지 않고, 잘못된 ID 를 조용히 넘기면
 * 운영자가 "지웠다고 생각한 건"과 실제 삭제 건수가 어긋난다.
 */
class BulkDestroyReactionRequest extends FormRequest
{
    use ProvidesFallbackRuleMessages;

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
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'min:1', 'distinct', Rule::exists(Reaction::class, 'id')],
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
            'ids.required' => __('sirsoft-board::validation.reaction.ids.required'),
            'ids.array' => __('sirsoft-board::validation.reaction.ids.array'),
            'ids.min' => __('sirsoft-board::validation.reaction.ids.min'),
            'ids.max' => __('sirsoft-board::validation.reaction.ids.max', ['max' => 100]),
            'ids.*.integer' => __('sirsoft-board::validation.reaction.ids.integer'),
            'ids.*.distinct' => __('sirsoft-board::validation.reaction.ids.distinct'),
            'ids.*.exists' => __('sirsoft-board::validation.reaction.ids.exists'),
        ];
    }

    /**
     * 검증 속성명
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'ids' => __('sirsoft-board::validation.attributes.reaction.ids'),
        ];
    }
}
