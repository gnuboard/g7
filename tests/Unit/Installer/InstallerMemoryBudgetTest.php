<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\CleansTemporaryBasePath;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 메모리 예산 축 회귀 가드 (20차 1· 2· 3)
 *
 * - ** 1 권장치 표류**: Step 2 카드는 256M 을 권장으로 칠했는데 실행 경로 둘(subprocess
 *   `-d memory_limit=512M` · in-process 상향)은 512M 을 요구했다. 256~512M 호스트는 화면을
 *   초록으로 지나고 설치 중반 OOM 을 만난다. 세 선언을 **소스에서 추출해 등치**로 잠근다 —
 *   어느 하나만 바꾸면 red 가 된다 (수기 상수 복제 금지, §16.25.10 규약 ②).
 * - ** 1 상향 무기록**: `installerIniSet()` 은 void 라 상향 실패가 어디에도 남지 않았다.
 *   시간 예산(실적용치 반환·선언)과 비대칭이다. in-process 부팅이 상향 후 재조회해 미달이면
 *   진행 기록에 남기는 배선을 소스 계약으로 고정한다.
 * - ** 2 로그 전량 배열화**: `getInstallationLogs()` 가 offset 절단을 **배열화 뒤에** 했다 —
 *   회전·상한 없는 파일 전량이 연관배열로 부풀려진 뒤 버려진다. 절단이 매핑보다 앞임을
 *   소스 계약으로, offset 의미가 불변임을 행위로 고정한다.
 * - ** 3 파서 이중화**: `installerIniBytes`(알 수 없는 단위를 조용히 숫자로 접음)와
 *   `installerParseByteSize`(-1 보존·해석 불가 null)의 규칙이 달랐다. 전자는 후자로 위임한다.
 *
 * 효과 요약(마커 아님 — 평문): installer_memory_budget_declarations_are_consistent
 */
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
class InstallerMemoryBudgetTest extends TestCase
{
    use CleansTemporaryBasePath;
    use ScansPhpSourceFunctions;

    private string $projectRoot = '';

    /**
     * 임시 BASE_PATH 와 로그 폴더를 만들고 언어 스텁, 설치기 설정, 상태, 실행 모드, Artisan 실행기 모듈을 적재한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->projectRoot = dirname(__DIR__, 3);

        if (! defined('BASE_PATH')) {
            define('BASE_PATH', sys_get_temp_dir().'/g7-memory-budget-'.bin2hex(random_bytes(4)));
            @mkdir(BASE_PATH.'/storage/logs', 0755, true);
        }

        require_once __DIR__.'/stubs/lang_stub.php';
        require_once $this->projectRoot.'/public/install/includes/config.php';
        require_once $this->projectRoot.'/public/install/includes/installer-state.php';
        require_once $this->projectRoot.'/public/install/includes/execution-mode.php';
        require_once $this->projectRoot.'/public/install/includes/artisan-executor.php';
    }

    /**
     * 1: 권장치(Step 2)·subprocess `-d`·in-process 상향 목표 — 세 선언은 같은 값이어야 한다.
     *
     * @scenario execution_mode_resolved=subprocess
     *
     * @effects installer_memory_budget_declarations_are_consistent
     *
     * @return void 반환값 없음
     */
    public function test_memory_declarations_agree_across_screen_and_both_strategies(): void
    {
        // Step 2 요구사항 판정 본문은 웹·터미널 설치 공유 파일(includes/requirements.php)로 옮겨졌다.
        $api = $this->sourceWithoutComments($this->projectRoot.'/public/install/includes/requirements.php');
        $executor = $this->sourceWithoutComments($this->projectRoot.'/public/install/includes/artisan-executor.php');

        $this->assertSame(1, preg_match('/\$recommendedMemoryMb = (\d+);/', $api, $screen), 'Step 2 권장치 선언을 찾지 못함');
        $this->assertSame(1, preg_match('/-d\W+memory_limit=(\d+)M/', $executor, $subprocess), 'subprocess -d 선언을 찾지 못함');
        $this->assertSame(1, preg_match('/installerIniSet\(\'memory_limit\', \'(\d+)M\'\)/', $executor, $inprocess), 'in-process 상향 선언을 찾지 못함');

        $this->assertSame($subprocess[1], $inprocess[1], '두 실행 전략의 메모리 목표가 갈라졌다');
        $this->assertSame($subprocess[1], $screen[1], 'Step 2 권장치가 실행 경로의 실제 요구와 다르다 — 화면이 초록인데 설치가 OOM 으로 죽는 구간이 생긴다');
    }

