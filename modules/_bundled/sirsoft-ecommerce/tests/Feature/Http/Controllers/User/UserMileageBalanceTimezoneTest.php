<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\User;

use Carbon\Carbon;
use Modules\Sirsoft\Ecommerce\Models\MileageBalance;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 사용자 마일리지 잔액 API 의 소멸 예정일 표시값 테스트 (#160)
 *
 * 마이페이지 마일리지 카드는 ISO 원문(`expiring_date`)이 아니라 보는 사람 날짜(`expiring_date_formatted`)를 쓴다.
 */
class UserMileageBalanceTimezoneTest extends ModuleTestCase
{
    /**
     * 소멸 예정일은 보는 사람 날짜(`expiring_date_formatted`)로도 내려온다 — ISO 원문은 기계값으로 유지.
     *
     * UTC 12-31 16:00 소멸분 → 서울 날짜 2027-01-01.
     *
     * @scenario surface=mileage_balance_formatted
     *
     * @effects mileage_balance_exposes_viewer_date
     *
     * @return void 반환값 없음
     */
    public function test_balance_exposes_viewer_date_for_expiring_date(): void
    {
        $user = $this->createUser();
        MileageBalance::create([
            'user_id' => $user->id, 'currency' => 'KRW', 'available' => 100,
            'expiring_soon' => 100, 'expiring_date' => Carbon::parse('2026-12-31 16:00:00', 'UTC'),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->withHeader('X-Timezone', 'Asia/Seoul')
            ->getJson('/api/modules/sirsoft-ecommerce/user/mileage');

        $response->assertOk();
        $this->assertStringContainsString('T', (string) $response->json('data.mileage.expiring_date'));
        $this->assertSame('2027-01-01', $response->json('data.mileage.expiring_date_formatted'));
        $this->assertSame('2027-01-01', $response->json('data.mileage.by_currency.KRW.expiring_date_formatted'));
    }
}
