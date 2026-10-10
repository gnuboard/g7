<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Notifications;

use App\Extension\Helpers\NotificationSyncHelper;
use App\Extension\ModuleManager;
use App\Models\NotificationDefinition;
use App\Models\NotificationTemplate;
use Modules\Sirsoft\Ecommerce\Listeners\ClaimStatusNotificationListener;
use Modules\Sirsoft\Ecommerce\Module;
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
        'order_pending_deposit',
        'mileage_expiring_soon',
        'order_confirmed',
        'order_shipped',
        'order_delivered',
        'order_completed',
        'order_cancelled',
        'new_order_admin',
        'order_confirmed_admin',
        'order_completed_admin',
        'deposit_after_cancel_admin',
        'inquiry_received',
        'inquiry_replied',
        'order_custom_email',
        'identity.purpose.checkout_verification',
    ];

    /**
     * 메일로만 보내는 정의 — mail + database 두 채널 규칙의 명시적 예외 (#699).
     *
     * - order_custom_email: 주문 상세 「고객에게 메일 보내기」. 관리자가 쓴 글을 메일로 보내는 기능이라 사이트 내 알림 짝이 없다.
     * - identity.purpose.checkout_verification: 결제 전 본인확인 인증번호 메일. 인증 메일은 메일 채널만 쓴다(본인인증 분류).
     *
     * @var array<int, string>
     */
    private const MAIL_ONLY_TYPES = [
        'order_custom_email',
        'identity.purpose.checkout_verification',
    ];

    /**
     * module.php::getNotificationDefinitions() 가 모든 알림 정의를 반환하는지 확인.
     *
     * @return void 반환값 없음
     *
     * @scenario stage=declaration
     *
     * @effects module_getter_returns_every_declared_definition
     */
    public function test_module_getter_returns_all_definitions(): void
    {
        $definitions = $this->getModuleDefinitions();

        // 클레임 알림(§4-8)은 유형 × 고객 가시 상태의 곱이라 여기에 다시 열거하지 않는다 —
        // 두 벌이 되면 한쪽만 늘어난 채로 통과한다. 그 집합의 전수 검증은
        // ClaimNotificationDefinitionsTest 가 담당하고, 여기서는 개수만 합산한다.
        // 운영자 사본(접수·추가결제 완료처럼 운영자도 알아야 하는 사건)은 모듈 상수가 정하므로 같은
        // 목록을 읽어 센다 — 숫자를 적어 두면 상수가 늘 때 이 합산만 낡는다.
        $staffKeys = (new \ReflectionClassConstant(Module::class, 'STAFF_NOTIFIED_CLAIM_KEYS'))->getValue();
        $claimCount = 3; // claim_withdrawn (관리자 수신) · claim_withdrawn_on_behalf (고객 수신) · claim_partially_processed
        foreach (ClaimStatusNotificationListener::notifiableStatuses() as $keys) {
            $claimCount += count($keys) + count(array_intersect($keys, $staffKeys));
        }
        // 상태가 아닌 사건 알림(송장 등록 3종 · 선교환 회수 지연 — #599) — 목록은 리스너가 소유한다.
        $claimCount += count(ClaimStatusNotificationListener::eventNotifyHooks());

        $this->assertCount(count($this->expectedTypes) + $claimCount, $definitions);

        $types = array_column($definitions, 'type');
        foreach ($this->expectedTypes as $expected) {
            $this->assertContains($expected, $types, "알림 타입 '{$expected}' 누락");
        }
    }

    /**
     * 모든 정의의 channels 배열에 mail 과 database 모두 포함되는지 확인.
     *
     * @return void 반환값 없음
     *
     * @scenario stage=declaration
     *
     * @effects every_definition_declares_mail_and_database_channels
     */
    public function test_all_definitions_have_both_channels(): void
    {
        foreach ($this->getModuleDefinitions() as $def) {
            if (in_array($def['type'], self::MAIL_ONLY_TYPES, true)) {
                // 예외 목록은 「메일만」 을 정확히 선언해야 한다 — 다른 채널이 붙으면 목록에서 빼고 일반 규칙을 따른다.
                $this->assertSame(['mail'], $def['channels'], "{$def['type']}: 메일 전용 정의가 다른 채널을 선언했다");

                continue;
            }
            $this->assertContains('mail', $def['channels'], "{$def['type']}: mail 채널 누락");
            $this->assertContains('database', $def['channels'], "{$def['type']}: database 채널 누락");
        }
    }

    /**
     * 모든 정의의 templates 배열에 mail + database 두 채널 템플릿이 존재하는지 확인.
     *
     * @return void 반환값 없음
     *
     * @scenario stage=declaration
     *
     * @effects every_definition_has_mail_and_database_templates
     */
    public function test_all_definitions_have_both_channel_templates(): void
    {
        foreach ($this->getModuleDefinitions() as $def) {
            $channels = array_column($def['templates'], 'channel');
            $this->assertContains('mail', $channels, "{$def['type']}: mail 템플릿 누락");
            if (in_array($def['type'], self::MAIL_ONLY_TYPES, true)) {
                continue;
            }
            $this->assertContains('database', $channels, "{$def['type']}: database 템플릿 누락");
        }
    }

    /**
     * database 채널 템플릿에 ko/en subject/body 가 모두 존재하는지 확인.
     *
     * @return void 반환값 없음
     *
     * @scenario stage=declaration
     *
     * @effects database_templates_carry_ko_and_en_subject_and_body
     */
    public function test_database_templates_have_bilingual_content(): void
    {
        foreach ($this->getModuleDefinitions() as $def) {
            if (in_array($def['type'], self::MAIL_ONLY_TYPES, true)) {
                continue;
            }
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
     * @return void 반환값 없음
     *
     * @scenario stage=database_sync
     *
     * @effects sync_creates_active_database_templates_in_db
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

        // 검증: 각 타입에 database 채널 템플릿 존재 (메일 전용 정의는 정의 행만 확인)
        foreach ($this->expectedTypes as $type) {
            $definition = NotificationDefinition::where('type', $type)->first();
            $this->assertNotNull($definition, "정의 '{$type}' DB 미생성");
            if (in_array($type, self::MAIL_ONLY_TYPES, true)) {
                continue;
            }

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
     * @return array<int, array<string, mixed>> 이커머스 모듈이 선언한 알림 정의 목록
     */
    private function getModuleDefinitions(): array
    {
        $module = app(ModuleManager::class)->getModule('sirsoft-ecommerce');
        $this->assertNotNull($module, '이커머스 모듈 로드 실패');

        return $module->getNotificationDefinitions();
    }
}
