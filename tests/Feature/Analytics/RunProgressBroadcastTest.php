<?php

namespace Tests\Feature\Analytics;

use App\Enums\Analytics\ArchiveRunStatus;
use App\Enums\Analytics\ArchiveRunType;
use App\Events\GenericBroadcastEvent;
use App\Services\Analytics\AnonymizeService;
use App\Services\Analytics\ArchiveService;
use App\Services\Analytics\ExtensionPurgeService;
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
     * @scenario period=range, data=empty, trigger=manual
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
}
