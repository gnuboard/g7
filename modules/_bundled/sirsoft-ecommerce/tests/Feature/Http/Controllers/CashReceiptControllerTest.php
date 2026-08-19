<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers;

use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptIdentifierType;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptType;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderCashReceipt;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Services\CashReceiptService;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\RegistersTestCashReceiptProvider;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 현금영수증 발급 API 테스트 (A-6 / A-8-3 / D12)
 *
 * 관리자: 발급 / 발급취소 / 재발급 복구
 * 유저:   발급 / 상태조회 (발급취소는 제공하지 않는다)
 */
class CashReceiptControllerTest extends ModuleTestCase
{
    use RegistersTestCashReceiptProvider;

    private const PROVIDER = 'tosspayments';

    private const IDENTIFIER = '01012345678';

    private const BUSINESS_NUMBER = '1078648269';

    protected User $adminUser;

    private bool $issueShouldFail = false;

    private int $receiptSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->issueShouldFail = false;
        $this->receiptSequence = 0;
        $this->adminUser = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);

        $this->registerCashReceiptProvider(self::PROVIDER);
        app(EcommerceSettingsService::class)->setSetting('order_settings.cash_receipt_provider', self::PROVIDER);
    }

    private function registerProvider(): void
    {
        HookManager::addFilter(
            'sirsoft-ecommerce.cash_receipt.issue',
            function (array $result, Order $order, string $provider, array $payload) {
                if ($provider !== self::PROVIDER) {
                    return $result;
                }

                if ($this->issueShouldFail) {
                    return [
                        'success' => false,
                        'error_code' => 'PROVIDER_ERROR',
                        'error_message' => '발급 실패',
                        'receipt_key' => null,
                        'receipt_url' => null,
                        'issue_number' => null,
                        'raw_response' => null,
                    ];
                }

                $key = 'receipt-'.(++$this->receiptSequence);

                return [
                    'success' => true,
                    'error_code' => null,
                    'error_message' => null,
                    'receipt_key' => $key,
                    'receipt_url' => "https://example.test/receipt/{$key}",
                    'issue_number' => '12345678',
                    'raw_response' => null,
                ];
            },
        );

        HookManager::addFilter(
            'sirsoft-ecommerce.cash_receipt.cancel',
            fn (array $result, Order $order, string $provider, string $receiptKey) => $provider === self::PROVIDER
                ? [
                    'success' => true, 'error_code' => null, 'error_message' => null,
                    'receipt_key' => $receiptKey, 'raw_response' => null,
                ]
                : $result,
        );
    }

    /**
     * 무통장 결제완료 주문을 생성합니다.
     *
     * @param  array<string, mixed>  $paymentOverrides  결제 레코드 오버라이드
     */
    private function makeOrder(?User $owner = null, int $cash = 11000, array $paymentOverrides = []): Order
    {
        $order = Order::factory()->create([
            'user_id' => $owner?->id,
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'total_amount' => $cash,
            'total_cash_equivalent_amount' => $cash,
            'total_tax_amount' => $cash,
            'total_tax_free_amount' => 0,
        ]);

        OrderPayment::factory()->forOrder($order)->create(array_merge([
            'payment_method' => PaymentMethodEnum::DBANK,
            'payment_status' => PaymentStatusEnum::PAID,
        ], $paymentOverrides));

        return $order->fresh();
    }

    private function adminUrl(Order $order, string $suffix = ''): string
    {
        return "/api/modules/sirsoft-ecommerce/admin/orders/{$order->id}/cash-receipt{$suffix}";
    }

    private function userUrl(Order $order): string
    {
        return "/api/modules/sirsoft-ecommerce/user/orders/{$order->id}/cash-receipt";
    }

    private function issuePayload(): array
    {
        return [
            'receipt_type' => CashReceiptType::INCOME->value,
            'identifier_type' => CashReceiptIdentifierType::PHONE->value,
            'identifier' => self::IDENTIFIER,
        ];
    }

    // ─────────────────────────────────────────────
    // 관리자 발급
    // ─────────────────────────────────────────────

    #[Test]
    public function 관리자는_사후_발급할_수_있고_식별번호는_마스킹되어_저장된다(): void
    {
        $this->registerProvider();
        $order = $this->makeOrder();

        $response = $this->actingAs($this->adminUser)
            ->postJson($this->adminUrl($order), $this->issuePayload());

        $response->assertOk();
        $response->assertJsonPath('data.identifier_masked', '*******5678');
        $response->assertJsonPath('data.receipt_url', 'https://example.test/receipt/receipt-1');

        // 응답 어디에도 평문이 없어야 한다
        $this->assertStringNotContainsString(self::IDENTIFIER, $response->getContent());
    }

    #[Test]
    public function 관리자는_지출증빙_사업자번호로_발급할_수_있다(): void
    {
        $this->registerProvider();
        $order = $this->makeOrder();

        $this->actingAs($this->adminUser)
            ->postJson($this->adminUrl($order), [
                'receipt_type' => CashReceiptType::EXPENSE->value,
                'identifier_type' => CashReceiptIdentifierType::BUSINESS->value,
                'identifier' => self::BUSINESS_NUMBER,
            ])
            ->assertOk()
            ->assertJsonPath('data.receipt_type', CashReceiptType::EXPENSE->value);
    }

    #[Test]
    public function 사업자번호_체크섬이_틀리면_422_를_반환한다(): void
    {
        $this->registerProvider();
        $order = $this->makeOrder();

        $this->actingAs($this->adminUser)
            ->postJson($this->adminUrl($order), [
                'receipt_type' => CashReceiptType::EXPENSE->value,
                'identifier_type' => CashReceiptIdentifierType::BUSINESS->value,
                'identifier' => '1078648260',
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function 소득공제_사업자번호_조합은_거부된다(): void
    {
        $this->registerProvider();
        $order = $this->makeOrder();

        $this->actingAs($this->adminUser)
            ->postJson($this->adminUrl($order), [
                'receipt_type' => CashReceiptType::INCOME->value,
                'identifier_type' => CashReceiptIdentifierType::BUSINESS->value,
                'identifier' => self::BUSINESS_NUMBER,
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function 무통장이_아닌_주문은_발급이_차단된다(): void
    {
        $this->registerProvider();
        $order = $this->makeOrder(null, 11000, ['payment_method' => PaymentMethodEnum::CARD]);

        $this->actingAs($this->adminUser)
            ->postJson($this->adminUrl($order), $this->issuePayload())
            ->assertStatus(422)
            ->assertJsonPath('errors.error_code', 'NOT_CASH_PAYMENT');
    }

    #[Test]
    public function 미결제_주문은_발급이_차단된다(): void
    {
        $this->registerProvider();
        $order = $this->makeOrder(null, 11000, ['payment_status' => PaymentStatusEnum::WAITING_DEPOSIT]);

        $this->actingAs($this->adminUser)
            ->postJson($this->adminUrl($order), $this->issuePayload())
            ->assertStatus(422)
            ->assertJsonPath('errors.error_code', 'PAYMENT_NOT_PAID');
    }

    #[Test]
    public function 이미_발급된_주문에_다시_발급하면_409_를_반환한다(): void
    {
        $this->registerProvider();
        $order = $this->makeOrder();

        $this->actingAs($this->adminUser)->postJson($this->adminUrl($order), $this->issuePayload())->assertOk();

        $this->actingAs($this->adminUser)
            ->postJson($this->adminUrl($order), $this->issuePayload())
            ->assertStatus(409)
            ->assertJsonPath('errors.error_code', 'ALREADY_ISSUED');
    }

    #[Test]
    public function 프로바이더_발급_실패시_422_와_에러코드를_반환한다(): void
    {
        $this->registerProvider();
        $this->issueShouldFail = true;
        $order = $this->makeOrder();

        $this->actingAs($this->adminUser)
            ->postJson($this->adminUrl($order), $this->issuePayload())
            ->assertStatus(422)
            ->assertJsonPath('errors.error_code', 'PROVIDER_ERROR');
    }

    #[Test]
    public function 권한이_없는_관리자는_발급할_수_없다(): void
    {
        $this->registerProvider();
        $order = $this->makeOrder();
        $weakAdmin = $this->createAdminUser();

        $this->actingAs($weakAdmin)
            ->postJson($this->adminUrl($order), $this->issuePayload())
            ->assertStatus(403);
    }

    // ─────────────────────────────────────────────
    // 관리자 발급취소 / 재발급
    // ─────────────────────────────────────────────

    #[Test]
    public function 관리자는_발급취소할_수_있다(): void
    {
        $this->registerProvider();
        $order = $this->makeOrder();
        $this->actingAs($this->adminUser)->postJson($this->adminUrl($order), $this->issuePayload())->assertOk();

        $this->actingAs($this->adminUser)->deleteJson($this->adminUrl($order))->assertOk();

        $this->assertNull(app(CashReceiptService::class)->findActiveReceipt($order->fresh()));
    }

    #[Test]
    public function 활성_영수증이_없으면_발급취소는_422_다(): void
    {
        $this->registerProvider();
        $order = $this->makeOrder();

        $this->actingAs($this->adminUser)
            ->deleteJson($this->adminUrl($order))
            ->assertStatus(422)
            ->assertJsonPath('errors.error_code', 'NO_ACTIVE_RECEIPT');
    }

    #[Test]
    public function 재발급_ap_i_는_재발급_실패_상태를_복구한다(): void
    {
        $this->registerProvider();
        $order = $this->makeOrder();
        $this->actingAs($this->adminUser)->postJson($this->adminUrl($order), $this->issuePayload())->assertOk();

        // 금액 변동 + 재발급 실패 → "취소 성공 + 재발급 실패" 중간 상태를 만든다
        $this->issueShouldFail = true;
        $order->fresh()->update(['total_cash_equivalent_amount' => 5500, 'total_tax_amount' => 5500]);
        app(CashReceiptService::class)->syncFromOrder($order->fresh(), '부분환불');
        $this->assertNull(app(CashReceiptService::class)->findActiveReceipt($order->fresh()));

        // 프로바이더 복구 후 관리자가 수동 재발급
        $this->issueShouldFail = false;

        $this->actingAs($this->adminUser)
            ->postJson($this->adminUrl($order, '/reissue'))
            ->assertOk()
            ->assertJsonPath('data.amount', 5500);

        $this->assertNotNull(app(CashReceiptService::class)->findActiveReceipt($order->fresh()));
    }

    // ─────────────────────────────────────────────
    // 유저
    // ─────────────────────────────────────────────

    #[Test]
    public function 유저는_본인_주문에_사후_발급할_수_있다(): void
    {
        $this->registerProvider();
        $owner = $this->createUser();
        $order = $this->makeOrder($owner);

        $this->actingAs($owner)
            ->postJson($this->userUrl($order), $this->issuePayload())
            ->assertOk()
            ->assertJsonPath('data.identifier_masked', '*******5678');
    }

    #[Test]
    public function 유저는_타인_주문에_발급할_수_없다(): void
    {
        $this->registerProvider();
        $order = $this->makeOrder($this->createUser());
        $stranger = $this->createUser();

        $this->actingAs($stranger)
            ->postJson($this->userUrl($order), $this->issuePayload())
            ->assertStatus(404);
    }

    #[Test]
    public function 유저는_발급취소할_수_없다(): void
    {
        // 유저용 DELETE 라우트 자체가 존재하지 않는다 (관리자 전용).
        $this->registerProvider();
        $owner = $this->createUser();
        $order = $this->makeOrder($owner);
        $this->actingAs($owner)->postJson($this->userUrl($order), $this->issuePayload())->assertOk();

        $response = $this->actingAs($owner)->deleteJson($this->userUrl($order));

        $this->assertContains($response->getStatusCode(), [404, 405], '유저 발급취소 경로가 없어야 한다');
        $this->assertNotNull(app(CashReceiptService::class)->findActiveReceipt($order->fresh()));
    }

    #[Test]
    public function 유저는_발급_상태를_조회할_수_있다(): void
    {
        $this->registerProvider();
        $owner = $this->createUser();
        $order = $this->makeOrder($owner);

        // 발급 전: issuable = true, cash_receipt = null
        $this->actingAs($owner)
            ->getJson($this->userUrl($order))
            ->assertOk()
            ->assertJsonPath('data.issuable', true)
            ->assertJsonPath('data.cash_receipt', null);

        $this->actingAs($owner)->postJson($this->userUrl($order), $this->issuePayload())->assertOk();

        // 발급 후: issuable = false, 영수증 노출
        $this->actingAs($owner)
            ->getJson($this->userUrl($order))
            ->assertOk()
            ->assertJsonPath('data.issuable', false)
            ->assertJsonPath('data.cash_receipt.identifier_masked', '*******5678');
    }

    #[Test]
    public function 비로그인_사용자는_유저_발급_ap_i_에_접근할_수_없다(): void
    {
        $this->registerProvider();
        $order = $this->makeOrder($this->createUser());

        $this->postJson($this->userUrl($order), $this->issuePayload())->assertStatus(401);
    }

    #[Test]
    public function 발급된_영수증은_주문_이력에_원장으로_남는다(): void
    {
        $this->registerProvider();
        $order = $this->makeOrder();

        $this->actingAs($this->adminUser)->postJson($this->adminUrl($order), $this->issuePayload())->assertOk();

        $rows = OrderCashReceipt::where('order_id', $order->id)->get();
        $this->assertCount(1, $rows);
        // 원장 어디에도 평문 식별번호가 없어야 한다
        foreach ($rows as $row) {
            foreach ($row->getAttributes() as $value) {
                $this->assertStringNotContainsString(self::IDENTIFIER, (string) $value);
            }
        }
    }

    // ─────────────────────────────────────────────
    // 비회원 (조회 토큰)
    // ─────────────────────────────────────────────

    /**
     * 비회원 무통장 결제완료 주문과 조회 토큰을 생성합니다.
     *
     * @return array{0: Order, 1: string} 주문과 조회 토큰
     */
    private function makeGuestOrderWithToken(): array
    {
        $phone = '010-1234-5678';
        $password = 'guest1234';

        $order = Order::factory()->forGuest()->create([
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'guest_lookup_password_hash' => Hash::make($password),
            'total_amount' => 11000,
            'total_cash_equivalent_amount' => 11000,
            'total_tax_amount' => 11000,
            'total_tax_free_amount' => 0,
        ]);

        OrderAddress::factory()->forOrder($order)->create([
            'address_type' => 'shipping',
            'orderer_phone' => $phone,
        ]);

        OrderPayment::factory()->forOrder($order)->create([
            'payment_method' => PaymentMethodEnum::DBANK,
            'payment_status' => PaymentStatusEnum::PAID,
        ]);

        $token = $this->postJson('/api/modules/sirsoft-ecommerce/guest/orders/verify', [
            'order_number' => $order->order_number,
            'orderer_phone' => $phone,
            'guest_lookup_password' => $password,
        ])->assertOk()->json('data.guest_order_token');

        return [$order->fresh(), $token];
    }

    #[Test]
    public function 비회원은_조회_토큰으로_사후_발급할_수_있다(): void
    {
        $this->registerProvider();
        [$order, $token] = $this->makeGuestOrderWithToken();

        $this->postJson(
            "/api/modules/sirsoft-ecommerce/guest/orders/{$order->order_number}/cash-receipt",
            $this->issuePayload(),
            ['X-Guest-Order-Token' => $token],
        )
            ->assertOk()
            ->assertJsonPath('data.identifier_masked', '*******5678');
    }

    /**
     * 사용자 계약(토큰 없이는 발급 불가)으로는 유효하지만, **미들웨어 부착의 증거는 아니다.**
     *
     * 토큰 미들웨어의 404 와 FormRequest 의 404 는 코드·메시지 키가 같아 응답으로 구별할 수 없고,
     * payload 가 유효해 422 로 갈라지지도 않는다. 즉 미들웨어가 아예 부착되지 않아도 green 이며
     * 실제로 그 상태가 오래 유지됐다(현금영수증 라우트 targets 누락). 상태코드나 메시지를 갈라
     * 구별 가능하게 만드는 방식은 채택하지 않는다 — 주문 존재 여부가 응답으로 새어 열거 공격
     * 표면이 생긴다. 동일 404 는 의도된 설계다.
     *
     * 따라서 증명 책임은 다음이 나눠 진다:
     * - 미들웨어 부착 자체 → `GuestOrderTokenMiddlewareRegistrationTest`
     * - 토큰이 실제로 통한다 → `비회원은_조회_토큰으로_사후_발급할_수_있다`
     */
    #[Test]
    public function 비회원은_토큰_없이_발급할_수_없다(): void
    {
        $this->registerProvider();
        [$order] = $this->makeGuestOrderWithToken();

        $this->postJson(
            "/api/modules/sirsoft-ecommerce/guest/orders/{$order->order_number}/cash-receipt",
            $this->issuePayload(),
        )->assertStatus(404);

        $this->assertNull(app(CashReceiptService::class)->findActiveReceipt($order->fresh()));
    }

    #[Test]
    public function 비회원은_발급_상태를_조회할_수_있다(): void
    {
        $this->registerProvider();
        [$order, $token] = $this->makeGuestOrderWithToken();
        $headers = ['X-Guest-Order-Token' => $token];
        $url = "/api/modules/sirsoft-ecommerce/guest/orders/{$order->order_number}/cash-receipt";

        // GET 은 입력을 받지 않는다 (발급 폼 검증에 걸리면 안 된다)
        $this->getJson($url, $headers)
            ->assertOk()
            ->assertJsonPath('data.issuable', true)
            ->assertJsonPath('data.cash_receipt', null);

        $this->postJson($url, $this->issuePayload(), $headers)->assertOk();

        $this->getJson($url, $headers)
            ->assertOk()
            ->assertJsonPath('data.issuable', false)
            ->assertJsonPath('data.cash_receipt.identifier_masked', '*******5678');
    }

    /**
     * 비회원 주문상세 응답이 발급 결과를 실어야 한다.
     *
     * 주문상세 화면의 현금영수증 카드는 `order.data.cash_receipt` 로 발급완료/미발급을 가른다
     * (회원·비회원이 같은 레이아웃 `mypage_order_cash_receipt.json` 을 공유한다).
     * 비회원 응답에 이 키가 없으면 발급에 성공해도 카드가 영구히 "미발급" 으로 남고
     * 영수증 URL 에 도달할 방법이 없다 — 오류도 경고도 없이 기능만 사라진다.
     *
     * `payment.is_cash_receipt_issued` 만으로는 대체되지 않는다. 카드가 보는 키가 다르다.
     */
    #[Test]
    public function 비회원_주문상세_응답에_발급된_현금영수증이_실린다(): void
    {
        $this->registerProvider();
        [$order, $token] = $this->makeGuestOrderWithToken();
        $headers = ['X-Guest-Order-Token' => $token];

        $detailUrl = "/api/modules/sirsoft-ecommerce/user/orders/{$order->order_number}";

        // 발급 전 — 키는 존재하되 값이 없다 (카드의 "미발급" 분기 근거)
        $this->getJson($detailUrl, $headers)
            ->assertOk()
            ->assertJsonPath('data.cash_receipt', null)
            ->assertJsonPath('data.cash_receipts', []);

        $this->postJson(
            "/api/modules/sirsoft-ecommerce/guest/orders/{$order->order_number}/cash-receipt",
            $this->issuePayload(),
            $headers,
        )->assertOk();

        $response = $this->getJson($detailUrl, $headers)->assertOk();

        $response->assertJsonPath('data.cash_receipt.identifier_masked', '*******5678');
        $response->assertJsonPath('data.cash_receipt.receipt_url', 'https://example.test/receipt/receipt-1');
        $response->assertJsonPath('data.cash_receipt.issue_number', '12345678');
        $this->assertNotNull($response->json('data.cash_receipt.issued_at_formatted'));
        $this->assertNotNull($response->json('data.cash_receipt.receipt_type_label'));

        // 이력 배열도 함께 — 카드의 "직전 발급 실패" 분기가 cash_receipts[0] 를 본다
        $this->assertCount(1, $response->json('data.cash_receipts'));

        // 비회원 응답에도 식별번호 평문은 절대 실리지 않는다
        $this->assertStringNotContainsString(self::IDENTIFIER, $response->getContent());
    }
}
