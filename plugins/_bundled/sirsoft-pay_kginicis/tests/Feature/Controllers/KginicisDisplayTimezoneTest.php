<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Tests\Feature\Controllers;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderAddressFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Plugins\Sirsoft\PayKginicis\Controllers\AdminTransactionController;
use Plugins\Sirsoft\PayKginicis\Services\CbtCvsOperationsService;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;
use ReflectionMethod;

/**
 * KG이니시스 관리자 결제조회·에스크로 화면 값의 시간대 테스트 (#160)
 *
 * - 화면 값(조회 시각·로컬 대체 시각·가상계좌 기한·에스크로 등록/확인 시각)은 보는 사람 시간대
 * - 일본 편의점 결제기한은 일본 시각 그대로 + (JST)
 * - 배송등록일(invoiceDay)은 PG 업무 날짜라 한국 시간
 */
class KginicisDisplayTimezoneTest extends PluginTestCase
{
    /**
     * 고정 시각을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * 해외(CBT) 결제의 로컬 결제조회 — 조회 시각·승인 시각 대체값은 보는 사람 시간대, 편의점 기한은 (JST).
     *
     * @scenario surface=cbt_local_transaction
     *
     * @effects kginicis_admin_times_use_viewer_timezone
     *
     * @return void 반환값 없음
     */
    public function test_local_cbt_transaction_times_use_viewer_timezone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-04 03:00:00', 'UTC'));
        $order = OrderFactory::new()->paid()->create(['order_number' => 'JP-ORDER-TZ-TX-001', 'currency' => 'JPY']);
        OrderPaymentFactory::new()->forOrder($order)->create([
            'payment_status' => PaymentStatusEnum::PAID,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'kginicis',
            'transaction_id' => 'INIJPGCVS_TZ_TX_001',
            'currency' => 'JPY',
            'paid_at' => Carbon::parse('2026-11-03 16:00:00', 'UTC'),
            'payment_meta' => [
                'is_cbt' => true,
                'cbt_type' => 'JPPG',
                'pay_method' => 'CVS',
                'cvs_payment_term' => '20261110235959',
            ],
        ]);
        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.read']);

        $data = $this->actingAs($admin)->withHeader('X-Timezone', 'Asia/Seoul')
            ->getJson('/api/plugins/sirsoft-pay_kginicis/admin/orders/JP-ORDER-TZ-TX-001/transaction-status')
            ->assertOk()
            ->json('data');

