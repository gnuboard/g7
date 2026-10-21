<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Enums\UserStatus;
use App\Models\User;
use Closure;
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
            // 탈퇴 회원에게는 지급하지 않는다 — 로그인할 수 없어 쓰지 못하고, 탈퇴 때 소멸시키는 규칙도 비켜 간다(7.1.1).
            // 차감은 받는다: 7.1.0 에서 탈퇴 회원에게 들어간 지급 잔액을 운영자가 정리하는 수단이다.
            'user_id' => ['required', 'uuid', Rule::exists(User::class, 'uuid'), $this->rejectWithdrawnOnEarn(...)],
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
     * 지급(earn)일 때 대상 회원이 탈퇴 상태면 실패시킵니다.
     *
     * @param  string  $attribute  검증 중인 필드명
     * @param  mixed  $value  회원 uuid
     * @param  Closure  $fail  실패 콜백
     * @return void 반환값 없음
     */
    public function rejectWithdrawnOnEarn(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->input('action') !== 'earn' || ! is_string($value)) {
            return;
        }

        if (app(UserRepositoryInterface::class)->findByUuid($value)?->status === UserStatus::Withdrawn->value) {
            $fail(__('sirsoft-ecommerce::validation.mileage.user_withdrawn'));
        }
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
