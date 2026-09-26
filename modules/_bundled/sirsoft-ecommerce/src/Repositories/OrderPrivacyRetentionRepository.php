<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderCancelOption;
use Modules\Sirsoft\Ecommerce\Models\OrderCashReceipt;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderRefundOption;
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
     * {@inheritDoc}
     */
    public function anonymizeOrder(int $orderId, array $orderMeta, string $maskedValue): void
    {
        OrderAddress::query()->where('order_id', $orderId)->toBase()->update(
            array_fill_keys(self::ADDRESS_MASKED_COLUMNS, $maskedValue) + array_fill_keys(self::ADDRESS_NULL_COLUMNS, null)
        );

        OrderPayment::query()->where('order_id', $orderId)->toBase()
            ->update(array_fill_keys(self::PAYMENT_NULL_COLUMNS, null));

        OrderRefund::query()->where('order_id', $orderId)->toBase()
            ->update(['refund_bank_holder' => null, 'refund_bank_account' => null]);

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
}
