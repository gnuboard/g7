<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 관리자 마일리지 일괄 유효기간 연장 요청
 */
class ExtendMileageExpiryRequest extends FormRequest
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
            // 선택 — 생략하면 고른 적립건이 여러 회원에 걸쳐 있어도 전부 연장한다(마일리지 내역 일괄 연장).
            // 지정하면 그 회원의 적립건만 연장한다(종전 호출 계약).
            'user_id' => ['nullable', 'uuid', Rule::exists(User::class, 'uuid')],
            'lot_ids' => ['required', 'array', 'min:1'],
            'lot_ids.*' => ['integer'],
            'days' => ['required', 'integer', 'min:1', 'max:3650'],
        ];
    }
}
