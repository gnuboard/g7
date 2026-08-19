<?php

namespace Tests\Feature\Settings;

use App\Extension\HookManager;
use App\Providers\CoreServiceProvider;
use App\Repositories\JsonConfigRepository;
use App\Services\DriverRegistryService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * 검색엔진 드라이버의 폴백 가드 편입 (A5b)
 *
 * 드라이버 폴백 가드는 `DriverRegistryService` 의 카테고리 레지스트리를 순회한다.
 * `search` 만 그 레지스트리에 없어 가드 대상 밖이었다 — 검색엔진 플러그인을 저장한 뒤
 * 그 플러그인을 제거하면 `scout.driver` 가 죽은 값으로 남고, 공개 검색이 500 으로 떨어진다.
 * (다른 카테고리는 기본 드라이버로 폴백해 사이트가 살아남는다)
 */
class SearchDriverFallbackTest extends TestCase
{
    private DriverRegistryService $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = app(DriverRegistryService::class);
    }

    /**
     * search 카테고리가 드라이버 레지스트리에 등재되어 있다. (실패-먼저)
     *
     * @scenario engine_source=core
     *
     * @effects search_category_registered_in_driver_registry
     */
    public function test_search_category_is_registered(): void
    {
        $this->assertContains(
            'search',
            $this->registry->getCategories(),
            '검색엔진이 드라이버 카테고리에 없어 폴백 가드 대상에서 빠집니다.'
        );
    }

    /**
     * 코어 검색엔진과 기본 폴백 드라이버가 선언되어 있다. (실패-먼저)
     *
     * @scenario engine_source=core
     *
     * @effects core_search_engine_declared_as_core_driver, search_default_fallback_is_core_engine
     */
    public function test_core_search_driver_and_default_are_declared(): void
    {
        $this->assertTrue($this->registry->isCoreDriver('search', 'mysql-fulltext'));
        $this->assertSame('mysql-fulltext', $this->registry->getDefaultDriver('search'));
    }

    /**
     * 설정 키와 Config 키가 실제 적용 지점과 일치한다. (실패-먼저)
     *
     * @scenario engine_source=core
     *
     * @effects search_settings_key_matches_saved_field, search_config_key_matches_apply_site
     */
    public function test_settings_and_config_keys_match_apply_site(): void
    {
        $this->assertSame(
            ['category' => 'drivers', 'key' => 'search_engine_driver'],
            $this->registry->getSettingsKey('search')
        );
        $this->assertSame('scout.driver', $this->registry->getConfigKey('search'));
    }

    /**
     * 플러그인이 등록한 검색엔진은 사용 가능으로 판정된다. (실패-먼저)
     *
     * 검색엔진은 전용 훅(`core.search.engine_drivers`)으로 등록되므로, 그 훅을 함께 읽지
     * 않으면 살아 있는 플러그인 엔진까지 "사용 불가" 로 오판해 폴백된다.
     *
     * @scenario engine_source=plugin_scout_hook
     *
     * @effects scout_hook_registered_engine_is_available
     */
    public function test_plugin_registered_engine_is_available(): void
    {
        HookManager::addFilter('core.search.engine_drivers', function (array $drivers) {
            $drivers['meilisearch'] = \stdClass::class;

            return $drivers;
        });

        $this->assertTrue(
            $this->registry->isDriverAvailable('search', 'meilisearch'),
            '플러그인이 등록한 검색엔진이 사용 불가로 판정되었습니다.'
        );

        $ids = array_map(fn ($d) => $d['id'], $this->registry->getAvailableDrivers('search'));
        $this->assertContains('mysql-fulltext', $ids);
        $this->assertContains('meilisearch', $ids);
    }

    /**
     * 제거된 플러그인의 검색엔진은 사용 불가로 판정된다. (실패-먼저)
     *
     * @scenario engine_source=removed
     *
     * @effects removed_plugin_engine_is_unavailable
     */
    public function test_removed_plugin_engine_is_unavailable(): void
    {
        $this->assertFalse(
            $this->registry->isDriverAvailable('search', 'ghost_engine'),
            '등록되지 않은 검색엔진이 사용 가능으로 판정되었습니다.'
        );
    }

    /**
     * 일반 드라이버 훅으로 등록한 검색엔진도 함께 인식된다. (하위호환)
     *
     * @scenario engine_source=plugin_generic_hook
     *
     * @effects generic_hook_registered_engine_is_available
     */
    public function test_generic_driver_hook_still_recognized(): void
    {
        HookManager::addFilter('core.settings.available_search_drivers', function (array $drivers) {
            $drivers[] = ['id' => 'legacy_engine', 'label' => ['ko' => 'Legacy', 'en' => 'Legacy']];

            return $drivers;
        });

        $this->assertTrue($this->registry->isDriverAvailable('search', 'legacy_engine'));
    }

    /**
     * 죽은 검색엔진이 저장돼 있으면 폴백 가드가 실제로 코어 엔진으로 되돌린다. (실패-먼저)
     *
     * 앞의 케이스들은 레지스트리 판정층만 본다 — "search 가 등재됐다" 는 사실이지
     * "그래서 공개 검색이 살아남는다" 는 결과가 아니다. 이 케이스는 폴백 가드
     * (`CoreServiceProvider::applyExtensionDriverConfigs`)를 실제로 태워
     * `scout.driver` 가 되돌아가고 경고가 남는 것까지 확인한다.
     *
     * 로직을 테스트 안에서 재구현하지 않는다 — 재구현하면 프로덕션 코드가 바뀌어도
     * 테스트는 자기 사본을 검사하며 계속 통과한다.
     *
     * @scenario engine_source=removed
     *
     * @effects removed_plugin_engine_is_unavailable, search_config_key_matches_apply_site, search_default_fallback_is_core_engine
     */
    public function test_dead_search_engine_falls_back_to_core_engine_at_apply_site(): void
    {
        Config::set('scout.driver', 'ghost_engine');

        // 저장본에 죽은 엔진만 남은 상태 (= 플러그인 제거 후). 다른 카테고리는 미선택으로
        // 두어 이 케이스가 search 폴백만 태우게 한다. 실제 설정 파일은 건드리지 않는다.
        $configRepository = $this->createMock(JsonConfigRepository::class);
        $configRepository->method('getCategory')->willReturnCallback(
            fn (string $category) => $category === 'drivers'
                ? ['search_engine_driver' => 'ghost_engine']
                : []
        );
        $this->app->instance(JsonConfigRepository::class, $configRepository);

        Log::spy();

        $provider = new CoreServiceProvider($this->app);
        $applyDriverConfigs = new \ReflectionMethod($provider, 'applyExtensionDriverConfigs');
        $applyDriverConfigs->setAccessible(true);
        $applyDriverConfigs->invoke($provider);

        $this->assertSame(
            'mysql-fulltext',
            Config::get('scout.driver'),
            '죽은 검색엔진이 그대로 남았습니다 — 이 상태의 공개 검색은 500 으로 떨어집니다.'
        );

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message) => str_contains($message, 'ghost_engine')
                && str_contains($message, 'search')
                && str_contains($message, 'mysql-fulltext')
        )->atLeast()->once();
    }
}
