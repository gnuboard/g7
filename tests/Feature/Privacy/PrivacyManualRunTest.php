<?php

namespace Tests\Feature\Privacy;

use App\Enums\ExtensionOwnerType;
use App\Enums\PrivacyManualRunStatus;
use App\Enums\UserStatus;
use App\Extension\HookManager;
use App\Http\Resources\PrivacyManualRunResource;
use App\Jobs\RunPrivacyManualActionJob;
use App\Models\ActivityLog;
use App\Models\Permission;
use App\Models\PrivacyManualRun;
use App\Models\Role;
use App\Models\User;
use App\Privacy\Contracts\PrivacyManualRunnerInterface;
use App\Privacy\DormantAccountService;
use App\Privacy\ManualRun\DormantStageRunner;
use App\Privacy\ManualRun\PrivacyManualRunService;
use App\Privacy\PrivacyRetentionManager;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 개인정보 조치 관리자 직권 실행 회귀 테스트 (이슈 #636 후속)
 *
 * 「지금 실행」이 큐에 접수되고, 큐 작업이 토글과 무관하게 처리하고, 결과를 기록·로그로 남기는지,
 * 권한·중복·버려진 기록·배치와의 락 공유·휴면 대기기간 생략을 고정합니다.
 *
 * 테스트 큐는 동기(`sync`)라 접수 요청 안에서 작업이 곧바로 돈다 — 「처리 대기」 상태를 봐야 하는
 * 테스트는 `Queue::fake()` 로 발행만 확인한다.
 */
class PrivacyManualRunTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 개인정보 설정을 기본값(전부 꺼짐)으로 고정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('g7_settings.core.privacy.withdrawn_retention_enabled', false);
        Config::set('g7_settings.core.privacy.withdrawn_retention_days', 365);
        Config::set('g7_settings.core.privacy.dormant_enabled', false);
        Config::set('g7_settings.core.privacy.dormant_days', 730);
        Config::set('g7_settings.core.privacy.dormant_notice_days', 30);
        Config::set('g7_settings.core.privacy.dormant_withdraw_enabled', false);
        Config::set('g7_settings.core.privacy.dormant_withdraw_days', 365);

        FakeManualRunner::$runs = 0;
        FakeManualRunner::$throw = false;
        FakeManualRunner::$exception = null;
    }

    /**
     * 토글이 꺼져 있어도 직권 실행은 접수·처리되고 결과가 기록·활동 로그로 남는다.
     *
     * @return void 반환값 없음
     *
     * @scenario target_kind=retention_handler, toggle=off, actor=runner
     *
     * @effects manual_run_is_queued_and_processed_by_the_job, manual_run_ignores_the_disabled_toggle, run_record_captures_count_and_failure, activity_log_records_queue_and_finish, activity_log_orders_queue_before_finish_on_sync_queue
     */
    public function test_retention_run_processes_even_when_the_toggle_is_off(): void
    {
        $targets = [$this->createWithdrawnUser(400), $this->createWithdrawnUser(500)];
        $kept = $this->createWithdrawnUser(10);
        $admin = $this->createAdmin(['core.settings.update', 'core.users.update']);

        $preview = $this->actingAs($admin)->getJson('/api/admin/privacy/manual-runs/retention:user-withdrawn/preview')->assertOk();
        $this->assertSame(2, $preview->json('data.count'));
        $this->assertFalse($preview->json('data.enabled'), '자동 실행은 꺼진 상태');

        $response = $this->actingAs($admin)->postJson('/api/admin/privacy/manual-runs/retention:user-withdrawn')->assertStatus(202);

        $run = PrivacyManualRun::findOrFail($response->json('data.id'));
        $this->assertSame(PrivacyManualRunStatus::Completed, $run->status, '동기 큐 — 접수와 함께 처리됐다');
        $this->assertSame(2, $run->processed_count);
        $this->assertSame('anonymize', $run->mode);
        $this->assertSame($admin->id, $run->requested_by);

        foreach ($targets as $user) {
            $this->assertStringEndsWith('@removed.invalid', (string) $user->fresh()->email, '꺼져 있어도 직권 실행은 파기한다');
        }
        $this->assertStringEndsWith('@removed.invalid', (string) $targets[0]->fresh()->email);
        $this->assertStringNotContainsString('@removed.invalid', (string) $kept->fresh()->email, '보유기간 이내는 남는다');

        $this->assertDatabaseHas('activity_logs', ['action' => 'privacy.manual_run_queued']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'privacy.manual_run_finished', 'description_key' => 'activity_log.description.privacy_manual_run_completed']);

        // 동기 큐는 접수 요청 안에서 작업까지 끝낸다 — 접수 기록이 먼저 남아야 활동 로그가 일어난 순서로 읽힌다.
        $this->assertLessThan(
            ActivityLog::where('action', 'privacy.manual_run_finished')->value('id'),
            ActivityLog::where('action', 'privacy.manual_run_queued')->value('id'),
            '접수 로그가 완료 로그보다 앞서야 한다'
        );

        // 자동 배치는 여전히 토글을 본다 — 직권 경로만 토글을 넘는다.
        $this->createWithdrawnUser(600);
        $result = app(PrivacyRetentionManager::class)->process(false, 'user-withdrawn');
        $this->assertSame('disabled', $result['user-withdrawn']['status']);
    }

    /**
     * 접수 훅 구독자가 예외를 던져도 작업은 발행되어 처리된다 — 발행 전에 훅이 실패하면 기록은 「대기」로 남아
     * 기준 시간이 지날 때까지 같은 대상의 재접수가 충돌로 막혔다.
     *
     * @return void 반환값 없음
     *
     * @scenario target_kind=retention_handler, toggle=off, actor=runner
     *
     * @effects manual_run_is_queued_and_processed_by_the_job, queued_hook_failure_does_not_block_dispatch
     */
    public function test_queued_hook_subscriber_failure_does_not_block_the_job(): void
    {
        $this->createWithdrawnUser(400);
        $admin = $this->createAdmin(['core.settings.update', 'core.users.update']);

        HookManager::addAction(PrivacyManualRunService::QUEUED_HOOK, function () {
            throw new \RuntimeException('subscriber failure');
        });

        $response = $this->actingAs($admin)->postJson('/api/admin/privacy/manual-runs/retention:user-withdrawn')->assertStatus(202);

        $run = PrivacyManualRun::findOrFail($response->json('data.id'));
        $this->assertSame(PrivacyManualRunStatus::Completed, $run->status, '접수 훅 구독자가 실패해도 작업은 발행·처리된다');
        $this->assertSame(1, $run->processed_count);
    }

    /**
     * 켜져 있어도 같은 경로로 처리되고, 상태 API 가 마지막 결과를 돌려준다.
     *
     * @return void 반환값 없음
     *
     * @scenario target_kind=retention_handler, toggle=on, actor=runner
     *
     * @effects manual_run_is_queued_and_processed_by_the_job, card_shows_active_status_and_last_result
     */
    public function test_status_api_returns_the_last_result_for_runnable_targets(): void
    {
        Config::set('g7_settings.core.privacy.withdrawn_retention_enabled', true);
        $this->createWithdrawnUser(400);
        $admin = $this->createAdmin(['core.settings.update', 'core.users.update']);

        $before = now()->subSecond()->toIso8601String();
        $this->actingAs($admin)->postJson('/api/admin/privacy/manual-runs/retention:user-withdrawn')->assertStatus(202);

        $status = $this->actingAs($admin)
            ->getJson('/api/admin/privacy/manual-runs?targets=retention:user-withdrawn,dormant:convert&since='.urlencode($before))
            ->assertOk();

        $row = $status->json('data.targets.retention:user-withdrawn');
        $this->assertTrue($row['can_run']);
        $this->assertTrue($row['enabled']);
        $this->assertSame('completed', $row['run']['status']);
        $this->assertSame(1, $row['run']['processed_count']);
        $this->assertSame($admin->name, $row['run']['requester_name']);
        $this->assertFalse($status->json('data.has_active'));
        $this->assertTrue($status->json('data.finished_since'), 'since 뒤에 끝난 작업이 있으면 화면이 건수를 다시 받는다');
        $this->assertNotEmpty($status->json('data.checked_at'));
        $this->assertNull($status->json('data.targets.dormant:convert.run'), '기록이 없는 대상');
    }

    /**
     * 설정 읽기·도메인 읽기만 가진 관리자는 실행·건수 API 가 403 이고 상태 API 는 가려진다.
     *
     * @return void 반환값 없음
     *
     * @scenario target_kind=retention_handler, toggle=off, actor=read_only
     *
     * @effects run_requires_settings_and_domain_update_permission, status_api_masks_targets_without_run_permission
     */
    public function test_read_only_admin_cannot_run_or_see_run_records(): void
    {
        $this->createWithdrawnUser(400);
        $runner = $this->createAdmin(['core.settings.update', 'core.users.update']);
        $this->actingAs($runner)->postJson('/api/admin/privacy/manual-runs/retention:user-withdrawn')->assertStatus(202);

        $reader = $this->createAdmin(['core.settings.read', 'core.users.read']);
        $this->actingAs($reader)->postJson('/api/admin/privacy/manual-runs/retention:user-withdrawn')->assertForbidden();
        $this->actingAs($reader)->getJson('/api/admin/privacy/manual-runs/retention:user-withdrawn/preview')->assertForbidden();

        $status = $this->actingAs($reader)->getJson('/api/admin/privacy/manual-runs?targets=retention:user-withdrawn')->assertOk();
        $this->assertFalse($status->json('data.targets.retention:user-withdrawn.can_run'));
        $this->assertNull($status->json('data.targets.retention:user-withdrawn.run'), '접수자 이름이 담긴 기록을 가린다');

        // 설정 수정만 있고 도메인 수정이 없어도 거절 — 설정 화면을 거쳐 회원을 파기하는 우회 차단.
        $settingsOnly = $this->createAdmin(['core.settings.update']);
        $this->actingAs($settingsOnly)->postJson('/api/admin/privacy/manual-runs/retention:user-withdrawn')->assertForbidden();

        // 범위를 좁혀 위임받은 관리자도 거절 — 직권 실행은 도메인 전체를 처리한다.
        $scoped = $this->createAdmin(['core.settings.update', 'core.users.update'], ['core.users.update' => 'role']);
        $this->actingAs($scoped)->postJson('/api/admin/privacy/manual-runs/retention:user-withdrawn')->assertForbidden();

        $this->actingAs($runner)->postJson('/api/admin/privacy/manual-runs/unknown:target')->assertNotFound();
    }

    /**
     * 휴면 전환은 꺼져 있어도 직권 실행되고, 대기기간 생략은 안내를 받지 못한 회원까지 넓힌다.
     *
     * @return void 반환값 없음
     *
     * @scenario target_kind=dormant_stage, toggle=off, actor=runner
     *
     * @effects manual_run_ignores_the_disabled_toggle, skip_waiting_widens_dormant_population_to_unnotified_members
     */
    public function test_dormant_convert_skip_waiting_includes_unnotified_members(): void
    {
        $notified = $this->createInactiveUser(800, now()->subDays(40));
        $unnotified = $this->createInactiveUser(800, null);
        $recentlyNotified = $this->createInactiveUser(800, now()->subDays(3));
        $admin = $this->createAdmin(['core.settings.update', 'core.users.update']);

        $normal = $this->actingAs($admin)->getJson('/api/admin/privacy/manual-runs/dormant:convert/preview')->assertOk();
        $this->assertSame(1, $normal->json('data.count'), '안내 후 기간 경과자만');
        $this->assertTrue($normal->json('data.supports_skip_waiting'));

        $skip = $this->actingAs($admin)->getJson('/api/admin/privacy/manual-runs/dormant:convert/preview?skip_waiting=1')->assertOk();
        $this->assertSame(3, $skip->json('data.count'), '생략하면 기간 경과자 전원');
        $this->assertTrue($skip->json('data.skip_waiting'));

        $response = $this->actingAs($admin)->postJson('/api/admin/privacy/manual-runs/dormant:convert', ['skip_waiting' => true])->assertStatus(202);
        $run = PrivacyManualRun::findOrFail($response->json('data.id'));

        $this->assertTrue($run->skip_waiting);
        $this->assertSame(3, $run->processed_count);
        foreach ([$notified, $unnotified, $recentlyNotified] as $user) {
            $this->assertSame(UserStatus::Dormant->value, $user->fresh()->status);
        }
        $this->assertDatabaseHas('activity_logs', ['action' => 'privacy.manual_run_queued', 'description_key' => 'activity_log.description.privacy_manual_run_queued_skip_waiting']);
    }

    /**
     * 켜진 휴면 탈퇴 단계를 생략으로 돌려도 관리자 계정은 탈퇴되지 않고, 생략 미지원 단계는 옵션을 무시한다.
     *
     * @return void 반환값 없음
     *
     * @scenario target_kind=dormant_stage, toggle=on, actor=runner
     *
     * @effects skip_waiting_keeps_admin_accounts_out_of_withdrawal, skip_waiting_is_ignored_for_stages_that_do_not_support_it
     */
    public function test_dormant_withdraw_skip_waiting_keeps_admin_accounts(): void
    {
        Config::set('g7_settings.core.privacy.dormant_enabled', true);
        Config::set('g7_settings.core.privacy.dormant_withdraw_enabled', true);

        $member = $this->createDormantUser(400);
        $dormantAdmin = $this->createDormantUser(400);
        $this->grantAdminRole($dormantAdmin);

        $service = app(DormantAccountService::class);
        $this->assertSame(0, $service->withdrawQuery()->count(), '탈퇴 예고를 받지 않았다');
        $this->assertSame(1, $service->withdrawQuery(true)->count(), '생략하면 기간 경과자 — 관리자는 제외');

        $admin = $this->createAdmin(['core.settings.update', 'core.users.update']);
        $response = $this->actingAs($admin)->postJson('/api/admin/privacy/manual-runs/dormant:withdraw', ['skip_waiting' => true])->assertStatus(202);

        $this->assertSame(1, PrivacyManualRun::findOrFail($response->json('data.id'))->processed_count);
        $this->assertSame(UserStatus::Withdrawn->value, $member->fresh()->status);
        $this->assertSame(UserStatus::Dormant->value, $dormantAdmin->fresh()->status, '관리자 보호 제외 유지');

        // 사전 안내 단계는 생략을 지원하지 않는다 — 옵션을 보내도 기록에 남지 않는다.
        $pre = $this->actingAs($admin)->postJson('/api/admin/privacy/manual-runs/dormant:pre_notice', ['skip_waiting' => true])->assertStatus(202);
        $this->assertFalse(PrivacyManualRun::findOrFail($pre->json('data.id'))->skip_waiting);
    }

    /**
     * 설정 읽기·회원 읽기만 가진 관리자는 휴면 단계를 직권 실행하지 못한다.
     *
     * @return void 반환값 없음
     *
     * @scenario target_kind=dormant_stage, toggle=off, actor=read_only
     *
     * @effects run_requires_settings_and_domain_update_permission
     */
    public function test_read_only_admin_cannot_run_dormant_stages(): void
    {
        $reader = $this->createAdmin(['core.settings.read', 'core.users.read']);

        foreach (DormantAccountService::STAGES as $stage) {
            $this->actingAs($reader)->postJson("/api/admin/privacy/manual-runs/dormant:{$stage}")->assertForbidden();
        }

        $this->assertSame(0, PrivacyManualRun::count());
    }

    /**
     * 처리 대기 중이면 같은 대상의 접수를 409 로 거절하고, 버려진 기록은 닫고 다시 받는다.
     *
     * @return void 반환값 없음
     *
     * @scenario target_kind=extension_runner, toggle=off, actor=runner
     *
     * @effects extension_registers_manual_run_target_via_filter, duplicate_queue_is_rejected_while_active, stale_active_run_is_closed_and_requeue_allowed
     */
    public function test_duplicate_queue_is_rejected_and_stale_runs_are_closed(): void
    {
        Queue::fake();
        $this->registerFakeRunner();
        $admin = $this->createAdmin(['core.settings.update', 'fake-ext.settings.update']);

        $first = $this->actingAs($admin)->postJson('/api/admin/privacy/manual-runs/fake-ext:notice')->assertStatus(202);
        $this->assertSame('queued', $first->json('data.status'));
        Queue::assertPushed(RunPrivacyManualActionJob::class, fn (RunPrivacyManualActionJob $job) => $job->runId === $first->json('data.id'));

        $this->actingAs($admin)->postJson('/api/admin/privacy/manual-runs/fake-ext:notice')
            ->assertStatus(409)
            ->assertJsonPath('errors.run.id', $first->json('data.id'));

        $status = $this->actingAs($admin)->getJson('/api/admin/privacy/manual-runs?targets=fake-ext:notice')->assertOk();
        $this->assertTrue($status->json('data.has_active'), '화면이 주기 조회를 계속한다');
        $this->assertSame('queued', $status->json('data.targets.fake-ext:notice.run.status'));

        // 워커가 멈춘 채 기준 시간이 지나면 버려진 기록으로 닫고 새 접수를 받는다.
        $this->travel(PrivacyManualRunService::STALE_AFTER_MINUTES + 1)->minutes();
        $second = $this->actingAs($admin)->postJson('/api/admin/privacy/manual-runs/fake-ext:notice')->assertStatus(202);

        $this->assertSame(PrivacyManualRunStatus::Failed, PrivacyManualRun::findOrFail($first->json('data.id'))->status);
        $this->assertNotSame($first->json('data.id'), $second->json('data.id'));

        // 버려진 기록의 사유는 닫은 요청의 언어가 아니라 보는 관리자의 언어로 보인다.
        $staleRun = PrivacyManualRun::findOrFail($first->json('data.id'));
        app()->setLocale('en');
        $this->assertSame(
            trans('privacy.manual_run.stale', ['minutes' => PrivacyManualRunService::STALE_AFTER_MINUTES], 'en'),
            (new PrivacyManualRunResource($staleRun))->toArray(request())['error'],
        );
        app()->setLocale('ko');

        // 늦게 도착한 옛 작업은 닫힌 기록을 다시 열지 않는다.
        $this->assertSame('skipped', app(PrivacyManualRunService::class)->execute($first->json('data.id')));
        $this->assertSame(0, FakeManualRunner::$runs);
    }

    /**
     * 확장 대상은 토글이 켜져 있어도 직권 실행되고, 실패는 사유와 함께 기록된다.
     *
     * @return void 반환값 없음
     *
     * @scenario target_kind=extension_runner, toggle=on, actor=runner
     *
     * @effects extension_registers_manual_run_target_via_filter, run_record_captures_count_and_failure, activity_log_records_queue_and_finish
     */
    public function test_extension_runner_failures_are_recorded(): void
    {
        $this->registerFakeRunner();
        FakeManualRunner::$enabled = true;
        FakeManualRunner::$throw = true;
        $admin = $this->createAdmin(['core.settings.update', 'fake-ext.settings.update']);

        $response = $this->actingAs($admin)->postJson('/api/admin/privacy/manual-runs/fake-ext:notice')->assertStatus(202);
        $run = PrivacyManualRun::findOrFail($response->json('data.id'));

        $this->assertSame(PrivacyManualRunStatus::Failed, $run->status);
        $this->assertSame('시험 실패', $run->error);
        $this->assertNotNull($run->finished_at);
        $this->assertDatabaseHas('activity_logs', ['action' => 'privacy.manual_run_finished', 'description_key' => 'activity_log.description.privacy_manual_run_failed']);

        FakeManualRunner::$throw = false;
        $ok = $this->actingAs($admin)->postJson('/api/admin/privacy/manual-runs/fake-ext:notice')->assertStatus(202);
        $okRun = PrivacyManualRun::findOrFail($ok->json('data.id'));
        $this->assertSame(PrivacyManualRunStatus::Completed, $okRun->status);
        $this->assertSame(7, $okRun->processed_count);
        $this->assertSame(2, $okRun->remaining_count);
    }

    /**
     * 운영 모드에서 DB·PHP 내부 예외로 실패한 기록은 원문 대신 일반 문구를 남긴다 — 카드·상태 API·활동 로그에
     * SQL·DB 호스트·서버 경로가 실리지 않는다(#736 H 영역 실측). 큐 작업이 끝내 실패한 경로(`failed()`)도 같다.
     * 확장이 일부러 던진 사용자용 사유는 {@see test_extension_runner_failures_are_recorded()} 가 그대로 남는지 고정한다.
     *
     * @return void 반환값 없음
     *
     * @scenario target_kind=extension_runner, toggle=on, actor=runner
     *
     * @effects run_failure_reason_hides_internal_detail_in_production
     */
    public function test_internal_failure_reason_is_masked_in_production(): void
    {
        Config::set('app.debug', false);
        $this->registerFakeRunner();
        FakeManualRunner::$enabled = true;
        FakeManualRunner::$exception = new QueryException(
            'mysql',
            'select * from g7_users where status = ?',
            ['dormant'],
            new \PDOException('SQLSTATE[HY000] [2002] Connection refused (Host: 10.0.0.9, Port: 3306, Database: g7_live)'),
        );
        $admin = $this->createAdmin(['core.settings.update', 'fake-ext.settings.update']);
        $generic = __('extensions.errors.internal_error');

        $response = $this->actingAs($admin)->postJson('/api/admin/privacy/manual-runs/fake-ext:notice')->assertStatus(202);
        $run = PrivacyManualRun::findOrFail($response->json('data.id'));

        $this->assertSame(PrivacyManualRunStatus::Failed, $run->status);
        $this->assertStringNotContainsString('SQLSTATE', (string) $run->error);
        $this->assertStringNotContainsString('10.0.0.9', (string) $run->error);

        $status = $this->actingAs($admin)->getJson('/api/admin/privacy/manual-runs?targets=fake-ext:notice')->assertOk();
        $this->assertSame($generic, $status->json('data.targets.fake-ext:notice.run.error'));
        $this->assertStringNotContainsString('SQLSTATE', (string) $status->getContent());
        $this->assertStringNotContainsString('10.0.0.9', (string) $status->getContent());

        // 코어가 붙이는 사유는 실행 시점 언어로 굳지 않고 보는 관리자의 언어로 보인다 — 큐 작업은 요청 언어 없이 돈다.
        $admin->forceFill(['language' => 'en'])->save();
        $en = $this->actingAs($admin)->withHeader('Accept-Language', 'en')
            ->getJson('/api/admin/privacy/manual-runs?targets=fake-ext:notice')->assertOk();
        $this->assertSame(trans('extensions.errors.internal_error', [], 'en'), $en->json('data.targets.fake-ext:notice.run.error'));
        $admin->forceFill(['language' => 'ko'])->save();
        app()->setLocale('ko');

        $log = ActivityLog::query()->where('action', 'privacy.manual_run_finished')->latest('id')->firstOrFail();
        $this->assertStringNotContainsString('SQLSTATE', json_encode($log->properties, JSON_UNESCAPED_UNICODE));

        // 큐 작업이 끝내 실패한 경로 — PHP 경고가 승격된 예외의 원문(서버 경로)도 가린다.
        FakeManualRunner::$exception = null;
        Queue::fake();
        $queued = $this->actingAs($admin)->postJson('/api/admin/privacy/manual-runs/fake-ext:notice')->assertStatus(202);
        $queuedId = $queued->json('data.id');
        (new RunPrivacyManualActionJob($queuedId))->failed(new \ErrorException('Undefined index: x in /var/www/g7/app/Privacy/Secret.php:12'));
        $failedRun = PrivacyManualRun::findOrFail($queuedId);
        $this->assertStringNotContainsString('/var/www', (string) $failedRun->error);
        $this->assertSame($generic, (new PrivacyManualRunResource($failedRun))->toArray(request())['error']);
    }

    /**
     * 디버그 모드에서는 내부 예외 원문을 그대로 남긴다 — 개발 중 원인 확인을 막지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario target_kind=extension_runner, toggle=on, actor=runner
     *
     * @effects run_failure_reason_hides_internal_detail_in_production
     */
    public function test_internal_failure_reason_is_kept_in_debug_mode(): void
    {
        Config::set('app.debug', true);
        $this->registerFakeRunner();
        FakeManualRunner::$enabled = true;
        FakeManualRunner::$exception = new \ErrorException('Undefined index: x in /var/www/g7/app/Privacy/Secret.php:12');
        $admin = $this->createAdmin(['core.settings.update', 'fake-ext.settings.update']);

        $response = $this->actingAs($admin)->postJson('/api/admin/privacy/manual-runs/fake-ext:notice')->assertStatus(202);

        $this->assertStringContainsString('/var/www/g7/app/Privacy/Secret.php', (string) PrivacyManualRun::findOrFail($response->json('data.id'))->error);
    }

    /**
     * 새벽 배치가 락을 잡고 있으면 작업은 다시 시도하도록 기다리고, 직권 실행이 잡고 있으면 배치가 그 도메인을 건너뛴다.
     *
     * @return void 반환값 없음
     *
     * @scenario target_kind=retention_handler, toggle=on, actor=runner
     *
     * @effects job_waits_while_batch_holds_the_lock, batch_command_skips_a_domain_locked_by_manual_run
     */
    public function test_manual_runs_and_batch_commands_share_locks(): void
    {
        Queue::fake();
        Config::set('g7_settings.core.privacy.withdrawn_retention_enabled', true);
        $user = $this->createWithdrawnUser(400);
        $admin = $this->createAdmin(['core.settings.update', 'core.users.update']);

        $runId = $this->actingAs($admin)->postJson('/api/admin/privacy/manual-runs/retention:user-withdrawn')->assertStatus(202)->json('data.id');

        $lock = Cache::lock(PrivacyRetentionManager::lockKey('user-withdrawn'), 60);
        $this->assertTrue($lock->get());

        // 배치(또는 다른 직권 작업)가 처리 중 — 작업은 기록을 처리 대기로 둔 채 다시 시도한다.
        $this->assertSame('busy', app(PrivacyManualRunService::class)->execute($runId));
        $this->assertSame(PrivacyManualRunStatus::Queued, PrivacyManualRun::findOrFail($runId)->status);

        // 같은 락을 잡은 채 배치를 돌리면 그 도메인은 건너뛴다(실패 아님).
        $this->artisan('privacy:process-retention', ['--handler' => 'user-withdrawn'])
            ->expectsOutputToContain('관리자 직권 실행이 처리 중이라 건너뛰었습니다')
            ->assertSuccessful();
        $this->assertStringNotContainsString('@removed.invalid', (string) $user->fresh()->email);

        $dormantLock = Cache::lock(DormantStageRunner::LOCK_KEY, 60);
        $this->assertTrue($dormantLock->get());
        Config::set('g7_settings.core.privacy.dormant_enabled', true);
        $this->artisan('privacy:process-dormant')
            ->expectsOutputToContain('관리자 직권 실행이 휴면 단계를 처리 중이라 이번 주기는 건너뛰었습니다')
            ->assertSuccessful();
        $dormantLock->release();

        $lock->release();
        $this->assertSame('done', app(PrivacyManualRunService::class)->execute($runId));
        $this->assertSame(1, PrivacyManualRun::findOrFail($runId)->processed_count);
    }

    /**
     * 시험용 확장 대상을 필터로 등록합니다.
     *
     * @return void 반환값 없음
     */
    private function registerFakeRunner(): void
    {
        FakeManualRunner::$enabled = false;
        HookManager::addFilter('core.privacy.manual_run_targets', function (array $runners) {
            $runners[] = FakeManualRunner::class;

            return $runners;
        });
    }

    /**
     * 보유기간이 지난 탈퇴 회원을 만듭니다.
     *
     * @param  int  $withdrawnDaysAgo  탈퇴 경과 일수
     * @return User 회원
     */
    private function createWithdrawnUser(int $withdrawnDaysAgo): User
    {
        return User::factory()->create([
            'status' => UserStatus::Withdrawn->value,
            'withdrawn_at' => now()->subDays($withdrawnDaysAgo),
        ]);
    }

    /**
     * 오래 접속하지 않은 활성 회원을 만듭니다.
     *
     * @param  int  $inactiveDays  마지막 활동 경과 일수
     * @param  Carbon|null  $notifiedAt  휴면 사전 안내 시각
     * @return User 회원
     */
    private function createInactiveUser(int $inactiveDays, $notifiedAt): User
    {
        $user = User::factory()->create(['status' => UserStatus::Active->value]);
        User::query()->whereKey($user->id)->update([
            'last_login_at' => now()->subDays($inactiveDays),
            'created_at' => now()->subDays($inactiveDays + 10),
            'dormant_notified_at' => $notifiedAt,
        ]);

        return $user->fresh();
    }

    /**
     * 휴면 상태로 오래된 회원을 만듭니다 (탈퇴 예고 없음).
     *
     * @param  int  $dormantDays  휴면 경과 일수
     * @return User 회원
     */
    private function createDormantUser(int $dormantDays): User
    {
        $user = User::factory()->create(['status' => UserStatus::Dormant->value]);
        User::query()->whereKey($user->id)->update([
            'dormant_at' => now()->subDays($dormantDays),
            'dormant_notified_at' => null,
        ]);

        return $user->fresh();
    }

    /**
     * 관리자 역할(관리자 유형 권한)을 붙입니다.
     *
     * @param  User  $user  회원
     * @return void 반환값 없음
     */
    private function grantAdminRole(User $user): void
    {
        $admin = $this->createAdmin(['core.users.read']);
        $user->roles()->attach($admin->roles->pluck('id')->all(), ['assigned_at' => now(), 'assigned_by' => null]);
    }

    /**
     * 지정한 관리자 권한만 가진 관리자를 만듭니다.
     *
     * @param  array<int, string>  $permissions  권한 식별자 목록
     * @param  array<string, string>  $scopes  권한 식별자 => 적용 범위(`self`·`role`) — 없으면 전체
     * @return User 관리자
     */
    private function createAdmin(array $permissions, array $scopes = []): User
    {
        $user = User::factory()->create();

        $permissionIds = collect($permissions)->map(fn (string $identifier) => Permission::firstOrCreate(
            ['identifier' => $identifier],
            [
                'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                'description' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'type' => 'admin',
            ],
        )->id)->all();

        $role = Role::create([
            'identifier' => 'privacy_manual_run_test_'.uniqid(),
            'name' => json_encode(['ko' => '시험 관리자', 'en' => 'Test admin']),
            'description' => json_encode(['ko' => '시험 관리자', 'en' => 'Test admin']),
            'is_active' => true,
        ]);
        $role->permissions()->sync($permissionIds);

        foreach ($scopes as $identifier => $scope) {
            $role->permissions()->updateExistingPivot(Permission::where('identifier', $identifier)->value('id'), ['scope_type' => $scope]);
        }

        $adminBase = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'description' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ],
        );

        $user->roles()->attach($adminBase->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }
}

