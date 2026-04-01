<?php

namespace Modules\Sirsoft\Board\Tests\Unit;

use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Board\Services\BoardPermissionService;
use Modules\Sirsoft\Board\Services\BoardSettingsService;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * BoardSettingsService 단위 테스트
 *
 * ModuleSettingsInterface 구현을 검증합니다.
 */
class BoardSettingsServiceTest extends ModuleTestCase
{
    private BoardSettingsService $service;

    private string $storagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $permissionService = $this->createMock(BoardPermissionService::class);
        $this->service = new BoardSettingsService($permissionService);
        $this->storagePath = storage_path('app/modules/sirsoft-board/settings');

        // 테스트 전 저장소 정리
        if (File::isDirectory($this->storagePath)) {
            File::deleteDirectory($this->storagePath);
        }
    }

    protected function tearDown(): void
    {
        // 테스트 후 저장소 정리
        if (File::isDirectory($this->storagePath)) {
            File::deleteDirectory($this->storagePath);
        }

        parent::tearDown();
    }

    // ========================================
    // getSettingsDefaultsPath 테스트
    // ========================================

    /**
     * defaults.json 경로가 정상적으로 반환되는지 확인
     */
    public function test_get_settings_defaults_path_returns_valid_path(): void
    {
        $path = $this->service->getSettingsDefaultsPath();

        $this->assertNotNull($path);
        $this->assertFileExists($path);
        $this->assertStringEndsWith('defaults.json', $path);
    }

    // ========================================
    // getAllSettings 테스트
    // ========================================

    /**
     * 저장된 설정이 없으면 defaults.json의 기본값을 반환하는지 확인
     */
    public function test_get_all_settings_returns_defaults_when_no_saved_settings(): void
    {
        $settings = $this->service->getAllSettings();

        $this->assertIsArray($settings);
        $this->assertArrayHasKey('basic_defaults', $settings);
        $this->assertArrayHasKey('report_policy', $settings);
        $this->assertArrayHasKey('spam_security', $settings);
    }

    /**
     * basic_defaults 카테고리의 기본값이 올바른지 확인
     */
    public function test_get_all_settings_has_correct_basic_defaults(): void
    {
        $settings = $this->service->getAllSettings();
        $basicDefaults = $settings['basic_defaults'];

        $this->assertEquals('basic', $basicDefaults['type']);
        $this->assertEquals(20, $basicDefaults['per_page']);
        $this->assertEquals(15, $basicDefaults['per_page_mobile']);
        $this->assertEquals(5, $basicDefaults['max_reply_depth']);
        $this->assertEquals(10, $basicDefaults['max_comment_depth']);
        $this->assertTrue($basicDefaults['use_comment']);
        $this->assertTrue($basicDefaults['use_reply']);
        $this->assertTrue($basicDefaults['show_view_count']);
        $this->assertTrue($basicDefaults['notify_admin_on_post']);
        $this->assertTrue($basicDefaults['notify_author']);
    }

    /**
     * report_policy 카테고리의 기본값이 올바른지 확인
     */
    public function test_get_all_settings_has_correct_report_policy(): void
    {
        $settings = $this->service->getAllSettings();
        $reportPolicy = $settings['report_policy'];

        $this->assertEquals(5, $reportPolicy['auto_hide_threshold']);
        $this->assertEquals('both', $reportPolicy['auto_hide_target']);
        $this->assertEquals(10, $reportPolicy['daily_report_limit']);
    }

    /**
     * spam_security 카테고리의 기본값이 올바른지 확인
     */
    public function test_get_all_settings_has_correct_spam_security(): void
    {
        $settings = $this->service->getAllSettings();
        $spamSecurity = $settings['spam_security'];

        $this->assertEquals(0, $spamSecurity['post_cooldown_seconds']);
        $this->assertEquals(86400, $spamSecurity['view_count_cache_ttl']);
    }

    /**
     * blocked_keywords가 basic_defaults에 포함되는지 확인
     */
    public function test_get_all_settings_has_blocked_keywords_in_basic_defaults(): void
    {
        $settings = $this->service->getAllSettings();
        $basicDefaults = $settings['basic_defaults'];

        $this->assertArrayHasKey('blocked_keywords', $basicDefaults);
        $this->assertIsArray($basicDefaults['blocked_keywords']);
        $this->assertNotEmpty($basicDefaults['blocked_keywords']);
    }

    /**
     * 결과가 캐싱되는지 확인 (두 번째 호출 시 동일 객체)
     */
    public function test_get_all_settings_caches_result(): void
    {
        $first = $this->service->getAllSettings();
        $second = $this->service->getAllSettings();

        $this->assertSame($first, $second);
    }

    // ========================================
    // getSettings (카테고리별) 테스트
    // ========================================

    /**
     * 특정 카테고리 설정을 조회할 수 있는지 확인
     */
    public function test_get_settings_returns_category_settings(): void
    {
        $basicDefaults = $this->service->getSettings('basic_defaults');

        $this->assertIsArray($basicDefaults);
        $this->assertArrayHasKey('per_page', $basicDefaults);
        $this->assertEquals(20, $basicDefaults['per_page']);
    }

    /**
     * 존재하지 않는 카테고리는 빈 배열 반환
     */
    public function test_get_settings_returns_empty_for_unknown_category(): void
    {
        $result = $this->service->getSettings('nonexistent');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // ========================================
    // getSetting (단일 키) 테스트
    // ========================================

    /**
     * dot notation으로 설정값을 조회할 수 있는지 확인
     */
    public function test_get_setting_with_dot_notation(): void
    {
        $perPage = $this->service->getSetting('basic_defaults.per_page');

        $this->assertEquals(20, $perPage);
    }

    /**
     * 존재하지 않는 키에 대해 기본값 반환
     */
    public function test_get_setting_returns_default_for_unknown_key(): void
    {
        $result = $this->service->getSetting('basic_defaults.nonexistent', 'fallback');

        $this->assertEquals('fallback', $result);
    }

    // ========================================
    // setSetting 테스트
    // ========================================

    /**
     * 개별 설정값을 저장할 수 있는지 확인
     */
    public function test_set_setting_saves_value(): void
    {
        $result = $this->service->setSetting('basic_defaults.per_page', 30);

        $this->assertTrue($result);

        // 캐시 초기화 후 다시 조회
        $this->service->clearCache();
        $perPage = $this->service->getSetting('basic_defaults.per_page');
        $this->assertEquals(30, $perPage);
    }

    /**
     * 설정이 파일에 실제로 저장되는지 확인
     */
    public function test_set_setting_persists_to_file(): void
    {
        $this->service->setSetting('report_policy.auto_hide_threshold', 10);

        $filePath = $this->storagePath.'/report_policy.json';
        $this->assertFileExists($filePath);

        $content = json_decode(File::get($filePath), true);
        $this->assertEquals(10, $content['auto_hide_threshold']);
    }

    // ========================================
    // saveSettings (전체 저장) 테스트
    // ========================================

    /**
     * 여러 카테고리를 한 번에 저장할 수 있는지 확인
     */
    public function test_save_settings_saves_multiple_categories(): void
    {
        $result = $this->service->saveSettings([
            'basic_defaults' => [
                'per_page' => 50,
                'type' => 'gallery',
            ],
            'report_policy' => [
                'auto_hide_threshold' => 3,
            ],
        ]);

        $this->assertTrue($result);

        // 캐시 초기화 후 검증
        $this->service->clearCache();
        $settings = $this->service->getAllSettings();

        $this->assertEquals(50, $settings['basic_defaults']['per_page']);
        $this->assertEquals('gallery', $settings['basic_defaults']['type']);
        $this->assertEquals(3, $settings['report_policy']['auto_hide_threshold']);
    }

    /**
     * _meta 등 메타 키는 저장하지 않는지 확인
     */
    public function test_save_settings_ignores_meta_keys(): void
    {
        $result = $this->service->saveSettings([
            '_meta' => ['version' => '2.0.0'],
            '_tab' => 'basic',
            'basic_defaults' => ['per_page' => 25],
        ]);

        $this->assertTrue($result);

        // _meta 파일이 생성되지 않아야 함
        $this->assertFileDoesNotExist($this->storagePath.'/_meta.json');
        $this->assertFileDoesNotExist($this->storagePath.'/_tab.json');
    }

    /**
     * 카테고리 값이 배열이 아닌 경우 무시되는지 확인
     */
    public function test_save_settings_ignores_non_array_category_values(): void
    {
        $result = $this->service->saveSettings([
            'basic_defaults' => ['per_page' => 25],
            'invalid_key' => 'string_value',
        ]);

        $this->assertTrue($result);
        $this->assertFileDoesNotExist($this->storagePath.'/invalid_key.json');
    }

    /**
     * 저장 후 캐시가 초기화되는지 확인
     */
    public function test_save_settings_clears_cache(): void
    {
        // 캐시를 채움
        $this->service->getAllSettings();

        // 저장 후 캐시가 초기화되어야 함
        $this->service->saveSettings([
            'basic_defaults' => ['per_page' => 99],
        ]);

        // 새로운 인스턴스처럼 동작해야 함
        $settings = $this->service->getAllSettings();
        $this->assertEquals(99, $settings['basic_defaults']['per_page']);
    }

    // ========================================
    // getFrontendSettings 테스트
    // ========================================

    /**
     * 프론트엔드 설정이 올바른 구조로 반환되는지 확인
     */
    public function test_get_frontend_settings_returns_exposed_categories(): void
    {
        $frontendSettings = $this->service->getFrontendSettings();

        $this->assertIsArray($frontendSettings);
        // defaults.json에서 모든 카테고리가 expose: true, fields: {} 이므로 전체 노출
        $this->assertArrayHasKey('basic_defaults', $frontendSettings);
        $this->assertArrayHasKey('report_policy', $frontendSettings);
        $this->assertArrayHasKey('spam_security', $frontendSettings);
    }

    /**
     * 프론트엔드 설정에 실제 값이 포함되는지 확인
     */
    public function test_get_frontend_settings_contains_actual_values(): void
    {
        $frontendSettings = $this->service->getFrontendSettings();

        $this->assertEquals(20, $frontendSettings['basic_defaults']['per_page']);
        $this->assertNotEmpty($frontendSettings['basic_defaults']['blocked_keywords']);
    }

    // ========================================
    // clearCache 테스트
    // ========================================

    /**
     * 캐시 초기화 후 fresh한 데이터를 가져오는지 확인
     */
    public function test_clear_cache_forces_fresh_load(): void
    {
        // 첫 번째 조회로 캐시 생성
        $first = $this->service->getAllSettings();

        // 파일 직접 수정 (캐시 우회)
        $this->service->setSetting('basic_defaults.per_page', 77);

        // 캐시 초기화
        $this->service->clearCache();

        // 수정된 값이 반영되어야 함
        $settings = $this->service->getAllSettings();
        $this->assertEquals(77, $settings['basic_defaults']['per_page']);
    }

    // ========================================
    // 저장 후 기본값 병합 테스트
    // ========================================

    /**
     * 저장된 설정이 기본값과 올바르게 병합되는지 확인
     */
    public function test_saved_settings_merge_with_defaults(): void
    {
        // 일부 필드만 저장
        $this->service->saveSettings([
            'basic_defaults' => ['per_page' => 40],
        ]);

        $this->service->clearCache();
        $settings = $this->service->getAllSettings();

        // 저장된 값 반영
        $this->assertEquals(40, $settings['basic_defaults']['per_page']);

        // 저장되지 않은 기본값은 유지
        $this->assertEquals('basic', $settings['basic_defaults']['type']);
        $this->assertEquals(15, $settings['basic_defaults']['per_page_mobile']);
        $this->assertTrue($settings['basic_defaults']['use_comment']);
    }

    /**
     * default_board_permissions 기본값이 정상적으로 포함되는지 확인
     */
    public function test_basic_defaults_includes_default_board_permissions(): void
    {
        $settings = $this->service->getAllSettings();
        $permissions = $settings['basic_defaults']['default_board_permissions'] ?? null;

        $this->assertNotNull($permissions);
        $this->assertIsArray($permissions);
        $this->assertArrayHasKey('posts.read', $permissions);
        $this->assertContains('admin', $permissions['posts.read']);
        $this->assertContains('guest', $permissions['posts.read']);
    }
}
