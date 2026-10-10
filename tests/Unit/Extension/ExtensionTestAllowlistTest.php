<?php

namespace Tests\Unit\Extension;

use App\Contracts\Extension\CacheInterface;
use App\Extension\Testing\ExtensionTestAllowlist;
use Tests\TestCase;

/**
 * ExtensionTestAllowlist 단위 테스트
 *
 * 테스트 환경 확장 격리 allowlist 컨테이너의 set/reset/isAllowed/isActive 동작을 검증합니다.
 */
class ExtensionTestAllowlistTest extends TestCase
{
    /**
     * 각 테스트 후 allowlist 를 깨끗한 상태로 되돌립니다.
     *
     * (TestCase::tearDown 이 이미 reset 하지만, 본 테스트는 set 동작을
     *  직접 검증하므로 명시적으로 한 번 더 보장합니다.)
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        ExtensionTestAllowlist::reset();

        parent::tearDown();
    }

    /**
     * `plugins/`·`modules/` 접두사로 확장 유형을 나눠 각각 허용한다.
     *
     * @effects set_classifies_plugin_and_module_by_path_prefix
     *
     * @return void 반환값 없음
     */
    public function test_set_classifies_plugin_and_module_by_path_prefix(): void
    {
        ExtensionTestAllowlist::set([
            'plugins/sirsoft-gdpr',
            'modules/sirsoft-ecommerce',
        ]);

        $this->assertTrue(ExtensionTestAllowlist::isAllowed('plugin', 'sirsoft-gdpr'));
        $this->assertTrue(ExtensionTestAllowlist::isAllowed('module', 'sirsoft-ecommerce'));
    }

    /**
     * 목록에 없는 확장, 다른 유형으로 등록된 같은 이름, 알 수 없는 유형은 허용하지 않는다.
     *
     * @effects set_classifies_plugin_and_module_by_path_prefix
     *
     * @return void 반환값 없음
     */
    public function test_is_allowed_returns_false_for_extension_outside_allowlist(): void
    {
        ExtensionTestAllowlist::set(['plugins/sirsoft-gdpr']);

        $this->assertFalse(ExtensionTestAllowlist::isAllowed('plugin', 'sirsoft-marketing'));
        $this->assertFalse(ExtensionTestAllowlist::isAllowed('module', 'sirsoft-gdpr'));
        $this->assertFalse(ExtensionTestAllowlist::isAllowed('unknown', 'sirsoft-gdpr'));
    }

    /**
     * 항목 끝의 슬래시를 떼고 같은 확장으로 인정한다.
     *
     * @effects set_normalizes_trailing_slash
     *
     * @return void 반환값 없음
     */
    public function test_set_normalizes_trailing_slash(): void
    {
        ExtensionTestAllowlist::set(['plugins/sirsoft-gdpr/']);

        $this->assertTrue(ExtensionTestAllowlist::isAllowed('plugin', 'sirsoft-gdpr'));
    }

    /**
     * 유형 접두사가 없거나 대상이 아닌 항목(템플릿)은 무시한다.
     *
     * @effects set_ignores_prefixless_entries
     *
     * @return void 반환값 없음
     */
    public function test_set_ignores_prefixless_entries(): void
    {
        ExtensionTestAllowlist::set(['sirsoft-gdpr', 'templates/sirsoft-basic']);

        $this->assertFalse(ExtensionTestAllowlist::isAllowed('plugin', 'sirsoft-gdpr'));
        $this->assertFalse(ExtensionTestAllowlist::isAllowed('module', 'sirsoft-gdpr'));
    }

    /**
     * testing 환경에서 allowlist 를 지정하면 가드가 켜진다.
     *
     * @effects allowlist_active_when_testing_and_configured
     *
     * @return void 반환값 없음
     */
    public function test_is_active_returns_true_when_testing_env_and_configured(): void
    {
        ExtensionTestAllowlist::set([]);

        // phpunit.xml 의 APP_ENV=testing + 명시적 set → 가드 활성
        $this->assertTrue(ExtensionTestAllowlist::isActive());
    }

