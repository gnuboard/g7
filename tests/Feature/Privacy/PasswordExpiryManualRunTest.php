<?php

namespace Tests\Feature\Privacy;

use App\Jobs\RunPrivacyManualActionJob;
use App\Models\PrivacyManualRun;
use App\Privacy\ManualRun\PrivacyManualRunRegistry;
use App\Privacy\ManualRun\PrivacyManualRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PasswordPolicyFixtures;
use Tests\TestCase;

/**
 * 비밀번호 만료 직권 실행 테스트 (#736 A9·D22·D25)
 *
 * 보안 탭 「지금 실행」이 개인정보 탭과 같은 접수·진행 장치(직권 실행 대상 수집기 + 큐 작업)로 돈다.
 * 러너가 두 단계로 등록되고, 이번 실행 건수 = min(대상, 상한), 남은 수 = 대상 − 처리, 권한·중복 접수를
 * 지키는지 본다. 휴면·보존기간과 달리 만료가 꺼져 있으면 접수를 거절하고, 접수 뒤 꺼지면 처리하지 않는다.
 */
class PasswordExpiryManualRunTest extends TestCase
{
    use PasswordPolicyFixtures;
    use RefreshDatabase;

    /**
     * 두 단계 러너가 코어 대상으로 등록된다.
     *
     * @scenario surface=registry
     *
     * @effects runners_registered_for_both_stages
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function both_stage_runners_are_registered(): void
    {
        $runners = app(PrivacyManualRunRegistry::class)->runners();

        $this->assertArrayHasKey('password_expiry:notice', $runners);
        $this->assertArrayHasKey('password_expiry:expire', $runners);
        $this->assertSame(['core.settings.update', 'core.users.update'], $runners['password_expiry:notice']->permissions());
    }

    /**
     * 상한 2 면 직권 실행이 이번 실행 2명·남은 1명을 기록한다.
     *
     * @scenario stage=notice, toggle=on, batch_size=2, actor=runner
     *
     * @effects manual_run_respects_cap_and_records_remaining
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function a_manual_run_processes_with_the_cap(): void
    {
        $this->enableExpiry(['password_expiry_batch_size' => 2]);
        foreach ([89, 88, 84] as $days) {
            $this->makeMember(['password_changed_at' => now()->subDays($days)]);
        }
        $admin = $this->makeLimitedAdmin(['core.settings.update', 'core.users.update']);

        $preview = $this->actingAs($admin)->getJson('/api/admin/privacy/manual-runs/password_expiry:notice/preview')->assertOk();
        $this->assertSame(2, $preview->json('data.count'), '이번 실행 건수 = min(대상 3, 상한 2)');

        $response = $this->actingAs($admin)->postJson('/api/admin/privacy/manual-runs/password_expiry:notice')->assertStatus(202);

        $run = PrivacyManualRun::findOrFail($response->json('data.id'));
        $this->assertSame(2, $run->processed_count);
        $this->assertSame(1, $run->remaining_count);
    }

    /**
     * 만료가 꺼져 있으면 두 단계 모두 접수를 409 로 거절하고 아무도 처리하지 않는다(#736, 2026-10-03 확정).
     *
     * 꺼져 있으면 로그인 관문이 변경을 요구하지 않으므로, 처리하면 "다음 로그인 때 변경" 안내와 로그아웃만 남는다.
     * 확인 창 건수는 계속 저장값으로 센다 — 화면이 「꺼짐」 안내와 함께 숫자를 보여 준다.
     *
     * @scenario stage=notice|expire, toggle=off, action=force, actor=runner
     *
     * @effects manual_run_rejected_while_disabled
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function a_manual_run_is_rejected_while_expiry_is_off(): void
    {
        $this->enableExpiry(['password_expiry_enabled' => false]);
        $noticed = $this->makeMember(['password_changed_at' => now()->subDays(88)]);
        $expired = $this->makeMember(['password_changed_at' => now()->subDays(100)]);
        $this->issueToken($expired);
        $admin = $this->makeLimitedAdmin(['core.settings.update', 'core.users.update']);

        foreach (['notice', 'expire'] as $stage) {
            $preview = $this->actingAs($admin)->getJson("/api/admin/privacy/manual-runs/password_expiry:{$stage}/preview")->assertOk();
            $this->assertSame(1, $preview->json('data.count'), "{$stage}: 확인 창 건수는 저장값으로 센다");
            $this->assertFalse($preview->json('data.enabled'));

            $this->actingAs($admin)->postJson("/api/admin/privacy/manual-runs/password_expiry:{$stage}")
                ->assertStatus(409)
                ->assertJsonPath('message', __('exceptions.privacy_manual_run_disabled'))
                ->assertJsonMissingPath('errors.run');
        }

        $this->assertSame(0, PrivacyManualRun::count(), '실행 기록을 만들지 않는다');
        $this->assertNull($noticed->fresh()->password_expiry_notified_at);
        $this->assertNull($expired->fresh()->password_expired_processed_at);
        $this->assertSame(1, $expired->tokens()->count(), '로그인을 풀지 않는다');
    }

    /**
     * 접수한 뒤 실행 전에 만료를 끄면, 큐 작업이 돌아도 아무도 처리하지 않는다.
     *
     * @scenario stage=notice|expire, toggle=off, action=force, concurrency=turned_off_after_queue
     *
     * @effects queued_run_processes_nothing_once_disabled
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function a_run_queued_before_turning_expiry_off_processes_nothing(): void
    {
        Queue::fake();
        $this->enableExpiry();
        $noticed = $this->makeMember(['password_changed_at' => now()->subDays(88)]);
        $expired = $this->makeMember(['password_changed_at' => now()->subDays(100)]);
        $this->issueToken($expired);
        $admin = $this->makeLimitedAdmin(['core.settings.update', 'core.users.update']);

        $runIds = [];
        foreach (['notice', 'expire'] as $stage) {
            $runIds[$stage] = $this->actingAs($admin)->postJson("/api/admin/privacy/manual-runs/password_expiry:{$stage}")
                ->assertStatus(202)->json('data.id');
        }

        $this->setSecurity(['password_expiry_enabled' => false]);

        foreach ($runIds as $stage => $runId) {
            app(PrivacyManualRunService::class)->execute($runId);
            $this->assertSame(0, PrivacyManualRun::findOrFail($runId)->processed_count, "{$stage}: 처리 0건");
        }

        $this->assertNull($noticed->fresh()->password_expiry_notified_at);
        $this->assertNull($expired->fresh()->password_expired_processed_at);
        $this->assertSame(1, $expired->tokens()->count(), '로그인을 풀지 않는다');
    }

    /**
     * 실행 권한(설정 변경 + 회원 변경)이 없으면 403 이다.
     *
     * @scenario stage=notice|expire
     *
     * @effects manual_run_requires_run_permissions
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function running_requires_both_update_permissions(): void
    {
        $reader = $this->makeLimitedAdmin(['core.settings.read', 'core.users.read', 'core.settings.update']);

        foreach (['notice', 'expire'] as $stage) {
            $this->actingAs($reader)->postJson("/api/admin/privacy/manual-runs/password_expiry:{$stage}")->assertForbidden();
        }
    }

    /**
     * 처리 대기 중이면 같은 단계 접수를 409 로 거절한다.
     *
     * @scenario stage=expire, concurrency=duplicate
     *
     * @effects duplicate_run_rejected
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function a_duplicate_run_is_rejected(): void
    {
        Queue::fake();
        $this->enableExpiry();
        $admin = $this->makeLimitedAdmin(['core.settings.update', 'core.users.update']);

        $first = $this->actingAs($admin)->postJson('/api/admin/privacy/manual-runs/password_expiry:expire')->assertStatus(202);
        Queue::assertPushed(RunPrivacyManualActionJob::class);

        $this->actingAs($admin)->postJson('/api/admin/privacy/manual-runs/password_expiry:expire')
            ->assertStatus(409)
            ->assertJsonPath('errors.run.id', $first->json('data.id'));
    }

    /**
     * 설정을 보기만 하는 관리자도 만료 카드의 마지막 직권 실행 기록(실행자 이름 포함)을 받는다 — 화면은 「지금 실행」을
     * 비활성으로 보이고 그 아래 마지막 실행 줄을 그린다(2026-10-04 결정). 실행 권한은 `can_run` 으로만 판정하고,
     * 개인정보 탭 대상(보유기간·휴면)은 종전대로 실행 권한이 없으면 기록을 가린다.
     *
     * @scenario stage=notice|expire
     *
     * @effects settings_reader_sees_last_run_but_cannot_run
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function a_settings_reader_sees_the_last_run_but_cannot_run(): void
    {
        $this->enableExpiry();
        $runner = $this->makeLimitedAdmin(['core.settings.update', 'core.users.update']);
        $this->actingAs($runner)->postJson('/api/admin/privacy/manual-runs/password_expiry:notice')->assertStatus(202);

        $reader = $this->makeLimitedAdmin(['core.settings.read']);
        $status = $this->actingAs($reader)
            ->getJson('/api/admin/privacy/manual-runs?targets=password_expiry:notice,password_expiry:expire,retention:user-withdrawn')
            ->assertOk();

        $notice = $status->json('data.targets.password_expiry:notice');
        $this->assertFalse($notice['can_run']);
        $this->assertSame($runner->name, $notice['run']['requester_name']);
        $this->assertNull($status->json('data.targets.password_expiry:expire.run'), '기록이 없는 단계');
        $this->assertFalse($status->json('data.targets.password_expiry:expire.can_run'));
        $this->assertNull($status->json('data.targets.retention:user-withdrawn.run'), '개인정보 탭 대상은 종전대로 가린다');

        $this->actingAs($reader)->postJson('/api/admin/privacy/manual-runs/password_expiry:notice')->assertForbidden();

        $outsider = $this->makeLimitedAdmin(['core.users.read']);
        $this->assertNull(
            $this->actingAs($outsider)->getJson('/api/admin/privacy/manual-runs?targets=password_expiry:notice')->json('data.targets.password_expiry:notice.run'),
            '설정 읽기 권한이 없으면 가린다',
        );
    }
}
