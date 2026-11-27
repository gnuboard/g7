<?php

namespace Plugins\Sirsoft\VerificationKginicis\Tests\Feature\Listeners;

use App\Enums\IdentityVerificationStatus;
use App\Extension\HookManager;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use App\Services\IdentityLogService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisIdentityLogQueryRepositoryInterface;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisIdentityRecordRepositoryInterface;
use Plugins\Sirsoft\VerificationKginicis\Tests\PluginTestCase;
use RuntimeException;

/**
 * 본인확인 정리 원자성 + 연쇄 삭제 테스트 (공개이슈 #112 / 110)
 *
 * record 파기와 로그 익명화는 함께 성공해야 한다 — 한쪽만 끝나면 파기된 것으로 보이는데
 * 이력에 식별자가 남거나, 그 반대가 된다.
 *
 * 함께, 보관주기 파기(identity:prune-logs)가 challenge 매핑을 리스너로 명시 삭제하는지 고정한다
 * (외래키 연쇄 삭제는 안전망일 뿐 삭제 경로가 아니다).
 *
 * 커버 축: trigger withdraw·delete·prune_logs (탈퇴·삭제는 로그 익명화 실패를 주입한다)
 */
class CleanInicisRecordAtomicityTest extends PluginTestCase
{
    /**
     * 로그 익명화가 실패하면 record 파기도 되돌아간다.
     *
     * @scenario trigger=withdraw
     *
     * @effects record_purge_rolled_back, half_done_state_prevented
     *
     * @return void 반환값 없음
     */
    public function test_record_purge_rolls_back_when_log_anonymize_fails(): void
    {
        $user = User::factory()->create();
        $this->seedRecordForUser($user);

        $this->assertDatabaseHas('inicis_identity_records', ['user_id' => $user->id]);

        // 두 번째 단계(로그 익명화)만 실패시킨다
        $failing = Mockery::mock(app(InicisIdentityLogQueryRepositoryInterface::class));
        $failing->shouldReceive('anonymizeUserId')->andThrow(new RuntimeException('익명화 실패'));
        $this->app->instance(InicisIdentityLogQueryRepositoryInterface::class, $failing);

        try {
            HookManager::doAction('core.user.after_withdraw', $user);
            $this->fail('로그 익명화 실패가 전파되어야 합니다.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('익명화 실패', $e->getMessage());
        }

        // 선행 단계(record 파기)가 원상 복구되어야 한다
        // (실패 시: record 만 파기되고 이력 익명화는 실패한 반쪽 상태)
        $this->assertDatabaseHas('inicis_identity_records', ['user_id' => $user->id]);
    }

    /**
     * 삭제 훅에서도 같은 원자성이 보장된다.
     *
     * @scenario trigger=delete
     *
     * @effects record_purge_rolled_back, half_done_state_prevented
     *
     * @return void 반환값 없음
     */
    public function test_delete_hook_rolls_back_when_log_anonymize_fails(): void
    {
        $user = User::factory()->create();
        $this->seedRecordForUser($user);

        $failing = Mockery::mock(app(InicisIdentityLogQueryRepositoryInterface::class));
        $failing->shouldReceive('anonymizeUserId')->andThrow(new RuntimeException('익명화 실패'));
        $this->app->instance(InicisIdentityLogQueryRepositoryInterface::class, $failing);

        try {
            HookManager::doAction('core.user.before_delete', $user);
            $this->fail('로그 익명화 실패가 전파되어야 합니다.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('익명화 실패', $e->getMessage());
        }

        $this->assertDatabaseHas('inicis_identity_records', ['user_id' => $user->id]);
    }

    /**
     * 보관주기 파기 직전 훅에서 리스너가 challenge 매핑을 명시 삭제한다.
     *
     * 외래키 검사를 꺼 DB 연쇄 삭제가 일어나지 않는 상태로 파기한다 — 그래도 매핑이 0건이면
     * `core.identity_logs.before_purge` 리스너가 지운 것이다.
     *
     * @scenario trigger=prune_logs
     *
     * @effects prune_logs_deletes_challenge_mappings_explicitly
     *
     * @return void 반환값 없음
     */
    public function test_prune_logs_deletes_challenge_mappings_explicitly(): void
    {
        $expiredLog = $this->seedChallengeLog(now()->subDays(200));
        $recentLog = $this->seedChallengeLog(now()->subDays(10));

        $this->assertDatabaseHas('inicis_challenge_mappings', ['challenge_id' => $expiredLog]);
        $this->assertDatabaseHas('inicis_challenge_mappings', ['challenge_id' => $recentLog]);

        // 외래키 연쇄 삭제를 끈다 — 매핑이 지워진다면 리스너가 지운 것이다
        Schema::disableForeignKeyConstraints();
        try {
            app(IdentityLogService::class)->purge(180);
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $this->assertDatabaseMissing('identity_verification_logs', ['id' => $expiredLog]);
        // 실패 시: 이력은 파기됐는데 challenge 매핑이 고아로 남은 상태 (외래키 연쇄에만 기대던 상태)
        $this->assertSame(0, DB::table('inicis_challenge_mappings')->where('challenge_id', $expiredLog)->count());

        // 보관기간 이내 이력과 그 매핑은 보존된다
        $this->assertDatabaseHas('identity_verification_logs', ['id' => $recentLog]);
        $this->assertDatabaseHas('inicis_challenge_mappings', ['challenge_id' => $recentLog]);
    }

    /**
     * 본인확인 record 를 시드합니다.
     *
     * @param  User  $user  대상 사용자
     * @return void 반환값 없음
     */
    private function seedRecordForUser(User $user): void
    {
        app(InicisIdentityRecordRepositoryInterface::class)->upsertForUser((int) $user->id, [
            'name_encrypted' => Crypt::encryptString('홍길동'),
            'phone_encrypted' => Crypt::encryptString('01012345678'),
            'birthday_encrypted' => Crypt::encryptString('19900101'),
            'di_encrypted' => Crypt::encryptString('DI-VAL-'.$user->id),
            'di_hash' => hash('sha256', 'DI-VAL-'.$user->id),
            'gender' => 'M',
            'is_foreigner' => false,
            'is_adult' => true,
            'provider_dev_cd' => 'SKT',
            'verified_at' => now(),
        ]);
    }

    /**
     * challenge 로그 + mTxId 매핑을 시드합니다.
     *
     * @param  Carbon  $createdAt  생성 시각
     * @return string 생성된 challenge UUID
     */
    private function seedChallengeLog($createdAt): string
    {
        $log = IdentityVerificationLog::create([
            'id' => (string) Str::uuid(),
            'provider_id' => 'inicis',
            'purpose' => 'signup',
            'channel' => 'sms',
            'target_hash' => hash('sha256', Str::random()),
            'status' => IdentityVerificationStatus::Verified->value,
            'expires_at' => $createdAt,
        ]);
        $log->forceFill(['created_at' => $createdAt])->saveQuietly();

        DB::table('inicis_challenge_mappings')->insert([
            'mtxid' => Str::random(18),
            'challenge_id' => $log->id,
            'created_at' => $createdAt,
        ]);

        return $log->id;
    }
}
