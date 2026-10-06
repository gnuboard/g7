<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

use Illuminate\Database\Eloquent\Model;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;

/**
 * 환불 발생 출처 유형 Enum
 *
 * 환불신청서(`ecommerce_order_refunds.refund_source_type`)가 어느 경로에서
 * 생겼는지를 기록한다. DIRECT·ADMIN 은 참조 대상 레코드가 없다.
 *
 * ## DIRECT 와 ADMIN 을 가르는 이유 (#599 후속 §4-3-1)
 *
 * 철회(`withdraw()`)와 수정(`updateRequest()`)은 "고객이 낸 신청서" 에만 열려 있어야 한다.
 * 새 모델에서 관리자 [주문취소]도 환불신청서를 만드는데, 그 행까지 DIRECT 로 두면
 * **고객이 운영자의 취소 조치를 철회해 버린다.** `requested_by` 로는 가릴 수 없다 —
 * 비회원 신청도 `requested_by` 가 null 이라 "운영자 생성" 과 구분되지 않는다.
 *
 * 수량 잠금·실행 분기·사유 사용중 판정은 두 값이 **같이** 움직인다(둘 다 주문상품 수량을
 * 선점하고, 실행 시점에 그 수량을 회수한다). 철회·수정만 DIRECT 로 좁힌다.
 *
 * 이 축을 Eloquent 다형 관계(morphTo)로 두지 않는 이유: DIRECT·ADMIN 에 대응하는 모델이
 * 없어 morphTo 가 그 값을 클래스명으로 해석하다 fatal 이 난다. 대신 sourceModelClass()
 * 로 명시 해석해 null 을 돌려준다.
 */
enum RefundSourceTypeEnum: string
{
    case CANCEL = 'cancel';        // 주문 취소 발
    case RETURN = 'return';        // 반품 발
    case EXCHANGE = 'exchange';    // 교환(저가 차액) 발
    case DIRECT = 'direct';        // 고객이 직접 접수한 취소·환불 신청 (참조 레코드 없음)
    case ADMIN = 'admin';          // 운영자가 대리 접수한 환불신청서 (참조 레코드 없음)

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string 다국어 라벨
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.refund_source_type.'.$this->value);
    }

    /**
     * 프론트엔드용 라벨 키를 반환합니다.
     *
     * @return string 다국어 라벨
     */
    public function getLabel(): string
    {
        return $this->label();
    }

    /**
     * 출처 레코드의 모델 클래스를 반환합니다.
     *
     * @return class-string<Model>|null 모델 클래스 (참조 대상이 없으면 null)
     */
    public function sourceModelClass(): ?string
    {
        return match ($this) {
            self::CANCEL => OrderCancel::class,
            self::RETURN => OrderReturn::class,
            self::EXCHANGE => OrderExchange::class,
            self::DIRECT, self::ADMIN => null,
        };
    }

    /**
     * 대응하는 클레임 유형을 반환합니다.
     *
     * @return ClaimTypeEnum|null 클레임 유형 (직접 환불이면 null)
     */
    public function claimType(): ?ClaimTypeEnum
    {
        return match ($this) {
            self::CANCEL => ClaimTypeEnum::CANCEL,
            self::RETURN => ClaimTypeEnum::RETURN,
            self::EXCHANGE => ClaimTypeEnum::EXCHANGE,
            self::DIRECT, self::ADMIN => null,
        };
    }

    /**
     * 주문상품 수량을 선점·회수하는 출처인지 여부를 반환합니다.
     *
     * 고객 신청과 운영자 대리 접수는 접수 시점에 주문상품을 판매 상태로 두고 수량만
     * 선점한다 — 그 수량을 실제로 빼는 것은 환불 실행이다. 반품·교환·구형 취소 발
     * 신청서는 자기 서비스가 이미 상품을 옮겼으므로 이 축에 들지 않는다.
     *
     * @return bool 수량을 소유하는 출처면 true
     */
    public function ownsOptionQuantities(): bool
    {
        return $this === self::DIRECT || $this === self::ADMIN;
    }

    /**
     * 구매자가 철회·수정할 수 있는 출처인지 여부를 반환합니다.
     *
     * 운영자가 건 취소를 고객이 되돌리지 못하게 하는 축이다 — `withdraw()`·`updateRequest()`
     * 와 구매자 화면의 [수정]/[철회] 노출(`ClaimResource::is_withdrawable`)이 같은 판정을
     * 써야 한다. 화면만 열려 있으면 버튼이 뜨고 누르면 언제나 422 다(F3).
     *
     * @return bool 구매자 조작 가능하면 true
     */
    public function isBuyerEditable(): bool
    {
        return $this === self::DIRECT;
    }

    /**
     * 주문상품 수량을 선점하는 출처의 값 목록을 반환합니다. (Repository 질의용)
     *
     * @return array<int, string> 출처 값 목록
     */
    public static function quantityOwningValues(): array
    {
        return array_values(array_map(
            fn (self $case) => $case->value,
            array_filter(self::cases(), fn (self $case) => $case->ownsOptionQuantities()),
        ));
    }

    /**
     * 상품을 취소로 뺀 환불의 출처인지 여부를 반환합니다. (재활성 회수 판정 — Q14)
     *
     * 취소한 주문·상품을 되살릴 때 「취소로 돌려준 돈」만 다시 받는다. 반품·교환으로 돌려준 몫은
     * 상품이 반품·교환 상태로 남아 되살아나지 않으므로 대상이 아니다. `ownsOptionQuantities()`
     * 는 DIRECT·ADMIN 만이라 구형 CANCEL 출처를 빠뜨린다 — 그래서 별도 축으로 둔다.
     *
     * @return bool 취소 출처면 true
     */
    public function isCancellationSource(): bool
    {
        return ! in_array($this, [self::RETURN, self::EXCHANGE], true);
    }

    /**
     * 취소 출처 값 목록을 반환합니다. (Repository 질의용)
     *
     * @return array<int, string> 출처 값 목록
     */
    public static function cancellationSourceValues(): array
    {
        return array_values(array_map(
            fn (self $case) => $case->value,
            array_filter(self::cases(), fn (self $case) => $case->isCancellationSource()),
        ));
    }

    /**
     * 모든 값 배열을 반환합니다.
     *
     * @return array<string> 출처 유형 값 배열
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 프론트엔드용 옵션 배열을 반환합니다.
     *
     * @return array<array{value: string, label: string}> 셀렉트 옵션 배열
     */
    public static function toSelectOptions(): array
    {
        return array_map(fn ($case) => [
            'value' => $case->value,
            'label' => $case->label(),
        ], self::cases());
    }
}
