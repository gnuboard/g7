<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Seeders;

use App\Models\NotificationDefinition;
use App\Models\NotificationTemplate;
use Modules\Sirsoft\Ecommerce\Database\Seeders\EcommerceNotificationDefinitionSeeder;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 이커머스 알림 정의 시더 테스트
 *
 * 모든 알림 타입에 mail + database 채널 템플릿이 시딩되는지 검증합니다.
 */
class EcommerceNotificationDefinitionSeederTest extends ModuleTestCase
{
    /**
     * 시더가 정의하는 알림 타입 목록
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
     * 시더 실행 후 모든 알림 정의가 생성되는지 확인
     */
    public function test_seeder_creates_all_definitions(): void
    {
        $seeder = new EcommerceNotificationDefinitionSeeder();
        $definitions = $seeder->getDefaultDefinitions();

        $this->assertCount(7, $definitions);

        $types = array_column($definitions, 'type');
        foreach ($this->expectedTypes as $expected) {
            $this->assertContains($expected, $types, "알림 타입 '{$expected}' 누락");
        }
    }

    /**
     * 모든 정의의 channels 배열에 mail과 database 모두 포함되는지 확인
     */
    public function test_all_definitions_have_both_channels(): void
    {
        $seeder = new EcommerceNotificationDefinitionSeeder();
        $definitions = $seeder->getDefaultDefinitions();

        foreach ($definitions as $def) {
            $this->assertContains('mail', $def['channels'], "{$def['type']}: mail 채널 누락");
            $this->assertContains('database', $def['channels'], "{$def['type']}: database 채널 누락");
        }
    }

    /**
     * 모든 정의의 templates 배열에 mail + database 두 채널 템플릿이 존재하는지 확인
     */
    public function test_all_definitions_have_both_channel_templates(): void
    {
        $seeder = new EcommerceNotificationDefinitionSeeder();
        $definitions = $seeder->getDefaultDefinitions();

        foreach ($definitions as $def) {
            $channels = array_column($def['templates'], 'channel');
            $this->assertContains('mail', $channels, "{$def['type']}: mail 템플릿 누락");
            $this->assertContains('database', $channels, "{$def['type']}: database 템플릿 누락");
        }
    }

    /**
     * database 채널 템플릿에 ko/en subject/body가 모두 존재하는지 확인
     */
    public function test_database_templates_have_bilingual_content(): void
    {
        $seeder = new EcommerceNotificationDefinitionSeeder();
        $definitions = $seeder->getDefaultDefinitions();

        foreach ($definitions as $def) {
            $dbTemplate = collect($def['templates'])->firstWhere('channel', 'database');
            $this->assertNotNull($dbTemplate, "{$def['type']}: database 템플릿 없음");

            $this->assertArrayHasKey('ko', $dbTemplate['subject'], "{$def['type']}: database subject에 ko 누락");
            $this->assertArrayHasKey('en', $dbTemplate['subject'], "{$def['type']}: database subject에 en 누락");
            $this->assertArrayHasKey('ko', $dbTemplate['body'], "{$def['type']}: database body에 ko 누락");
            $this->assertArrayHasKey('en', $dbTemplate['body'], "{$def['type']}: database body에 en 누락");

            // 빈 문자열이 아닌지
            $this->assertNotEmpty($dbTemplate['subject']['ko'], "{$def['type']}: database subject(ko) 빈 값");
            $this->assertNotEmpty($dbTemplate['body']['ko'], "{$def['type']}: database body(ko) 빈 값");
        }
    }

    /**
     * 시더 실행 시 DB에 NotificationTemplate이 실제로 생성되는지 확인
     */
    public function test_seeder_run_creates_database_templates_in_db(): void
    {
        // 기존 데이터 정리
        foreach ($this->expectedTypes as $type) {
            $definition = NotificationDefinition::where('type', $type)->first();
            if ($definition) {
                NotificationTemplate::where('definition_id', $definition->id)->delete();
                $definition->delete();
            }
        }

        // 시더 실행
        $seeder = new EcommerceNotificationDefinitionSeeder();
        $seeder->run();

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
}
