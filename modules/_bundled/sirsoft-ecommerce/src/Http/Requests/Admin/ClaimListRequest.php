<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Support\Query\PaginationLimits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\CancelStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonFaultTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;

/**
 * 관리자 클레임 목록 조회 요청 (유형 서브탭 단위)
 *
 * 상태 어휘는 유형마다 다르므로(교환 14 / 반품 11 / 환불 7 / 취소 2) 유형별 Enum 을 단일
 * 출처로 게이트한다 — 유저 목록(`User\ClaimListRequest`)과 같은 근거다. 관리자 화면의 상태
 * 필터는 ChipCheckbox 다중선택(D28)이라 유저와 달리 `statuses[]` 배열이다.
 *
 * 귀책·청구 축이 없는 유형(취소는 둘 다, 환불은 청구)에 그 필터를 실으면 `prohibited` 로
 * 거절한다 — 조용히 무시하면 필터가 안 걸린 목록이 "필터 결과" 로 보인다.
 */
class ClaimListRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인 (권한 체크는 라우트의 permission 미들웨어에서 수행)
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, mixed> 검증 규칙 배열
     */
    public function rules(): array
    {
        $type = ClaimTypeEnum::tryFrom((string) $this->input('type')) ?? ClaimTypeEnum::EXCHANGE;

        return [
            'type' => ['nullable', 'string', Rule::in(ClaimTypeEnum::values())],
            'statuses' => ['nullable', 'array'],
            'statuses.*' => ['string', Rule::in($this->allowedStatuses())],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            // 검색 드롭다운 4종 (D27) — 상세 필터에 같은 입력을 두 번 두지 않는다.
            'search_field' => ['nullable', 'in:claim_number,order_number,requester,product_name'],
            'search_keyword' => ['nullable', 'string', 'max:200'],
            'fault_types' => $type === ClaimTypeEnum::CANCEL ? ['prohibited'] : ['nullable', 'array'],
            'fault_types.*' => ['string', Rule::in(ClaimReasonFaultTypeEnum::values())],
            'charge_methods' => in_array($type, [ClaimTypeEnum::CANCEL, ClaimTypeEnum::REFUND], true)
                ? ['prohibited']
                : ['nullable', 'array'],
            'charge_methods.*' => ['string', Rule::in(ClaimChargeMethodEnum::values())],
            'reason_code' => ['nullable', 'string', 'max:50'],
            'requester_type' => ['nullable', 'in:member,guest'],
            'page' => array_filter([
                'nullable',
                'integer',
                'min:1',
                ($maxPage = PaginationLimits::maxPage('ecommerce.claims')) !== null ? 'max:'.$maxPage : null,
            ]),
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string> 메시지 배열
     */
    public function messages(): array
    {
        return [
            'type.in' => __('sirsoft-ecommerce::validation.claims.type.in'),
            'statuses.array' => __('sirsoft-ecommerce::validation.claims.statuses.array'),
            'statuses.*.in' => __('sirsoft-ecommerce::validation.claims.status.in'),
            'search_field.in' => __('sirsoft-ecommerce::validation.claims.search_field.in'),
            'search_keyword.max' => __('sirsoft-ecommerce::validation.claims.search_keyword.max'),
            'fault_types.array' => __('sirsoft-ecommerce::validation.claims.fault_types.array'),
            'fault_types.prohibited' => __('sirsoft-ecommerce::validation.claims.fault_types.prohibited'),
            'fault_types.*.in' => __('sirsoft-ecommerce::validation.claims.fault_types.in'),
            'charge_methods.array' => __('sirsoft-ecommerce::validation.claims.charge_methods.array'),
            'charge_methods.prohibited' => __('sirsoft-ecommerce::validation.claims.charge_methods.prohibited'),
            'charge_methods.*.in' => __('sirsoft-ecommerce::validation.claims.charge_methods.in'),
            'requester_type.in' => __('sirsoft-ecommerce::validation.claims.requester_type.in'),
            'page.integer' => __('sirsoft-ecommerce::validation.orders.page.integer'),
            'page.min' => __('sirsoft-ecommerce::validation.orders.page.min'),
            'per_page.integer' => __('sirsoft-ecommerce::validation.orders.per_page.integer'),
            'per_page.min' => __('sirsoft-ecommerce::validation.orders.per_page.min'),
            'per_page.max' => __('sirsoft-ecommerce::validation.orders.per_page.max'),
        ];
    }

    /**
     * 조회 유형에 허용되는 상태 어휘를 반환합니다. (유형별 Enum 단일 출처)
     *
     * @return array<int, string> 허용 상태 값
     */
    private function allowedStatuses(): array
    {
        return match (ClaimTypeEnum::tryFrom((string) $this->input('type'))) {
            ClaimTypeEnum::RETURN => ReturnStatusEnum::values(),
            ClaimTypeEnum::REFUND => RefundStatusEnum::values(),
            ClaimTypeEnum::CANCEL => CancelStatusEnum::values(),
            default => ExchangeStatusEnum::values(),
        };
    }

    /**
     * 조회 유형을 반환합니다. (기본 교환 — 서브탭 순서 D16)
     *
     * @return ClaimTypeEnum 유형
     */
    public function claimType(): ClaimTypeEnum
    {
        $type = $this->validated('type');

        return $type ? ClaimTypeEnum::from((string) $type) : ClaimTypeEnum::EXCHANGE;
    }

    /**
     * 저장소 필터를 반환합니다. (`paginateForAdmin()` 계약)
     *
     * @return array<string, mixed> 필터
     */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'statuses' => $validated['statuses'] ?? null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'search_field' => $validated['search_field'] ?? null,
            'search_keyword' => $validated['search_keyword'] ?? null,
            'fault_types' => $validated['fault_types'] ?? null,
            'charge_methods' => $validated['charge_methods'] ?? null,
            'reason_code' => $validated['reason_code'] ?? null,
            'requester_type' => $validated['requester_type'] ?? null,
        ];
    }

    /**
     * 서브탭 뱃지 건수용 유형 공통 필터를 반환합니다. (`countForAdmin()` 계약)
     *
     * 상태·귀책·청구·사유 필터는 유형 종속 어휘라 다른 탭의 건수에 적용할 수 없어 뺀다.
     *
     * @return array<string, mixed> 유형 공통 필터
     */
    public function countFilters(): array
    {
        $validated = $this->validated();

        return [
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'search_field' => $validated['search_field'] ?? null,
            'search_keyword' => $validated['search_keyword'] ?? null,
            'requester_type' => $validated['requester_type'] ?? null,
        ];
    }

    /**
     * 페이지당 건수를 반환합니다.
     *
     * @return int 페이지당 건수
     */
    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 20);
    }
}
