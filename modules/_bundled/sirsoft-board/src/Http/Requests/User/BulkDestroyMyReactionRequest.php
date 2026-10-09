<?php

namespace Modules\Sirsoft\Board\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Sirsoft\Board\Http\Requests\Concerns\ProvidesFallbackRuleMessages;

/**
 * 마이페이지 반응 선택 해제(일괄 삭제) 요청
 *
 * exists 규칙을 두지 않는다 — 소유권 필터는 서비스가 수행하고, 남의 ID·없는 ID 는
 * 조용히 건너뛰어 deleted_count 로만 드러난다. exists 를 걸면 "그 ID 가 존재하는가" 가
 * 422/200 으로 갈려 남의 반응 ID 를 탐색하는 통로가 된다
 * (BulkDestroyPostScrapRequest 와 같은 규약).
 */
class BulkDestroyMyReactionRequest extends FormRequest
{
    use ProvidesFallbackRuleMessages;

    /**
     * 권한 확인 (인증은 라우트 미들웨어가 담당).
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 유효성 검사 규칙
     *
     * @return array<string, array<int, mixed>> 검증 규칙
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'min:1', 'distinct'],
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
