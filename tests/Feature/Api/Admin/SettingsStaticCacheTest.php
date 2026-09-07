<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Extension\Traits\ClearsTemplateCaches;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\ExtensionStaticCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 초기 화면 정적 파일(정적 게시) 상태 조회 + 관리자 수동 복구 API 테스트 (#651 U1·U2·U6).
 *
 * 캐시 버전이 만료로 재생성되지 않으므로(영구 번호) 재게시 누락은 무기한 stale 이 된다 —
 * 관리자 화면의 [지금 다시 만들기] 가 그 안전망이다. 이 테스트는 그 통로(권한 경계 · 진단 결과
 * 200 규약 · 버전 증가 + 게시)를 잠근다.
 */
class SettingsStaticCacheTest extends TestCase
{
    use RefreshDatabase;

    private const VERSION = 987650000;

    private string $isolatedPublicPath;

    protected function setUp(): void
    {
        parent::setUp();

        // 실 게시 트리(public/build/ext) 격리 — 운영 중 사이트의 게시본을 건드리지 않는다
        $this->isolatedPublicPath = storage_path('framework/testing/public-api-'.getmypid());
        File::ensureDirectoryExists($this->isolatedPublicPath);
        $this->app->usePublicPath($this->isolatedPublicPath);

        ClearsTemplateCaches::resetExtensionCacheStoreMemo();
        Cache::put('g7:core:ext.cache_version', self::VERSION);
        Cache::forget('g7:core:ext.static.publish_failure');
        Cache::lock('ext-static.publish.'.self::VERSION, 300)->forceRelease();

        ExtensionStaticCacheService::resetPublishScheduleForTesting();
        File::deleteDirectory(public_path('build/ext'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(public_path('build/ext'));
        File::deleteDirectory($this->isolatedPublicPath);
        ExtensionStaticCacheService::resetPublishScheduleForTesting();

        parent::tearDown();
    }

    /**
     * 권한을 가진 관리자를 만들고 Bearer 토큰을 돌려줍니다.
     *
     * @param  array<int, string>  $permissions  권한 식별자 목록
     * @return string plainText 토큰
     */
    private function tokenWith(array $permissions): string
    {
        $user = User::factory()->create();

        $permissionIds = [];
        foreach ($permissions as $identifier) {
            $permissionIds[] = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'description' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ]
            )->id;
        }

        $role = Role::create([
            'identifier' => 'static_cache_test_'.uniqid(),
            'name' => json_encode(['ko' => '테스트', 'en' => 'Test']),
            'description' => json_encode(['ko' => '테스트', 'en' => 'Test']),
            'is_active' => true,
        ]);
        $role->permissions()->sync($permissionIds);

        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'description' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );

        $user->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh()->createToken('test')->plainTextToken;
    }

    /**
     * 상태 조회는 CLI 와 같은 보고서 필드를 돌려준다.
     *
     * @scenario permission=granted, publishable=non_production, outcome=success
     *
     * @effects status_endpoint_returns_report_fields
     */
    public function test_status_returns_report_fields(): void
    {
        $response = $this->withToken($this->tokenWith(['core.settings.read']))
            ->getJson('/api/admin/settings/static-cache');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.version', self::VERSION)
            ->assertJsonPath('data.published', false)
            ->assertJsonPath('data.publishable', false)
            ->assertJsonStructure(['data' => [
                'enabled', 'publishable', 'environment', 'version', 'published', 'files',
                'published_at', 'tree_writable', 'process_user', 'failure', 'retained_versions',
            ]]);

        // 응답 메시지가 키 그대로 새어 나가지 않는다
        $this->assertStringNotContainsString('settings.', (string) $response->json('message'));
    }

    /**
     * 프로덕션에서 재게시하면 버전이 오르고 새 버전이 게시된다.
     *
     * @scenario permission=granted, publishable=production_enabled, outcome=success
     *
     * @effects republish_bumps_version_and_publishes
     */
    public function test_republish_bumps_version_and_publishes_in_production(): void
    {
        $this->app['env'] = 'production';
        ExtensionStaticCacheService::fakeRootProcessForTesting(false);

        $response = $this->withToken($this->tokenWith(['core.settings.read', 'core.settings.update']))
            ->postJson('/api/admin/settings/static-cache/republish');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.republished', true)
            ->assertJsonPath('data.published', true)
            ->assertJsonPath('data.publishable', true)
            ->assertJsonPath('data.previous_version', self::VERSION);

        $newVersion = (int) $response->json('data.version');
        $this->assertGreaterThan(self::VERSION, $newVersion, '재게시가 캐시 버전을 올리지 않았다');
        $this->assertTrue((bool) $response->json('data.version_changed'));
        $this->assertFileExists(public_path('build/ext/'.$newVersion.'/manifest.json'));
    }

    /**
     * 비프로덕션에서는 bump 하지 않고 200 + `republished=false` 로 사유를 돌려준다 (진단 결과 규약).
     *
     * @scenario permission=granted, publishable=non_production, outcome=publish_failed_marker
     *
     * @effects republish_disabled_when_not_publishable
     */
    public function test_republish_outside_production_is_a_diagnostic_result_not_an_error(): void
    {
        $response = $this->withToken($this->tokenWith(['core.settings.read', 'core.settings.update']))
            ->postJson('/api/admin/settings/static-cache/republish');

        $response->assertOk()
            ->assertJsonPath('data.republished', false)
            ->assertJsonPath('data.publishable', false)
            ->assertJsonPath('data.reason', 'not_production')
            ->assertJsonPath('data.version', self::VERSION);

        $this->assertSame(self::VERSION, (int) Cache::get('g7:core:ext.cache_version'), '게시가 쓰이지 않는 환경에서 버전을 올렸다');
    }

    /**
     * kill-switch 가 꺼져 있으면 `enabled=false` 이고 재게시는 `disabled` 사유로 거절된다.
     *
     * @scenario permission=granted, publishable=kill_switch_off, outcome=publish_failed_marker
     *
     * @effects republish_disabled_when_not_publishable
     */
    public function test_kill_switch_off_reports_disabled(): void
    {
        config(['core.static_cache.enabled' => false]);
        $this->app['env'] = 'production';

        $token = $this->tokenWith(['core.settings.read', 'core.settings.update']);

        $this->withToken($token)->getJson('/api/admin/settings/static-cache')
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.publishable', false);

        $this->withToken($token)->postJson('/api/admin/settings/static-cache/republish')
            ->assertOk()
            ->assertJsonPath('data.republished', false)
            ->assertJsonPath('data.reason', 'disabled');
    }

    /**
     * 읽기 권한만으로는 재게시할 수 없다.
     *
     * @scenario permission=denied, publishable=production_enabled, outcome=success
     *
     * @effects republish_denied_without_settings_update_permission
     */
    public function test_republish_requires_settings_update_permission(): void
    {
        $this->withToken($this->tokenWith(['core.settings.read']))
            ->postJson('/api/admin/settings/static-cache/republish')
            ->assertStatus(403);
    }

    /**
     * 비인증 요청은 401 이다.
     *
     * @scenario permission=denied, publishable=non_production, outcome=success
     *
     * @effects republish_denied_without_settings_update_permission
     */
    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/admin/settings/static-cache')->assertStatus(401);
        $this->postJson('/api/admin/settings/static-cache/republish')->assertStatus(401);
    }
}
