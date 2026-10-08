<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimImage;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderCancelOption;
use Modules\Sirsoft\Ecommerce\Models\OrderCashReceipt;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderExchangeOption;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderRefundOption;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Models\OrderReturnOption;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
use Modules\Sirsoft\Ecommerce\Models\OrderTaxInvoice;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderPrivacyRetentionRepositoryInterface;

/**
 * 종결 주문 개인정보 보유기간 처리 Repository (이슈 #636)
 *
 * 모든 갱신은 `toBase()` 로 모델 이벤트·수정 시각 갱신 없이 한다 — 파기는 주문 변경이 아니고,
 * 주문의 `updated_at` 은 레거시 주문의 종결 시각 대용으로도 쓰인다.
 */
class OrderPrivacyRetentionRepository implements OrderPrivacyRetentionRepositoryInterface
{
    /**
     * NOT NULL 이라 치환값을 넣는 주소 칸
     *
     * @var array<int, string>
     */
    private const ADDRESS_MASKED_COLUMNS = [
        'orderer_name', 'orderer_phone', 'recipient_name', 'recipient_phone', 'zipcode', 'address',
    ];

    /**
     * NULL 로 지우는 주소 칸
     *
     * @var array<int, string>
     */
    private const ADDRESS_NULL_COLUMNS = [
        'orderer_email', 'recipient_email', 'recipient_city', 'address_detail', 'address_line_1',
        'address_line_2', 'intl_city', 'intl_state', 'intl_postal_code', 'delivery_memo', 'delivery_memo_label',
    ];

    /**
     * NULL 로 지우는 결제 칸 — 구매자 연락처, 입금·환불 계좌 명의, 현금영수증 식별번호, 카드번호
     * 앞뒤 자리(마스킹본도 카드를 가리키는 파생 식별자다), 접속 환경.
     *
     * 카드 승인번호·카드사명·할부개월은 남긴다 — 결제 대행사와 대사·취소 조회에 쓰는 거래 기록
     * (전자상거래법 §6 대금결제 기록)이고 그것만으로는 사람을 가리키지 않는다.
     *
     * @var array<int, string>
     */
    private const PAYMENT_NULL_COLUMNS = [
        'buyer_name', 'buyer_email', 'buyer_phone', 'depositor_name', 'vbank_number', 'vbank_holder',
        'dbank_account', 'dbank_holder', 'refund_bank_account', 'refund_bank_holder',
        'cash_receipt_identifier', 'cash_receipt_identifier_encrypted', 'card_number_masked', 'user_agent',
    ];

    /**
     * 교환·반품 신청서에서 NULL 로 지우는 칸 (#599) — 환불 계좌 명의·번호(주문 환불과 같은 기준)와 자유 기재 칸.
     *
     * 상세 사유·관리자 메모는 주문의 배송 메모·관리자 메모처럼 상담 중 받아 적은 연락처·주소가 섞이는 칸이다.
     * 은행 코드·사유 코드·금액·상태·시각은 청약철회 거래 기록이라 남긴다.
     *
     * @var array<int, string>
     */
    private const CLAIM_NULL_COLUMNS = ['refund_bank_holder', 'refund_bank_account', 'reason_detail', 'admin_memo'];

    /**
     * 교환 재배송지 스냅샷에서 치환값을 넣는 키 — 주문 배송지의 NOT NULL 칸(`ADDRESS_MASKED_COLUMNS`)에 대응
     *
     * @var array<int, string>
     */
    private const REDELIVERY_MASKED_KEYS = ['recipient_name', 'phone', 'zipcode', 'address1'];

    /**
     * 교환 재배송지 스냅샷에서 NULL 로 지우는 키 — 주문 배송지의 상세 주소(`address_detail`)와
     * 해외 주소의 도시·주(`intl_city`·`intl_state`)에 대응
     *
     * @var array<int, string>
     */
    private const REDELIVERY_NULL_KEYS = ['address2', 'city', 'state'];

