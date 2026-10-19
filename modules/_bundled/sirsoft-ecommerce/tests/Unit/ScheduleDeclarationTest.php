<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit;

use App\Enums\ExtensionOwnerType;
use Modules\Sirsoft\Ecommerce\Module;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Tests\Support\Concerns\AssertsExtensionScheduleDeclarations;

/**
 * sirsoft-ecommerce 스케줄 선언 (코어 7.2.0 DB 스케줄 등록부)
 *
 * 선언은 설치·갱신 때 코어가 스케줄 표로 동기화한다. 동기화는 검증 위반 항목을 건너뛰므로 선언이 틀리면
 * 설치 뒤 작업이 조용히 빠진다. 또 옛 등록(Laravel 주기 메서드·enabled_config)과 실행 시각·조건이 같아야
 * 업데이트한 사이트의 동작이 바뀌지 않는다.
 *
 * 참고: 판매 현황 집계의 config 전용 값(dashboard.scheduler_enabled)은 화면이 없어 조건에서 뺐다 — 커맨드가 스스로 건너뛰고 운영자는 스케줄 활성 토글로 끈다.
 */
class ScheduleDeclarationTest extends ModuleTestCase
{
    use AssertsExtensionScheduleDeclarations;

    /**
     * 선언이 새 형식이고 정규화·검증·실행 조건 해석을 통과하는지 확인합니다.
     *
     * @scenario shape=new_schema
     *
     * @effects declarations_syncable
     *
     * @return void 반환값 없음
     */
    public function test_declarations_are_syncable(): void
    {
        $this->assertScheduleDeclarationsSyncable((new Module)->getSchedules(), ExtensionOwnerType::Module, 'sirsoft-ecommerce');
    }

    /**
     * 옛 등록과 같은 명령·실행 시각(사이트 시간대)·실행 조건을 유지하는지 확인합니다.
     *
     * @scenario shape=new_schema
     *
     * @effects legacy_timing_and_conditions_preserved
     *
     * @return void 반환값 없음
     */
    public function test_legacy_timing_and_conditions_preserved(): void
    {
        $this->assertSame([
            'cancel-pending-orders' => ['sirsoft-ecommerce:cancel-pending-orders', '0 0 * * *', null, ['module_setting:order_settings.auto_cancel_expired']],
            'prune-expired-carts' => ['sirsoft-ecommerce:prune-expired-carts', '0 0 * * *', null, []],
            'prune-temp-product-images' => ['sirsoft-ecommerce:prune-temp-product-images', '0 0 * * *', null, []],
            'prune-temp-orders' => ['sirsoft-ecommerce:prune-temp-orders', '0 * * * *', null, []],
            'earn-mileage' => ['sirsoft-ecommerce:earn-mileage', '0 * * * *', null, ['module_setting:mileage.enabled']],
            'expire-mileage' => ['sirsoft-ecommerce:expire-mileage', '0 0 * * *', null, ['module_setting:mileage.expiry_enabled']],
            'notify-expiring-mileage' => ['sirsoft-ecommerce:notify-expiring-mileage', '0 0 * * *', null, ['module_setting:mileage.expiry_notification_enabled']],
            'reconcile-mileage-balance' => ['sirsoft-ecommerce:reconcile-mileage-balance', '0 0 * * *', null, ['module_setting:mileage.enabled']],
            'aggregate-stats' => ['sirsoft-ecommerce:aggregate-stats', '0 * * * *', null, []],
            'prune-pending-claim-images' => ['sirsoft-ecommerce:prune-pending-claim-images', '0 0 * * *', null, []],
            'cancel-orphan-claim-payments' => ['sirsoft-ecommerce:cancel-orphan-claim-payments', '0 0 * * *', null, []],
            'notify-overdue-collections' => ['sirsoft-ecommerce:claims:notify-overdue-collections', '0 0 * * *', null, []],
        ], $this->scheduleDeclarationTable((new Module)->getSchedules()));
    }

    /**
     * 코어를 먼저 7.2.0 으로 올린 사이트에서 옛 형식이 자동 이름으로 만든 행을, 새 형식 업데이트가 지우지 않고 이어받는지 확인합니다.
     *
     * @scenario shape=new_schema
     *
     * @effects legacy_named_rows_adopted_on_format_upgrade
     *
     * @return void 반환값 없음
     */
    public function test_legacy_rows_adopted_on_format_upgrade(): void
    {
        $this->assertLegacyRowsAdoptedOnFormatUpgrade((new Module)->getSchedules(), ExtensionOwnerType::Module, 'sirsoft-ecommerce');
    }
}
