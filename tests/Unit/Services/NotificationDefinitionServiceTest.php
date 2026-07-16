<?php

namespace Tests\Unit\Services;

use App\Models\NotificationDefinition;
use App\Services\NotificationDefinitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * NotificationDefinitionService 테스트
 *
 * 알림 정의 조회, 캐싱, 수정 동작을 검증합니다.
 */
class NotificationDefinitionServiceTest extends TestCase
{
    use RefreshDatabase;

    private NotificationDefinitionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(NotificationDefinitionService::class);
    }

    /**
     * resolve()가 활성 정의를 반환하는지 확인
     */
    public function test_resolve_returns_active_definition(): void
    {
        NotificationDefinition::create([
            'type' => 'welcome',
            'hook_prefix' => 'core.auth',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '환영', 'en' => 'Welcome'],
            'variables' => [],
            'channels' => ['mail'],
            'hooks' => ['core.auth.after_register'],
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->service->invalidateCache('welcome');

        $result = $this->service->resolve('welcome');

        $this->assertNotNull($result);
        $this->assertEquals('welcome', $result->type);
    }

    /**
     * resolve()가 비활성 정의를 반환하지 않는지 확인
     */
    public function test_resolve_returns_null_for_inactive_definition(): void
    {
        NotificationDefinition::create([
            'type' => 'inactive_type',
            'hook_prefix' => 'core.auth',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '비활성', 'en' => 'Inactive'],
            'variables' => [],
            'channels' => ['mail'],
            'hooks' => [],
            'is_active' => false,
            'is_default' => true,
        ]);

        $this->service->invalidateCache('inactive_type');

        $result = $this->service->resolve('inactive_type');

        $this->assertNull($result);
    }

    /**
     * getAllActive()가 활성 정의만 반환하는지 확인
     */
    public function test_get_all_active_returns_only_active(): void
    {
        NotificationDefinition::create([
            'type' => 'active_one',
            'hook_prefix' => 'core.auth',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '활성1'],
            'variables' => [],
            'channels' => ['mail'],
            'hooks' => [],
            'is_active' => true,
            'is_default' => true,
        ]);

        NotificationDefinition::create([
            'type' => 'inactive_one',
            'hook_prefix' => 'core.auth',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '비활성1'],
            'variables' => [],
            'channels' => ['mail'],
            'hooks' => [],
            'is_active' => false,
            'is_default' => true,
        ]);

        $this->service->invalidateAllCache();

        $result = $this->service->getAllActive();

        $this->assertCount(1, $result);
        $this->assertEquals('active_one', $result->first()->type);
    }

    /**
     * toggleActive()가 활성 상태를 반전시키는지 확인
     */
    public function test_toggle_active_inverts_status(): void
    {
        $definition = NotificationDefinition::create([
            'type' => 'toggle_test',
            'hook_prefix' => 'core.auth',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '토글 테스트'],
            'variables' => [],
            'channels' => ['mail'],
            'hooks' => [],
            'is_active' => true,
            'is_default' => true,
        ]);

        $result = $this->service->toggleActive($definition);

        $this->assertFalse($result->is_active);
    }

    /**
     * updateDefinition()이 채널과 훅을 수정하는지 확인
     */
    public function test_update_definition_modifies_channels_and_hooks(): void
    {
        $definition = NotificationDefinition::create([
            'type' => 'update_test',
            'hook_prefix' => 'core.auth',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '수정 테스트'],
            'variables' => [],
            'channels' => ['mail'],
            'hooks' => ['core.auth.after_register'],
            'is_active' => true,
            'is_default' => true,
        ]);

        $result = $this->service->updateDefinition($definition, [
            'channels' => ['mail', 'database'],
            'hooks' => ['core.auth.after_register', 'core.auth.after_login'],
        ]);

        $this->assertEquals(['mail', 'database'], $result->channels);
        $this->assertEquals(['core.auth.after_register', 'core.auth.after_login'], $result->hooks);
    }

    /**
     * getAllActive() 캐시 히트 시 notification_definitions DB 조회가 0건인지 확인.
     *
     * 서빙 API 부팅 비용 최적화 검증 (계획서 §2-1 나): 동적 훅(알림) 등록 경로
     * (NotificationHookListener::registerDynamicHooks → getAllActive)는 이미
     * `['notification']` 태그로 캐시되어 있으므로, 첫 요청(캐시 워밍) 이후에는
     * 매 요청 DB 조회가 발생하지 않아야 한다 (37ms 캐시 미스는 첫 요청 1회 비용).
     *
     * @scenario cache_state=present_but_testing_env, listener_type=action_sync, regeneration_trigger=extension_update, registration_source=plugin
     *
     * @effects notification_getAllActive_cache_hit_issues_zero_db_query
     */
    public function test_get_all_active_cache_hit_issues_zero_db_query(): void
    {
        NotificationDefinition::create([
            'type' => 'cache_hit_probe',
            'hook_prefix' => 'core.auth',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '캐시 히트 검증'],
            'variables' => [],
            'channels' => ['mail'],
            'hooks' => ['core.auth.after_register'],
            'is_active' => true,
            'is_default' => true,
        ]);

        // 1차 호출: 캐시 워밍 (DB 조회 발생)
        $this->service->invalidateAllCache();
        $this->service->getAllActive();

        // 2차 호출: 캐시 히트 → notification_definitions 조회 0건이어야 함
        DB::enableQueryLog();
        $this->service->getAllActive();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $definitionQueries = array_filter(
            $queries,
            fn ($q) => str_contains($q['query'], 'notification_definitions')
        );

        $this->assertCount(
            0,
            $definitionQueries,
            '캐시 히트 시 notification_definitions DB 조회가 발생하지 않아야 합니다 (동적 훅 등록 비용 제거)'
        );
    }
}
