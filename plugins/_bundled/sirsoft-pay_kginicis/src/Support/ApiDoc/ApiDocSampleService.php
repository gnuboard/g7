<?php

namespace Plugins\Sirsoft\PayKginicis\Support\ApiDoc;

use App\Contracts\ApiDoc\ApiDocSampleSeeder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;

/**
 * sirsoft-pay_kginicis API 문서 실측용 완전 샘플 시더
 *
 * KG 이니시스 결제 플러그인의 관리자/사용자 GET 라우트(주문번호 path 파라미터)는
 * route-model binding 없이 `orders/{orderNumber}` 문자열 파라미터로 ecommerce 주문을
 * 조회한다. 실측 시 `{orderNumber}` 를 실제 kginicis 결제 주문번호로 치환하지 못하면
 * 상세 GET 이 실측 제외(unresolved-path-param)로 남으므로, 이 시더는 완전한 kginicis
 * 결제 주문(에스크로 + CBT 편의점 메타 + 배송지)을 멱등 생성하고 `orders`·`user`·`vbank`
 * 도메인의 `path_params` 맵으로 주문번호를 실제 값으로 정확 일치 치환한다.
 *
 * 실측 대상 GET(로컬 DB/서비스 조회):
 *   - admin/orders/{orderNumber}/cbt-cvs            (CbtCvsOperationsService::summary)
 *   - admin/orders/{orderNumber}/cbt-reconciliation (CbtReconciliationService::get)
 *   - admin/orders/{orderNumber}/escrow-delivery    (배송지 prefill + escrow 이력)
 *   - user/orders/{orderNumber}/receipt             (소유자 매칭 — 실측 사용자 소유 주문)
 *
 * 실측 제외 정당(외부 의존): admin/orders/{orderNumber}/transaction-status 는 KG 이니시스
 * INIAPI 거래조회를 실호출하므로 시더로 실측 불가(외부 PG 도달성 의존).
 *
 * 소유 사용자: 코어 완전 샘플 사용자(`apidoc-sample-user@example.com`, admin role)를
 * 우선한다. docgen 은 이 사용자로 실측 토큰을 발급하므로 receipt 의 소유자 매칭
 * (`o.user_id == Auth::id()`)이 통과되어 회원 영수증 경로가 실측된다.
 *
 * `api:docgen --scope=plugin:sirsoft-pay_kginicis --seed` 실행 시 커맨드가 규약 위치
 * (`Plugins\Sirsoft\PayKginicis\Support\ApiDoc\ApiDocSampleService`)로 자동 발견한다.
 */
class ApiDocSampleService implements ApiDocSampleSeeder
{
    /**
     * @var string 샘플 주문 식별용 주문번호 마커
     */
    private const SAMPLE_ORDER_NUMBER = 'APIDOC-KGINICIS-000001';

    /**
     * @var string 샘플 거래 고유 ID(KG 이니시스 CBT TID 포맷: INIJPG prefix)
     */
    private const SAMPLE_TID = 'INIJPGCARDapidocsmpl0000000001';

    /**
     * kginicis 결제 도메인 완전 샘플을 멱등 생성하고 도메인별 대표 route key + path_params 맵을 반환합니다.
     *
     * @return array<string, array{model: class-string, key: string, value: string, path_params?: array<string, string>}> 도메인 => 대표 레코드 정보
     */
    public function seed(): array
    {
        // ecommerce 모델이 없으면(모듈 비활성) 실측 폴백 없이 빈 맵 반환.
        if (! class_exists(Order::class)) {
            return [];
        }

        $actor = $this->sampleActor();
        if ($actor === null) {
            return [];
        }

        $order = $this->seedOrder($actor);
        $this->seedPayment($order);
        $this->seedShippingAddress($order);

        // orders/{orderNumber} 계열 GET(cbt-cvs·cbt-reconciliation·escrow-delivery·
        // transaction-status)과 user/orders/{orderNumber}/receipt 를 실측하기 위한
        // path 파라미터 맵. route-model binding 이 없는 문자열 param 을 실제 주문번호로
        // 정확 일치 치환한다.
        $orderParams = [
            'orderNumber' => (string) $order->order_number,
        ];

        $entry = [
            'model' => Order::class,
            'key' => 'order_number',
            'value' => (string) $order->order_number,
            'path_params' => $orderParams,
        ];

        // 라우트 도메인 그룹(orders.md · vbank.md · payment.md · cbt.md · transaction.md)이
        // 공유하는 {orderNumber} 를 모두 실측 가능하도록 각 그룹에 동일 맵을 노출한다.
        return [
            'orders' => $entry,
            'vbank' => $entry,
            'transaction' => $entry,
            'cbt' => $entry,
            'payment' => $entry,
        ];
    }

