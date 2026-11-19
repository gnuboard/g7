<?php

namespace Tests\Feature\Api\Admin;

use App\Extension\HookManager;
use App\Repositories\JsonConfigRepository;
use Tests\Concerns\ActsAsSettingsAdmin;
use Tests\TestCase;

/**
 * 플러그인이 등록한 드라이버 저장 (스토리지와 캐시, 세션, 큐, 로그)
 *
 * 화면 셀렉트는 드라이버 카탈로그(코어 local·s3 + `core.settings.available_storage_drivers` 필터로 등록한 드라이버)를 그대로 그린다.
 * 그런데 저장 검증이 정적 목록(local, s3)이라 플러그인 드라이버를 골라 저장하면 422 였다. 연결 시험 요청도 같았다. 캐시·세션·큐·로그도
 * 같은 정적 목록이었다. 카탈로그에 없지만 예전부터 받던 코어 값(캐시 memcached)은 계속 받는다.
 */
class StoragePluginDriverSaveTest extends TestCase
{
    use ActsAsSettingsAdmin;

    /**
     * 스키마를 다시 만들지 않고 트랜잭션으로 격리합니다.
     *
     * @return void 반환값 없음
     */
    public function refreshDatabase(): void
    {
        $this->beginDatabaseTransaction();
    }

    /**
     * 관리자를 준비하고 플러그인 스토리지 드라이버를 등록합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->makeSettingsAdmin();
        HookManager::addFilter('core.settings.available_storage_drivers', function (array $drivers) {
            $drivers[] = ['id' => 'fx916-store', 'label' => ['ko' => '시험 저장소', 'en' => 'Test store'], 'provider' => 'fx-store'];

            return $drivers;
        });
        foreach (['cache', 'session', 'queue', 'log'] as $category) {
            HookManager::addFilter("core.settings.available_{$category}_drivers", function (array $drivers) use ($category) {
                $drivers[] = ['id' => "fx916-{$category}", 'label' => ['ko' => $category, 'en' => $category], 'provider' => 'fx-drivers'];

                return $drivers;
            });
        }
    }

    /**
     * 시험이 등록한 훅을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::resetAll();
        parent::tearDown();
    }

    /**
     * 플러그인이 등록한 스토리지 드라이버는 저장된다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=plugin_storage_driver
     *
     * @effects plugin_storage_driver_saved_through_catalog
     */
    public function test_plugin_storage_driver_saves(): void
    {
        $this->asSettingsAdmin()->postJson('/api/admin/settings', [
            '_tab' => 'drivers',
            'drivers' => $this->driversPayload(['storage_driver' => 'fx916-store']),
        ])->assertStatus(200);

        $this->assertSame('fx916-store', (new JsonConfigRepository)->getCategory('drivers')['storage_driver'] ?? null);
    }

    /**
     * 카탈로그에 없는 스토리지 드라이버는 422 다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=unregistered_storage_driver
     *
     * @effects unregistered_storage_driver_rejected_with_422
     */
    public function test_unregistered_storage_driver_is_rejected(): void
    {
        $this->asSettingsAdmin()->postJson('/api/admin/settings', [
            '_tab' => 'drivers',
            'drivers' => $this->driversPayload(['storage_driver' => 'fx916-ghost']),
        ])->assertStatus(422)->assertJsonValidationErrors(['drivers.storage_driver']);
    }

    /**
     * 연결 시험 요청도 같은 카탈로그 판정이다 — 등록 드라이버는 검증을 지나고, 미등록은 422.
     *
     * @return void 반환값 없음
     *
     * @scenario case=connection_test_request
     *
     * @effects connection_test_request_uses_driver_catalog
     */
    public function test_connection_test_request_uses_catalog(): void
    {
        $this->asSettingsAdmin()->postJson('/api/admin/settings/test-driver', [
            'storage_driver' => 'fx916-ghost',
        ])->assertStatus(422)->assertJsonValidationErrors(['storage_driver']);

        $response = $this->asSettingsAdmin()->postJson('/api/admin/settings/test-driver', [
            'storage_driver' => 'fx916-store',
        ]);
        $this->assertNotSame(422, $response->status(), '등록 드라이버가 검증에서 막혔습니다: '.$response->getContent());
    }

    /**
     * 캐시·세션·큐·로그도 플러그인 드라이버는 저장되고, 카탈로그에 없는 값은 그 필드 422 다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=other_driver_categories
     *
     * @effects cache_session_queue_log_drivers_follow_catalog
     */
    public function test_cache_session_queue_log_plugin_drivers_follow_catalog(): void
    {
        $this->asSettingsAdmin()->postJson('/api/admin/settings', [
            '_tab' => 'drivers',
            'drivers' => $this->driversPayload([
                'cache_driver' => 'fx916-cache',
                'session_driver' => 'fx916-session',
                'queue_driver' => 'fx916-queue',
                'log_driver' => 'fx916-log',
            ]),
        ])->assertStatus(200);
        $saved = (new JsonConfigRepository)->getCategory('drivers');
        $this->assertSame(['fx916-cache', 'fx916-session', 'fx916-queue', 'fx916-log'], [$saved['cache_driver'] ?? null, $saved['session_driver'] ?? null, $saved['queue_driver'] ?? null, $saved['log_driver'] ?? null]);

        foreach (['cache_driver', 'session_driver', 'queue_driver', 'log_driver'] as $field) {
            $this->asSettingsAdmin()->postJson('/api/admin/settings', [
                '_tab' => 'drivers',
                'drivers' => $this->driversPayload([$field => 'fx916-ghost']),
            ])->assertStatus(422)->assertJsonValidationErrors(["drivers.{$field}"]);
        }
    }

    /**
     * 카탈로그에 없지만 예전부터 받던 코어 값(캐시 memcached)은 검증에서 막지 않는다 — 서버에 확장이 없으면 사용 가능 판정이 사유와 함께 막는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=legacy_core_driver_value
     *
     * @effects legacy_core_driver_value_not_rejected_by_catalog
     */
    public function test_legacy_core_value_is_not_rejected_by_catalog(): void
    {
        $response = $this->asSettingsAdmin()->postJson('/api/admin/settings', [
            '_tab' => 'drivers',
            'drivers' => $this->driversPayload(['cache_driver' => 'memcached']),
        ]);

        if (extension_loaded('memcached')) {
            $response->assertStatus(200);
        } else {
            $response->assertStatus(422);
            $this->assertStringNotContainsString(__('validation.settings.cache_driver_invalid'), (string) json_encode($response->json('errors')), '카탈로그 판정이 아니라 사용 가능 판정으로 막혀야 합니다');
        }
    }
}
