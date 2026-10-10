<?php

declare(strict_types=1);

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

use App\Models\User;
use App\Notifications\GenericNotification;
use Illuminate\Support\Facades\Config;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Listeners\EcommerceNotificationDataListener;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Module;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * EcommerceNotificationDataListener 테스트
 *
 * notification_definitions 의 extract_data 필터를 통해 알림 본문에 주입되는
 * 변수들을 검증한다. 회귀: total_amount 변수가 미결제 상태(total_paid_amount=0)
 * 에서 잘못된 0원 으로 출력되던 `??` 폴백 버그가 재발하지 않도록 보장한다.
 */
class EcommerceNotificationDataListenerTest extends ModuleTestCase
{
    private EcommerceNotificationDataListener $listener;

    /**
     * 컨테이너에서 리스너를 꺼내 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        // 컨테이너로 resolve — OrderCancelRepositoryInterface 등 의존성 주입
        $this->listener = app(EcommerceNotificationDataListener::class);
    }

    /**
     * new_order_admin 알림 + 미결제 주문 → total_amount 가 결제 예정 금액으로 표시되어야 한다.
     *
     * 회귀 방지: 과거 `total_paid_amount ?? total_amount` 패턴은 0 (null 아님) 을
     * 폴백 트리거로 인식하지 못해 0원 을 출력했다. `?:` 로 교체된 뒤 회귀 차단.
     *
     * @effects order_notification_amount_uses_paid_then_total
     *
     * @return void 반환값 없음
     */
    public function test_new_order_admin_shows_total_amount_for_unpaid_order(): void
    {
        $order = new Order(['total_amount' => 1000, 'total_paid_amount' => 0]);

        $result = $this->listener->extractData(
            $this->emptyDefault(),
            'new_order_admin',
            [$order],
        );

        $this->assertSame('1,000원', $result['data']['total_amount']);
    }

    /**
     * new_order_admin 알림 + 결제 완료 주문 → 결제 금액으로 표시.
     *
     * @effects order_notification_amount_uses_paid_then_total
     *
     * @return void 반환값 없음
     */
    public function test_new_order_admin_shows_paid_amount_when_paid(): void
    {
        $order = new Order(['total_amount' => 1000, 'total_paid_amount' => 1000]);

        $result = $this->listener->extractData(
            $this->emptyDefault(),
            'new_order_admin',
            [$order],
        );

        $this->assertSame('1,000원', $result['data']['total_amount']);
    }

    /**
     * order_confirmed 알림 (buildOrderData 경유) — 결제 금액 표시 + 폴백 정합성.
     *
     * @effects order_notification_amount_uses_paid_then_total
     *
     * @return void 반환값 없음
     */
    public function test_order_confirmed_uses_paid_amount_with_total_amount_fallback(): void
    {
        $paidOrder = new Order(['total_amount' => 5000, 'total_paid_amount' => 5000]);
        $result = $this->listener->extractData(
            $this->emptyDefault(),
            'order_confirmed',
            [$paidOrder],
        );
        $this->assertSame('5,000원', $result['data']['total_amount']);

        // total_paid_amount=0 인 비정상 케이스 (예: 외부 결제 모듈 미연동) 에서도 0원 회귀 차단
        $zeroOrder = new Order(['total_amount' => 3000, 'total_paid_amount' => 0]);
        $result = $this->listener->extractData(
            $this->emptyDefault(),
            'order_confirmed',
            [$zeroOrder],
        );
        $this->assertSame('3,000원', $result['data']['total_amount']);
    }

    /**
     * 회원 주문 (order_confirmed) → name 은 회원명, order_url 은 마이페이지 경로.
     *
     * 회귀 방지: 비회원 분기 추가 후에도 회원 알림 데이터 구조가 기존과 동일해야 한다.
     *
     * @effects member_order_notification_uses_member_name_and_mypage_url
     *
     * @return void 반환값 없음
     */
    public function test_member_order_uses_member_name_and_mypage_url(): void
    {
        $order = $this->makeMemberOrder('홍길동', 'ORD-MEMBER-1');

        $result = $this->listener->extractData($this->emptyDefault(), 'order_confirmed', [$order]);

        $this->assertSame('홍길동', $result['data']['name']);
        $this->assertStringContainsString('/mypage/orders/ORD-MEMBER-1', $result['data']['order_url']);
        // 회원 주문 컨텍스트에는 비회원 전용 키가 추가되지 않는다.
        $this->assertArrayNotHasKey('is_guest_order', $result['context']);
        $this->assertArrayNotHasKey('guest_orderer_email', $result['context']);
    }

