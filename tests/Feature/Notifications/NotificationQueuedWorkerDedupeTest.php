<?php

namespace Tests\Feature\Notifications;

use App\Extension\HookListenerRegistrar;
use App\Extension\HookManager;
use App\Listeners\NotificationHookListener;
use App\Models\NotificationDefinition;
use App\Models\NotificationTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Queue\Console\WorkCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 실제 큐 워커가 알림 훅 잡을 다시 집어도 회원마다 알림이 한 번만 간다 (#139 회귀)
 *
 * 훅 리스너 잡은 실행이 `retry_after`(database 기본 90초)를 넘기면 다른 워커가 같은 잡을 다시 집는다.
 * 종전에는 알림 수신자 루프가 처음부터 다시 돌아, 먼저 돈 사본이 보낸 회원에게 같은 알림이 또 갔다.
 *
 * 재현: 세 번째 발송 직후 그 잡의 `reserved_at` 을 과거로 돌려 「만료된 예약」으로 만들고, 같은 프로세스에서
 * 워커를 한 번 더 돌린다(= 다른 워커가 같은 잡을 다시 집은 상황). **수정 전 코드는 같은 절차에서 16건**
 * (먼저 돈 사본 3 + 다시 집은 사본 8 + 먼저 돈 사본의 나머지 5)을 보냈다.
 *
 * 테스트 기본 큐는 sync 라 이 결함이 보이지 않는다 — database 큐로 발화하고 워커를 따로 돌린다.
 */
class NotificationQueuedWorkerDedupeTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 큐를 database 로 바꿔 훅 리스너가 워커에서 돌게 합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database']);
        // queue:work 는 실패 기록 리스너를 프로세스에 한 번만 붙인다 — 테스트마다 새 앱이므로 다시 붙게 한다
        (new \ReflectionProperty(WorkCommand::class, 'hasRegisteredListeners'))->setValue(null, false);
    }

    /**
     * 다시 예약된 훅 잡이 이미 보낸 회원에게 다시 보내지 않는다.
     *
     * @scenario recipients=within_chunk, rerun=rereserved_copy
     *
     * @effects rereserved_hook_job_does_not_resend
     *
     * @return void 반환값 없음
     */
    public function test_rereserved_hook_job_does_not_resend_already_delivered_recipients(): void
    {
        $users = User::factory()->count(8)->create();
        $hook = 'test.issue139.dedupe.rereserve';
        $definition = $this->createDefinition('issue139_dedupe_rereserve', $users->pluck('uuid')->all());
        $failedBefore = DB::table('failed_jobs')->count();

        HookListenerRegistrar::registerDynamicAction(
            hookName: $hook,
            listenerClass: NotificationHookListener::class,
            method: 'dispatchForDefinition',
            boundArgs: [$definition],
            priority: 30,
            queueOptions: ['tries' => 5],
        );

        // 세 번째 발송 직후 한 번만 — 그 잡의 예약을 만료시키고 다른 워커가 다시 집게 한다
        $sent = 0;
        $rereserved = false;
        HookManager::addAction('core.notification.after_channel_send', function (string $channel) use (&$sent, &$rereserved) {
            if ($channel !== 'database') {
                return;
            }

            $sent++;
            if ($sent !== 3 || $rereserved) {
                return;
            }

            $rereserved = true;
            DB::table('jobs')->whereNotNull('reserved_at')->update(['reserved_at' => now()->subSeconds(1000)->getTimestamp()]);
            $this->runWorker(once: true);
        }, 99);

        HookManager::doAction($hook);
        $this->assertGreaterThan(0, DB::table('jobs')->count(), '알림 훅이 큐에 쌓이지 않았다 — 워커 경로를 재지 못한다');

        $this->runWorker();
        $this->travel(120)->seconds();
        $this->runWorker();

        $this->assertTrue($rereserved, '재예약 재현이 일어나지 않았다');
        $this->assertSame(8, $this->notificationCount($users->pluck('id')->all()), '회원 8명에게 정확히 8건이 가야 한다 (수정 전 16건)');
        foreach ($users as $user) {
            $this->assertSame(1, $this->notificationCount([$user->id]), "회원 {$user->id} 가 알림을 한 번만 받아야 한다");
        }
        $this->assertSame($failedBefore, DB::table('failed_jobs')->count(), '실패한 잡이 없어야 한다');
        $this->assertSame(0, DB::table('jobs')->count(), '남은 잡이 없어야 한다');
    }

    /**
     * 수신자가 묶음 크기를 넘으면 자식 잡으로 나뉘고, 실제 워커에서도 회원마다 한 번씩만 간다.
     *
     * @scenario recipients=over_chunk, rerun=none
     *
     * @effects fanout_children_deliver_once
     *
     * @return void 반환값 없음
     */
    public function test_fanout_children_deliver_each_recipient_once_under_real_worker(): void
    {
        config(['notification.fanout.chunk_size' => 3]);
        $users = User::factory()->count(8)->create();
        $hook = 'test.issue139.dedupe.fanout';
        $definition = $this->createDefinition('issue139_dedupe_fanout', $users->pluck('uuid')->all());
        $failedBefore = DB::table('failed_jobs')->count();

        HookListenerRegistrar::registerDynamicAction(
            hookName: $hook,
            listenerClass: NotificationHookListener::class,
            method: 'dispatchForDefinition',
            boundArgs: [$definition],
            priority: 30,
            queueOptions: ['tries' => 5],
        );

        HookManager::doAction($hook);
        $this->runWorker();

        $this->assertSame(8, $this->notificationCount($users->pluck('id')->all()));
        foreach ($users as $user) {
            $this->assertSame(1, $this->notificationCount([$user->id]), "회원 {$user->id} 가 알림을 한 번만 받아야 한다");
        }
        $this->assertSame($failedBefore, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    /**
     * database 채널 템플릿 하나와 특정 사용자 수신자 규칙을 가진 알림 정의를 만듭니다.
     *
     * @param  string  $type  알림 타입
     * @param  array<int, string>  $uuids  수신자 회원 UUID
     * @return NotificationDefinition 알림 정의
     */
    private function createDefinition(string $type, array $uuids): NotificationDefinition
    {
        $definition = NotificationDefinition::create([
            'type' => $type,
            'hook_prefix' => 'core.test',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => $type],
            'variables' => [],
            'channels' => ['database'],
            'hooks' => [],
            'is_active' => true,
            'is_default' => true,
        ]);

        NotificationTemplate::create([
            'definition_id' => $definition->id,
            'channel' => 'database',
            'subject' => ['ko' => '중복 방지 회귀'],
            'body' => ['ko' => '본문'],
            'recipients' => [['type' => 'specific_users', 'value' => $uuids]],
            'is_active' => true,
            'is_default' => true,
        ]);

        return $definition;
    }

    /**
     * 같은 프로세스에서 database 큐 워커를 돌립니다.
     *
     * @param  bool  $once  true 면 잡 하나만, false 면 큐가 빌 때까지
     * @return void 반환값 없음
     */
    private function runWorker(bool $once = false): void
    {
        // 워커 기본 메모리 한도(128MB)는 테스트 프로세스가 이미 넘어, 첫 작업 뒤 워커가 멈춘다
        Artisan::call('queue:work', [
            'connection' => 'database',
            $once ? '--once' : '--stop-when-empty' => true,
            '--memory' => 4096,
            '--sleep' => 0,
        ]);
    }

    /**
     * 주어진 회원들이 받은 알림함 행 수를 셉니다.
     *
     * @param  array<int, int>  $userIds  회원 ID
     * @return int 행 수
     */
    private function notificationCount(array $userIds): int
    {
        return DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->whereIn('notifiable_id', $userIds)
            ->count();
    }
}
