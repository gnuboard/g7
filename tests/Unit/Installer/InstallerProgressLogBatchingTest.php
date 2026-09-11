<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 단계별 실행의 커맨드 원문이 **한 묶음**으로 기록·전달되는지 (D2 L6).
 *
 * 배경 (PO 현장 제보 2026-09-11 · 「게시판 모듈 설치 단계에서 브라우저 무응답」):
 *   진행 모드마다 로그가 파일에 닿는 단위가 달랐다.
 *     · 폴링(NullEmitter)  — 이미 `addLogBatch` 로 묶어 쓴다
 *     · SSE(SseEmitter)    — 줄 단위. 그 모드의 존재 이유가 실시간 표시라 그대로 둔다
 *     · 단계별(BufferEmitter) — **줄 단위**였다. 응답은 태스크가 끝난 뒤 한 번에 나가므로
 *       줄 단위로 쪼갤 이유가 없는데, 줄마다 `addLog()` 가 파일을
 *       open/flock/write/fflush/close 한다(공유 호스팅 디스크에서 줄당 수 ms).
 *
 *   실측(2026-09-11, 수정 전 리허설): 한 설치가 `[cmd]` 12,798 줄을 만들고, 가장 긴 태스크
 *   하나가 3,844 줄을 뱉었다. 그 태스크의 응답은 **44.0 초** 걸렸고 그 사이 화면은 아무것도
 *   받지 못했다. 응답 JSON 에도 이벤트 객체가 줄 수만큼 실렸다.
 *
 * 잠그는 계약:
 *   1. BufferEmitter 환경에서 N 줄은 `log_batch` **이벤트 1건**이 된다 (줄은 하나도 잃지 않는다).
 *   2. 그 1건이 로그 파일에 N 줄을 남긴다 — 묶는 것이지 접는 것이 아니다.
 *   3. SseEmitter 는 **줄 단위 유지** (회귀 축 — 배치화하면 SSE 가 폴링과 같아진다).
 *   4. 빈 출력은 아무 이벤트도 만들지 않는다.
 *
 * BASE_PATH 를 temp 로 박으므로 클래스 격리 실행이 필요하다 (AddLogUtf8ScrubTest 와 같은 패턴).
 *
 * @scenario progress_log_volume=large
 *
 * @effects stepwise_command_output_is_written_in_one_batch
 */
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
class InstallerProgressLogBatchingTest extends TestCase
{
    private static string $sharedBase = '';

    private static string $skipReason = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $tempPrefix = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
        if (defined('BASE_PATH')) {
            $resolved = realpath((string) BASE_PATH) ?: (string) BASE_PATH;
            if (strpos($resolved, $tempPrefix) !== 0) {
                self::$skipReason = 'BASE_PATH ('.$resolved.') 가 시스템 temp 하위가 아님 — 격리 실행 필요: '.
                    'php artisan test --filter=InstallerProgressLogBatchingTest';

                return;
            }
            self::$sharedBase = (string) BASE_PATH;
        } else {
            self::$sharedBase = sys_get_temp_dir().'/g7-installer-logbatch-'.bin2hex(random_bytes(4));
            define('BASE_PATH', self::$sharedBase);
        }

        if (! is_dir(self::$sharedBase.'/storage/logs')) {
            mkdir(self::$sharedBase.'/storage/logs', 0755, true);
        }

        if (! isset($_SERVER['SCRIPT_NAME'])) {
            $_SERVER['SCRIPT_NAME'] = '/install/index.php';
        }

