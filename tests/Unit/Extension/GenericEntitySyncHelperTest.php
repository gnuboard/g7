<?php

namespace Tests\Unit\Extension;

use App\Extension\Helpers\GenericEntitySyncHelper;
use App\Models\NotificationDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GenericEntitySyncHelper 회귀 방지.
 *
 * 단일 테이블·단일 unique 키·선택적 scope 필터 범용 패턴 검증.
 *
 * 실제 소비처: ShippingType, BoardType, ClaimReason, (다음 버전) Schedule.
 * 본 테스트는 `NotificationDefinition` (HasUserOverrides 적용 + 스코프 컬럼 존재)
 * 을 대리 대상으로 사용하여 helper 동작을 검증합니다.
 */
class GenericEntitySyncHelperTest extends TestCase
{
    use RefreshDatabase;

    private GenericEntitySyncHelper $helper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->helper = app(GenericEntitySyncHelper::class);
    }

    public function test_sync_creates_new_entity(): void
    {
        $entity = $this->helper->sync(
            NotificationDefinition::class,
            ['type' => 'generic-new'],
            [
                'hook_prefix' => 'core.auth',
                'extension_type' => 'core',
                'extension_identifier' => 'core',
                'name' => ['ko' => '신규'],
                'description' => ['ko' => '신규'],
                'channels' => ['mail'],
                'hooks' => [],
                'variables' => [],
                'is_active' => true,
                'is_default' => true,
            ]
        );

        $this->assertSame('generic-new', $entity->type);
        $this->assertDatabaseHas('notification_definitions', ['type' => 'generic-new']);
    }

    public function test_sync_preserves_user_overrides(): void
    {
        NotificationDefinition::create([
            'type' => 'generic-override',
            'hook_prefix' => 'core.auth',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '사용자 수정됨'],
            'description' => ['ko' => '설명'],
            'channels' => ['mail'],
            'hooks' => [],
            'variables' => [],
            'is_active' => true,
            'is_default' => true,
            'user_overrides' => ['name'],
        ]);

        $this->helper->sync(
            NotificationDefinition::class,
            ['type' => 'generic-override'],
            [
                'hook_prefix' => 'core.auth',
                'extension_type' => 'core',
                'extension_identifier' => 'core',
                'name' => ['ko' => '시더 기본값'],
                'description' => ['ko' => '새 설명'],
                'channels' => ['mail', 'database'],
                'hooks' => [],
                'variables' => [],
                'is_active' => true,
                'is_default' => true,
            ]
        );

        $entity = NotificationDefinition::where('type', 'generic-override')->first();

        $this->assertSame(['ko' => '사용자 수정됨'], $entity->name, 'user_overrides 필드 name 은 보존');
        $this->assertSame(['ko' => '새 설명'], $entity->description, '추적 외 필드는 갱신');
    }

    public function test_cleanupStale_with_scope_filter(): void
    {
        NotificationDefinition::create([
            'type' => 'keep',
            'hook_prefix' => 'x',
            'extension_type' => 'module',
            'extension_identifier' => 'sirsoft-ecommerce',
            'name' => ['ko' => '유지'],
            'description' => ['ko' => '유지'],
            'channels' => ['mail'],
            'hooks' => [],
            'variables' => [],
            'is_active' => true,
            'is_default' => true,
        ]);
        NotificationDefinition::create([
            'type' => 'remove',
            'hook_prefix' => 'x',
            'extension_type' => 'module',
            'extension_identifier' => 'sirsoft-ecommerce',
            'name' => ['ko' => '삭제'],
            'description' => ['ko' => '삭제'],
            'channels' => ['mail'],
            'hooks' => [],
            'variables' => [],
            'is_active' => true,
            'is_default' => true,
        ]);
        NotificationDefinition::create([
            'type' => 'other-module',
            'hook_prefix' => 'x',
            'extension_type' => 'module',
            'extension_identifier' => 'sirsoft-board',
            'name' => ['ko' => '타 모듈'],
            'description' => ['ko' => '타 모듈'],
            'channels' => ['mail'],
            'hooks' => [],
            'variables' => [],
            'is_active' => true,
            'is_default' => true,
        ]);

        $deleted = $this->helper->cleanupStale(
            NotificationDefinition::class,
            ['extension_type' => 'module', 'extension_identifier' => 'sirsoft-ecommerce'],
            'type',
            ['keep'],
        );

        $this->assertSame(1, $deleted);
        $this->assertDatabaseHas('notification_definitions', ['type' => 'keep']);
        $this->assertDatabaseMissing('notification_definitions', ['type' => 'remove']);
        $this->assertDatabaseHas('notification_definitions', ['type' => 'other-module'], null, 'scope 외 데이터 보호');
    }

    public function test_cleanupStale_deletes_even_with_user_overrides(): void
    {
        NotificationDefinition::create([
            'type' => 'stale-overridden',
            'hook_prefix' => 'x',
            'extension_type' => 'module',
            'extension_identifier' => 'm1',
            'name' => ['ko' => '사용자 수정'],
            'description' => ['ko' => '삭제 대상'],
            'channels' => ['mail'],
            'hooks' => [],
            'variables' => [],
            'is_active' => true,
            'is_default' => true,
            'user_overrides' => ['name'],
        ]);

        $deleted = $this->helper->cleanupStale(
            NotificationDefinition::class,
            ['extension_type' => 'module', 'extension_identifier' => 'm1'],
            'type',
            [],
        );

        $this->assertSame(1, $deleted);
        $this->assertDatabaseMissing('notification_definitions', ['type' => 'stale-overridden']);
    }

    public function test_cleanupStale_empty_scope_affects_whole_table(): void
    {
        NotificationDefinition::create([
            'type' => 'a',
            'hook_prefix' => 'x',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => 'a'],
            'description' => ['ko' => 'a'],
            'channels' => ['mail'],
            'hooks' => [],
            'variables' => [],
            'is_active' => true,
            'is_default' => true,
        ]);
        NotificationDefinition::create([
            'type' => 'b',
            'hook_prefix' => 'x',
            'extension_type' => 'module',
            'extension_identifier' => 'm1',
            'name' => ['ko' => 'b'],
            'description' => ['ko' => 'b'],
            'channels' => ['mail'],
            'hooks' => [],
            'variables' => [],
            'is_active' => true,
            'is_default' => true,
        ]);

        $deleted = $this->helper->cleanupStale(
            NotificationDefinition::class,
            [],
            'type',
            ['a'],
        );

        $this->assertSame(1, $deleted, '빈 scope 는 전체 테이블 대상');
        $this->assertDatabaseHas('notification_definitions', ['type' => 'a']);
        $this->assertDatabaseMissing('notification_definitions', ['type' => 'b']);
    }

    public function test_cleanupStale_noop_when_all_current(): void
    {
        NotificationDefinition::create([
            'type' => 'single',
            'hook_prefix' => 'x',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => 'single'],
            'description' => ['ko' => 'single'],
            'channels' => ['mail'],
            'hooks' => [],
            'variables' => [],
            'is_active' => true,
            'is_default' => true,
        ]);

        $deleted = $this->helper->cleanupStale(
            NotificationDefinition::class,
            ['extension_type' => 'core'],
            'type',
            ['single'],
        );

        $this->assertSame(0, $deleted);
        $this->assertDatabaseHas('notification_definitions', ['type' => 'single']);
    }
}
