<?php

namespace Modules\Sirsoft\Board\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Board\Models\PostScrap;

/**
 * 관리자 스크랩 일괄 삭제 요청 폼 검증
 *
 * 사용자 측(BulkDestroyPostScrapRequest)과 달리 exists 를 건다 — 관리자는 이미 전 회원
 * 스크랩을 조회할 수 있으므로 ID 탐색 우려가 없고, 없는 ID 를 조용히 삼키는 대신
 * 422 로 되돌려 목록이 낡았음을 드러내는 편이 낫다.
 */
class BulkDestroyScrapRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인합니다.
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
        return [
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'distinct', Rule::exists(PostScrap::class, 'id')],
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
            'ids.required' => __('sirsoft-board::validation.scrap.ids.required'),
            'ids.array' => __('sirsoft-board::validation.scrap.ids.array'),
            'ids.min' => __('sirsoft-board::validation.scrap.ids.min'),
            'ids.max' => __('sirsoft-board::validation.scrap.ids.max', ['max' => 100]),
            'ids.*.integer' => __('sirsoft-board::validation.scrap.ids.integer'),
            'ids.*.distinct' => __('sirsoft-board::validation.scrap.ids.distinct'),
            'ids.*.exists' => __('sirsoft-board::validation.scrap.ids.exists'),
        ];
    }
}