    /**
     * 실측 사용자 소유의 완전 샘플 kginicis 주문을 멱등 생성합니다.
     *
     * @param  User  $actor  실측 사용자
     * @return Order 대표 주문 레코드
     */
    private function seedOrder(User $actor): Order
    {
        $order = Order::query()->where('order_number', self::SAMPLE_ORDER_NUMBER)->first();
        if ($order) {
            return $order;
        }

        return Order::factory()->forUser($actor)->create([
            'order_number' => self::SAMPLE_ORDER_NUMBER,
        ]);
    }

    /**
     * 완전 샘플 kginicis 결제(에스크로 + CBT 편의점 메타)를 멱등 생성합니다.
     *
     * 채우는 특화 필드:
     *   - pg_provider = 'kginicis' (모든 조회 컨트롤러의 WHERE 조건)
     *   - is_escrow = true (escrow-delivery formData 의 findEscrowPayment 조건)
     *   - payment_meta.is_cbt = true + pay_method = 'CVS' (cbt-cvs summary 의 is_cbt_cvs 판정)
     *   - transaction_id (모든 조회 컨트롤러가 요구하는 non-null TID)
     *
     * @param  Order  $order  대표 주문
     * @return OrderPayment 대표 결제 레코드
     */
    private function seedPayment(Order $order): OrderPayment
    {
        $payment = OrderPayment::query()
            ->where('order_id', $order->id)
            ->where('pg_provider', 'kginicis')
            ->first();

        if ($payment) {
            return $payment;
        }

        return OrderPayment::factory()->forOrder($order)->create([
            'pg_provider' => 'kginicis',
            'transaction_id' => self::SAMPLE_TID,
            'merchant_order_id' => 'MO-'.self::SAMPLE_ORDER_NUMBER,
            'payment_status' => PaymentStatusEnum::PAID,
            'payment_method' => PaymentMethodEnum::VBANK,
            'is_escrow' => true,
            'buyer_name' => 'API 문서 샘플 구매자',
            'buyer_email' => 'apidoc-sample-user@example.com',
            'buyer_phone' => '010-0000-0001',
            'payment_name' => 'API 문서 샘플 CBT 결제',
            'payment_meta' => [
                'mid' => 'apidocmid1',
                'is_test_mode' => true,
                'is_cbt' => true,
                'cbt_type' => 'cvs',
                'pay_method' => 'CVS',
                'cbt_mid' => 'apidocmid1',
                'cbt_sid' => 'apidocsid1',
                'cvs_convenience' => 'seven_eleven',
                'cvs_conf_no' => '1234567890',
                'cvs_receipt_no' => '0987654321',
                'cvs_amount' => 5000,
                'cvs_status' => 'waiting_deposit',
                'cvs_payment_term' => '20260710235959',
                'cvs_last_notify_at' => '',
                'cvs_notify_history' => [],
                'pg_raw_response' => [
                    'resultCode' => '0000',
                    'resultMsg' => '정상처리',
                    'tid' => self::SAMPLE_TID,
                    'payMethod' => 'CVS',
                    'currency' => 'JPY',
                ],
            ],
        ]);
    }

    /**
     * escrow-delivery formData 의 배송지 prefill 을 실측하기 위한 배송지를 멱등 생성합니다.
     *
     * @param  Order  $order  대표 주문
     */
    private function seedShippingAddress(Order $order): void
    {
        $exists = DB::table('ecommerce_order_addresses')
            ->where('order_id', $order->id)
            ->where('address_type', 'shipping')
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('ecommerce_order_addresses')->insert([
            'order_id' => $order->id,
            'address_type' => 'shipping',
            'orderer_name' => 'API 문서 샘플 주문자',
            'orderer_phone' => '010-0000-0001',
            'orderer_email' => 'apidoc-sample-user@example.com',
            'recipient_name' => 'API 문서 샘플 수령인',
            'recipient_phone' => '010-0000-0002',
            'zipcode' => '06134',
            'address' => '서울특별시 강남구 테헤란로 001',
            'address_detail' => 'API 문서 샘플 빌딩 1층',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * 샘플 주문 소유자로 쓸 사용자를 반환합니다.
     *
     * 코어 완전 샘플 사용자(admin role, docgen 실측 주체)를 우선하고,
     * 없으면 첫 사용자로 폴백합니다.
     *
     * @return User|null 샘플 사용자 (없으면 null)
     */
    private function sampleActor(): ?User
    {
        return User::query()->where('email', 'apidoc-sample-user@example.com')->first()
            ?? User::query()->orderBy('id')->first();
    }
}
