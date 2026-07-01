<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 마이페이지 마일리지 내역 조회 요청
 */
class UserMileageHistoryRequest extends FormRequest
{
    /**
     * 인증된 사용자만 접근 — 권한은 라우트 미들웨어에서 처리.
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
            'category' => ['nullable', 'string', 'in:earn,use,expire,adjust'],
            'currency' => ['nullable', 'string', 'max:10'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