    /**
     * {@inheritDoc}
     */
    public function anonymizeOrder(int $orderId, array $orderMeta, string $maskedValue): void
    {
        OrderAddress::query()->where('order_id', $orderId)->toBase()->update(
            array_fill_keys(self::ADDRESS_MASKED_COLUMNS, $maskedValue) + array_fill_keys(self::ADDRESS_NULL_COLUMNS, null)
        );

        OrderPayment::query()->where('order_id', $orderId)->toBase()
            ->update(array_fill_keys(self::PAYMENT_NULL_COLUMNS, null));

        // 환불신청서의 상세 사유도 교환·반품과 같은 자유 기재 칸이다(#599).
        OrderRefund::query()->where('order_id', $orderId)->toBase()
            ->update(['refund_bank_holder' => null, 'refund_bank_account' => null, 'reason_detail' => null]);

        // 교환·반품 신청서 (#599) — 두 신청서는 주문 상태를 바꾸지 않고 주문 곁에 남는다.
        foreach ([OrderExchange::class, OrderReturn::class] as $claim) {
            $claim::query()->where('order_id', $orderId)->toBase()
                ->update(array_fill_keys(self::CLAIM_NULL_COLUMNS, null));
        }

        $this->maskRedeliveryAddresses($orderId, $maskedValue);

        // 세금계산서의 사업자 정보(상호·등록번호·주소)는 세법상 거래 기록이라 두고, 대표자명과 담당자 개인 연락처를 지운다
        // (담당자 이메일은 NOT NULL 이라 치환값).
        OrderTaxInvoice::query()->where('order_id', $orderId)->toBase()->update([
            'ceo_name' => null,
            'manager_name' => null,
            'manager_phone' => null,
            'manager_email' => $maskedValue,
        ]);

        OrderCashReceipt::query()->where('order_id', $orderId)->toBase()->update(['identifier_masked' => null]);

        Order::withTrashed()->whereKey($orderId)->toBase()->update([
            'ip_address' => null,
            'guest_lookup_password_hash' => null,
            // 관리자 메모는 상담 중 받아 적은 연락처·주소가 섞이는 칸이라 함께 지운다.
            'admin_memo' => null,
            'order_meta' => json_encode($orderMeta, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * 교환 재배송지 스냅샷을 주문 배송지와 같은 기준으로 가립니다 (#599).
     *
     * 받는 분·연락처·우편번호·주소는 치환값, 상세 주소는 NULL 로 두고 국가·변경 여부 표지는 남긴다 —
     * 주문 배송지(`ADDRESS_MASKED_COLUMNS`·`ADDRESS_NULL_COLUMNS`)와 같은 판정이다. 스냅샷 모양을 유지해
     * 교환 상세가 빈 배송지로 깨지지 않고, 가린 주문 배송지와 값이 같아 「배송지 다름」 표시도 사라진다.
     * 해석할 수 없는 스냅샷은 통째로 비운다(개인정보가 남는 쪽으로 틀리지 않게).
     *
     * @param  int  $orderId  주문 ID
     * @param  string  $maskedValue  치환값
     * @return void 반환값 없음
     */
    private function maskRedeliveryAddresses(int $orderId, string $maskedValue): void
    {
        // ID 순회 — 스냅숏을 비우는 행이 조회 조건(NOT NULL)에서 빠져도 건너뛰는 행이 없다.
        OrderExchange::query()->where('order_id', $orderId)->whereNotNull('redelivery_address_snapshot')
            ->toBase()->select(['id', 'redelivery_address_snapshot'])
            ->chunkById(100, function ($rows) use ($maskedValue) {
                foreach ($rows as $row) {
                    $snapshot = json_decode((string) $row->redelivery_address_snapshot, true);

                    if (is_array($snapshot)) {
                        foreach (self::REDELIVERY_MASKED_KEYS as $key) {
                            if (array_key_exists($key, $snapshot)) {
                                $snapshot[$key] = $maskedValue;
                            }
                        }
                        foreach (self::REDELIVERY_NULL_KEYS as $key) {
                            if (array_key_exists($key, $snapshot)) {
                                $snapshot[$key] = null;
                            }
                        }
                    }

                    OrderExchange::query()->whereKey($row->id)->toBase()->update([
                        'redelivery_address_snapshot' => is_array($snapshot) ? json_encode($snapshot, JSON_UNESCAPED_UNICODE) : null,
                    ]);
                }
            });
    }

    /**
     * {@inheritDoc}
     */
    public function optionIds(int $orderId): array
    {
        return OrderOption::query()->where('order_id', $orderId)->pluck('id')->all();
    }

    /**
     * {@inheritDoc}
     */
    public function purgeOrder(int $orderId): void
    {
        // 행은 남기고 주문 연결만 끊는다 — 쿠폰 발급·마일리지 거래는 회원 잔액·발급 이력의 원장이다.
        CouponIssue::query()->where('order_id', $orderId)->toBase()->update(['order_id' => null]);
        MileageTransaction::query()->where('order_id', $orderId)->toBase()->update(['order_id' => null]);

        $this->purgeClaims($orderId);

        // 참조하는 쪽 → 참조되는 쪽 순서. DB CASCADE 에 기대지 않는다.
        OrderShipping::query()->where('order_id', $orderId)->delete();
        OrderRefundOption::query()->where('order_id', $orderId)->delete();
        OrderRefund::query()->where('order_id', $orderId)->delete();
        OrderCancelOption::query()->where('order_id', $orderId)->delete();
        OrderCancel::query()->where('order_id', $orderId)->delete();
        OrderTaxInvoice::query()->where('order_id', $orderId)->delete();
        // 현금영수증 이력은 결제를 참조하므로 결제보다 먼저 지운다.
        OrderCashReceipt::query()->where('order_id', $orderId)->delete();
        OrderPayment::query()->where('order_id', $orderId)->delete();
        // 옵션끼리의 자기 참조(부모·원본 옵션)를 먼저 끊어 삭제 순서에 기대지 않는다.
        OrderOption::query()->where('order_id', $orderId)->toBase()
            ->update(['parent_option_id' => null, 'source_option_id' => null]);
        OrderOption::query()->where('order_id', $orderId)->delete();
        OrderAddress::query()->where('order_id', $orderId)->delete();

        Order::withTrashed()->whereKey($orderId)->forceDelete();
    }

    /**
     * 주문의 교환·반품·클레임 결제와 클레임 첨부 행을 명시 삭제합니다 (#599).
     *
     * 이 행들은 주문 FK 의 CASCADE 로도 사라지지만 거기에 기대지 않는다 — 첨부(`ecommerce_claim_images`)는
     * 주문 FK 가 없어(다형 연결) CASCADE 로도 남는다. 첨부 파일은 저장소가 아니라 서비스가 커밋 뒤에 지운다.
     * 마일리지 거래의 클레임 결제·환불 연결은 주문 연결처럼 행을 남기고 끊는다. 반품 상품 줄은 환불을
     * 가리키므로(`refund_id`) 환불보다 먼저 지운다.
     *
     * @param  int  $orderId  주문 ID
     * @return void 반환값 없음
     */
    private function purgeClaims(int $orderId): void
    {
        // 이 주문의 신청서·결제 ID 는 목록으로 끌어오지 않고 서브쿼리로 넘긴다.
        $refundIds = OrderRefund::query()->select('id')->where('order_id', $orderId);
        $claimPaymentIds = ClaimPayment::query()->select('id')->where('order_id', $orderId);

        ClaimImage::query()->where(function (Builder $query) use ($orderId, $refundIds) {
            foreach ([
                ClaimTypeEnum::EXCHANGE->value => OrderExchange::query()->select('id')->where('order_id', $orderId),
                ClaimTypeEnum::RETURN->value => OrderReturn::query()->select('id')->where('order_id', $orderId),
                ClaimTypeEnum::REFUND->value => $refundIds,
            ] as $type => $ids) {
                $query->orWhere(fn (Builder $q) => $q->where('claimable_type', $type)->whereIn('claimable_id', $ids));
            }

            // 신청으로 이어지지 않은 임시 첨부 — 주문 ID 로 묶여 있다.
            $query->orWhere(fn (Builder $q) => $q->where('claimable_type', ClaimImage::PENDING_CLAIMABLE_TYPE)->where('claimable_id', $orderId));
        })->delete();

        MileageTransaction::query()->whereIn('claim_payment_id', $claimPaymentIds)->toBase()->update(['claim_payment_id' => null]);
        MileageTransaction::query()->whereIn('order_refund_id', $refundIds)->toBase()->update(['order_refund_id' => null]);

        ClaimPayment::query()->where('order_id', $orderId)->delete();
        OrderExchangeOption::query()->where('order_id', $orderId)->delete();
        OrderExchange::query()->where('order_id', $orderId)->delete();
        OrderReturnOption::query()->where('order_id', $orderId)->delete();
        OrderReturn::query()->where('order_id', $orderId)->delete();
    }
}
