<?php

namespace Tests\Feature\Analytics;

use App\Analytics\AnalyticsClock;
use App\Enums\Analytics\ArchiveRunStatus;
use App\Enums\Analytics\ArchiveRunType;
use App\Events\GenericBroadcastEvent;
use App\Models\Analytics\AnalyticsArchiveRun;
use App\Services\Analytics\AnonymizeService;
use App\Services\Analytics\ArchiveService;
use App\Services\Analytics\Concerns\BroadcastsRunProgress;
use App\Services\Analytics\ExtensionPurgeService;
use App\Services\Analytics\StaleRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * 집계가 아닌 실행의 진행률 방송 — 진단 화면의 「실행 중」이 스스로 풀리는가.
 *
 * 진단 화면은 `analytics_archive_runs` 의 running 행 하나를 진행률의 출처로 삼는다. 그 행이
 * 있으면 「실행 중」 배지가 뜨고 수동 집계 버튼 두 개가 잠긴다. 그런데 이 표에 쓰는 실행은 집계만이
 * 아니다 — 소급 익명화와 확장 데이터 정리도 같은 표에 running 행을 만든다.
 *
 * 소켓을 켠 사이트에서 화면을 다시 받는 유일한 신호가 이 방송이다. 익명화·정리가 방송하지 않으면
 * 작업이 끝나도 배지와 잠금이 새로고침 전까지 남는다(#682 S3 라운드 2 — PO 결정 「익명화·정리도
 * 방송하게 한다」). 그래서 두 서비스가 시작과 종료를 같은 채널로 알리는지 직접 잰다.
 */
class RunProgressBroadcastTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 방송 드라이버를 켜고 이벤트를 가로챕니다.
     *
     * @return void 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.options.host' => 'localhost']);
        Event::fake([GenericBroadcastEvent::class]);
    }

    /**
     * 관리자 채널로 나간 실행 이력 방송 중 조건에 맞는 것이 있는지 봅니다.
     *
     * @param  string  $runType  실행 종류
     * @param  string  $status  기대 상태
     * @return void 없음
     */
    private function assertBroadcast(string $runType, string $status): void
    {
        Event::assertDispatched(
            GenericBroadcastEvent::class,
            static fn (GenericBroadcastEvent $event): bool => $event->channel === ArchiveService::PROGRESS_CHANNEL
                && $event->eventName === ArchiveService::PROGRESS_EVENT
                && ($event->payload['type'] ?? null) === 'archive_progress'
                && ($event->payload['data']['run_type'] ?? null) === $runType
                && ($event->payload['data']['status'] ?? null) === $status,
        );
    }

    /**
     * 소급 익명화가 시작과 끝을 방송하는지 확인합니다.
     *
     * @scenario target=visits, row_age=within, dry_run=false, retention_source=setting
     *
     * @effects non_archive_runs_broadcast_their_progress
     *
     * @return void 없음
     */
    public function test_retroactive_anonymize_broadcasts_start_and_finish(): void
    {
        app(AnonymizeService::class)->anonymize(['mask_octets' => 2]);

        $this->assertBroadcast(ArchiveRunType::Anonymize->value, ArchiveRunStatus::Running->value);
        $this->assertBroadcast(ArchiveRunType::Anonymize->value, ArchiveRunStatus::Success->value);
    }

    /**
     * 확장 데이터 정리가 시작과 끝을 방송하는지 확인합니다.
     *
     * @scenario target=conversions, row_age=within, dry_run=false, retention_source=setting
     *
     * @effects non_archive_runs_broadcast_their_progress
     *
     * @return void 없음
     */
    public function test_extension_purge_broadcasts_start_and_finish(): void
    {
        app(ExtensionPurgeService::class)->purge('module', 'sirsoft-ecommerce');

        $this->assertBroadcast(ArchiveRunType::ExtensionPurge->value, ArchiveRunStatus::Running->value);
        $this->assertBroadcast(ArchiveRunType::ExtensionPurge->value, ArchiveRunStatus::Success->value);
    }

    /**
     * 건너뛴 실행도 종료를 방송하는지 확인합니다.
     *
     * 건너뜀은 종료 상태다. 방송하지 않으면 소켓을 켠 사이트에서 화면의 `skipped` 분기가 도달하지
     * 못해, 전부 건너뛴 재집계는 시작 토스트만 남기고 이력 표가 새로고침 전까지 그대로 선다
     * (#682 S3 라운드 3).
     *
     * @scenario target=archive_runs, row_age=within, dry_run=false, retention_source=setting
     *
     * @effects non_archive_runs_broadcast_their_progress
     *
     * @return void 없음
     */
    public function test_skipped_run_broadcasts_its_terminal_state(): void
    {
        // 보존일 밖 날짜는 집계하지 않고 건너뜀 이력만 남긴다 — 시드 없이 그 분기를 탄다.
        $old = now()->subYears(3)->format('Y-m-d');

        app(ArchiveService::class)->archiveRange($old, $old, [
            'run_type' => ArchiveRunType::Reaggregate->value,
            'trigger' => 'manual',
            'force' => false,
        ]);

        $this->assertBroadcast(ArchiveRunType::Reaggregate->value, ArchiveRunStatus::Skipped->value);
    }

    /**
     * 실행 이력 행을 만드는 서비스는 전부 방송 트레이트를 쓴다(모집단 파생).
     *
     * 손으로 적은 목록은 네 번째 「running 행을 여는 서비스」가 붙어도 조용히 통과한다. 서비스 폴더에서
     * 실행 이력 저장소로 행을 만드는(`->runs->create(`) 클래스를 파생해 트레이트 사용을 단언한다.
     *
     * @return void 없음
     *
     * @scenario target=archive_runs, row_age=within, dry_run=false, retention_source=setting
     *
     * @effects every_run_writing_service_broadcasts_progress
     */
    public function test_every_service_that_opens_a_run_broadcasts(): void
    {
        $services = [];

        foreach (glob(app_path('Services/Analytics/*.php')) as $file) {
            $source = (string) file_get_contents($file);

            if (str_contains($source, '->runs->create(')) {
                $services[] = 'App\\Services\\Analytics\\'.basename($file, '.php');
            }
        }

        $this->assertNotEmpty($services, '파생 모집단이 비었다 — 판정식이 낡았다');

        foreach ($services as $class) {
            $this->assertContains(BroadcastsRunProgress::class, class_uses_recursive($class), "{$class} 는 실행 이력을 열지만 방송하지 않는다");
        }

        // 행을 닫기만 하는 정리 서비스도 같은 트레이트다.
        $this->assertContains(BroadcastsRunProgress::class, class_uses_recursive(StaleRunService::class));
    }

    /**
     * 방송이 싣는 시각은 목록 API 와 같은 사이트 표준시다.
     *
     * 방송만 UTC 원값을 실으면, 화면이 방송 행에서 시각을 그리는 순간 목록과 몇 시간 어긋난다.
     *
     * @return void 없음
     *
     * @scenario target=archive_runs, row_age=within, dry_run=false, retention_source=setting
     *
     * @effects progress_broadcast_times_use_the_site_timezone
     */
    public function test_broadcast_times_are_in_the_site_timezone(): void
    {
        config(['app.timezone' => 'UTC', 'g7_settings.core.general.timezone' => 'Asia/Seoul']);

        $run = AnalyticsArchiveRun::factory()->create([
            'started_at' => '2026-09-01 15:30:00',
            'finished_at' => null,
        ]);

        (new class
        {
            use BroadcastsRunProgress;

            /**
             * 방송을 부릅니다.
             *
             * @param  AnalyticsArchiveRun  $run  실행 이력
             * @return void 없음
             */
            public function fire(AnalyticsArchiveRun $run): void
            {
                $this->broadcastRunProgress($run);
            }
        })->fire($run);

        Event::assertDispatched(
            GenericBroadcastEvent::class,
            fn (GenericBroadcastEvent $event): bool => ($event->payload['data']['started_at'] ?? null) === AnalyticsClock::toSiteDateTimeString($run->started_at)
                && ($event->payload['data']['started_at'] ?? null) === '2026-09-02 00:30:00',
        );
    }
}
