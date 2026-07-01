<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 관리자 마일리지 내역 목록 조회 요청
 */
class MileageTransactionListRequest extends FormRequest
{
    /**
     * 권한 체크는 라우트의 permission 미들웨어에서 수행됩니다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙
     *
     * @return array 검증 규칙
     */
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', 'string', 'in:created_at_desc,created_at_asc,amount_desc,amount_asc'],
            'search_field' => ['nullable', 'string', 'in:member,member_id,email,order'],
            'search_keyword' => ['nullable', 'string', 'max:100'],
            // 거래유형은 UI 4분류 슬러그(전체='', earn/use/expire/adjust)를 받아 Repository에서 8종 enum으로 역매핑
            'type' => ['nullable', 'string', 'in:earn,use,expire,adjust'],
            'currency' => ['nullable', 'string', 'max:10'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
        ];
    }
}
