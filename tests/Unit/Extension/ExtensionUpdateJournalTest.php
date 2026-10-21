<?php

namespace Tests\Unit\Extension;

use App\Console\Commands\Traits\ReportsStuckUpdateRecovery;
use App\Enums\ExtensionStatus;
use App\Extension\Helpers\ExtensionOperationLock;
use App\Extension\Helpers\ExtensionUpdateJournal;
use App\Extension\Helpers\StuckUpdateRecovery;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * 확장 업데이트 진행 기록 — 끊긴 업데이트를 이어받는 규칙과 멈춘 업데이트 판정·안내 (7.1.1 출시 전)
 */
class ExtensionUpdateJournalTest extends TestCase
{
    private const ID = 'g7test-journal-ext';

    /**
     * 테스트 준비 — 기록·잠금·정리 기록을 비운다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        ExtensionUpdateJournal::discard('module', self::ID);
        Cache::lock(ExtensionOperationLock::key('module', self::ID))->forceRelease();
        StuckUpdateRecovery::takeRecovered();
    }

    /**
     * 기록·잠금을 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        ExtensionUpdateJournal::discard('module', self::ID);
        Cache::lock(ExtensionOperationLock::key('module', self::ID))->forceRelease();
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * 끝나지 않은 기록을 이어받으면 시작 판은 낮은 쪽, 끝낸 스텝은 그대로 남는다.
     *
     * @scenario source=module, path=journal_resume
     *
     * @effects resumed_journal_keeps_lowest_start_and_completed_steps
     *
     * @return void 반환값 없음
     */
    public function test_begin_resumes_unfinished_journal(): void
    {
        $first = ExtensionUpdateJournal::begin('module', self::ID, '1.1.0', '1.1.4', 'bundled');
        $first->markFilesTouched();
        $first->markStepCompleted('1.1.1');
        $first->markStepCompleted('1.1.1');

        // 끊긴 뒤 DB 판이 1.1.4 로 남은 채 --force 재업데이트가 시작된 경우
        $resumed = ExtensionUpdateJournal::begin('module', self::ID, '1.1.4', '1.1.4', 'bundled');

        $this->assertTrue($resumed->resumed());
        $this->assertSame('1.1.0', $resumed->fromVersion());
        $this->assertSame(['1.1.1'], $resumed->completedSteps());
        $this->assertTrue($resumed->hasCompleted('1.1.1'));
        $this->assertFalse($resumed->hasCompleted('1.1.2'));
        $this->assertFalse($resumed->filesTouched(), '새 시도는 파일을 아직 건드리지 않았다');

        $resumed->finish();
        $this->assertNull(ExtensionUpdateJournal::find('module', self::ID));

        // 지운 뒤에는 실패 정리가 기록을 되살리지 않는다
        $resumed->settleAfterFailure(true);
        $this->assertNull(ExtensionUpdateJournal::find('module', self::ID));
    }

    /**
     * 예외 실패 정리 — 파일을 되돌렸고 끝낸 스텝이 없으면 지우고, 끝낸 스텝이 있으면 남긴다.
     *
     * @scenario source=module, path=journal_failure_settle
     *
     * @effects failure_without_steps_discards_journal, failure_with_steps_keeps_journal
     *
     * @return void 반환값 없음
     */
    public function test_settle_after_failure(): void
    {
        ExtensionUpdateJournal::begin('module', self::ID, '1.0.0', '1.0.2', 'bundled')->settleAfterFailure(true);
        $this->assertNull(ExtensionUpdateJournal::find('module', self::ID));

        $journal = ExtensionUpdateJournal::begin('module', self::ID, '1.0.0', '1.0.2', 'bundled');
        $journal->markFilesTouched();
        $journal->markStepCompleted('1.0.1');
        $journal->settleAfterFailure(true);

        $kept = ExtensionUpdateJournal::find('module', self::ID);
        $this->assertNotNull($kept);
        $this->assertSame(['1.0.1'], $kept->completedSteps());
        $this->assertFalse($kept->filesTouched(), '파일을 되돌렸으니 정리가 「파일이 새 판일 수 있음」을 알리지 않는다');
    }

