<?php

namespace Tests\Feature\Extension;

use App\Extension\AbstractModule;
use App\Extension\Helpers\NotificationSyncHelper;
use App\Models\NotificationDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ModuleManager 가 AbstractModule::getNotificationDefinitions() 결과를 DB 에 동기화하는지 검증.
 *
 * IdentityMessages / IdentityPolicies 와 동일한 declarative getter 패턴.
 * `syncModuleNotificationDefinitions()` protected 동작을 helper 직접 호출로 재현하여
 * Manager 계약이 helper 에 정확히 위임되는지 확인합니다.
 *
 * @since 7.0.0-beta.4
 */
class ModuleManagerNotificationDefinitionSyncTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return void
     */
    public function test_module_getNotificationDefinitions_is_upserted_via_helper(): void
    {
        $module = $this->makeFakeModule([
            [
                'type' => 'fake_event',
                'hook_prefix' => 'fake-module',
                'name' => ['ko' => '가짜 이벤트', 'en' => 'Fake Event'],
                'description' => ['ko' => '테스트', 'en' => 'Test'],
                'channels' => ['mail', 'database'],
                'hooks' => ['fake-module.event.after_create'],
                'variables' => [['key' => 'name', 'description' => '수신자']],
                'templates' => [
                    [
                        'channel' => 'mail',
                        'recipients' => [['type' => 'trigger_user']],
                        'subject' => ['ko' => '제목', 'en' => 'Subject'],
                        'body' => ['ko' => '본문 {name}', 'en' => 'Body {name}'],
                    ],
                    [
                        'channel' => 'database',
                        'recipients' => [['type' => 'trigger_user']],
                        'subject' => ['ko' => 'DB제목', 'en' => 'DB Subject'],
                        'body' => ['ko' => 'DB본문', 'en' => 'DB Body'],
                    ],
                ],
            ],
        ]);

        $helper = app(NotificationSyncHelper::class);
        foreach ($module->getNotificationDefinitions() as $data) {
            $data['extension_type'] = 'module';
            $data['extension_identifier'] = $module->getIdentifier();
            $definition = $helper->syncDefinition($data);
            foreach ($data['templates'] ?? [] as $template) {
                $helper->syncTemplate($definition->id, $template);
            }
        }

        $this->assertDatabaseHas('notification_definitions', [
            'type' => 'fake_event',
            'extension_type' => 'module',
            'extension_identifier' => 'fake-module',
        ]);

        $defId = NotificationDefinition::query()
            ->where('extension_identifier', 'fake-module')
            ->where('type', 'fake_event')
            ->value('id');
        $this->assertNotNull($defId);

        $this->assertDatabaseHas('notification_templates', [
            'definition_id' => $defId,
            'channel' => 'mail',
        ]);
        $this->assertDatabaseHas('notification_templates', [
            'definition_id' => $defId,
            'channel' => 'database',
        ]);
    }

    /**
     * @return void
     */
    public function test_cleanup_removes_definitions_not_in_current_declaration(): void
    {
        $helper = app(NotificationSyncHelper::class);

        $helper->syncDefinition([
            'type' => 'keep_me',
            'hook_prefix' => 'fake-module',
            'extension_type' => 'module',
            'extension_identifier' => 'fake-module',
            'name' => ['ko' => '유지', 'en' => 'Keep'],
            'channels' => ['mail'],
            'hooks' => [],
        ]);
        $helper->syncDefinition([
            'type' => 'stale_def',
            'hook_prefix' => 'fake-module',
            'extension_type' => 'module',
            'extension_identifier' => 'fake-module',
            'name' => ['ko' => '오래됨', 'en' => 'Stale'],
            'channels' => ['mail'],
            'hooks' => [],
        ]);

        $this->assertDatabaseHas('notification_definitions', [
            'extension_identifier' => 'fake-module',
            'type' => 'stale_def',
        ]);

        $helper->cleanupStaleDefinitions('module', 'fake-module', ['keep_me']);

        $this->assertDatabaseHas('notification_definitions', [
            'extension_identifier' => 'fake-module',
            'type' => 'keep_me',
        ]);
        $this->assertDatabaseMissing('notification_definitions', [
            'extension_identifier' => 'fake-module',
            'type' => 'stale_def',
        ]);
    }

    /**
     * @return void
     */
    public function test_empty_declaration_with_uninstall_clears_all_module_definitions(): void
    {
        $helper = app(NotificationSyncHelper::class);

        $helper->syncDefinition([
            'type' => 'temp_def',
            'hook_prefix' => 'fake-module',
            'extension_type' => 'module',
            'extension_identifier' => 'fake-module',
            'name' => ['ko' => '임시', 'en' => 'Temp'],
            'channels' => ['mail'],
            'hooks' => [],
        ]);

        // uninstall(deleteData=true) 시뮬레이션 — 빈 currentTypes 로 cleanup
        $helper->cleanupStaleDefinitions('module', 'fake-module', []);

        $this->assertDatabaseMissing('notification_definitions', [
            'extension_identifier' => 'fake-module',
        ]);
    }

    /**
     * @return void
     */
    public function test_user_overrides_are_preserved_across_resync(): void
    {
        $helper = app(NotificationSyncHelper::class);

        $def = $helper->syncDefinition([
            'type' => 'override_test',
            'hook_prefix' => 'fake-module',
            'extension_type' => 'module',
            'extension_identifier' => 'fake-module',
            'name' => ['ko' => '원본', 'en' => 'Original'],
            'channels' => ['mail'],
            'hooks' => [],
        ]);

        // 운영자가 UI 에서 name 수정
        NotificationDefinition::where('id', $def->id)->update([
            'name' => ['ko' => '운영자 수정', 'en' => 'Edited'],
            'user_overrides' => ['name'],
        ]);

        // 2차 seed (모듈 update 시뮬레이션 — 모듈 코드는 원본 그대로)
        $helper->syncDefinition([
            'type' => 'override_test',
            'hook_prefix' => 'fake-module',
            'extension_type' => 'module',
            'extension_identifier' => 'fake-module',
            'name' => ['ko' => '원본', 'en' => 'Original'],
            'channels' => ['mail'],
            'hooks' => [],
        ]);

        $row = NotificationDefinition::where('id', $def->id)->first();
        $this->assertSame('운영자 수정', $row->name['ko'] ?? null, 'user_overrides 로 보존되어야 함');
    }

    /**
     * 코어 config/core.php 의 notification_definitions 가 정확히 로드되는지 검증.
     *
     * @return void
     */
    public function test_core_config_notification_definitions_are_present(): void
    {
        $coreDefs = config('core.notification_definitions', []);

        $this->assertNotEmpty($coreDefs, 'config/core.php 에 notification_definitions 누락');
        $this->assertArrayHasKey('welcome', $coreDefs);
        $this->assertArrayHasKey('reset_password', $coreDefs);
        $this->assertArrayHasKey('password_changed', $coreDefs);

        // 각 정의에 templates 가 있는지
        foreach (['welcome', 'reset_password', 'password_changed'] as $type) {
            $this->assertNotEmpty($coreDefs[$type]['templates'] ?? [], "{$type}: templates 누락");
            $this->assertNotEmpty($coreDefs[$type]['name'] ?? [], "{$type}: name 누락");
        }
    }

    /**
     * 테스트용 가짜 모듈 인스턴스 생성.
     *
     * @param  array<int, array<string, mixed>>  $definitions
     * @return AbstractModule
     */
    private function makeFakeModule(array $definitions): AbstractModule
    {
        $module = new class($definitions) extends AbstractModule
        {
            public function __construct(private array $definitionData)
            {
            }

            public function getName(): string|array
            {
                return 'Fake Module';
            }

            public function getDescription(): string|array
            {
                return 'Test module for notification sync';
            }

            public function getVersion(): string
            {
                return '1.0.0';
            }

            public function getNotificationDefinitions(): array
            {
                return $this->definitionData;
            }
        };

        $reflection = new \ReflectionClass($module);
        while ($reflection && ! $reflection->hasProperty('identifier')) {
            $reflection = $reflection->getParentClass();
        }
        if ($reflection) {
            $prop = $reflection->getProperty('identifier');
            $prop->setAccessible(true);
            $prop->setValue($module, 'fake-module');
        }

        return $module;
    }
}