    /**
     * 비회원 주문 (order_confirmed) → name 은 주문자명(배송지), order_url 은 비회원 조회 화면 경로.
     *
     * @effects guest_order_notification_uses_orderer_name_and_lookup_url
     *
     * @return void 반환값 없음
     */
    public function test_guest_order_uses_orderer_name_and_lookup_url(): void
    {
        $order = $this->makeGuestOrder('비회원주문자', 'guest@example.com', 'ORD-GUEST-1');

        $result = $this->listener->extractData($this->emptyDefault(), 'order_confirmed', [$order]);

        $this->assertSame('비회원주문자', $result['data']['name']);
        // 비회원 조회 라우트(routes.json) 및 OrderController 비회원 redirect_to 와 동일한 경로여야 한다.
        $this->assertStringEndsWith('/shop/guest/orders', $result['data']['order_url']);
        // 비회원 주문번호가 조회 URL 쿼리에 노출되지 않는다.
        $this->assertStringNotContainsString('ORD-GUEST-1', $result['data']['order_url']);
    }

    /**
     * 비회원 주문 안내 메일의 조회 주소는 상점 주소 설정을 따른다 (공개 #85).
     *
     * 상수로 `/shop` 을 박아 두면 주소를 바꾼 상점의 손님이 받은 메일이 존재하지 않는
     * 화면을 가리킨다 — 메일은 정상 발송되므로 아무 오류도 남지 않는다.
     *
     * @effects notification_guest_order_url_follows_route_path
     *
     * @return void 반환값 없음
     */
    public function test_guest_lookup_url_follows_shop_route_path_setting(): void
    {
        Config::set('g7_settings.modules.sirsoft-ecommerce.basic_info', ['route_path' => 'store']);

        $order = $this->makeGuestOrder('비회원주문자', 'guest@example.com', 'ORD-GUEST-2');
        $result = $this->listener->extractData($this->emptyDefault(), 'order_confirmed', [$order]);

        $this->assertStringEndsWith('/store/guest/orders', $result['data']['order_url']);
    }

    /**
     * 비회원 주문 컨텍스트 → 코어 알림 표준 키 guest_recipient 제공 (회원과 동일 발송 경로).
     *
     * @effects ecommerce_guest_order_context_exposes_guest_recipient
     *
     * @return void 반환값 없음
     */
    public function test_guest_order_context_exposes_guest_recipient(): void
    {
        $order = $this->makeGuestOrder('비회원주문자', 'guest@example.com', 'ORD-GUEST-2', 'en');

        $result = $this->listener->extractData($this->emptyDefault(), 'order_confirmed', [$order]);

        $this->assertTrue($result['context']['is_guest_order']);
        // 회원 수신자 컨텍스트는 비회원이므로 null 이다.
        $this->assertNull($result['context']['trigger_user_id']);

        // 코어 표준 게스트 수신자 키 — resolver 의 trigger_user 폴백이 이 키로 GuestNotifiable 생성
        $this->assertArrayHasKey('guest_recipient', $result['context']);
        $this->assertSame('guest@example.com', $result['context']['guest_recipient']['email']);
        $this->assertSame('비회원주문자', $result['context']['guest_recipient']['name']);
        $this->assertSame('en', $result['context']['guest_recipient']['locale']);
    }

