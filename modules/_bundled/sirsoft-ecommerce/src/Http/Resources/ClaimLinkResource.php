<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;

/**
 * 연결 신청서 한 건의 요약 리소스 (보완 5 — 원 신청서 · 후속 환불 · 처리 결과 취소)
 *
 * 번호·상태 대응과 금액 표기는 `ClaimResource` 와 같은 규칙을 쓴다 — 같은 금액이 화면마다 다른 문자열로 보이지 않게 한다.
 * 링크 주소는 소비 지점이 정한다(관리자/회원/비회원) — 이 리소스는 번호를 받아 주소를 만드는 함수만 받는다.
 */
class ClaimLinkResource extends BaseApiResource
{
    use HasMultiCurrencyPrices;

    /** @var callable(string): string 신청서 번호 → 상세 경로 */
    private $urlFor;

    /** @var Order|null 주문 — 통화 표기와 마일리지 차감 여부(Q15) 판정 기준 */
    private ?Order $order;

    /**
     * 리소스를 만듭니다.
     *
     * @param  mixed  $resource  신청서 모델 (교환·반품·환불·취소)
     * @param  callable(string): string  $urlFor  신청서 번호 → 상세 경로
     * @param  Order|null  $order  주문 (통화 표기·마일리지 차감 여부 판정 기준)
     */
    public function __construct($resource, callable $urlFor, ?Order $order = null)
    {
        parent::__construct($resource);
        $this->urlFor = $urlFor;
        $this->order = $order;
        $this->withOrderCurrency($order ? $this->resolveOrderBaseCurrencyCode($order) : null)
            ->withCurrencySnapshot($order?->currency_snapshot ?? []);
    }

    /**
     * 연결 신청서 봉투를 만듭니다. (화면 응답의 `linked_claims`)
     *
     * @param  array{source: mixed, refunds: iterable, result_cancel: mixed}  $linked  `UserClaimService::linkedClaimsFor()` 결과
     * @param  callable(string): string  $urlFor  신청서 번호 → 상세 경로
     * @param  Order|null  $order  주문
     * @return array{source: array|null, refunds: array<int, array>, latest_refund: array|null, result_cancel: array|null} 봉투
     */
    public static function envelope(array $linked, callable $urlFor, ?Order $order): array
    {
        $one = fn ($claim) => $claim === null ? null : (new self($claim, $urlFor, $order))->toArray(request());
        $refunds = [];
        foreach ($linked['refunds'] ?? [] as $refund) {
            $refunds[] = $one($refund);
        }

        return [
            'source' => $one($linked['source'] ?? null),
            'refunds' => $refunds,
            'latest_refund' => $refunds === [] ? null : $refunds[count($refunds) - 1],
            'result_cancel' => $one($linked['result_cancel'] ?? null),
        ];
    }

    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  요청
     * @return array<string, mixed> 연결 신청서 요약
     */
    public function toArray(Request $request): array
    {
        $claim = $this->resource;
        $type = $claim->claimType();
        [$numberField, $statusField] = match ($type) {
            ClaimTypeEnum::EXCHANGE => ['exchange_number', 'exchange_status'],
            ClaimTypeEnum::RETURN => ['return_number', 'return_status'],
            ClaimTypeEnum::REFUND => ['refund_number', 'refund_status'],
            ClaimTypeEnum::CANCEL => ['cancel_number', 'cancel_status'],
        };
        $status = $claim->{$statusField};
        $number = (string) $claim->{$numberField};

        $data = [
            'claim_type' => $type->value,
            'claim_type_label' => $type->label(),
            'claim_number' => $number,
            'status' => $status instanceof \BackedEnum ? $status->value : $status,
            'status_label' => $status instanceof \BackedEnum ? $status->label() : null,
            'status_variant' => $status instanceof \BackedEnum && method_exists($status, 'variant') ? $status->variant() : null,
            'status_icon' => $status instanceof \BackedEnum && method_exists($status, 'icon') ? $status->icon() : null,
            // 반려·철회된 환불신청서는 「살아 있지 않다」 — 화면이 흐리게 보인다.
            'is_live' => ! ($claim instanceof OrderRefund && in_array($status?->value, RefundStatusEnum::linkReleasingValues(), true)),
            'requested_at_formatted' => $this->formatDateTimeStringForUser($claim->created_at),
            'detail_url' => ($this->urlFor)($number),
        ];

        if ($claim instanceof OrderRefund) {
            // 차감된 적 없는 사용 마일리지는 돌려주지 않는다(Q15) — 신청서 상세와 같은 합계를 낸다.
            $pointsNotDeducted = ClaimResource::orderPointsNotDeducted($this->order);
            $total = (float) $claim->refund_amount + ($pointsNotDeducted ? 0.0 : (float) $claim->refund_points_amount);
            $data += [
                'refund_method' => $claim->refund_method?->value,
                'refund_method_label' => $claim->refund_method?->label(),
                'refund_total' => $this->roundToOrderCurrency($total),
                'refund_total_formatted' => $this->formatOrderCurrency($total),
                'mc_refund_total' => $this->formatStoredMultiCurrency(
                    $this->sumMultiCurrency($claim->mc_refund_amount, $pointsNotDeducted ? null : $claim->mc_refund_points_amount)
                ),
                'refunded_at_formatted' => $this->formatDateTimeStringForUser($claim->refunded_at),
            ];
        }

        return $data;
    }
}
