<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Upgrades;

use App\Enums\UserStatus;
use App\Extension\UpgradeContext;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Psr\Log\AbstractLogger;

/**
 * 탈퇴 회원에게 남은 마일리지 소멸(1.3.1 DataMigration) 테스트
 *
 * 회원 탈퇴는 남은 마일리지를 「탈퇴로 소멸」 거래로 기록해 0 으로 만든다. 1.3.0 의 관리자 수동 지급은 탈퇴 회원을 거르지 않아
 * 탈퇴 뒤 지급된 잔액이 남을 수 있었다. 업그레이드는 그 잔액을 탈퇴 때와 같은 계약(`forfeitForWithdrawal`)으로 소멸 기록한다.
 *
 * 시나리오 매니페스트: `tests/scenarios/withdrawn-member-mileage-ledger.yaml`
 */
class ForfeitWithdrawnMemberMileageTest extends ModuleTestCase
{
    private object $migration;

    /** @var array<int, array{0: string, 1: string, 2: array}> 기록된 로그 */
    private array $records = [];

    private UpgradeContext $context;

    /**
     * 업그레이드 데이터 마이그레이션을 불러오고 로그를 모으는 컨텍스트를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        require_once dirname(__DIR__, 3)
            .'/upgrades/data/1.3.1/migrations/01_ForfeitWithdrawnMemberMileage.php';

        $class = 'App\\Upgrades\\Data\\Ext\\Modules\\SirsoftEcommerce\\V1_3_1\\Migrations\\ForfeitWithdrawnMemberMileage';
        $this->migration = new $class;

        $records = &$this->records;
        $logger = new class($records) extends AbstractLogger
        {
            /**
             * 로그 버퍼를 참조로 받습니다.
             *
             * @param  array  $records  기록 버퍼 (참조)
             */
            public function __construct(private array &$records) {}

            /**
             * 로그 한 줄을 버퍼에 쌓습니다.
             *
             * @param  mixed  $level  수준
             * @param  string|\Stringable  $message  메시지
             * @param  array  $context  문맥
             * @return void 반환값 없음
             */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $level, (string) $message, $context];
            }
        };

        Log::shouldReceive('channel')->once()->andReturn($logger);
        $this->context = new UpgradeContext(fromVersion: '1.3.0', toVersion: '1.3.1', currentStep: '1.3.1');
        Log::clearResolvedInstance('log');
    }

    /**
     * 관리자 지급 적립건을 직접 만듭니다(1.3.0 의 탈퇴 회원 지급 흉내).
     *
     * @param  int  $userId  회원 ID
     * @param  float  $amount  금액
     * @param  mixed  $expiresAt  유효기간 (null 이면 무기한)
     * @return MileageTransaction 적립건
     */
    private function seedAdminLot(int $userId, float $amount, $expiresAt = null): MileageTransaction
    {
        return MileageTransaction::create([
            'user_id' => $userId,
            'currency' => 'KRW',
            'type' => MileageTransactionTypeEnum::ADMIN_EARN->value,
            'amount' => $amount,
            'remaining_amount' => $amount,
            'balance_after' => $amount,
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * 탈퇴 상태 회원을 만듭니다.
     *
     * @return User 회원
     */
    private function withdrawnUser(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['status' => UserStatus::Withdrawn->value, 'withdrawn_at' => now()->subDay()])->save();

        return $user;
    }

    /**
     * 탈퇴 뒤 지급된 잔액을 「탈퇴로 소멸」 거래로 기록해 0 으로 만들고, 적립건 행은 남긴다.
     *
     * @scenario member_state=withdrawn, event=upgrade_backfill, refund_credit=absent
     *
     * @effects upgrade_forfeits_balance_left_on_withdrawn_member
     *
     * @return void 반환값 없음
     */
    public function test_forfeits_balance_left_on_withdrawn_member(): void
    {
        $user = $this->withdrawnUser();
        $lot = $this->seedAdminLot($user->id, 5000);

        $this->migration->run($this->context);

        $this->assertSame(0.0, (float) $lot->fresh()->remaining_amount);
        $this->assertNotNull($lot->fresh()->expired_at);
        $expired = MileageTransaction::query()->where('source_transaction_id', $lot->id)->firstOrFail();
        $this->assertSame(MileageTransactionTypeEnum::EXPIRED, $expired->type);
        $this->assertEquals(-5000, (float) $expired->amount);
        $this->assertSame('withdrawal', $expired->metadata['reason'] ?? null);
        $this->assertTrue(collect($this->records)->contains(fn ($r) => str_contains($r[1], '탈퇴 회원 마일리지 소멸 완료') && ($r[2]['users'] ?? null) === 1 && ($r[2]['lots'] ?? null) === 1));
    }

    /**
     * 정상 회원 잔액·이미 유효기간이 지난 적립건(소멸 배치 몫)은 건드리지 않는다.
     *
     * @scenario member_state=active, event=upgrade_backfill, refund_credit=absent
     *
     * @effects upgrade_forfeits_balance_left_on_withdrawn_member
     *
     * @return void 반환값 없음
     */
    public function test_leaves_active_member_and_lapsed_lot_untouched(): void
    {
        $active = User::factory()->create();
        $activeLot = $this->seedAdminLot($active->id, 3000);
        $withdrawn = $this->withdrawnUser();
        $lapsed = $this->seedAdminLot($withdrawn->id, 1000, now()->subDay());

        $this->migration->run($this->context);

        $this->assertSame(3000.0, (float) $activeLot->fresh()->remaining_amount);
        $this->assertNull($activeLot->fresh()->expired_at);
        $this->assertSame(1000.0, (float) $lapsed->fresh()->remaining_amount);
        $this->assertSame(0, MileageTransaction::query()->where('type', MileageTransactionTypeEnum::EXPIRED->value)->count());
        $this->assertTrue(collect($this->records)->contains(fn ($r) => str_contains($r[1], '소멸 대상 0명')));
    }

    /**
     * 두 번 돌려도 소멸 거래가 한 번만 생긴다 (idempotent — `--force` 재실행 안전).
     *
     * @scenario member_state=withdrawn, event=upgrade_backfill, refund_credit=absent
     *
     * @effects upgrade_forfeits_balance_left_on_withdrawn_member
     *
     * @return void 반환값 없음
     */
    public function test_rerun_is_idempotent(): void
    {
        $user = $this->withdrawnUser();
        $this->seedAdminLot($user->id, 2000);
        $this->seedAdminLot($user->id, 500);

        $this->migration->run($this->context);
        $this->migration->run($this->context);

        $this->assertSame(2, MileageTransaction::query()
            ->where('user_id', $user->id)
            ->where('type', MileageTransactionTypeEnum::EXPIRED->value)
            ->count());
    }
}
