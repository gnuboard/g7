<?php

namespace Tests\Unit\Extension;

use App\Extension\Helpers\NotificationSyncHelper;
use App\Models\NotificationDefinition;
use App\Models\NotificationTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NotificationSyncHelper 의 cleanupStale* 회귀 방지 테스트.
 *
 * 정책:
 *  - seeder 에 없는 (extension_type + extension_identifier + type) 조합의 definition 삭제
 *  - definition 삭제 시 FK cascade 로 연관 template 도 자동 정리
 *  - definition 은 유지되지만 channel 이 재구성된 경우: 제거된 channel 의 template 삭제
 *  - user_overrides 무관 삭제
 */
class NotificationSyncHelperTest extends TestCase
{
    use RefreshDatabase;

    private NotificationSyncHelper $helper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->helper = app(NotificationSyncHelper::class);
    }

    public function test_cleanupStaleDefinitions_deletes_definitions_not_in_seeder_scope(): void
    {
        NotificationDefinition::create([
            'type' => 'in-seeder',
            'hook_prefix' => 'core.auth',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '유지'],
            'description' => ['ko' => '유지'],
            'channels' => ['mail'],
            'hooks' => [],
            'variables' => [],
            'is_active' => true,
            'is_default' => true,
        ]);
        NotificationDefinition::create([
            'type' => 'not-in-seeder',
            'hook_prefix' => 'core.auth',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '삭제'],
            'description' => ['ko' => '삭제'],
            'channels' => ['mail'],
            'hooks' => [],
            'variables' => [],
            'is_active' => true,
            'is_default' => true,
        ]);

        $deleted = $this->helper->cleanupStaleDefinitions(
            'core',
            'core',
            ['in-seeder'],
        );

        $this->assertSame(1, $deleted);
        $this->assertDatabaseHas('notification_definitions', ['type' => 'in-seeder']);
        $this->assertDatabaseMissing('notification_definitions', ['type' => 'not-in-seeder']);
    }

    public function test_cleanupStaleDefinitions_deletes_even_with_user_overrides(): void
    {
        NotificationDefinition::create([
            'type' => 'stale-with-overrides',
            'hook_prefix' => 'core.auth',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '유저 수정 있음'],
            'description' => ['ko' => '삭제 대상'],
            'channels' => ['mail'],
            'hooks' => [],
            'variables' => [],
            'is_active' => true,
            'is_default' => true,
            'user_overrides' => ['name'],
        ]);

        $deleted = $this->helper->cleanupStaleDefinitions('core', 'core', []);

        $this->assertSame(1, $deleted);
        $this->assertDatabaseMissing('notification_definitions', ['type' => 'stale-with-overrides']);
    }

    public function test_cleanupStaleDefinitions_scope_isolation_protects_other_extensions(): void
    {
        NotificationDefinition::create([
            'type' => 'core-type',
            'hook_prefix' => 'core.auth',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => 'core'],
            'description' => ['ko' => 'core'],
            'channels' => ['mail'],
            'hooks' => [],
            'variables' => [],
            'is_active' => true,
            'is_default' => true,
        ]);
        NotificationDefinition::create([
            'type' => 'module-type',
            'hook_prefix' => 'core.auth',
            'extension_type' => 'module',
            'extension_identifier' => 'sirsoft-board',
            'name' => ['ko' => 'module'],
            'description' => ['ko' => 'module'],
            'channels' => ['mail'],
            'hooks' => [],
            'variables' => [],
            'is_active' => true,
            'is_default' => true,
        ]);

        $deleted = $this->helper->cleanupStaleDefinitions('core', 'core', []);

        $this->assertSame(1, $deleted, '코어 scope 만 삭제, 모듈 scope 는 보호');
        $this->assertDatabaseMissing('notification_definitions', ['type' => 'core-type']);
        $this->assertDatabaseHas('notification_definitions', ['type' => 'module-type']);
    }

    public function test_cleanupStaleTemplates_deletes_by_channel_diff(): void
    {
        $def = NotificationDefinition::create([
            'type' => 'with-templates',
            'hook_prefix' => 'core.auth',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '정의'],
            'description' => ['ko' => '정의'],
            'channels' => ['mail', 'database'],
            'hooks' => [],
            'variables' => [],
            'is_active' => true,
            'is_default' => true,
        ]);

        NotificationTemplate::create([
            'definition_id' => $def->id,
            'channel' => 'mail',
            'subject' => ['ko' => '유지'],
            'body' => ['ko' => '유지'],
            'is_active' => true,
            'is_default' => true,
        ]);
        NotificationTemplate::create([
            'definition_id' => $def->id,
            'channel' => 'fcm',
            'subject' => ['ko' => '삭제'],
            'body' => ['ko' => '삭제'],
            'is_active' => true,
            'is_default' => true,
        ]);

        $deleted = $this->helper->cleanupStaleTemplates($def->id, ['mail']);

        $this->assertSame(1, $deleted);
        $this->assertDatabaseHas('notification_templates', ['definition_id' => $def->id, 'channel' => 'mail']);
        $this->assertDatabaseMissing('notification_templates', ['definition_id' => $def->id, 'channel' => 'fcm']);
    }

    public function test_cleanupStaleTemplates_deletes_even_with_user_overrides(): void
    {
        $def = NotificationDefinition::create([
            'type' => 'tpl-overrides-test',
            'hook_prefix' => 'core.auth',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '정의'],
            'description' => ['ko' => '정의'],
            'channels' => ['mail'],
            'hooks' => [],
            'variables' => [],
            'is_active' => true,
            'is_default' => true,
        ]);

        NotificationTemplate::create([
            'definition_id' => $def->id,
            'channel' => 'fcm',
            'subject' => ['ko' => '유저 수정 있음'],
            'body' => ['ko' => '삭제 대상'],
            'is_active' => true,
            'is_default' => true,
            'user_overrides' => ['subject', 'body'],
        ]);

        $deleted = $this->helper->cleanupStaleTemplates($def->id, ['mail']);

        $this->assertSame(1, $deleted);
        $this->assertDatabaseMissing('notification_templates', ['definition_id' => $def->id, 'channel' => 'fcm']);
    }

    public function test_definition_delete_cascades_to_templates(): void
    {
        $def = NotificationDefinition::create([
            'type' => 'cascade-test',
            'hook_prefix' => 'core.auth',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => 'cascade'],
            'description' => ['ko' => 'cascade'],
            'channels' => ['mail'],
            'hooks' => [],
            'variables' => [],
            'is_active' => true,
            'is_default' => true,
        ]);
        $tpl = NotificationTemplate::create([
            'definition_id' => $def->id,
            'channel' => 'mail',
            'subject' => ['ko' => 'cascade'],
            'body' => ['ko' => 'cascade'],
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->helper->cleanupStaleDefinitions('core', 'core', []);

        $this->assertDatabaseMissing('notification_definitions', ['id' => $def->id]);
        $this->assertDatabaseMissing('notification_templates', ['id' => $tpl->id]);
    }
}