    /**
     * 주문 시 저장된 locale 이 없으면 guest_recipient.locale 은 null (수신 측 app locale 폴백).
     *
     * @effects ecommerce_guest_recipient_locale_null_when_not_stored
     *
     * @return void 반환값 없음
     */
    public function test_guest_recipient_locale_is_null_when_not_stored(): void
    {
        $order = $this->makeGuestOrder('비회원주문자', 'guest@example.com', 'ORD-GUEST-2B');

        $result = $this->listener->extractData($this->emptyDefault(), 'order_confirmed', [$order]);

        $this->assertNull($result['context']['guest_recipient']['locale']);
    }

    /**
     * new_order_admin 알림 → 비회원 주문자명도 customer_name 으로 채워진다.
     *
     * @effects guest_order_notification_uses_orderer_name_and_lookup_url
     *
     * @return void 반환값 없음
     */
    public function test_new_order_admin_uses_orderer_name_for_guest(): void
    {
        $order = $this->makeGuestOrder('비회원주문자', 'guest@example.com', 'ORD-GUEST-3');

        $result = $this->listener->extractData($this->emptyDefault(), 'new_order_admin', [$order]);

        $this->assertSame('비회원주문자', $result['data']['customer_name']);
    }

    /**
     * 결제완료·구매확정 관리자 알림도 신규 주문 관리자 알림과 같은 변수를 채운다.
     *
     * 두 알림은 정의만 있고 데이터 추출이 없어 코어 기본값(빈 data·context)으로 떨어졌다 — 메일 본문에
     * `{order_number}` 원문이 그대로 나가고, context 에 주문번호가 없어 관리자 주소로 올리는 단계도 건너뛰었다.
     *
     * @effects staff_order_status_notifications_carry_order_data
     *
     * @return void 반환값 없음
     */
    public function test_staff_order_status_notifications_carry_order_data(): void
    {
        foreach (['order_confirmed_admin', 'order_completed_admin'] as $type) {
            $order = $this->makeGuestOrder('비회원주문자', 'guest@example.com', 'ORD-STAFF-'.$type);
            $order->total_amount = 12000;
            $order->total_paid_amount = 12000;

            $result = $this->listener->extractData($this->emptyDefault(), $type, [$order]);

            $this->assertSame('ORD-STAFF-'.$type, $result['data']['order_number'] ?? null, $type.' 의 order_number 가 비었습니다.');
            $this->assertSame('비회원주문자', $result['data']['customer_name'] ?? null, $type);
            $this->assertSame('12,000원', $result['data']['total_amount'] ?? null, $type);
            $this->assertSame('{recipient_name}', $result['data']['name'] ?? null, $type);
            $this->assertArrayHasKey('order_url', $result['data'], $type);
            $this->assertSame('ORD-STAFF-'.$type, $result['context']['order_number'] ?? null, $type.' 의 context 에 주문번호가 없어 관리자 주소로 올리지 못합니다.');
        }
    }

    /**
     * 비회원 주문 알림 6종은 주문자 연락처를 코어 표준 수신 전화번호 키로 data 에 싣는다.
     *
     * 비회원은 수신자 객체에 전화번호가 없으므로, 문자·알림톡 채널이 이커머스를 모른 채 읽을 수
     * 있도록 도메인이 직접 싣는다(종전에는 문자 플러그인이 이커머스 주문 모델을 읽어 채웠다).
     *
     * @scenario recipient_type=guest, channel=mail
     *
     * @effects ecommerce_guest_order_data_carries_standard_recipient_phone
     *
     * @return void 반환값 없음
     */
    public function test_guest_order_data_carries_standard_recipient_phone(): void
    {
        $order = $this->makeGuestOrder('비회원주문자', 'guest@example.com', 'ORD-GUEST-PHONE');
        $order->shippingAddress->setAttribute('orderer_phone', '010-1234-5678');

        // 6종이 모두 같은 buildOrderData 를 지나므로 DB 조회가 없는 두 유형으로 대표한다
        foreach (['order_confirmed', 'order_completed'] as $type) {
            $result = $this->listener->extractData($this->emptyDefault(), $type, [$order]);

            $this->assertSame(
                '010-1234-5678',
                $result['data'][GenericNotification::RECIPIENT_PHONE_KEY] ?? null,
                "{$type} 알림 data 에 표준 수신 전화번호 키가 있어야 합니다."
            );
        }
    }

