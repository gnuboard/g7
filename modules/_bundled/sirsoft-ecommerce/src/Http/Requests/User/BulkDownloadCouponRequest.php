<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\User;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Sirsoft\Ecommerce\Services\UserCouponService;

/**
 * 쿠폰 일괄 받기 요청 검증
 *
 * 쿠폰 번호 목록은 선택 값이다 — 주지 않으면 그 회원이 받을 수 있는 쿠폰 전체가 대상이다.
 * 번호마다 존재 여부를 묻지 않는다(쿠폰 수만큼 조회가 늘어난다). 없는 번호는 서비스가
 * 「받을 수 없는 쿠폰」 사유로 결과에 담는다.
 */
class BulkDownloadCouponRequest extends FormRequest
{
    /**
     * 권한 확인 — auth:sanctum 미들웨어에서 처리
     *
     * @return bool 항상 허용
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙 정의
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        $rules = [
            // audit:allow formrequest-sometimes-array-min-inert reason: 전체 객체 PUT 이 아니라 POST — 키를 생략하면 「받을 수 있는 쿠폰 전체」, 빈 배열을 보내면 422 로 가르는 것이 계약이다
            'coupon_ids' => ['sometimes', 'array', 'min:1', 'max:'.UserCouponService::BULK_DOWNLOAD_MAX],
            'coupon_ids.*' => ['integer', 'distinct', 'min:1'],
        ];

        return HookManager::applyFilters('sirsoft-ecommerce.user_coupon.bulk_download_validation_rules', $rules, $this);
    }

    /**
     * 검증 필드 라벨
     *
     * @return array<string, string> 필드 라벨
     */
    public function attributes(): array
    {
        return [
            'coupon_ids' => __('sirsoft-ecommerce::validation.user_coupon_attributes.coupon_ids'),
            'coupon_ids.*' => __('sirsoft-ecommerce::validation.user_coupon_attributes.coupon_ids'),
        ];
    }
}
