<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 관리자 찜 목록 조회 요청
 *
 * 전 회원의 찜(위시리스트) 목록을 조회할 때의 필터·정렬·페이지네이션 형식을 검증합니다.
 * 권한은 라우트 permission 미들웨어가 담당하므로 여기서는 형식만 본다.
 */
class AdminWishlistListRequest extends FormRequest
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
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', 'string', 'in:created_at_desc,created_at_asc'],
            'search_field' => ['nullable', 'string', 'in:member,member_id,email,product'],
            'search_keyword' => ['nullable', 'string', 'max:100'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'user_uuid' => ['nullable', 'string', 'uuid'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
        ];
    }
}