    /**
     * 회원 주문과 연락처 없는 비회원 주문은 표준 수신 전화번호 키를 싣지 않는다.
     *
     * 회원은 채널이 회원 휴대폰을 쓰고, 빈 번호를 실으면 채널이 빈 번호로 발송을 시도한다.
     *
     * @scenario recipient_type=member, channel=mail
     *
     * @effects ecommerce_member_order_data_has_no_recipient_phone
     *
     * @return void 반환값 없음
     */
    public function test_member_order_and_phoneless_guest_have_no_recipient_phone(): void
    {
        $member = $this->makeMemberOrder('회원', 'ORD-MEMBER-PHONE');
        $member->shippingAddress->setAttribute('orderer_phone', '010-9999-0000');
        $guest = $this->makeGuestOrder('비회원주문자', 'guest@example.com', 'ORD-GUEST-NOPHONE');

        $memberResult = $this->listener->extractData($this->emptyDefault(), 'order_confirmed', [$member]);
        $guestResult = $this->listener->extractData($this->emptyDefault(), 'order_confirmed', [$guest]);

        $this->assertArrayNotHasKey(GenericNotification::RECIPIENT_PHONE_KEY, $memberResult['data']);
        $this->assertArrayNotHasKey(GenericNotification::RECIPIENT_PHONE_KEY, $guestResult['data']);
    }

    /**
     * 회원 주문 모델(배송지 관계 포함)을 DB 없이 합성합니다.
     *
     * @param  string  $memberName  회원명
     * @param  string  $orderNumber  주문번호
     * @return Order 결과 객체
     */
    private function makeMemberOrder(string $memberName, string $orderNumber): Order
    {
        $order = new Order([
            'order_number' => $orderNumber,
            'total_amount' => 1000,
            'total_paid_amount' => 1000,
        ]);
        $order->setAttribute('user_id', 1);
        $order->setRelation('user', new User(['name' => $memberName]));
        $order->setRelation('shippingAddress', new OrderAddress([
            'orderer_name' => '배송지주문자',
            'orderer_email' => 'member-address@example.com',
        ]));

        return $order;
    }

    /**
     * 비회원 주문 모델(user_id=null, 배송지 관계 포함)을 DB 없이 합성합니다.
     *
     * @param  string  $ordererName  주문자명
     * @param  string  $ordererEmail  주문자 이메일
     * @param  string  $orderNumber  주문번호
     * @param  ?string  $ordererLocale  주문자 선호 언어
     * @return Order 결과 객체
     */
    private function makeGuestOrder(string $ordererName, string $ordererEmail, string $orderNumber, ?string $ordererLocale = null): Order
    {
        $order = new Order([
            'order_number' => $orderNumber,
            'total_amount' => 1000,
            'total_paid_amount' => 1000,
        ]);
        $order->setAttribute('user_id', null);
        $order->setRelation('user', null);
        $order->setRelation('shippingAddress', new OrderAddress([
            'orderer_name' => $ordererName,
            'orderer_email' => $ordererEmail,
            'orderer_locale' => $ordererLocale,
        ]));

        return $order;
    }