        require_once dirname(__DIR__, 3).'/public/install/includes/config.php';
        require_once dirname(__DIR__, 3).'/public/install/includes/installer-state.php';
        require_once dirname(__DIR__, 3).'/public/install/includes/progress-emitter.php';
        require_once dirname(__DIR__, 3).'/public/install/includes/task-runner.php';
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$skipReason === '' && self::$sharedBase !== '') {
            foreach (['/storage/logs/installation.log', '/storage/logs/installation.log.1'] as $suffix) {
                if (file_exists(self::$sharedBase.$suffix)) {
                    @unlink(self::$sharedBase.$suffix);
                }
            }
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$skipReason !== '') {
            $this->markTestSkipped(self::$skipReason);
        }

        $logFile = self::$sharedBase.'/storage/logs/installation.log';
        if (file_exists($logFile)) {
            @unlink($logFile);
        }
    }

    private function logLines(): array
    {
        $path = self::$sharedBase.'/storage/logs/installation.log';
        if (! is_file($path)) {
            return [];
        }

        return array_values(array_filter(
            preg_split('/\r\n|\n|\r/', (string) file_get_contents($path)) ?: [],
            static fn (string $line): bool => trim($line) !== ''
        ));
    }
    /**
     * @scenario progress_log_volume=large, installation_mode=stepwise
     *
     * @effects stepwise_command_output_is_written_in_one_batch
     */
    #[Test]
    public function stepwise_command_output_becomes_one_batch_event(): void
    {
        $emitter = new \BufferEmitter;
        setProgressEmitter($emitter);

        $output = [];
        for ($i = 0; $i < 250; $i++) {
            $output[] = "migrating table_{$i}";
        }

        sendCommandOutputSSE($output);

        $events = $emitter->drain();
        $batches = array_values(array_filter($events, static fn (array $e): bool => $e['event'] === 'log_batch'));
        $singles = array_values(array_filter($events, static fn (array $e): bool => $e['event'] === 'log'));

        $this->assertCount(
            1,
            $batches,
            '단계별 경로의 커맨드 출력이 한 묶음이 아니다 — 응답 JSON 에 이벤트가 줄 수만큼 실린다'
        );
        $this->assertSame([], $singles, '묶음과 줄 단위가 섞이면 클라이언트가 같은 줄을 두 번 그린다');

        // **묶는** 것이지 **접는** 것이 아니다 — 줄은 하나도 잃지 않는다 (heartbeat 과 다른 점).
        $this->assertCount(250, $batches[0]['data']['messages'], '묶음이 줄을 잃었다');
        $this->assertStringStartsWith(
            INSTALLER_COMMAND_OUTPUT_PREFIX,
            $batches[0]['data']['messages'][0],
            '커맨드 원문 표식이 붙지 않으면 화면이 그 줄을 진행 기록으로 그린다'
        );

        // 파일 쓰기도 한 번이지만, 남는 줄 수는 그대로다.
        $this->assertCount(250, $this->logLines(), '묶어 쓰면서 줄이 사라졌다 — 사후 분석 근거가 없어진다');
    }
    /**
     * @scenario progress_log_volume=small, installation_mode=stepwise
     *
     * @effects stepwise_command_output_is_written_in_one_batch
     */
    #[Test]
    public function empty_output_emits_nothing(): void
    {
        $emitter = new \BufferEmitter;
        setProgressEmitter($emitter);

        sendCommandOutputSSE([]);
        sendCommandOutputSSE(['', '   ', "\t"]);

        $this->assertSame([], $emitter->drain(), '빈 출력이 이벤트를 만들면 빈 접기 영역이 열린다');
        $this->assertSame([], $this->logLines());
    }

    /**
     * BufferEmitter 가 **아닌** 경로는 줄 단위를 그대로 유지한다 (회귀 축).
     *
     * SSE 는 그 모드의 존재 이유가 줄 단위 실시간 표시라 배치화하면 폴링과 같아진다.
     * 여기서는 같은 분기를 타는 `NullEmitter` 로 잰다 — `SseEmitter` 는 생성 자체가 출력
     * 버퍼·헤더를 만지므로 단위 테스트에서 세울 수 있는 대상이 아니다. 「SSE 를 묶지 않는다」는
     * 축은 아래 소스 계약(분기가 `BufferEmitter` 만 지목한다)이 함께 잠근다.
     */
    /**
     * @scenario progress_log_volume=small, installation_mode=polling
     *
     * @effects stepwise_command_output_is_written_in_one_batch
     */
    #[Test]
    public function non_buffer_emitters_keep_line_by_line_emission(): void
    {
        $emitter = new \NullEmitter;
        setProgressEmitter($emitter);

        sendCommandOutputSSE(['a', 'b', 'c']);

        $this->assertCount(3, $this->logLines(), '줄 단위 경로가 묶여 버렸다 — 실시간 표시가 사라진다');
    }

    /**
     * 세 모드의 분기가 **소스에 실재하는지** 함께 잠근다 — 위 단언은 emitter 종류로만
     * 갈리므로, 분기 자체가 사라지고 전부 줄 단위가 되어도 두 케이스는 초록일 수 있다.
     */
    /**
     * @scenario progress_log_volume=large, installation_mode=stepwise
     *
     * @effects stepwise_command_output_is_written_in_one_batch
     */
    #[Test]
    public function batching_branch_is_explicit_about_the_emitter_it_targets(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3).'/public/install/includes/task-runner.php');

        $this->assertStringContainsString(
            'instanceof BufferEmitter',
            $source,
            '어느 모드를 묶는지 코드가 말하지 않는다 — 모드별 규칙이 주석에만 남으면 다음 편집자가 전부 묶는다'
        );
        $this->assertStringContainsString(
            'emitLogBatch(',
            $source,
            '배치 전용 통로를 쓰지 않는다 — emit(\'log\') 를 반복하면 파일 쓰기가 줄마다 일어난다'
        );
    }
}
