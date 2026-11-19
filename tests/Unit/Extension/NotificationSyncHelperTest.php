<?php

namespace Tests\Unit\Extension;

use App\Extension\Helpers\NotificationSyncHelper;
use App\Models\ActivityLog;
use App\Models\NotificationDefinition;
use App\Models\NotificationTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * NotificationSyncHelper 의 cleanupStale* 회귀 방지 테스트.
 *
 * 정책:
 *  - seeder 에 없는 (extension_type + extension_identifier + type) 조합의 definition 삭제
 *  - definition 삭제 시 그 template 을 정리 코드가 명시적으로 지운다(DB CASCADE 비의존)
 *  - definition 은 유지되지만 channel 이 재구성된 경우: 제거된 channel 의 template 삭제
 *  - 운영자가 고친 항목도 선언에서 빠지면 지운다 — 지우기 직전 활동 로그에 남긴다(#888)
 */
class NotificationSyncHelperTest extends TestCase
{
    use RefreshDatabase;

    private NotificationSyncHelper $helper;

    /**
     * 테스트 대상 헬퍼를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->helper = app(NotificationSyncHelper::class);
    }

    /**
     * 선언에 없는 정의만 지운다.
     *
     * @scenario case=notification__definition
     *
     * @effects declarative_children_deleted_explicitly
     *
     * @return void 반환값 없음
     */
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

    /**
     * 운영자가 고친 정의도 선언에서 빠지면 지운다(지우기 직전 기록은 별도 시험).
     *
     * @scenario case=notification__definition
     *
     * @effects sync_deletion_logged_for_operator_data
     *
     * @return void 반환값 없음
     */
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

    /**
     * 다른 확장의 정의는 건드리지 않는다.
     *
     * @scenario case=notification__definition
     *
     * @effects declarative_children_deleted_explicitly
     *
     * @return void 반환값 없음
     */
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

    /**
     * 선언에서 빠진 채널의 템플릿만 지운다.
     *
     * @scenario case=notification__template
     *
     * @effects declarative_children_deleted_explicitly
     *
     * @return void 반환값 없음
     */
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

    /**
     * 운영자가 고친 템플릿도 선언에서 빠지면 지운다.
     *
     * @scenario case=notification__template
     *
     * @effects sync_deletion_logged_for_operator_data
     *
     * @return void 반환값 없음
     */
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

    /**
     * 정의를 지우면 그 템플릿을 DB CASCADE 가 아니라 정리 코드가 명시적으로 지운다 — FK 를 꺼도 고아가 남지 않는다.
     * 운영자가 고친 템플릿이 있으면 지우기 직전 활동 로그 `notification_definition.sync_deleted` 에 정의와 템플릿이 남는다(#888).
     *
     * @scenario case=notification__definition
     *
     * @effects declarative_children_deleted_explicitly, sync_deletion_logged_for_operator_data
     *
     * @return void 반환값 없음
     */
    public function test_definition_delete_removes_templates_explicitly(): void
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
            'user_overrides' => ['subject'],
        ]);

        Schema::disableForeignKeyConstraints();
        try {
            $this->helper->cleanupStaleDefinitions('core', 'core', []);
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $this->assertDatabaseMissing('notification_definitions', ['id' => $def->id]);
        $this->assertDatabaseMissing('notification_templates', ['id' => $tpl->id]);
        $log = ActivityLog::where('action', 'notification_definition.sync_deleted')->latest('id')->first();
        $this->assertNotNull($log, '운영자가 고친 템플릿이 있으면 지우기 직전 기록이 남아야 한다');
        $this->assertSame('cascade-test', $log->properties['key']);
        $this->assertCount(1, $log->properties['snapshot']['templates']);
    }
}