    /**
     * 목록의 멈춤 판정은 시간 조건과 잠금을 함께 본다 — 잠금이 잡혀 있으면(실제로 도는 업데이트) 멈춤이 아니다.
     *
     * @scenario source=module, path=stuck_listing_judgement
     *
     * @effects listing_stuck_flag_requires_stale_and_free_lock
     *
     * @return void 반환값 없음
     */
    public function test_is_stuck_requires_stale_row_and_free_lock(): void
    {
        $stale = (object) ['status' => ExtensionStatus::Updating->value, 'updated_at' => Carbon::now()->subHour()];
        $recent = (object) ['status' => ExtensionStatus::Updating->value, 'updated_at' => Carbon::now()->subSeconds(30)];
        $active = (object) ['status' => ExtensionStatus::Active->value, 'updated_at' => Carbon::now()->subHour()];

        $this->assertTrue(StuckUpdateRecovery::isStuck('module', self::ID, $stale));
        $this->assertFalse(StuckUpdateRecovery::isStuck('module', self::ID, $recent));
        $this->assertFalse(StuckUpdateRecovery::isStuck('module', self::ID, $active));

        $lock = ExtensionOperationLock::acquire('module', self::ID);
        $this->assertFalse(StuckUpdateRecovery::isStuck('module', self::ID, $stale), '잠금이 잡혀 있으면 업데이트가 아직 돈다');
        $lock->release();
    }

    /**
     * 판 전환을 끝내지 못한 정리는 활성화 명령에서 「업데이트를 다시 실행하라」를 안내하고, 업데이트 명령의 성공 갈래에서는 안내하지 않는다.
     *
     * @scenario source=module, path=command_output_unfinished_hint
     *
     * @effects activate_command_tells_to_finish_unfinished_update
     *
     * @return void 반환값 없음
     */
    public function test_report_tells_to_finish_unfinished_update(): void
    {
        $output = $this->reportWith(false, false, ['from' => '1.1.0', 'to' => '1.1.4']);
        $this->assertStringContainsString('php artisan module:update '.self::ID, $output);
        $this->assertStringContainsString('1.1.0', $output);
        $this->assertStringNotContainsString('module:activate', $output);

        $updateSuccess = $this->reportWith(true, false, ['from' => '1.1.0', 'to' => '1.1.4']);
        $this->assertStringContainsString('php artisan module:activate '.self::ID, $updateSuccess);
        $this->assertStringNotContainsString('module:update', $updateSuccess);

        $updateFailed = $this->reportWith(true, true, ['from' => '1.1.0', 'to' => '1.1.4']);
        $this->assertStringContainsString('php artisan module:update '.self::ID, $updateFailed);

        $finishedChange = $this->reportWith(false, false, null);
        $this->assertStringNotContainsString('module:update', $finishedChange);
    }

    /**
     * 정리 기록 하나를 넣고 트레이트 출력을 받습니다.
     *
     * @param  bool  $suggestActivate  업데이트 명령인지
     * @param  bool  $updateFailed  이어 한 업데이트가 실패했는지
     * @param  array{from: string, to: string}|null  $unfinished  끝나지 않은 판 전환
     * @return string 출력
     */
    private function reportWith(bool $suggestActivate, bool $updateFailed, ?array $unfinished): string
    {
        // 정리 기록은 recover() 로만 쌓이므로 실제로 멈춘 행을 정리시킨다
        if ($unfinished !== null) {
            $journal = ExtensionUpdateJournal::begin('module', self::ID, $unfinished['from'], $unfinished['to'], 'bundled');
            $journal->markFilesTouched();
        }
        $record = (object) ['status' => ExtensionStatus::Updating->value, 'updated_at' => Carbon::now()->subHour(), 'version' => $unfinished['to'] ?? '1.0.0'];
        StuckUpdateRecovery::recover('module', self::ID, $record, fn (array $attributes) => null);
        ExtensionUpdateJournal::discard('module', self::ID);

        $buffer = new BufferedOutput;
        $command = new class extends Command
        {
            use ReportsStuckUpdateRecovery;

            protected $signature = 'g7test:report-stuck';

            /**
             * 트레이트 출력을 부른다.
             *
             * @param  bool  $suggestActivate  업데이트 명령인지
             * @param  bool  $updateFailed  이어 한 업데이트가 실패했는지
             * @return void 반환값 없음
             */
            public function report(bool $suggestActivate, bool $updateFailed): void
            {
                $this->reportStuckUpdateRecovery($suggestActivate, $updateFailed);
            }
        };
        $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));
        $command->report($suggestActivate, $updateFailed);

        return $buffer->fetch();
    }
}
