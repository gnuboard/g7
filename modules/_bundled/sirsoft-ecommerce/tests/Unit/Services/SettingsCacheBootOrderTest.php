<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Extension\HookManager;
use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 부팅 중 만들어진 병합 결과를 요청 전체가 물고 가지 않는다 (공개 #116 파생)
 *
 * 설정 조회 결과에는 훅 카탈로그와 병합된 값이 섞여 있다 — 확장이 등록한 결제수단, 그리고
 * 그 수단·PG 의 생사 판정(`_orphaned` / `_orphaned_pg`)이다.
 *
 * 그런데 코어는 부팅 중(`CoreServiceProvider::boot`)에 config 미러를 채우려고 이 설정을 한 번
 * 읽는다. 그 시점은 플러그인이 자기 훅을 등록하기 **전**이라 카탈로그가 비어 있다. 서비스가
 * 비-싱글톤이던 때는 요청 처리 단계에서 새 인스턴스가 다시 읽어 문제가 드러나지 않았지만,
 * 공유 인스턴스가 되면 그 빈 카탈로그 기준 판정이 요청 내내 남는다 — 살아 있는 PG 를 지정한
 * 결제수단이 "PG 없음" 으로 판정되어 주문서에서 통째로 사라진다.
 */
class SettingsCacheBootOrderTest extends ModuleTestCase
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
        $this->setBooted(true);

        if (File::isDirectory($this->storagePath)) {
            File::cleanDirectory($this->storagePath);
        }

        parent::tearDown();
    }

    /**
     * 컨테이너의 부팅 완료 플래그를 조작합니다. (부팅 순서 재현용)
     *
     * @param  bool  $booted  부팅 완료 여부
     */
    private function setBooted(bool $booted): void
    {
        $prop = (new \ReflectionClass($this->app))->getProperty('booted');
        $prop->setAccessible(true);
        $prop->setValue($this->app, $booted);
    }

    /**
     * 결제수단 카탈로그의 ID 목록을 반환합니다.
     *
     * @param  EcommerceSettingsService  $service  설정 서비스
     * @return array<int, string> 결제수단 ID 목록
     */
    private function methodIds(EcommerceSettingsService $service): array
    {
        $methods = $service->getSettings('order_settings')['payment_methods'] ?? [];

        return array_values(array_map(fn ($m) => $m['id'] ?? null, $methods));
    }

    /**
     * 부팅 중 읽은 결과가 캐시로 굳지 않는다. (실패-먼저)
     *
     * @scenario pg_provider_state=live
     *
     * @effects boot_time_read_not_cached
     */
    public function test_settings_read_during_boot_is_not_cached(): void
    {
        $service = app(EcommerceSettingsService::class);

        // 부팅 중 — 플러그인 훅 등록 이전 상태에서 코어가 config 미러를 채우려고 한 번 읽는다
        $this->setBooted(false);
        $this->assertNotContains('plugin_pay', $this->methodIds($service));

        // 부팅 완료 — 이제 플러그인이 자기 결제수단을 등록한 상태다
        $this->setBooted(true);
        HookManager::addFilter(
            'sirsoft-ecommerce.settings.filter_available_payment_methods',
            function (array $methods) {
                $methods[] = [
                    'id' => 'plugin_pay',
                    'name' => ['ko' => '플러그인 결제', 'en' => 'Plugin Pay'],
                    'description' => ['ko' => '', 'en' => ''],
                    'icon' => 'credit-card',
                    'source' => 'plugin',
                    'defaults' => ['needs_pg' => true, 'pg_provider' => 'plugin_pg', 'is_active' => true],
                ];

                return $methods;
            }
        );

        $this->assertContains(
            'plugin_pay',
            $this->methodIds($service),
            '부팅 중 만들어진 카탈로그가 캐시로 굳어 확장 결제수단이 요청 내내 사라졌습니다.'
        );
    }

    /**
     * 살아 있는 PG 를 지정한 결제수단이 부팅 순서 때문에 고아로 오판되지 않는다. (실패-먼저)
     *
     * @scenario pg_provider_state=live
     *
     * @effects live_pg_method_remains_visible
     */
    public function test_live_pg_method_is_not_flagged_due_to_boot_order(): void
    {
        File::ensureDirectoryExists($this->storagePath);
        File::put($this->storagePath.'/order_settings.json', json_encode([
            'payment_methods' => [
                ['id' => 'card', 'is_active' => true, 'sort_order' => 1, 'pg_provider' => 'late_pg'],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $service = app(EcommerceSettingsService::class);

        // 부팅 중 조회 — PG 레지스트리가 아직 비어 있다.
        // 캐시 비우기도 부팅 구간 안에서 수행한다(부팅 완료 상태에서 비우면 그 시점의
        // "훅 미등록" 상태가 정상적으로 캐시되어 재현 대상이 아닌 상태가 된다).
        $this->setBooted(false);
        $service->clearCache();
        $service->getSettings('order_settings');

        // 부팅 완료 후 PG 플러그인이 자기 PG 를 등록한다
        $this->setBooted(true);
        HookManager::addFilter(
            'sirsoft-ecommerce.payment.registered_pg_providers',
            function (array $providers) {
                $providers[] = ['id' => 'late_pg', 'name' => 'Late PG'];

                return $providers;
            }
        );

        $card = collect($service->getSettings('order_settings')['payment_methods'] ?? [])->firstWhere('id', 'card');

        $this->assertFalse(
            (bool) ($card['_orphaned_pg'] ?? false),
            '살아 있는 PG 인데 부팅 순서 때문에 죽은 PG 로 판정되었습니다.'
        );
        $this->assertContains(
            'card',
            array_map(fn ($m) => $m['id'], $service->getPublicPaymentSettings()['payment_methods'] ?? []),
            '정상 결제수단이 공개 응답에서 제거되었습니다.'
        );
    }
}
