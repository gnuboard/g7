<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Notifications;

use App\Extension\Helpers\NotificationSyncHelper;
use App\Extension\ModuleManager;
use App\Models\NotificationDefinition;
use App\Models\NotificationTemplate;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 이커머스 알림 정의 — declarative getter 패턴 테스트.
 *
 * module.php 의 `getNotificationDefinitions()` 가 SSoT 임을 검증하고,
 * 모든 알림 타입에 mail + database 채널 템플릿이 시딩되는지 확인합니다.
 */
class EcommerceNotificationDefinitionsTest extends ModuleTestCase
{
    /**
     * module.php 가 정의하는 알림 타입 목록
     *
     * @var array<int, string>
     */
    private array $expectedTypes = [
        'order_confirmed',
        'order_shipped',
        'order_completed',
        'order_cancelled',
        'new_order_admin',
        'inquiry_received',
        'inquiry_replied',
    ];

    /**
     * module.php::getNotificationDefinitions() 가 모든 알림 정의를 반환하는지 확인.
     *
     * @return void
     */
    public function test_module_getter_returns_all_definitions(): void
    {
        $definitions = $this->getModuleDefinitions();

        $this->assertCount(7, $definitions);

        $types = array_column($definitions, 'type');
        foreach ($this->expectedTypes as $expected) {
            $this->assertContains($expected, $types, "알림 타입 '{$expected}' 누락");
        }
    }

    /**
     * 모든 정의의 channels 배열에 mail 과 database 모두 포함되는지 확인.
     *
     * @return void
     */
    public function test_all_definitions_have_both_channels(): void
    {
        foreach ($this->getModuleDefinitions() as $def) {
            $this->assertContains('mail', $def['channels'], "{$def['type']}: mail 채널 누락");
            $this->assertContains('database', $def['channels'], "{$def['type']}: database 채널 누락");
        }
    }

    /**
     * 모든 정의의 templates 배열에 mail + database 두 채널 템플릿이 존재하는지 확인.
     *
     * @return void
     */
    public function test_all_definitions_have_both_channel_templates(): void
    {
        foreach ($this->getModuleDefinitions() as $def) {
            $channels = array_column($def['templates'], 'channel');
            $this->assertContains('mail', $channels, "{$def['type']}: mail 템플릿 누락");
            $this->assertContains('database', $channels, "{$def['type']}: database 템플릿 누락");
        }
    }

    /**
     * database 채널 템플릿에 ko/en subject/body 가 모두 존재하는지 확인.
     *
     * @return void
     */
    public function test_database_templates_have_bilingual_content(): void
    {
        foreach ($this->getModuleDefinitions() as $def) {
            $dbTemplate = collect($def['templates'])->firstWhere('channel', 'database');
            $this->assertNotNull($dbTemplate, "{$def['type']}: database 템플릿 없음");

            $this->assertArrayHasKey('ko', $dbTemplate['subject'], "{$def['type']}: database subject에 ko 누락");
            $this->assertArrayHasKey('en', $dbTemplate['subject'], "{$def['type']}: database subject에 en 누락");
            $this->assertArrayHasKey('ko', $dbTemplate['body'], "{$def['type']}: database body에 ko 누락");
            $this->assertArrayHasKey('en', $dbTemplate['body'], "{$def['type']}: database body에 en 누락");

            $this->assertNotEmpty($dbTemplate['subject']['ko'], "{$def['type']}: database subject(ko) 빈 값");
            $this->assertNotEmpty($dbTemplate['body']['ko'], "{$def['type']}: database body(ko) 빈 값");
        }
    }

    /**
     * NotificationSyncHelper 동기화 시 DB 에 NotificationTemplate 이 실제 생성되는지 확인.
     *
     * @return void
     */
    public function test_sync_creates_database_templates_in_db(): void
    {
        // 기존 데이터 정리
        foreach ($this->expectedTypes as $type) {
            $definition = NotificationDefinition::where('type', $type)->first();
            if ($definition) {
                NotificationTemplate::where('definition_id', $definition->id)->delete();
                $definition->delete();
            }
        }

        // module.php SSoT 기반 동기화 (Manager activate/update 가 수행하는 것과 동일 흐름)
        $module = app(ModuleManager::class)->getModule('sirsoft-ecommerce');
        $this->assertNotNull($module, '이커머스 모듈 로드 실패');

        $helper = app(NotificationSyncHelper::class);
        foreach ($module->getNotificationDefinitions() as $data) {
            $data['extension_type'] = 'module';
            $data['extension_identifier'] = 'sirsoft-ecommerce';

            $definition = $helper->syncDefinition($data);
            foreach ($data['templates'] ?? [] as $template) {
                $helper->syncTemplate($definition->id, $template);
            }
        }

        // 검증: 각 타입에 database 채널 템플릿 존재
        foreach ($this->expectedTypes as $type) {
            $definition = NotificationDefinition::where('type', $type)->first();
            $this->assertNotNull($definition, "정의 '{$type}' DB 미생성");

            $dbTemplate = NotificationTemplate::where('definition_id', $definition->id)
                ->where('channel', 'database')
                ->first();
            $this->assertNotNull($dbTemplate, "'{$type}' database 템플릿 DB 미생성");
            $this->assertTrue($dbTemplate->is_active, "'{$type}' database 템플릿이 비활성");
        }
    }

    /**
     * module.php::getNotificationDefinitions() 결과를 가져옵니다.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getModuleDefinitions(): array
    {
        $module = app(ModuleManager::class)->getModule('sirsoft-ecommerce');
        $this->assertNotNull($module, '이커머스 모듈 로드 실패');

        return $module->getNotificationDefinitions();
    }
}
