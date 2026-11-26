<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Contracts;

use InvalidArgumentException;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\SamplePaymentSpec;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\ShippingAddress;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\PaymentSampleProvisioner;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * API 문서 실측용 샘플 결제 생성 공개 계약 
 *
 * 문서 생성 도구가 실제 주문번호로 조회하므로 DB 행이 생겨야 하고, 여러 번 불러도 한 벌만 있어야 한다.
 */
class PaymentSampleProvisionerTest extends ModuleTestCase
{
    /**
     * 스키마를 다시 만들지 않고 트랜잭션으로 격리합니다.
     *
     * @return void 반환값 없음
     */
    public function refreshDatabase(): void
    {
        $this->beginDatabaseTransaction();
    }

    /**
     * 샘플 명세를 만듭니다.
     *
     * @param  int  $ownerUserId  소유 회원 id
     * @param  array<string, mixed>  $attributes  결제 행 값
     * @return SamplePaymentSpec 명세
     */
    private function spec(int $ownerUserId, array $attributes = []): SamplePaymentSpec
    {
        return new SamplePaymentSpec(
            orderNumber: 'APIDOC-PG916-000001',
            ownerUserId: $ownerUserId,
            pgProvider: 'kginicis',
            paymentAttributes: $attributes + ['transaction_id' => 'SAMPLE-TID', 'payment_status' => PaymentStatusEnum::PAID, 'is_escrow' => true],
            paymentMeta: ['is_cbt' => true, 'pay_method' => 'CVS'],
            shippingAddress: new ShippingAddress(ordererName: '샘플 주문자', ordererEmail: 'sample@example.com', recipientName: '샘플 수령인', zipcode: '06134', address: '서울'),
        );
    }

    /**
     * 주문, 결제 행, 배송지를 만들고 다시 불러도 늘어나지 않는다.
     *
     * @scenario case=sample_payment_is_idempotent
     *
     * @effects sample_payment_rows_exist_once
     *
     * @return void 반환값 없음
     */
    public function test_creates_sample_rows_once(): void
    {
        $user = $this->createUser();
        $provisioner = app(PaymentSampleProvisioner::class);

        $this->assertSame('APIDOC-PG916-000001', $provisioner->ensureSamplePayment($this->spec($user->id)));
        $this->assertSame('APIDOC-PG916-000001', $provisioner->ensureSamplePayment($this->spec($user->id)));

        $order = Order::query()->where('order_number', 'APIDOC-PG916-000001')->firstOrFail();
        $this->assertSame($user->id, (int) $order->user_id);
        $payments = OrderPayment::query()->where('order_id', $order->id)->where('pg_provider', 'kginicis')->get();
        $this->assertCount(1, $payments);
        $this->assertSame('SAMPLE-TID', $payments->first()->transaction_id);
        $this->assertSame(['is_cbt' => true, 'pay_method' => 'CVS'], $payments->first()->payment_meta);
        $this->assertSame(1, OrderAddress::query()->where('order_id', $order->id)->where('address_type', 'shipping')->count());
    }

    /**
     * 허용 목록 밖 결제 행 컬럼은 거부하고, 소유 회원이 없으면 null 이다.
     *
     * @scenario case=sample_payment_guards
     *
     * @effects sample_payment_rejects_unknown_columns_and_missing_owner
     *
     * @return void 반환값 없음
     */
    public function test_rejects_unknown_columns_and_missing_owner(): void
    {
        $provisioner = app(PaymentSampleProvisioner::class);
        $this->assertNull($provisioner->ensureSamplePayment($this->spec(999999999)));

        $this->expectException(InvalidArgumentException::class);
        $provisioner->ensureSamplePayment($this->spec($this->createUser()->id, ['paid_amount_local' => 1]));
    }
}