/**
 * 시험용 확장 직권 실행 대상 — 실행 횟수를 세고, 요청하면 실패한다.
 */
class FakeManualRunner implements PrivacyManualRunnerInterface
{
    /** 실행 횟수 */
    public static int $runs = 0;

    /** 실행 시 예외 여부 */
    public static bool $throw = false;

    /** 자동 실행 토글 */
    public static bool $enabled = false;

    /** 실행 시 던질 예외(지정하면 `$throw` 보다 먼저) */
    public static ?\Throwable $exception = null;

    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return 'fake-ext:notice';
    }

    /**
     * {@inheritDoc}
     */
    public function label(): string
    {
        return '시험 통지';
    }

    /**
     * {@inheritDoc}
     */
    public function permissions(): array
    {
        return ['fake-ext.settings.update'];
    }

    /**
     * {@inheritDoc}
     */
    public function isEnabled(): bool
    {
        return self::$enabled;
    }

    /**
     * {@inheritDoc}
     */
    public function supportsSkipWaiting(): bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function mode(): ?string
    {
        return 'notify';
    }

    /**
     * {@inheritDoc}
     */
    public function count(bool $skipWaiting = false): int
    {
        return 9;
    }

    /**
     * {@inheritDoc}
     */
    public function run(bool $skipWaiting = false): array
    {
        self::$runs++;

        if (self::$exception !== null) {
            throw self::$exception;
        }

        if (self::$throw) {
            throw new \RuntimeException('시험 실패');
        }

        return ['count' => 7, 'mode' => 'notify', 'remaining' => 2];
    }

    /**
     * {@inheritDoc}
     */
    public function lockKey(): ?string
    {
        return null;
    }
}
