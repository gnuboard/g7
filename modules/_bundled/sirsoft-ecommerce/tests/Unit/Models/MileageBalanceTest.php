<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Models;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Modules\Sirsoft\Ecommerce\Models\MileageBalance;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * MileageBalance 모델 테스트 (파생 캐시 — casts / 관계 / 유니크)
 */
class MileageBalanceTest extends ModuleTestCase
{
    /**
     * casts: 금액=decimal, 시각=datetime.
     */
    public function test_casts(): void
    {
        $user = User::factory()->create();
        $balance = MileageBalance::create([
            'user_id' => $user->id,
            'currency' => 'KRW',
            'available' => 1000,
            'pending' => 500,
            'total_earned' => 1500,
            'total_used' => 0,
            'expiring_soon' => 200,
            'expiring_date' => now()->addDays(5),
            'recalculated_at' => now(),
        ])->fresh();

        $this->assertSame('1000.00', (string) $balance->available);
        $this->assertInstanceOf(Carbon::class, $balance->expiring_date);
        $this->assertInstanceOf(Carbon::class, $balance->recalculated_at);
    }

    /**
     * user 관계.
     */
    public function test_user_relation(): void
    {
        $user = User::factory()->create();
        $balance = MileageBalance::create(['user_id' => $user->id, 'currency' => 'KRW', 'available' => 0]);

        $this->assertTrue($balance->user->is($user));
    }

    /**
     * (user_id, currency) 유니크 — 동일 조합 중복 생성 거부.
     */
    public function test_user_currency_unique(): void
    {
        $user = User::factory()->create();
        MileageBalance::create(['user_id' => $user->id, 'currency' => 'KRW', 'available' => 0]);

        $this->expectException(QueryException::class);
        MileageBalance::create(['user_id' => $user->id, 'currency' => 'KRW', 'available' => 100]);
    }

    /**
     * 동일 회원 + 다른 통화는 별도 행으로 허용.
     */
    public function test_different_currency_allowed(): void
    {
        $user = User::factory()->create();
        MileageBalance::create(['user_id' => $user->id, 'currency' => 'KRW', 'available' => 0]);
        MileageBalance::create(['user_id' => $user->id, 'currency' => 'USD', 'available' => 0]);

        $this->assertSame(2, MileageBalance::where('user_id', $user->id)->count());
    }
}
