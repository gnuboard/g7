<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Http\Requests\Concerns\ResolvesClaimCompletionOptions;
use Modules\Sirsoft\Ecommerce\Models\OrderExchangeOption;
use Modules\Sirsoft\Ecommerce\Models\OrderReturnOption;

/**
 * 관리자 신청 상품 단위 일괄 상태 전이 요청 (D25)
 *
 * 상품 행 ID 는 라우트의 신청서에 소속된 것만 통과한다 — 상위 스코프 없는 존재 확인은
 * 다른 신청서의 상품을 이 경로로 움직이게 한다("배열 항목의 상위 스코프" 규정). 스코프에
 * 걸린 ID 는 서비스의 무음 탈락(`resolveItems()`)에 닿기 전에 422 로 드러난다 — 화면이
 * "0건 처리됨" 을 침묵으로 받는 결함의 차단 지점이다.
 *
 * 결제대기는 상품 단위 어휘에서 뺀다 — 결제는 신청서 단위 축이라 상품 행에는 의미가 없다.
 */
class BulkClaimOptionStatusRequest extends FormRequest
{
    use ResolvesClaimCompletionOptions;

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
        $type = ClaimTypeEnum::fromAdminPathSegment((string) $this->route('claimType'));
        $claimId = (int) $this->route('id');

        $itemExists = $type === ClaimTypeEnum::EXCHANGE
            ? Rule::exists(OrderExchangeOption::class, 'id')->where('order_exchange_id', $claimId)
            : Rule::exists(OrderReturnOption::class, 'id')->where('order_return_id', $claimId);

        return [
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*' => ['integer', $itemExists],
            'status' => ['required', 'string', Rule::in($this->assignableStatuses($type))],
        ] + $this->completionRules();
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string> 메시지 배열
     */
    public function messages(): array
    {
        return [
            'items.required' => __('sirsoft-ecommerce::validation.claims.items.required'),
            'items.array' => __('sirsoft-ecommerce::validation.claims.items.array'),
            'items.min' => __('sirsoft-ecommerce::validation.claims.items.required'),
            'items.max' => __('sirsoft-ecommerce::validation.claims.items.max'),
            'items.*.integer' => __('sirsoft-ecommerce::validation.claims.items.id_integer'),
            'items.*.exists' => __('sirsoft-ecommerce::validation.claims.items.not_in_claim'),
            'status.required' => __('sirsoft-ecommerce::validation.claims.admin_status.required'),
            'status.in' => __('sirsoft-ecommerce::validation.claims.admin_status.in'),
        ] + $this->completionMessages();
    }

    /**
     * 상품 단위 전이에 허용되는 상태 어휘를 반환합니다. (결제대기 제외)
     *
     * @param  ClaimTypeEnum|null  $type  클레임 유형
     * @return array<int, string> 허용 상태 값
     */
    protected function assignableStatuses(?ClaimTypeEnum $type): array
    {
        $values = match ($type) {
            ClaimTypeEnum::EXCHANGE => ExchangeStatusEnum::adminAssignableValues(),
            ClaimTypeEnum::RETURN => ReturnStatusEnum::adminAssignableValues(),
            default => [],
        };

        return array_values(array_diff($values, ['payment_pending']));
    }

    /**
     * 대상 상품 행 ID 목록을 반환합니다.
     *
     * @return array<int, int> 상품 행 ID 목록
     */
    public function itemIds(): array
    {
        return array_map('intval', (array) $this->validated('items'));
    }

    /**
     * 목표 상태 값을 반환합니다.
     *
     * @return string 상태 값
     */
    public function statusValue(): string
    {
        return (string) $this->validated('status');
    }

    /**
     * 전이 부가 정보를 반환합니다.
     *
     * @param  int|null  $processedBy  처리 관리자 ID
     * @return array<string, mixed> 부가 정보
     */
    public function context(?int $processedBy): array
    {
        return ['processed_by' => $processedBy];
    }
}