    /**
     * extract_data 기본 default 구조.
     *
     * @return array{notifiable: null, notifiables: null, data: array, context: array} extract_data 기본 구조
     */
    private function emptyDefault(): array
    {
        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => [],
            'context' => [],
        ];
    }

    // ──────────────────────────────────────────────
    // 무통장입금 입금 안내 (order_pending_deposit)
    // ──────────────────────────────────────────────

    /**
     * 무통장입금(dbank) + 입금 필요액 > 0 → 계좌/예금주/입금기한 + 차감 후 입금액 포함.
     *
     * @effects pending_deposit_notification_only_for_dbank_with_due_amount
     *
     * @return void 반환값 없음
     */
    public function test_order_pending_deposit_includes_dbank_fields_and_post_mileage_amount(): void
    {
        // 상품 30,000 중 마일리지 10,000 사용 → 입금 필요액 20,000
        $order = $this->makeDbankOrder(orderNumber: 'ORD-DBANK-1', totalDue: 20000);

        $result = $this->listener->extractData($this->emptyDefault(), 'order_pending_deposit', [$order]);

        // 입금액은 마일리지 차감 후 실제 입금 필요액(total_due_amount)
        $this->assertSame('20,000원', $result['data']['deposit_amount']);
        $this->assertSame('국민은행', $result['data']['bank_name']);
        $this->assertSame('123-456-789012', $result['data']['account_number']);
        $this->assertSame('주식회사 테스트', $result['data']['account_holder']);
        $this->assertSame('홍길동', $result['data']['depositor_name']);
        $this->assertNotEmpty($result['data']['deposit_due_at']);
        $this->assertSame('ORD-DBANK-1', $result['data']['order_number']);
    }

    /**
     * 입금 필요액이 0원(전액 마일리지/예치금 충당)이면 입금 안내를 발송하지 않는다 (빈 결과).
     *
     * @effects pending_deposit_notification_only_for_dbank_with_due_amount
     *
     * @return void 반환값 없음
     */
    public function test_order_pending_deposit_suppressed_when_due_amount_zero(): void
    {
        $order = $this->makeDbankOrder(orderNumber: 'ORD-DBANK-ZERO', totalDue: 0);

        $result = $this->listener->extractData($this->emptyDefault(), 'order_pending_deposit', [$order]);

        $this->assertSame([], $result['data'], '입금 필요액 0원 주문은 입금 안내 미발송이어야 합니다.');
    }

    /**
     * 무통장입금이 아닌 결제수단(예: card)은 입금 안내를 발송하지 않는다 (빈 결과).
     *
     * @effects pending_deposit_notification_only_for_dbank_with_due_amount
     *
     * @return void 반환값 없음
     */
    public function test_order_pending_deposit_suppressed_for_non_dbank_method(): void
    {
        $order = $this->makeDbankOrder(orderNumber: 'ORD-CARD-1', totalDue: 20000, paymentMethod: 'card');

        $result = $this->listener->extractData($this->emptyDefault(), 'order_pending_deposit', [$order]);

        $this->assertSame([], $result['data'], '무통장입금이 아니면 입금 안내 미발송이어야 합니다.');
    }

    /**
     * 무통장입금 주문(payment 관계 포함)을 DB 없이 합성합니다.
     *
     * @param  string  $orderNumber  주문번호
     * @param  int  $totalDue  입금 필요액
     * @param  string  $paymentMethod  결제수단 (기본 dbank)
     * @param  array  $currencySnapshot  통화 스냅샷
     * @return Order 결과 객체
     */
    private function makeDbankOrder(string $orderNumber, int $totalDue, string $paymentMethod = 'dbank', array $currencySnapshot = []): Order
    {
        $order = new Order([
            'order_number' => $orderNumber,
            'total_amount' => 30000,
            'total_paid_amount' => 0,
            'total_due_amount' => $totalDue,
            'currency_snapshot' => $currencySnapshot,
        ]);
        $order->setAttribute('user_id', 1);
        $order->setRelation('user', new User(['name' => '홍길동']));

        $payment = new OrderPayment([
            'payment_method' => $paymentMethod,
            'dbank_name' => '국민은행',
            'dbank_account' => '123-456-789012',
            'dbank_holder' => '주식회사 테스트',
            'depositor_name' => '홍길동',
            'deposit_due_at' => now()->addDays(7),
        ]);
        $order->setRelation('payment', $payment);

        return $order;
    }

    /**
     * base=USD 주문에서 무통장 입금 안내액이 결제 통화(KRW)로 환산·포맷되는지 검증.
     *
     * 회귀: 종전엔 base 통화 정수 + '원' 하드코딩이라 "$6 입금"이 "6원"으로 잘못 안내됨.
     * 환산 후 결제 통화(KRW) 포맷으로 "7,058원" 안내해야 한다(PG 청구액과 동일 SSoT).
     *
     * @effects pending_deposit_amount_in_order_currency
     *
     * @return void 반환값 없음
     */
    public function test_order_pending_deposit_converts_base_usd_amount_to_order_currency(): void
    {
        $snapshot = [
            'base_currency' => 'USD',
            'order_currency' => 'KRW',
            'exchange_rates' => [
                'KRW' => ['rate' => 1176470, 'rounding_unit' => '1', 'rounding_method' => 'floor', 'decimal_places' => 0],
                'USD' => ['rate' => 1, 'rounding_unit' => '0.01', 'rounding_method' => 'round', 'decimal_places' => 2],
            ],
        ];
        // base 결제예정액 $6 → KRW 7058 환산
        $order = $this->makeDbankOrder(orderNumber: 'ORD-USD-DBANK', totalDue: 6, currencySnapshot: $snapshot);

        $result = $this->listener->extractData($this->emptyDefault(), 'order_pending_deposit', [$order]);

        $this->assertStringContainsString('7,058', $result['data']['deposit_amount']);
        $this->assertStringNotContainsString('6원', $result['data']['deposit_amount']);
    }

    // ──────────────────────────────────────────────
    // 배송 완료 (order_delivered) — D3 신설
    // ──────────────────────────────────────────────

    /**
     * order_delivered 추출 — 주문 데이터 + 운송장(carrier/tracking) 동봉 가능.
     *
     * @scenario transition_path=update, target_status=delivered, previous_status=different, order_count=single
     *
     * @effects order_delivered_extract_filter_handles_carrier_tracking
     *
     * @return void 반환값 없음
     */
    public function test_order_delivered_extract_returns_order_data_with_carrier_fields(): void
    {
        $order = OrderFactory::new()->create([
            'order_number' => 'ORD-DELIVERED-1',
        ]);

        $result = $this->listener->extractData($this->emptyDefault(), 'order_delivered', [$order]);

        $this->assertSame('ORD-DELIVERED-1', $result['data']['order_number']);
        // 배송 레코드가 없으면 carrier/tracking 은 빈 문자열로 안전 폴백
        $this->assertArrayHasKey('carrier_name', $result['data']);
        $this->assertArrayHasKey('tracking_number', $result['data']);
    }

    /**
     * order_delivered 정의가 getNotificationDefinitions 에 등록되고 after_deliver 훅을 구독한다 (D3).
     *
     * @scenario transition_path=update, target_status=delivered, previous_status=different, order_count=single
     *
     * @effects order_delivered_definition_registered
     *
     * @return void 반환값 없음
     */
    public function test_order_delivered_definition_is_registered(): void
    {
        $module = new Module(
            'sirsoft-ecommerce',
            $this->getModuleBasePath(),
        );
        $definitions = $module->getNotificationDefinitions();
        $delivered = collect($definitions)->firstWhere('type', 'order_delivered');

        $this->assertNotNull($delivered, 'order_delivered 알림 정의가 등록되어야 합니다.');
        $this->assertContains('sirsoft-ecommerce.order.after_deliver', $delivered['hooks']);
        $this->assertEquals('배송 완료', $delivered['name']['ko']);
    }

    /**
     * D8 — order_confirmed 알림 라벨이 "결제 완료" 의미로 명확화 (type/hook 불변).
     *
     * @scenario transition_path=complete_payment, target_status=payment_complete, previous_status=different, order_count=single
     *
     * @effects order_confirmed_label_means_payment_complete
     *
     * @return void 반환값 없음
     */
    public function test_order_confirmed_label_means_payment_complete(): void
    {
        $module = new Module(
            'sirsoft-ecommerce',
            $this->getModuleBasePath(),
        );
        $definitions = $module->getNotificationDefinitions();
        $confirmed = collect($definitions)->firstWhere('type', 'order_confirmed');

        $this->assertNotNull($confirmed);
        // type/hook 은 불변 — 발화/추출/수신자 그대로
        $this->assertContains('sirsoft-ecommerce.order.after_confirm', $confirmed['hooks']);
        // 화면 라벨만 "결제 완료" 의미로
        $this->assertEquals('결제 완료', $confirmed['name']['ko']);
        $this->assertEquals('Payment Completed', $confirmed['name']['en']);
    }
}
