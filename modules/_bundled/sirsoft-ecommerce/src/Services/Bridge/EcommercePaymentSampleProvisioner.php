<?php

namespace Modules\Sirsoft\Ecommerce\Services\Bridge;

use App\Contracts\Repositories\UserRepositoryInterface;
use InvalidArgumentException;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\SamplePaymentSpec;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\PaymentSampleProvisioner;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\PaymentGatewayRepositoryInterface;

/**
 * API 문서 실측용 샘플 결제 생성 공개 계약 구현 — 이커머스 팩토리로 주문, 결제 행, 배송지를 멱등 생성한다.
 *
 * 팩토리를 쓰므로 개발 환경 전용이다(결제사 플러그인이 지금까지 직접 쓰던 방식과 같은 제약).
 *
 * @internal 다른 확장은 {@see PaymentSampleProvisioner} 로 주입받는다.
 */
class EcommercePaymentSampleProvisioner implements PaymentSampleProvisioner
{
    /**
     * EcommercePaymentSampleProvisioner 생성자
     *
     * @param  UserRepositoryInterface  $users  회원 저장소
     * @param  PaymentGatewayRepositoryInterface  $gateway  결제사 계약 전용 저장소
     */
    public function __construct(
        private UserRepositoryInterface $users,
        private PaymentGatewayRepositoryInterface $gateway,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @param  SamplePaymentSpec  $spec  샘플 명세
     * @return string|null 주문번호
     */
    public function ensureSamplePayment(SamplePaymentSpec $spec): ?string
    {
        foreach (array_keys($spec->paymentAttributes) as $column) {
            if (! in_array($column, SamplePaymentSpec::ALLOWED_PAYMENT_ATTRIBUTES, true)) {
                throw new InvalidArgumentException(__('sirsoft-ecommerce::exceptions.payment_contract.sample_column_not_allowed', ['column' => $column]));
            }
        }

        $owner = $this->users->findById($spec->ownerUserId);
        if ($owner === null) {
            return null;
        }

        $order = $this->gateway->firstOrCreateSampleOrder($spec->orderNumber, $owner);
        $this->gateway->ensureSamplePayment($order, $spec->pgProvider, $spec->paymentAttributes + ['payment_meta' => $spec->paymentMeta]);

        $address = $spec->shippingAddress;
        // 명세가 비운 칸은 팩토리 기본값을 쓴다(필수 칸이 null 로 들어가지 않게).
        $this->gateway->ensureSampleShippingAddress($order, array_filter([
            'orderer_name' => $address->ordererName,
            'orderer_phone' => $address->ordererPhone,
            'orderer_email' => $address->ordererEmail,
            'recipient_name' => $address->recipientName,
            'recipient_phone' => $address->recipientPhone,
            'zipcode' => $address->zipcode,
            'address' => $address->address,
            'address_detail' => $address->addressDetail,
        ], fn ($value) => $value !== null) + [
            // 팩토리가 무작위로 채우는 칸은 비워 둔다 — 문서 샘플은 실행마다 같아야 한다.
            'orderer_locale' => null,
            'delivery_memo' => null,
            'delivery_memo_label' => null,
            'recipient_country_code' => null,
        ]);

        return (string) $order->order_number;
    }
}
