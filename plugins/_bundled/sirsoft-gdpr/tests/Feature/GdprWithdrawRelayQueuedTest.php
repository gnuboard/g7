<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Feature;

use App\Extension\Consent\ConsentManager;
use App\Extension\HookListenerRegistrar;
use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Mockery\MockInterface;
use Plugins\Sirsoft\Gdpr\Listeners\GdprConsentChangeRelayListener;
use Plugins\Sirsoft\Gdpr\Listeners\GdprUserWithdrawListener;
use Plugins\Sirsoft\Gdpr\Models\GdprUserConsent;
use Plugins\Sirsoft\Gdpr\Models\GdprUserConsentHistory;
use Plugins\Sirsoft\Gdpr\Providers\GdprServiceProvider;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;

/**
 * 큐 워커에서 탈퇴를 처리해도 동의 철회가 작업이 끝날 때 코어에 알려진다
 *
 * 탈퇴 리스너는 큐에서 돈다. 그 안에서 모인 동의 변경을 중계 리스너가 「요청 종료」(app()->terminating) 때만
 * 알리면, 워커는 요청이 끝나지 않으므로 워커를 끌 때까지 알림이 밀리거나 사라진다. 한 번 예약되면 다시 예약하지도
 * 않아 이후 작업의 변경은 아예 알리지 못한다. 큐 작업이 끝날 때도 모은 변경을 알린다.
 */
class GdprWithdrawRelayQueuedTest extends PluginTestCase
{
    /**
     * 코어 동의 관리자 대역 (reportChange 호출을 센다).
     *
     * @var MockInterface&ConsentManager
     */
    private $consentManager;

    /**
     * 큐를 database 로 바꾸고 탈퇴·중계 리스너를 운영과 같은 등록기로 등록합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database']);
        $this->app->register(GdprServiceProvider::class);

        HookListenerRegistrar::register(GdprUserWithdrawListener::class, 'sirsoft-gdpr');
        HookListenerRegistrar::register(GdprConsentChangeRelayListener::class, 'sirsoft-gdpr');

        $this->consentManager = Mockery::spy(ConsentManager::class);
        $this->app->instance(ConsentManager::class, $this->consentManager);
    }

    /**
     * 쿠키(분석) 동의 상태인 회원을 만듭니다.
     *
     * @return User 회원
     */
    private function makeConsentingMember(): User
    {
        $user = User::factory()->create();

        GdprUserConsent::create([
            'user_id' => $user->id,
            'consent_key' => 'cookie_analytics',
            'is_consented' => true,
            'consented_at' => now()->subDay(),
            'consent_count' => 1,
            'policy_version' => '1.0',
            'last_source' => 'banner',
        ]);
        GdprUserConsentHistory::create([
            'user_id' => $user->id,
            'consent_key' => 'cookie_analytics',
            'action' => 'granted',
            'source' => 'banner',
            'policy_version' => '1.0',
            'created_at' => now()->subDay(),
        ]);

        return $user;
    }

    /**
     * 큐 워커를 남은 작업이 없을 때까지 돌립니다.
     *
     * @return void 반환값 없음
     */
    private function runWorker(): void
    {
        // 워커 기본 메모리 한도(128MB)는 테스트 프로세스가 이미 넘어, 첫 작업 뒤 워커가 멈추고 나머지 작업이 남는다
        Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--memory' => 4096]);
    }

    /**
     * 탈퇴 작업을 워커가 처리하면 그 작업이 끝날 때 동의 철회가 한 번 알려진다. (실패-먼저)
     *
     * @scenario case=queued_withdraw
     *
     * @effects consent_change_relayed_when_queue_job_ends
     *
     * @return void 반환값 없음
     */
    public function test_withdraw_on_worker_reports_revocation_when_job_ends(): void
    {
        $user = $this->makeConsentingMember();

        HookManager::doAction('core.user.after_withdraw', $user);
        $this->runWorker();

        $this->consentManager->shouldHaveReceived('reportChange')->once();
    }

    /**
     * 같은 워커에서 두 회원이 연달아 탈퇴하면 각각 한 번씩 알린다 — 첫 예약에 묶여 뒤 작업을 놓치지 않는다. (실패-먼저)
     *
     * @scenario case=queued_withdraw_twice
     *
     * @effects consent_change_relayed_when_queue_job_ends
     *
     * @return void 반환값 없음
     */
    public function test_two_withdrawals_on_same_worker_are_reported_separately(): void
    {
        $first = $this->makeConsentingMember();
        $second = $this->makeConsentingMember();

        HookManager::doAction('core.user.after_withdraw', $first);
        HookManager::doAction('core.user.after_withdraw', $second);
        $this->runWorker();

        $this->consentManager->shouldHaveReceived('reportChange')->twice();
    }

    /**
     * 큐 작업이 예외로 끝나면 그 작업이 모은 변경은 버리고, 다음 작업으로 새지 않는다. (실패-먼저)
     *
     * @scenario case=queued_job_failed
     *
     * @effects pending_changes_discarded_on_job_failure
     *
     * @return void 반환값 없음
     */
    public function test_pending_changes_are_discarded_when_job_fails(): void
    {
        $user = $this->makeConsentingMember();
        GdprUserConsentHistory::create([
            'user_id' => $user->id,
            'consent_key' => 'cookie_analytics',
            'action' => 'revoked',
            'source' => 'withdraw',
            'policy_version' => '1.0',
        ]);
        $relay = app(GdprConsentChangeRelayListener::class);
        $relay->onRevoked(null, 'withdraw', 'cookie_analytics', $user->id, null);

        event(new JobExceptionOccurred('database', $this->fakeJob(), new \RuntimeException('작업 실패 대역')));
        event(new JobProcessed('database', $this->fakeJob()));

        $this->consentManager->shouldNotHaveReceived('reportChange');
        $this->assertSame([], app('sirsoft-gdpr.consent-relay.buffer')['pending'], '실패한 작업의 변경이 버퍼에 남았습니다.');
    }

    /**
     * 큐 작업 대역을 만듭니다 (라라벨 문맥 복원이 읽는 payload 만 응답).
     *
     * @return Job 큐 작업 대역
     */
    private function fakeJob(): Job
    {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn([]);
        $job->shouldIgnoreMissing();

        return $job;
    }
}
