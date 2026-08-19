<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Requests;

use App\Extension\HookManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Modules\Sirsoft\Ecommerce\Http\Requests\Public\CreateOrderRequest;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\PaymentMethodResolver;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 죽은 PG 를 지정한 결제수단의 주문 제출 차단 (A2 서버 대칭 가드)
 *
 * 공개 응답에서 감추는 것만으로는 부족하다 — 프론트를 우회해 직접 제출하면 그대로 통과해
 * 결제창 없는 주문이 만들어진다. 검증 계층에서도 같은 판정을 적용한다.
 *
 * 이 가드는 `_orphaned`(수단 자체 고아)도 함께 막는다. `allValidIds()` 는 카탈로그 키를
 * 그대로 쓰므로 고아 수단이 통과하는 공백이 있었다 — 공개 #111 의 서버측 대칭이 여기서 완성된다.
 * `allValidIds()` 자체는 건드리지 않는다(과거 주문 목록 필터가 같은 목록을 쓴다 — 조이면
 * 예전 수단으로 결제한 주문을 조회할 수 없게 된다).
 */
class CreateOrderDeadPgGuardTest extends ModuleTestCase
{
    private string $storagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');

        if (File::isDirectory($this->storagePath)) {
            File::cleanDirectory($this->storagePath);
        }
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->storagePath)) {
            File::cleanDirectory($this->storagePath);
        }

        parent::tearDown();
    }

    /**
     * 살아 있는 PG provider 를 레지스트리에 등록합니다.
     *
     * @param  array<int, string>  $ids  provider ID 목록
     */
    private function registerPgProviders(array $ids): void
    {
        HookManager::addFilter(
            'sirsoft-ecommerce.payment.registered_pg_providers',
            function (array $providers) use ($ids) {
                foreach ($ids as $id) {
                    $providers[] = ['id' => $id, 'name' => strtoupper($id)];
                }

                return $providers;
            }
        );
    }

    /**
     * order_settings 저장 파일을 구성합니다.
     *
     * @param  array  $paymentMethods  결제수단 배열
     * @param  string|null  $defaultPgProvider  기본 PG
     */
    private function seedOrderSettings(array $paymentMethods, ?string $defaultPgProvider = null): void
    {
        File::ensureDirectoryExists($this->storagePath);
        File::put($this->storagePath.'/order_settings.json', json_encode([
            'default_pg_provider' => $defaultPgProvider,
            'payment_methods' => $paymentMethods,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        app(EcommerceSettingsService::class)->clearCache();
        app(PaymentMethodResolver::class)->flushCache();
    }

    /**
     * payment_method 규칙만 떼어 검증합니다.
     *
     * @param  string  $methodId  결제수단 ID
     * @return \Illuminate\Contracts\Validation\Validator 검증기
     */
    private function validatePaymentMethod(string $methodId)
    {
        $rules = (new CreateOrderRequest)->rules();

        return Validator::make(
            ['payment_method' => $methodId],
            ['payment_method' => $rules['payment_method']]
        );
    }

    /**
     * 죽은 PG 를 지정한 수단의 주문 제출은 거부된다. (실패-먼저)
     *
     * @scenario pg_provider_state=dead_own
     *
     * @effects dead_pg_order_submission_rejected_422
     */
    public function test_dead_pg_method_submission_is_rejected(): void
    {
        $this->registerPgProviders(['kginicis']);
        $this->seedOrderSettings([
            ['id' => 'card', 'is_active' => true, 'pg_provider' => 'ghost_pg'],
        ], 'kginicis');

        $validator = $this->validatePaymentMethod('card');

        $this->assertTrue($validator->fails(), '죽은 PG 를 지정한 결제수단이 검증을 통과했습니다.');
        $message = $validator->errors()->first('payment_method');
        $this->assertNotSame(
            'sirsoft-ecommerce::validation.order.payment_method_unavailable',
            $message,
            '다국어 키가 원문 그대로 노출되었습니다.'
        );
        $this->assertNotEmpty($message);
    }

    /**
     * 카탈로그에서 사라진 고아 수단의 제출도 거부된다. (공개 #111 서버 대칭)
     *
     * @scenario pg_provider_state=live
     *
     * @effects orphaned_method_order_submission_rejected_422
     */
    public function test_orphaned_method_submission_is_rejected(): void
    {
        $this->registerPgProviders(['kginicis']);
        $this->seedOrderSettings([
            ['id' => 'zombie_pay', 'is_active' => true, 'pg_provider' => 'kginicis'],
        ], 'kginicis');

        $this->assertTrue(
            $this->validatePaymentMethod('zombie_pay')->fails(),
            '카탈로그에 없는 고아 결제수단이 검증을 통과했습니다.'
        );
    }

    /**
     * 살아 있는 PG 를 지정한 수단은 그대로 통과한다. (비회귀 pin)
     *
     * @scenario pg_provider_state=live
     *
     * @effects live_pg_order_submission_accepted
     */
    public function test_live_pg_method_submission_passes(): void
    {
        $this->registerPgProviders(['kginicis']);
        $this->seedOrderSettings([
            ['id' => 'card', 'is_active' => true, 'pg_provider' => 'kginicis'],
        ], 'kginicis');

        $this->assertFalse(
            $this->validatePaymentMethod('card')->fails(),
            '정상 결제수단이 거부되었습니다.'
        );
    }

    /**
     * PG 미설정 수단(non-PG)은 그대로 통과한다. (비회귀 pin)
     *
     * @scenario pg_provider_state=none
     *
     * @effects live_pg_order_submission_accepted
     */
    public function test_non_pg_method_submission_passes(): void
    {
        $this->registerPgProviders([]);
        $this->seedOrderSettings([
            ['id' => 'dbank', 'is_active' => true],
        ]);

        $this->assertFalse($this->validatePaymentMethod('dbank')->fails());
    }

    /**
     * 죽은 PG 수단은 실제 주문 생성 엔드포인트에서도 422 로 거부된다.
     *
     * 위 테스트들은 `rules()` 배열을 떼어 검증한다 — 규칙 자체는 고정되지만, 그 규칙이
     * 라우트에 실제로 걸려 있는지는 말해 주지 않는다. FormRequest 를 컨트롤러가 타입힌트하지
     * 않으면 규칙은 살아 있고 요청만 그대로 통과한다. 그 연결을 여기서 고정한다.
     *
     * @scenario pg_provider_state=dead_own
     *
     * @effects dead_pg_order_submission_rejected_422
     */
    public function test_dead_pg_method_submission_is_rejected_over_http(): void
    {
        $this->registerPgProviders(['kginicis']);
        $this->seedOrderSettings([
            ['id' => 'card', 'is_active' => true, 'pg_provider' => 'ghost_pg'],
        ], 'kginicis');

        $this->postJson('/api/modules/sirsoft-ecommerce/user/orders', $this->orderPayload('card'))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payment_method']);
    }

    /**
     * 살아 있는 PG 수단은 결제수단 검증에서 걸리지 않는다. (거짓 양성 차단)
     *
     * 다른 필드(주소·상품)로 422 가 날 수는 있으나 `payment_method` 는 그 목록에 없어야 한다 —
     * 그래야 위 테스트의 422 가 결제수단 때문임이 증명된다.
     *
     * @scenario pg_provider_state=live
     *
     * @effects live_pg_order_submission_accepted
     */
    public function test_live_pg_method_is_not_flagged_over_http(): void
    {
        $this->registerPgProviders(['kginicis']);
        $this->seedOrderSettings([
            ['id' => 'card', 'is_active' => true, 'pg_provider' => 'kginicis'],
        ], 'kginicis');

        $response = $this->postJson('/api/modules/sirsoft-ecommerce/user/orders', $this->orderPayload('card'));

        $this->assertArrayNotHasKey(
            'payment_method',
            (array) ($response->json('errors') ?? []),
            '정상 PG 를 지정한 결제수단이 HTTP 검증에서 거부되었습니다.'
        );
    }

    /**
     * 주문 생성 요청 본문을 만듭니다.
     *
     * 결제수단 축만 보므로 나머지 필드는 형식만 갖춘다 — 다른 필드의 검증 실패는
     * `payment_method` 오류의 유무 판정에 영향을 주지 않는다.
     *
     * @param  string  $methodId  결제수단 ID
     * @return array<string, mixed> 요청 본문
     */
    private function orderPayload(string $methodId): array
    {
        return [
            'payment_method' => $methodId,
            'items' => [['product_id' => 1, 'quantity' => 1]],
            'receiver_name' => '홍길동',
            'receiver_phone' => '01012345678',
            'address' => '서울시 강남구',
            'address_detail' => '101호',
            'postcode' => '06234',
        ];
    }

    /**
     * 리졸버의 주문 가능 판정은 카탈로그 밖 ID 를 막지 않는다. (기존 계약 유지)
     *
     * @scenario pg_provider_state=live
     *
     * @effects unknown_method_id_keeps_legacy_contract
     */
    public function test_resolver_allows_ids_outside_catalog(): void
    {
        $this->registerPgProviders(['kginicis']);
        $this->seedOrderSettings([
            ['id' => 'card', 'is_active' => true, 'pg_provider' => 'kginicis'],
        ], 'kginicis');

        $this->assertTrue(
            app(PaymentMethodResolver::class)->isOrderable('some_unknown_id'),
            '카탈로그 밖 ID 판정이 기존 계약보다 좁아졌습니다.'
        );
    }
}