        $this->assertSame('2026-11-04 12:00:00', $data['_inquiry_at']);
        $this->assertSame('2026-11-04 01:00:00', $data['_auth_date']);
        $this->assertSame('2026-11-10 23:59:59 (JST)', $data['_vbank_expire_date']);
    }

    /**
     * 일본 편의점 결제기한(YmdHis)은 일본 시각으로 읽어 순간값을 만든다 — 만료 판정의 기준(D11).
     *
     * @scenario surface=cbt_payment_term_parse
     *
     * @effects cbt_payment_term_read_in_japan_time
     *
     * @return void 반환값 없음
     */
    public function test_cbt_payment_term_is_read_as_japan_time(): void
    {
        $parse = new ReflectionMethod(CbtCvsOperationsService::class, 'parseCbtDateTime');
        $service = app(CbtCvsOperationsService::class);

        // 일본 10:00 = UTC 01:00 — UTC 로 읽으면 10:00 UTC 가 되어 9시간 늦게 만료된다.
        $this->assertSame('2026-11-04 01:00:00', $parse->invoke($service, '20261104100000')->utc()->format('Y-m-d H:i:s'));
        // 구분자가 섞여도 같은 순간이다.
        $this->assertSame('2026-11-04 01:00:00', $parse->invoke($service, '2026-11-04 10:00:00')->utc()->format('Y-m-d H:i:s'));
        $this->assertNull($parse->invoke($service, '2026110410'));
    }

    /**
     * 편의점·카드 해외결제의 PG 시각 표시 — 편의점만 (JST) 를 붙인다.
     *
     * @scenario surface=cbt_cvs_pg_times
     *
     * @effects cbt_cvs_pg_times_marked_jst
     *
     * @return void 반환값 없음
     */
    public function test_cbt_cvs_pg_times_are_marked_jst_and_card_times_are_not(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.read']);

        foreach (['CVS' => ' (JST)', 'CARD' => ''] as $payMethod => $suffix) {
            $orderNumber = 'JP-ORDER-TZ-PG-'.$payMethod;
            $order = OrderFactory::new()->paid()->create(['order_number' => $orderNumber, 'currency' => 'JPY']);
            OrderPaymentFactory::new()->forOrder($order)->create([
                'payment_status' => PaymentStatusEnum::PAID,
                'payment_method' => PaymentMethodEnum::CARD,
                'pg_provider' => 'kginicis',
                'transaction_id' => 'INIJPG_TZ_PG_'.$payMethod,
                'currency' => 'JPY',
                'paid_at' => Carbon::parse('2026-11-04 01:00:00', 'UTC'),
                'payment_meta' => [
                    'is_cbt' => true,
                    'cbt_type' => 'JPPG',
                    'pay_method' => $payMethod,
                    'auth_date' => '20261104100000',
                    'applDt' => '20261104',
                    'applTm' => '100000',
                ],
            ]);

            $data = $this->actingAs($admin)->withHeader('X-Timezone', 'Asia/Seoul')
                ->getJson("/api/plugins/sirsoft-pay_kginicis/admin/orders/{$orderNumber}/transaction-status")
                ->assertOk()
                ->json('data');

            $this->assertSame('2026-11-04 10:00:00'.$suffix, $data['_auth_date'], $payMethod.' 승인일시');
            $this->assertSame('2026-11-04 10:00:00 (JST)', $data['_vbank_paid_at'], $payMethod.' 입금 시각');
        }
    }

    /**
     * 국내 가상계좌 기한(로컬 vbank_due_at, UTC)은 고정 KST 가 아니라 보는 사람 시간대로 바꾼다.
     *
     * @scenario surface=domestic_vbank_expire
     *
     * @effects kginicis_admin_times_use_viewer_timezone
     *
     * @return void 반환값 없음
     */
    public function test_domestic_vbank_expire_date_uses_viewer_timezone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-04 03:00:00', 'UTC'));
        $controller = app(AdminTransactionController::class);
        $enrich = new ReflectionMethod($controller, 'enrichResult');
        $enrich->setAccessible(true);

        app()->instance('user_timezone', 'Asia/Seoul');
        $seoul = $enrich->invoke($controller, [], [], false, '2026-11-03 16:00:00');
        $this->assertSame('2026-11-04 01:00:00', $seoul['_vbank_expire_date']);
        $this->assertSame('2026-11-04 12:00:00', $seoul['_inquiry_at']);

        app()->instance('user_timezone', 'UTC');
        $utc = $enrich->invoke($controller, [], [], false, '2026-11-03 16:00:00');
        $this->assertSame('2026-11-03 16:00:00', $utc['_vbank_expire_date']);
    }

    /**
     * 에스크로 배송등록·구매확인 시각은 UTC 로 저장하고, 결제조회 화면 응답에서 보는 사람 시간대로 바꾼다.
     *
     * @scenario surface=escrow_meta
     *
     * @effects kginicis_admin_times_use_viewer_timezone
     *
     * @return void 반환값 없음
     */
    public function test_escrow_form_data_times_use_viewer_timezone(): void
    {
        $payment = $this->createEscrowPayment('ORD-ESCROW-TZ-001', [
            'escrow_delivery' => ['registered_at' => '2026-11-03 16:00:00', 'invoice' => 'INV-TZ'],
            'escrow_confirm' => ['confirmed_at' => '2026-11-03 17:30:00'],
        ]);
        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.read']);

        $data = $this->actingAs($admin)->withHeader('X-Timezone', 'Asia/Seoul')
            ->getJson('/api/plugins/sirsoft-pay_kginicis/admin/orders/ORD-ESCROW-TZ-001/escrow-delivery')
            ->assertOk()
            ->json('data');

        $this->assertSame('2026-11-04 01:00:00', $data['registered_delivery']['registered_at']);
        $this->assertSame('2026-11-04 02:30:00', $data['escrow_confirm']['confirmed_at']);
        // 저장값은 그대로 UTC
        $this->assertSame('2026-11-03 16:00:00', $payment->fresh()->payment_meta['escrow_delivery']['registered_at']);
    }

    /**
     * 배송등록일(invoiceDay) 기본값은 PG 업무 날짜라 한국 시간으로 만든다.
     *
     * @scenario surface=invoice_day
     *
     * @effects invoice_day_uses_korea_time
     *
     * @return void 반환값 없음
     */
    public function test_invoice_day_defaults_to_korea_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-03 16:00:00', 'UTC'));
        Http::fake(['*' => Http::response(['resultCode' => '00', 'resultMsg' => 'OK'])]);

        app(KgInicisApiService::class)->registerEscrowDelivery([
            'tid' => 'TID-TZ', 'oid' => 'ORD-TZ', 'price' => 1000, 'invoice' => 'INV', 'exCode' => 'hanjin', 'exName' => '한진택배',
        ]);

        Http::assertSent(fn ($request) => ($request->data()['invoiceDay'] ?? null) === '2026-11-04 01:00:00');
    }

    /**
     * 에스크로 결제 레코드를 만듭니다.
     *
     * @param  string  $orderNumber  주문번호
     * @param  array  $paymentMeta  결제 메타
     * @return OrderPayment 결제 레코드
     */
    private function createEscrowPayment(string $orderNumber, array $paymentMeta = []): OrderPayment
    {
        $order = OrderFactory::new()->paid()->create(['order_number' => $orderNumber]);
        OrderAddressFactory::new()->forOrder($order)->shipping()->create();

        return OrderPaymentFactory::new()->forOrder($order)->create([
            'payment_status' => PaymentStatusEnum::PAID,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'kginicis',
            'transaction_id' => 'INIMX_CARD_TZ_'.$orderNumber,
            'is_escrow' => true,
            'payment_meta' => $paymentMeta,
        ]);
    }
}
