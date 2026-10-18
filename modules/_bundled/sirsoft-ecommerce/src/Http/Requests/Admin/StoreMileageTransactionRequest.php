<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 관리자 마일리지 수동 지급/차감 요청
 */
class StoreMileageTransactionRequest extends FormRequest
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
            'action' => ['required', 'string', 'in:earn,deduct'],
            'amount' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'max:10'],
            'memo' => ['nullable', 'string', 'max:1000'],
            'description' => ['nullable', 'string', 'max:500'],
            // 지급 시에만 사용 (직접 만료일 / 무기한 여부)
            'expires_at' => ['nullable', 'date'],
            'use_default_expiry' => ['nullable', 'boolean'],
        ];
    }

    /**
     * 다국어 검증 메시지
     *
     * @return array 메시지
     */
    public function messages(): array
    {
        return [
            'user_id.required' => __('sirsoft-ecommerce::validation.mileage.user_required'),
            'amount.min' => __('sirsoft-ecommerce::validation.mileage.amount_min'),
            'action.in' => __('sirsoft-ecommerce::validation.mileage.action_invalid'),
        ];
    }
}