    /**
     * 1: in-process 상향은 재조회로 실적용을 확인하고, 미달이면 진행 기록에 남긴다.
     *
     * @scenario execution_mode_resolved=inprocess
     *
     * @effects installer_memory_budget_declarations_are_consistent
     *
     * @return void 반환값 없음
     */
    public function test_inprocess_memory_raise_shortfall_is_recorded(): void
    {
        $executor = $this->sourceWithoutComments($this->projectRoot.'/public/install/includes/artisan-executor.php');

        $this->assertStringContainsString(
            'log_memory_limit_raise_shortfall',
            $executor,
            '상향 미달의 진행 기록 배선 부재 — installerIniSet 은 void 라 실패가 어디에도 남지 않는다'
        );
        // 다국어 등재 (별개 채널 — ko/en 대칭은 InstallerLanguageParityTest 소유)
        $ko = $this->sourceWithoutComments($this->projectRoot.'/public/install/lang/ko.php');
        $this->assertStringContainsString('log_memory_limit_raise_shortfall', $ko);
    }

    /**
     * 3: installerIniBytes 는 installerParseByteSize 로 위임한다 — 해석 불가는 null, -1 보존.
     *
     * @scenario execution_mode_resolved=inprocess
     *
     * @effects installer_memory_budget_declarations_are_consistent
     *
     * @return void 반환값 없음
     */
    public function test_ini_bytes_parser_is_unified(): void
    {
        $this->assertSame(installerParseByteSize('128M'), installerIniBytes('128M'));
        $this->assertSame(128 * 1024 * 1024, installerIniBytes('128M'));
        $this->assertSame(-1, installerIniBytes('-1'), '-1 은 무제한 상태값이다 — 바이트로 접으면 안 된다');
        $this->assertNull(installerIniBytes('1x'), '알 수 없는 단위를 조용히 숫자로 접으면 안 된다');
        $this->assertNull(installerIniBytes(''), '빈 값은 해석 불가다');
    }

    /**
     * 2: offset 절단이 연관배열 매핑보다 앞이어야 한다 (소스 계약).
     *
     * @scenario progress_log_volume=large
     *
     * @effects installer_memory_budget_declarations_are_consistent
     *
     * @return void 반환값 없음
     */
    public function test_log_offset_slices_before_materialization(): void
    {
        $source = $this->sourceWithoutComments($this->projectRoot.'/public/install/includes/installer-state.php');
        $body = $this->functionBody($source, 'getInstallationLogs');

        $this->assertNotSame('', $body, 'getInstallationLogs() 를 찾지 못했다');

        $slicePos = strpos($body, 'array_slice');
        $mapPos = strpos($body, 'preg_match');

        $this->assertNotFalse($slicePos, 'offset 절단이 사라졌다');
        $this->assertNotFalse($mapPos);
        $this->assertLessThan($mapPos, $slicePos, 'offset 절단이 배열화 뒤에 있다 — 폴링 1회가 로그 전량을 연관배열로 부풀린 뒤 버린다');
    }

    /**
     * 2: 절단 위치를 옮겨도 offset 의미(증분 조회 계약)는 불변이어야 한다 (행위).
     *
     * @scenario progress_log_volume=large
     *
     * @effects installer_memory_budget_declarations_are_consistent
     *
     * @return void 반환값 없음
     */
    public function test_log_offset_semantics_unchanged(): void
    {
        $logFile = BASE_PATH.'/storage/logs/installation.log';
        $lines = [];
        for ($i = 1; $i <= 7; $i++) {
            $lines[] = "[2026-08-25 00:00:0{$i}] line {$i}";
        }
        file_put_contents($logFile, implode("\n", $lines)."\n");

        $all = getInstallationLogs();
        $this->assertCount(7, $all);
        $this->assertSame('line 1', $all[0]['message']);

        $tail = getInstallationLogs(5);
        $this->assertCount(2, $tail);
        $this->assertSame('line 6', $tail[0]['message']);
        $this->assertSame('line 7', $tail[1]['message']);

        $this->assertSame([], getInstallationLogs(7), 'offset == 총량이면 빈 배열');
        $this->assertSame([], getInstallationLogs(99), 'offset > 총량이면 빈 배열');
    }
}
