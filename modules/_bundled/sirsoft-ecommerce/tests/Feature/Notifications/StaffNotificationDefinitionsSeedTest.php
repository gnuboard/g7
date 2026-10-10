<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Notifications;

use App\Extension\Helpers\NotificationSyncHelper;
use App\Extension\ModuleManager;
use App\Models\NotificationDefinition;
use App\Services\NotificationDefinitionService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 운영자 수신 알림 정의가 **실제로 활성 목록에 나타나는지** 검증합니다.
 *
 * 선언만 있고 DB 에 없으면 훅 구독 자체가 일어나지 않아 **알림이 조용히 안 나갑니다** —
 * 화면에도 오류에도 흔적이 남지 않습니다. 정의 캐시가 남아 있는 경우도 같은 증상이므로
 * 이 테스트가 배포 절차(`optimize:clear`)의 근거입니다.
 */
class StaffNotificationDefinitionsSeedTest extends ModuleTestCase
{
    /**
     * 이번에 신설된 운영자 수신 정의.
     *
     * @var array<int, string>
     */
    private array $newStaffTypes = [
        'exchange_requested_admin',
        'return_requested_admin',
        'refund_requested_admin',
        'exchange_payment_completed_admin',
        'return_payment_completed_admin',
        'order_confirmed_admin',
        'order_completed_admin',
        // 선교환 회수 지연(D2) — 배치가 한 번 발화하는 운영자 수신 알림
        'exchange_collection_overdue',
    ];

    /**
     * 선언된 알림 정의를 테스트 DB 에 동기화합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        // 선언형 산출물은 `module:update` 가 동기화한다 — 테스트 DB 에는 그 경로가 돌지 않으므로
        // 같은 helper 로 직접 시드해 "선언 → DB" 구간을 실제로 밟는다(게시판 테스트와 같은 방식).
        $this->syncEcommerceNotificationDefinitions();
    }

    /**
     * 선언된 정의가 하나도 빠짐없이 DB 에 동기화된다.
     *
     * @effects declared_definitions_are_all_synced_to_the_database
     *
     * @return void 반환값 없음
     */
    public function test_every_declared_definition_exists_in_the_database(): void
    {
        $module = app(ModuleManager::class)->getModule('sirsoft-ecommerce');
        $this->assertNotNull($module, '이커머스 모듈 로드 실패');

        $declared = array_column($module->getNotificationDefinitions(), 'type');
        $stored = NotificationDefinition::query()
            ->where('extension_identifier', 'sirsoft-ecommerce')
            ->pluck('type')
            ->all();

        $missing = array_values(array_diff($declared, $stored));

        $this->assertSame(
            [],
            $missing,
            "선언은 있는데 DB 에 없으면 그 훅을 아무도 구독하지 않아 알림이 조용히 안 나갑니다:\n - "
            .implode("\n - ", $missing)
        );
    }

    /**
     * 신설 정의가 활성 목록(훅 구독의 출처)에 나타난다.
     *
     * @effects new_staff_definitions_appear_in_the_active_list
     *
     * @return void 반환값 없음
     */
    public function test_new_staff_definitions_appear_in_the_active_list(): void
    {
        app(NotificationDefinitionService::class)->invalidateAllCache();

        $activeTypes = app(NotificationDefinitionService::class)
            ->getAllActive()
            ->pluck('type')
            ->all();

        foreach ($this->newStaffTypes as $type) {
            $this->assertContains(
                $type,
                $activeTypes,
                "{$type} 가 활성 목록에 없으면 그 훅이 구독되지 않아 알림이 한 통도 나가지 않습니다."
            );
        }
    }

    /**
     * 신설 정의는 메일·알림센터 두 채널이 **켜진 채로** 시드된다.
     *
     * @effects new_staff_definitions_are_seeded_with_both_channels_active
     *
     * @return void 반환값 없음
     */
    public function test_new_staff_definitions_are_seeded_with_both_channels_active(): void
    {
        foreach ($this->newStaffTypes as $type) {
            $definition = NotificationDefinition::query()->where('type', $type)->first();
            $this->assertNotNull($definition, "{$type} 정의가 DB 에 없습니다.");

            $channels = $definition->templates()->where('is_active', true)->pluck('channel')->all();

            $this->assertContains('mail', $channels, "{$type}: mail 템플릿이 활성 상태가 아닙니다.");
            $this->assertContains('database', $channels, "{$type}: database 템플릿이 활성 상태가 아닙니다.");
        }
    }

    /**
     * 선교환 회수 지연 알림은 운영자 수신이고, 수신자는 다른 운영자 수신 알림과 같은 운영 역할 목록이다 —
     * 한 역할이라도 빠지면 그 역할의 사람은 반송 기한이 지나도 통보받지 못한다.
     *
     * @effects collection_overdue_definition_targets_staff_roles
     *
     * @return void 반환값 없음
     */
    public function test_collection_overdue_definition_targets_the_staff_roles(): void
    {
        $definitions = collect(app(ModuleManager::class)->getModule('sirsoft-ecommerce')->getNotificationDefinitions())->keyBy('type');

        $overdue = $definitions->get('exchange_collection_overdue');
        $reference = $definitions->get('exchange_requested_admin');

        $this->assertNotNull($overdue, '선교환 회수 지연 알림 정의가 없습니다.');
        $this->assertSame('staff', $overdue['audience']);
        $this->assertSame(['sirsoft-ecommerce.claim.exchange.notify_collection_overdue'], $overdue['hooks']);

        $recipientsOf = fn (array $definition) => collect($definition['templates'])->firstWhere('channel', 'mail')['recipients'] ?? null;

        $this->assertNotEmpty($recipientsOf($overdue));
        $this->assertEquals($recipientsOf($reference), $recipientsOf($overdue));
    }

    /**
     * `module.php::getNotificationDefinitions()` 를 SSoT 로 알림 정의를 동기화합니다.
     *
     * @return void 반환값 없음
     */
    private function syncEcommerceNotificationDefinitions(): void
    {
        $module = app(ModuleManager::class)->getModule('sirsoft-ecommerce');
        if (! $module) {
            return;
        }

        $helper = app(NotificationSyncHelper::class);
        foreach ($module->getNotificationDefinitions() as $data) {
            $data['extension_type'] = 'module';
            $data['extension_identifier'] = 'sirsoft-ecommerce';

            $definition = $helper->syncDefinition($data);

            foreach ($data['templates'] ?? [] as $template) {
                $helper->syncTemplate($definition->id, $template);
            }
        }
    }
}
