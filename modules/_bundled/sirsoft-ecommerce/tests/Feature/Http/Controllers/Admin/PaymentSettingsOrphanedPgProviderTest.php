<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Extension\HookManager;
use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 죽은 PG 를 지정한 결제수단의 공개 노출 차단 (A2, 공개 #111 동종)
 *
 * 고아 판정(`_orphaned`)은 결제수단 ID 에만 계산된다. 그래서 builtin 수단(card 등)에
 * 특정 PG 를 지정한 뒤 그 PG 플러그인을 제거하면, 수단 자체는 카탈로그에 남아 있으므로
 * 고아 필터를 그대로 통과한다. 결과적으로 체크아웃에 선택 가능한 결제수단으로 노출되고,
 * 주문 시 PG 라우팅이 매칭에 실패해 **결제창 없이 주문완료로 넘어간다**.
 *
 * 판정식은 런타임 폴백과 일치시킨다:
 *   effective = method.pg_provider ?? default_pg_provider   // null 일 때만 폴백
 *   _orphaned_pg = needs_pg && effective 가 비지 않았고 && 레지스트리에 없음
 *
 * own 이 죽었고 default 가 살아 있어도 폴백하지 않는다 — `determinePgProvider()` 의 실제
 * 동작이 그렇다(죽은 own 을 그대로 반환). 레지스트리 인식 폴백으로 바꾸는 것은 결제 라우팅
 * 계약 변경이므로 하지 않고, 차단만 한다.
 */
class PaymentSettingsOrphanedPgProviderTest extends ModuleTestCase
{
    private EcommerceSettingsService $service;

    private string $storagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');

        if (File::isDirectory($this->storagePath)) {
            File::cleanDirectory($this->storagePath);
        }

        $this->service = app(EcommerceSettingsService::class);
        $this->service->clearCache();
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
            },
            10
        );
    }

    /**
     * PG 고정(`pg_locked`) 확장 결제수단을 카탈로그에 등록합니다.
     *
     * @param  string  $pgProvider  이 수단이 고정으로 쓰는 PG
     */
    private function registerLockedExtensionMethod(string $pgProvider): void
    {
        HookManager::addFilter(
            'sirsoft-ecommerce.settings.filter_available_payment_methods',
            fn (array $methods) => array_merge($methods, [[
                'id' => 'locked_easypay',
                'name' => ['ko' => '고정PG 간편결제', 'en' => 'Locked Easy Pay'],
                'description' => ['ko' => '', 'en' => ''],
                'icon' => 'credit-card',
                'source' => 'plugin:test-locked',
                'defaults' => [
                    'pg_provider' => $pgProvider,
                    'pg_locked' => true,
                    'needs_pg' => true,
                    'refund_method' => 'pg',
                    'is_active' => true,
                    'min_order_amount' => 0,
                ],
            ]]),
            10
        );
    }

    /**
     * order_settings 저장 파일을 직접 구성합니다.
     *
     * @param  string|null  $cardPgProvider  card 수단에 지정할 PG
     * @param  string|null  $defaultPgProvider  기본 PG
     */
    private function seedOrderSettings(?string $cardPgProvider, ?string $defaultPgProvider): void
    {
        File::ensureDirectoryExists($this->storagePath);
        File::put($this->storagePath.'/order_settings.json', json_encode([
            'default_pg_provider' => $defaultPgProvider,
            'payment_methods' => [
                ['id' => 'card', 'is_active' => true, 'sort_order' => 1, 'pg_provider' => $cardPgProvider],
                ['id' => 'dbank', 'is_active' => true, 'sort_order' => 2],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->service->clearCache();
    }

    /**
     * 관리자 응답에서 특정 결제수단 항목을 찾습니다.
     *
     * @param  string  $id  결제수단 ID
     * @return array|null 항목
     */
    private function adminMethod(string $id): ?array
    {
        $methods = $this->service->getSettings('order_settings')['payment_methods'] ?? [];

        return collect($methods)->firstWhere('id', $id);
    }

    /**
     * 공개 응답의 결제수단 ID 목록을 반환합니다.
     *
     * @return array<int, string> 결제수단 ID 목록
     */
    private function publicMethodIds(): array
    {
        $methods = $this->service->getPublicPaymentSettings()['payment_methods'] ?? [];

        return array_values(array_map(fn ($m) => $m['id'] ?? null, $methods));
    }

    /**
     * 수단에 지정된 PG 가 레지스트리에 없으면 공개 응답에서 제거된다. (실패-먼저)
     *
     * @scenario pg_provider_state=dead_own
     *
     * @effects dead_pg_method_hidden_from_checkout, dead_pg_method_flagged_for_admin
     */
    public function test_dead_own_pg_provider_is_hidden_from_public(): void
    {
        $this->registerPgProviders(['kginicis']);
        $this->seedOrderSettings(cardPgProvider: 'ghost_pg', defaultPgProvider: 'kginicis');

        $this->assertTrue(
            (bool) ($this->adminMethod('card')['_orphaned_pg'] ?? false),
            '관리자 응답에 죽은 PG 표시가 없습니다.'
        );
        $this->assertNotContains('card', $this->publicMethodIds(), '죽은 PG 를 지정한 수단이 공개 응답에 남았습니다.');
        $this->assertContains('dbank', $this->publicMethodIds(), '정상 수단까지 제거되었습니다.');
    }

    /**
     * own 이 죽었으면 default 가 살아 있어도 차단한다. (런타임 폴백과 동일 판정)
     *
     * @scenario pg_provider_state=dead_own
     *
     * @effects dead_pg_method_hidden_from_checkout
     */
    public function test_live_default_does_not_rescue_dead_own_provider(): void
    {
        $this->registerPgProviders(['kginicis']);
        $this->seedOrderSettings(cardPgProvider: 'ghost_pg', defaultPgProvider: 'kginicis');

        $this->assertNotContains('card', $this->publicMethodIds());
    }

    /**
     * 수단에 PG 지정이 없으면 기본 PG 로 폴백하며, 그 기본 PG 가 죽었으면 차단한다. (실패-먼저)
     *
     * @scenario pg_provider_state=dead_default
     *
     * @effects dead_pg_method_hidden_from_checkout, dead_default_pg_normalized_in_public
     */
    public function test_dead_default_pg_provider_blocks_method_without_own(): void
    {
        $this->registerPgProviders(['kginicis']);
        $this->seedOrderSettings(cardPgProvider: null, defaultPgProvider: 'ghost_pg');

        $this->assertTrue((bool) ($this->adminMethod('card')['_orphaned_pg'] ?? false));
        $this->assertNotContains('card', $this->publicMethodIds());

        // `??` 는 null 을 부재로 접으므로 키 존재 여부와 값을 나눠 확인한다
        $public = $this->service->getPublicPaymentSettings();
        $this->assertArrayHasKey('default_pg_provider', $public);
        $this->assertNull(
            $public['default_pg_provider'],
            '죽은 기본 PG 가 공개 응답에 그대로 노출되었습니다.'
        );
    }

    /**
     * 양쪽 모두 미설정이면 기존 계약(non-PG 강하)을 유지한다. (비회귀 pin)
     *
     * @scenario pg_provider_state=none
     *
     * @effects unconfigured_pg_keeps_legacy_contract
     */
    public function test_unset_pg_provider_keeps_method_visible(): void
    {
        $this->registerPgProviders(['kginicis']);
        $this->seedOrderSettings(cardPgProvider: null, defaultPgProvider: null);

        $this->assertFalse(
            (bool) ($this->adminMethod('card')['_orphaned_pg'] ?? false),
            'PG 미설정 상태가 고아로 판정되었습니다 (기존 계약 축소).'
        );
        $this->assertContains('card', $this->publicMethodIds());
    }

    /**
     * 살아 있는 PG 지정은 그대로 노출된다. (비회귀 pin)
     *
     * @scenario pg_provider_state=live
     *
     * @effects live_pg_method_remains_visible
     */
    public function test_live_pg_provider_stays_visible(): void
    {
        $this->registerPgProviders(['kginicis']);
        $this->seedOrderSettings(cardPgProvider: 'kginicis', defaultPgProvider: 'kginicis');

        $this->assertFalse((bool) ($this->adminMethod('card')['_orphaned_pg'] ?? false));
        $this->assertContains('card', $this->publicMethodIds());
        $this->assertSame('kginicis', $this->service->getPublicPaymentSettings()['default_pg_provider'] ?? null);
    }

    /**
     * PG 불필요 수단(dbank)은 PG 레지스트리와 무관하게 노출된다.
     *
     * @scenario pg_provider_state=dead_default
     *
     * @effects non_pg_method_unaffected_by_registry
     */
    public function test_non_pg_method_is_not_affected(): void
    {
        $this->registerPgProviders([]);
        $this->seedOrderSettings(cardPgProvider: null, defaultPgProvider: 'ghost_pg');

        $this->assertFalse((bool) ($this->adminMethod('dbank')['_orphaned_pg'] ?? false));
        $this->assertContains('dbank', $this->publicMethodIds());
    }

    /**
     * PG 고정(`pg_locked`) 수단도 그 PG 가 사라지면 똑같이 차단된다. (비회귀 pin)
     *
     * 판정식에 `pg_locked` 특례를 두면 안 된다 — 고정이라는 선언은 "운영자가 PG 를 바꿀 수
     * 없다" 는 뜻일 뿐, 그 PG 가 살아 있다는 보증이 아니다. 특례를 두는 순간 PG 고정 수단만
     * 죽은 PG 를 달고 주문서에 남아, 결제창 없이 주문완료로 넘어가는 원래 결함이 되살아난다.
     *
     * @scenario pg_provider_state=dead_own
     *
     * @effects dead_pg_method_hidden_from_checkout, dead_pg_method_flagged_for_admin
     */
    public function test_pg_locked_method_has_no_orphan_exemption(): void
    {
        // 수단은 등록하되 그 수단이 고정으로 쓰는 PG 는 레지스트리에 없다 (PG 플러그인 삭제)
        $this->registerLockedExtensionMethod('ghost_pg');
        $this->registerPgProviders(['kginicis']);

        File::ensureDirectoryExists($this->storagePath);
        File::put($this->storagePath.'/order_settings.json', json_encode([
            'default_pg_provider' => 'kginicis',
            'payment_methods' => [
                ['id' => 'locked_easypay', 'is_active' => true, 'sort_order' => 1],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->service->clearCache();

        $method = $this->adminMethod('locked_easypay');

        $this->assertNotNull($method, '고정 PG 수단이 카탈로그에서 사라졌습니다.');
        $this->assertTrue((bool) ($method['pg_locked'] ?? false), '테스트 전제(pg_locked)가 깨졌습니다.');
        $this->assertTrue(
            (bool) ($method['_orphaned_pg'] ?? false),
            'pg_locked 수단이 고아 판정에서 면제되었습니다.'
        );
        $this->assertNotContains('locked_easypay', $this->publicMethodIds());
    }

    /**
     * PG 고정 수단의 PG 가 살아 있으면 그대로 노출된다. (비회귀 pin)
     *
     * @scenario pg_provider_state=live
     *
     * @effects live_pg_method_remains_visible
     */
    public function test_pg_locked_method_with_live_provider_stays_visible(): void
    {
        $this->registerLockedExtensionMethod('kginicis');
        $this->registerPgProviders(['kginicis']);

        File::ensureDirectoryExists($this->storagePath);
        File::put($this->storagePath.'/order_settings.json', json_encode([
            'default_pg_provider' => 'kginicis',
            'payment_methods' => [
                ['id' => 'locked_easypay', 'is_active' => true, 'sort_order' => 1],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->service->clearCache();

        $this->assertFalse((bool) ($this->adminMethod('locked_easypay')['_orphaned_pg'] ?? false));
        $this->assertContains('locked_easypay', $this->publicMethodIds());
    }

    /**
     * 런타임 전용 플래그는 저장 파일에 박제되지 않는다.
     *
     * @scenario pg_provider_state=dead_own
     *
     * @effects runtime_only_flag_not_persisted
     */
    public function test_orphaned_pg_flag_is_not_persisted(): void
    {
        $this->registerPgProviders(['kginicis']);
        $this->seedOrderSettings(cardPgProvider: 'ghost_pg', defaultPgProvider: 'kginicis');

        // 관리자 화면이 읽은 값을 그대로 되저장하는 상황
        $methods = $this->service->getSettings('order_settings')['payment_methods'];
        $this->service->saveSettings(['order_settings' => ['payment_methods' => $methods]]);

        $saved = json_decode(File::get($this->storagePath.'/order_settings.json'), true);
        foreach ($saved['payment_methods'] as $method) {
            $this->assertArrayNotHasKey('_orphaned_pg', $method, '런타임 전용 플래그가 저장되었습니다.');
        }
    }

    /**
     * 체크아웃이 실제로 호출하는 엔드포인트도 같은 목록을 내려준다.
     *
     * 위 테스트들은 서비스 메서드를 직접 부른다 — 필터가 응답 조립 경로에 실제로 걸려
     * 있는지는 말해 주지 않는다. 컨트롤러가 raw `getSettings()` 를 쓰면 서비스는 정상이고
     * 그 엔드포인트만 조용히 뚫린다.
     *
     * 항목 제거 후 인덱스 재정렬(`array_values`)도 여기서 함께 본다 — 비연속 키는 JSON 에서
     * 배열이 아니라 객체로 직렬화되어, 화면의 반복 렌더가 아무것도 그리지 않는다.
     *
     * @scenario pg_provider_state=dead_own
     *
     * @effects dead_pg_method_hidden_from_checkout, dead_default_pg_normalized_in_public
     */
    public function test_checkout_endpoint_returns_the_same_filtered_list(): void
    {
        $this->registerPgProviders(['kginicis']);
        $this->seedOrderSettings(cardPgProvider: 'ghost_pg', defaultPgProvider: 'kginicis');

        // 같은 데이터를 내보내는 공개 엔드포인트가 둘이다 — 한쪽이 raw 게터를 쓰면 그 경로만 뚫린다.
        foreach (['checkout', 'payment'] as $endpoint) {
            $methods = $this->getJson('/api/modules/sirsoft-ecommerce/settings/'.$endpoint)
                ->assertOk()
                ->json('data.order_settings.payment_methods');

            $this->assertIsArray($methods, "[{$endpoint}] 결제수단이 배열로 직렬화되지 않았습니다.");
            $this->assertSame(
                range(0, count($methods) - 1),
                array_keys($methods),
                "[{$endpoint}] 결제수단 배열의 키가 비연속입니다 — JSON 객체로 직렬화되어 화면 반복이 깨집니다."
            );

            $ids = array_column($methods, 'id');
            $this->assertNotContains('card', $ids, "[{$endpoint}] 죽은 PG 수단이 공개 응답에 남았습니다.");
            $this->assertContains('dbank', $ids, "[{$endpoint}] 정상 수단까지 응답에서 사라졌습니다.");
            $this->assertSame(
                $this->publicMethodIds(),
                $ids,
                "[{$endpoint}] 공개 게터와 다른 목록을 내려줍니다."
            );
        }
    }
}
