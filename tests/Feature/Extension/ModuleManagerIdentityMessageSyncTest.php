<?php

namespace Tests\Feature\Extension;

use App\Extension\AbstractModule;
use App\Extension\Helpers\IdentityMessageSyncHelper;
use App\Models\IdentityMessageDefinition;
use App\Models\IdentityMessageTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ModuleManager 가 AbstractModule::getIdentityMessages() 결과를 DB 에 동기화하는지 검증.
 *
 * `IdentityPolicies` / `IdentityPurposes` 와 동일 패턴: getter + helper 자동 동기화.
 * `syncModuleIdentityMessages()` protected 메서드 동작을 helper 직접 호출로 재현하여
 * Manager 계약이 helper 에 정확히 위임되는지 확인한다.
 */
class ModuleManagerIdentityMessageSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_module_getIdentityMessages_is_upserted_via_helper(): void
    {
        $module = $this->makeFakeModule([
            [
                'provider_id' => 'g7:core.mail',
                'scope_type' => IdentityMessageDefinition::SCOPE_PURPOSE,
                'scope_value' => 'fake_purpose',
                'name' => ['ko' => '가짜 목적', 'en' => 'Fake Purpose'],
                'description' => ['ko' => '테스트', 'en' => 'Test'],
                'channels' => ['mail'],
                'variables' => [['key' => 'code', 'description' => '코드']],
                'templates' => [
                    [
                        'channel' => 'mail',
                        'subject' => ['ko' => '제목', 'en' => 'Subject'],
                        'body' => ['ko' => '본문 {code}', 'en' => 'Body {code}'],
                    ],
                ],
            ],
        ]);

        $helper = app(IdentityMessageSyncHelper::class);
        $definedScopes = [];
        foreach ($module->getIdentityMessages() as $data) {
            $data['extension_type'] = 'module';
            $data['extension_identifier'] = $module->getIdentifier();
            $definition = $helper->syncDefinition($data);
            $definedScopes[] = [
                'provider_id' => $definition->provider_id,
                'scope_type' => $definition->scope_type->value,
                'scope_value' => $definition->scope_value,
            ];
            foreach ($data['templates'] ?? [] as $template) {
                $helper->syncTemplate($definition->id, $template);
            }
        }

        $this->assertDatabaseHas('identity_message_definitions', [
            'provider_id' => 'g7:core.mail',
            'scope_value' => 'fake_purpose',
            'extension_type' => 'module',
            'extension_identifier' => 'fake-module',
        ]);

        $defId = IdentityMessageDefinition::query()
            ->where('extension_identifier', 'fake-module')
            ->where('scope_value', 'fake_purpose')
            ->value('id');
        $this->assertNotNull($defId);

        $this->assertDatabaseHas('identity_message_templates', [
            'definition_id' => $defId,
            'channel' => 'mail',
        ]);
    }

    public function test_cleanup_removes_definitions_not_in_current_declaration(): void
    {
        $helper = app(IdentityMessageSyncHelper::class);

        // 초기: 2개 정의 시드
        $helper->syncDefinition([
            'provider_id' => 'g7:core.mail',
            'scope_type' => IdentityMessageDefinition::SCOPE_PURPOSE,
            'scope_value' => 'keep_me',
            'extension_type' => 'module',
            'extension_identifier' => 'fake-module',
            'name' => ['ko' => '유지', 'en' => 'Keep'],
        ]);
        $helper->syncDefinition([
            'provider_id' => 'g7:core.mail',
            'scope_type' => IdentityMessageDefinition::SCOPE_PURPOSE,
            'scope_value' => 'stale',
            'extension_type' => 'module',
            'extension_identifier' => 'fake-module',
            'name' => ['ko' => '오래됨', 'en' => 'Stale'],
        ]);

        $this->assertDatabaseHas('identity_message_definitions', [
            'extension_identifier' => 'fake-module',
            'scope_value' => 'stale',
        ]);

        // 재동기화 시 keep_me 만 선언에 남으면 stale 은 제거되어야 함
        $helper->cleanupStaleDefinitions('module', 'fake-module', [
            ['provider_id' => 'g7:core.mail', 'scope_type' => 'purpose', 'scope_value' => 'keep_me'],
        ]);

        $this->assertDatabaseHas('identity_message_definitions', [
            'extension_identifier' => 'fake-module',
            'scope_value' => 'keep_me',
        ]);
        $this->assertDatabaseMissing('identity_message_definitions', [
            'extension_identifier' => 'fake-module',
            'scope_value' => 'stale',
        ]);
    }

    public function test_empty_declaration_with_uninstall_clears_all_module_definitions(): void
    {
        $helper = app(IdentityMessageSyncHelper::class);

        // 모듈 정의 1건 시드
        $helper->syncDefinition([
            'provider_id' => 'g7:core.mail',
            'scope_type' => IdentityMessageDefinition::SCOPE_PURPOSE,
            'scope_value' => 'temp',
            'extension_type' => 'module',
            'extension_identifier' => 'fake-module',
            'name' => ['ko' => '임시', 'en' => 'Temp'],
        ]);

        // uninstall(deleteData=true) 시뮬레이션 — 빈 currentScopes 로 cleanup
        $helper->cleanupStaleDefinitions('module', 'fake-module', []);

        $this->assertDatabaseMissing('identity_message_definitions', [
            'extension_identifier' => 'fake-module',
        ]);
    }

    public function test_user_overrides_are_preserved_across_resync(): void
    {
        $helper = app(IdentityMessageSyncHelper::class);

        // 1차 seed
        $def = $helper->syncDefinition([
            'provider_id' => 'g7:core.mail',
            'scope_type' => IdentityMessageDefinition::SCOPE_PURPOSE,
            'scope_value' => 'override_test',
            'extension_type' => 'module',
            'extension_identifier' => 'fake-module',
            'name' => ['ko' => '원본', 'en' => 'Original'],
        ]);

        // 운영자가 UI 에서 name 수정
        IdentityMessageDefinition::where('id', $def->id)->update([
            'name' => ['ko' => '운영자 수정', 'en' => 'Edited'],
            'user_overrides' => ['name'],
        ]);

        // 2차 seed (모듈 update 시뮬레이션)
        $helper->syncDefinition([
            'provider_id' => 'g7:core.mail',
            'scope_type' => IdentityMessageDefinition::SCOPE_PURPOSE,
            'scope_value' => 'override_test',
            'extension_type' => 'module',
            'extension_identifier' => 'fake-module',
            'name' => ['ko' => '원본', 'en' => 'Original'], // 모듈 코드는 원본 그대로
        ]);

        $row = IdentityMessageDefinition::where('id', $def->id)->first();
        $this->assertSame('운영자 수정', $row->name['ko'] ?? null, 'user_overrides 로 보존되어야 함');
    }

    /**
     * 테스트용 가짜 모듈 인스턴스 생성.
     */
    private function makeFakeModule(array $messages): AbstractModule
    {
        $module = new class($messages) extends AbstractModule
        {
            public function __construct(private array $messageData)
            {
            }

            public function getName(): string|array
            {
                return 'Fake Module';
            }

            public function getDescription(): string|array
            {
                return 'Test module for IDV message sync';
            }

            public function getVersion(): string
            {
                return '1.0.0';
            }

            public function getIdentityMessages(): array
            {
                return $this->messageData;
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