    /**
     * 빈 allowlist(core-only 테스트)도 가드를 켜고 모든 확장을 막는다.
     *
     * @effects empty_allowlist_is_active_and_blocks_all_extensions
     *
     * @return void 반환값 없음
     */
    public function test_empty_allowlist_is_active_and_blocks_all_extensions(): void
    {
        // 빈 allowlist = core-only 테스트 → 가드는 활성, 모든 확장이 차단됨
        ExtensionTestAllowlist::set([]);

        $this->assertTrue(ExtensionTestAllowlist::isActive());
        $this->assertFalse(ExtensionTestAllowlist::isAllowed('plugin', 'sirsoft-gdpr'));
    }

    /**
     * reset 하면 가드가 꺼지고 이전 허용 목록도 사라진다.
     *
     * @effects reset_deactivates_guard
     *
     * @return void 반환값 없음
     */
    public function test_reset_deactivates_guard(): void
    {
        ExtensionTestAllowlist::set(['plugins/sirsoft-gdpr']);
        ExtensionTestAllowlist::reset();

        $this->assertFalse(ExtensionTestAllowlist::isActive());
        $this->assertFalse(ExtensionTestAllowlist::isAllowed('plugin', 'sirsoft-gdpr'));
    }

    /**
     * set 을 다시 부르면 이전 목록을 합치지 않고 교체한다.
     *
     * @effects set_recall_replaces_previous_allowlist
     *
     * @return void 반환값 없음
     */
    public function test_set_recall_replaces_previous_allowlist(): void
    {
        ExtensionTestAllowlist::set(['plugins/sirsoft-gdpr']);
        ExtensionTestAllowlist::set(['plugins/sirsoft-marketing']);

        $this->assertFalse(ExtensionTestAllowlist::isAllowed('plugin', 'sirsoft-gdpr'));
        $this->assertTrue(ExtensionTestAllowlist::isAllowed('plugin', 'sirsoft-marketing'));
    }

    /**
     * testing 이 아닌 환경에서는 목록을 지정해도 가드가 꺼져 확장을 전부 싣는다.
     *
     * @effects allowlist_inactive_when_app_env_not_testing, production_env_keeps_full_extension_loading
     *
     * @return void 반환값 없음
     */
    public function test_is_active_returns_false_in_non_testing_env_even_when_configured(): void
    {
        ExtensionTestAllowlist::set(['plugins/sirsoft-gdpr']);

        // 운영/개발 환경에서는 가드가 비활성 → 확장 전수 로딩 보존
        $this->app['env'] = 'production';

        $this->assertFalse(ExtensionTestAllowlist::isActive());

        // 후속 테스트 보호: 환경 복원
        $this->app['env'] = 'testing';
    }

    /**
     * 테스트 프로세스의 캐시는 개발 사이트와 공유되지 않는 프로세스 전용(array) 스토어다.
     *
     * 확장 활성 목록(`ext.plugins.active_identifiers`)·미들웨어 인덱스는 캐시에서 먼저 읽힌다.
     * `php artisan test` 는 `.env.testing` 을 먼저 적재하는데, 거기 `CACHE_STORE=file` 이면 개발
     * 사이트와 같은 `storage/framework/cache/data` 를 읽어 개발 DB 의 활성 목록이 테스트로 새고,
     * allowlist 밖이라 바인딩이 없는 플러그인 미들웨어가 붙어 HTTP 테스트가 전부 500 이 된다.
     * `tests/bootstrap.php` 의 「캐시 스토어 격리」 블록이 빠지면 이 테스트가 실패한다.
     *
     * @effects tests_use_process_local_cache_store_not_shared_with_dev
     *
     * @return void 반환값 없음
     */
    public function test_tests_use_process_local_cache_store_not_shared_with_dev(): void
    {
        $this->assertSame('array', config('cache.default'));
        $this->assertSame('array', app(CacheInterface::class)->getStore());
    }
}
