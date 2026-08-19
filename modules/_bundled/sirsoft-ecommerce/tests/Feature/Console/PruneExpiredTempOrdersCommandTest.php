<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Console;

use Illuminate\Support\Carbon;
use Modules\Sirsoft\Ecommerce\Models\TempOrder;
use Modules\Sirsoft\Ecommerce\Module;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 만료 임시 주문 자동 정리 커맨드 테스트 (공개이슈 #110 전수 해소분, B5)
 *
 * 임시 주문은 체크아웃 진입마다 생성되고 TTL 로 만료되지만, 이 커맨드가 없으면
 * 만료 행을 실제로 지우는 주체가 없어 무한히 누적됩니다.
 *
 * @scenario target=ecommerce_temp_orders, row_age=beyond_retention
 *
 * @effects rows_beyond_retention_are_deleted, rows_within_retention_are_kept, all_gc_commands_are_registered_in_schedule
 */
class PruneExpiredTempOrdersCommandTest extends ModuleTestCase
{
    /**
     * 만료된 임시 주문만 삭제되고 유효한 임시 주문은 보존된다.
     */
    public function test_prunes_only_expired_temp_orders(): void
    {
        $expired = TempOrder::create([
            'cart_key' => 'expired-key',
            'items' => [],
            'calculation_input' => [],
            'calculation_result' => [],
            'expires_at' => Carbon::now()->subMinutes(10),
        ]);

        $alive = TempOrder::create([
            'cart_key' => 'alive-key',
            'items' => [],
            'calculation_input' => [],
            'calculation_result' => [],
            'expires_at' => Carbon::now()->addMinutes(10),
        ]);

        $this->artisan('sirsoft-ecommerce:prune-temp-orders')->assertSuccessful();

        $this->assertDatabaseMissing('ecommerce_temp_orders', ['id' => $expired->id]);
        $this->assertDatabaseHas('ecommerce_temp_orders', ['id' => $alive->id]);
    }

    /**
     * 모듈이 이 커맨드를 스케줄로 등록한다 (등록 누락 회귀 차단).
     */
    public function test_command_is_registered_in_module_schedules(): void
    {
        $entry = collect((new Module)->getSchedules())
            ->firstWhere('command', 'sirsoft-ecommerce:prune-temp-orders');

        $this->assertNotNull($entry, '만료 임시 주문 정리 스케줄이 등록되어야 합니다.');
        $this->assertSame('hourly', $entry['schedule']);
    }
}
