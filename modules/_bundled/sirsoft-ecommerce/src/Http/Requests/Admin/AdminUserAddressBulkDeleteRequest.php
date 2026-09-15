<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Models\UserAddress;

/**
 * 관리자 회원 배송지 일괄 삭제 요청
 *
 * 관리자는 이미 전 회원의 행을 조회할 수 있으므로 exists 를 걸어도 ID 탐색 통로가 되지 않는다.
 * 없는 ID 를 조용히 삼키는 대신 422 로 되돌려 목록이 낡았다는 사실을 드러낸다.
 */
class AdminUserAddressBulkDeleteRequest extends FormRequest
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
            'ids.*' => ['integer', 'distinct', Rule::exists(UserAddress::class, 'id')],
        ];
    }
}
