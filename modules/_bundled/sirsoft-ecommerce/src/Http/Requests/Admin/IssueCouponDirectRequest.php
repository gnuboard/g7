<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Enums\UserStatus;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 쿠폰 직접발급 요청 (관리자가 회원을 지정해 즉시 발급)
 */
class IssueCouponDirectRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * @return bool 권한 미들웨어가 처리하므로 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array 검증 규칙 배열
     */
    public function rules(): array
    {
        return [
            'user_uuids' => ['required', 'array', 'min:1'],
            // 탈퇴 회원에게는 발급하지 않는다 — 로그인할 수 없어 쓰지 못한다(7.1.1). 하나라도 섞이면 요청 전체가 422 다.
            'user_uuids.*' => ['string', Rule::exists(User::class, 'uuid'), $this->rejectWithdrawn(...)],
        ];
    }

    /**
     * 대상 회원이 탈퇴 상태면 실패시킵니다.
     *
     * @param  string  $attribute  검증 중인 필드명
     * @param  mixed  $value  회원 uuid
     * @param  Closure  $fail  실패 콜백
     * @return void 반환값 없음
     */
    public function rejectWithdrawn(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && app(UserRepositoryInterface::class)->findByUuid($value)?->status === UserStatus::Withdrawn->value) {
            $fail(__('sirsoft-ecommerce::validation.coupon.user_withdrawn'));
        }
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array 필드별 오류 메시지 배열
     */
    public function messages(): array
    {
        return [
            'user_uuids.required' => __('sirsoft-ecommerce::validation.coupon.user_ids_required'),
            'user_uuids.min' => __('sirsoft-ecommerce::validation.coupon.user_ids_min'),
            'user_uuids.*.exists' => __('sirsoft-ecommerce::validation.coupon.user_ids_invalid'),
        ];
    }

    /**
     * 검증된 uuid 목록을 내부 정수 회원 ID 로 해석해 반환합니다.
     *
     * 관리자 UI 는 uuid 만 노출하므로 발급 서비스가 사용하는 정수 ID 로 변환합니다.
     *
     * @return int[] 발급 대상 회원 ID 배열
     */
    public function resolvedUserIds(): array
    {
        return app(UserRepositoryInterface::class)->getIdsByUuids($this->validated()['user_uuids']);
    }
}
