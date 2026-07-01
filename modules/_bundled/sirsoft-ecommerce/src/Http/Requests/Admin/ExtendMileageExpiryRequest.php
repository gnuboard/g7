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
            'user_id' => ['required', 'uuid', Rule::exists(User::class, 'uuid')],
            'lot_ids' => ['required', 'array', 'min:1'],
            'lot_ids.*' => ['integer'],
            'days' => ['required', 'integer', 'min:1', 'max:3650'],
        ];
    }
}
