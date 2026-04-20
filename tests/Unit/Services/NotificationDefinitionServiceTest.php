<?php

namespace Tests\Unit\Services;

use App\Models\NotificationDefinition;
use App\Services\NotificationDefinitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
