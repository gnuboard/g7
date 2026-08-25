<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\CleansTemporaryBasePath;

/**
 * 상태 파일·로그·워커 판정이 조용히 데이터를 잃지 않는다는 계약 (13차 #D13 · 환경#11·#16·#19).
 *
 * 고정하는 계약:
 *
 *  - `state.json` 이 파손되면 「부재」와 구분한다. 종전에는 파싱 실패가 기본 상태로 떨어지고
 *    그 뒤 어떤 헬퍼든 저장하는 순간 `completed_tasks`·`config`·`installation_mode` 가
 *    영구 소실됐다 — 오류도 남지 않는다.
 *  - 로그 인코딩 최종 방어(`mb_scrub`)는 단건·배치 **양쪽**이 공유한다. 배치 경로는 폴링
 *    모드가 쓰는데, 잘못된 바이트가 남으면 폴링 응답의 `json_encode` 가 false 를 돌려
 *    본문 없는 200 이 나가고 프론트의 `res.json()` 이 폭주한다.
 *  - Windows 판정은 `isWindows()` 하나다. 사본이 늘어나면 그 분기는 Linux CI 에서 한 번도
 *    평가되지 않는 채로 갈라진다.
 *  - 워커 stale 판정은 시계 역행(NTP 보정·컨테이너 시각 점프)에 무방비여선 안 된다.
 *    경과가 음수면 항상 alive 가 되어, 살아 있는 워커가 없는데도 영구 busy 가 된다.
 *
 * 커버 축: state_file valid·absent·corrupt / log_path single·batch / encoding valid·invalid / clock forward·backward
 *
 * @effects corrupt_state_is_preserved_and_blocks_writes, log_encoding_defense_is_shared, windows_detection_is_single_source, worker_stale_handles_clock_skew
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class InstallerStateIntegrityTest extends TestCase
{
    use CleansTemporaryBasePath;

    private static string $base = '';

    private static string $skipReason = '';

    private static bool $booted = false;

    private static function bootInstallerRuntime(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        $tempPrefix = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();

        if (defined('BASE_PATH')) {
            $resolved = realpath((string) BASE_PATH) ?: (string) BASE_PATH;
            if (strpos($resolved, $tempPrefix) !== 0) {
                self::$skipReason = 'BASE_PATH ('.$resolved.') 가 시스템 temp 하위가 아님 — 실 상태 파일 파괴 방지를 위해 skip. '
                    .'격리 실행: php vendor/bin/phpunit --filter=InstallerStateIntegrityTest';

                return;
            }
            self::$base = (string) BASE_PATH;
        } else {
            self::$base = sys_get_temp_dir().'/g7-installer-state-integrity-'.bin2hex(random_bytes(4));
            define('BASE_PATH', self::$base);
        }

        foreach (['storage/logs', 'storage/installer', 'storage/app'] as $dir) {
            if (! is_dir(self::$base.'/'.$dir)) {
                @mkdir(self::$base.'/'.$dir, 0755, true);
            }
        }

        if (! isset($_SERVER['SCRIPT_NAME'])) {
            $_SERVER['SCRIPT_NAME'] = '/install/api/run-task.php';
        }

        $root = dirname(__DIR__, 3);
        require_once $root.'/public/install/includes/config.php';
        require_once $root.'/public/install/includes/functions.php';
        require_once $root.'/public/install/includes/installer-state.php';
    }

    protected function setUp(): void
    {
        parent::setUp();

        self::bootInstallerRuntime();

        if (self::$skipReason !== '') {
            $this->markTestSkipped(self::$skipReason);
        }

        foreach (glob(self::$base.'/storage/installer-state.json*') ?: [] as $path) {
            @unlink($path);
        }
        if (is_file(self::$base.'/storage/logs/installation.log')) {
            unlink(self::$base.'/storage/logs/installation.log');
        }
    }

    /**
     * 주석을 지운 소스 — 배선이 「살아 있는지」는 주석이 아니라 코드가 증명한다.
     *
     * @param  string  $relative  저장소 상대 경로
     * @return string 주석이 제거된 소스
     */
    private function strippedSource(string $relative): string
    {
        return $this->stripComments((string) file_get_contents(dirname(__DIR__, 3).'/'.$relative));
    }

    /**
     * PHP 주석을 토크나이저로 지운다 (줄 수는 보존).
     *
     * @param  string  $source  원본 소스
     * @return string 주석이 제거된 소스
     */
    private function stripComments(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                $out .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
                    ? str_repeat("\n", substr_count($token[1], "\n"))
                    : $token[1];

                continue;
            }
            $out .= $token;
        }

        return $out;
    }

    /**
     * #D13 — 파손된 state.json 은 보존되고, 그 위에 덮어쓰는 저장은 거부된다.
     */
    public function test_corrupt_state_is_preserved_and_blocks_writes(): void
    {
        $state = DEFAULT_INSTALLATION_STATE;
        $state['completed_tasks'] = ['db_migrate', 'db_seed'];
        $state['installation_mode'] = 'stepwise';
        $this->assertTrue(saveInstallationState($state));

        // 요청이 쓰는 도중 죽으면 남는 형태 — 앞부분만 있는 JSON
        file_put_contents(STATE_PATH, '{"completed_tasks": ["db_migrate"');

        $loaded = getInstallationState();

        $this->assertSame(
            [],
            $loaded['completed_tasks'] ?? null,
            '파손은 기본 상태로 떨어진다 — 그 자체는 종전과 같다',
        );
        $this->assertTrue(
            installerStateIsCorrupt(),
            '파손을 「부재」와 구분하지 않으면 다음 저장이 완료 마커를 통째로 지운다',
        );

        $preserved = glob(self::$base.'/storage/installer-state.json.corrupt.*') ?: [];
        $this->assertNotEmpty($preserved, '파손본을 보존하지 않으면 원인 분석 근거가 사라진다');
        // meta:allow raw-source-read 생산 소스가 아니라 보존된 상태 파일 사본이다 — 주석 채널과 무관하다.
        $this->assertStringContainsString('db_migrate', (string) file_get_contents($preserved[0]));

        $this->assertFalse(
            saveInstallationState($loaded),
            '파손 상태에서 저장을 허용하면 기본 상태가 정상 상태를 덮어쓴다',
        );
    }

    /**
     * #D13 — 상태 파일이 사라지면(초기화) 다시 정상 경로로 돌아온다.
     */
    public function test_removing_the_corrupt_file_restores_writes(): void
    {
        file_put_contents(STATE_PATH, 'not json at all');
        getInstallationState();
        $this->assertTrue(installerStateIsCorrupt());

        unlink(STATE_PATH);
        getInstallationState();

        $this->assertFalse(installerStateIsCorrupt(), '파손본이 사라지면 판정도 함께 풀려야 복구가 가능하다');
        $this->assertTrue(saveInstallationState(DEFAULT_INSTALLATION_STATE));
    }

    /**
     * 환경#11 — 로그 인코딩 방어는 단건·배치가 같은 함수를 쓴다.
     */
    public function test_log_encoding_defense_is_shared(): void
    {
        // 유효 UTF-8 은 그대로 통과
        $this->assertSame('정상 메시지', installerNormalizeLogMessage('정상 메시지', false));

        // 잘린 멀티바이트(진행바 \r 갱신이 임의 경계에서 끊긴 형태)
        $broken = "composer \xEC\xA7 install";
        $this->assertFalse(mb_check_encoding($broken, 'UTF-8'));
        $this->assertTrue(
            mb_check_encoding(installerNormalizeLogMessage($broken, false), 'UTF-8'),
            '잘못된 바이트가 남으면 폴링 응답의 json_encode 가 false → 빈 본문 200 이 된다',
        );
        $this->assertTrue(mb_check_encoding(installerNormalizeLogMessage($broken, true), 'UTF-8'));

        // 배치 경로(폴링 모드가 쓴다)도 같은 방어를 거친다
        $this->assertTrue(addLogBatch([$broken, 'ok']));
        $written = (string) file_get_contents(self::$base.'/storage/logs/installation.log');
        $this->assertTrue(mb_check_encoding($written, 'UTF-8'), '배치 경로에 방어가 없으면 폴링 응답이 통째로 무력화된다');
        $this->assertNotFalse(json_encode(['message' => $written], JSON_UNESCAPED_UNICODE));

        $source = $this->strippedSource('public/install/includes/installer-state.php');
        $this->assertSame(
            3,
            substr_count($source, 'installerNormalizeLogMessage('),
            '선언 1 + 단건·배치 호출 2 = 3. 두 경로가 모두 이 함수를 지나야 비대칭이 구조적으로 사라진다',
        );
    }

    /**
     * 환경#16 — Windows 판정은 `isWindows()` 하나다.
     */
    public function test_windows_detection_is_single_source(): void
    {
        foreach ([
            'public/install/includes/installer-state.php',
            'public/install/includes/task-runner.php',
        ] as $relative) {
            $this->assertStringNotContainsString(
                "strtoupper(substr(PHP_OS, 0, 3)) === 'WIN'",
                $this->strippedSource($relative),
                $relative.' 에 Windows 판정 사본이 남아 있다 — 사본은 Linux CI 에서 한 번도 평가되지 않는다',
            );
        }
    }

    /**
     * 환경#19 — 시계가 뒤로 가도 stale 판정이 무력화되지 않는다.
     */
    public function test_worker_stale_handles_clock_skew(): void
    {
        $alive = [
            'active_worker_id' => 'w1',
            'last_heartbeat' => time() - 3,
            'worker_stale_after' => 15,
        ];
        $this->assertTrue(installerActiveWorkerIsAlive($alive, 15), '정상 경과는 종전대로 alive');

        $expired = [
            'active_worker_id' => 'w1',
            'last_heartbeat' => time() - 3600,
            'worker_stale_after' => 15,
        ];
        $this->assertFalse(installerActiveWorkerIsAlive($expired, 15));

        // 미래 heartbeat — NTP 보정·컨테이너 시각 점프로 실제로 발생한다
        $future = [
            'active_worker_id' => 'w1',
            'last_heartbeat' => time() + 3600,
            'worker_stale_after' => 15,
        ];
        $this->assertFalse(
            installerActiveWorkerIsAlive($future, 15),
            '경과가 음수면 항상 alive 가 되어, 살아 있는 워커가 없는데도 영구 busy 가 된다',
        );
    }

    /**
     * 17차 #C1 — 상태 저장의 인코딩 방어가 인스톨러의 다른 json_encode 와 같은 강도여야 한다.
     *
     * `error_detail` 은 `exec()` 원출력을 담는다 (`task-runner.php` 의 `implode("\n", $output)`).
     * 그 경로에는 `mb_scrub` 이 없다 — 스트리밍 루프에만 있다. Windows CP949 콘솔 한 줄이나
     * 진행바 `\r` 이 바이트 경계에서 잘리면 잘못된 UTF-8 이 그대로 상태에 실린다.
     *
     * 치환 플래그가 없으면 `json_encode` 가 false 를 돌려 **실패 기록 자체가 저장되지 않는다**.
     * 그러면 `installation_status` 는 `running` 인 채 `failed_task` 가 비어, 12차 #A2 와
     * 13차 환경#10 이 각각 닫은 「[건너뛰고 계속] 이 원리상 불가능한」 고착 상태가 재현된다.
     */
    public function test_state_save_survives_invalid_utf8_in_command_output(): void
    {
        $state = DEFAULT_INSTALLATION_STATE;
        $state['installation_status'] = 'failed';
        $state['failed_task'] = 'db_migrate';
        // CP949 「가」(0xB0 0xA1) — UTF-8 로는 부적법한 바이트열이다.
        $state['error_detail'] = "SQLSTATE[HY000] \xB0\xA1 driver error";

        $this->assertTrue(
            saveInstallationState($state),
            '잘못된 UTF-8 이 섞이면 실패 기록의 저장이 통째로 실패한다 — 화면은 영원히 진행 중이 된다',
        );

        $reloaded = getInstallationState();
        $this->assertSame('failed', $reloaded['installation_status'] ?? null);
        $this->assertSame('db_migrate', $reloaded['failed_task'] ?? null);
        $this->assertNotSame(
            '',
            (string) ($reloaded['error_detail'] ?? ''),
            '치환된 사유라도 남아야 한다 — 비면 운영자에게 근거가 사라진다',
        );
    }

    /**
     * 17차 #C5 — 파손된 상태에서 운영자가 스스로 빠져나올 수 있다.
     *
     * 13차 #D13 이 「파손 원본 위에 덮어쓰지 않는다」를 세운 뒤로, 파손이 나면 초기화·재시작·
     * 중단이 전부 500 이 됐다. `STATE_PATH` 를 지우는 제품 코드는 finalize(설치 완료 게이트 뒤)
     * 하나뿐이라, 운영자는 **파일 시스템 접근 없이는 복구할 수 없었다** — 회귀 테스트조차
     * 복구를 `unlink()` 로만 했다(제품 경로가 없다는 것을 테스트가 인정한 셈).
     *
     * 초기화는 진행분을 **의도적으로 버리는** 유일한 조작이므로 그 자리에서만 원본을 치운다.
     * 덮어쓰기 거부 자체는 그대로 유지된다 — 파손 사본도 남는다.
     */
    public function test_corrupt_state_can_be_discarded_by_an_explicit_reset(): void
    {
        file_put_contents(self::$base.'/storage/installer-state.json', '{"completed_tasks": ["db_migrate"');

        // 읽기가 파손을 감지하고 사본을 남긴다 (13차 #D13, 종전 동작).
        getInstallationState();
        $this->assertTrue(installerStateIsCorrupt());
        $this->assertFalse(
            saveInstallationState(DEFAULT_INSTALLATION_STATE),
            '덮어쓰기 거부는 유지되어야 한다 — 이 변경이 13차 #D13 을 되돌려선 안 된다',
        );

        $this->assertTrue(
            installerDiscardCorruptState(),
            '초기화가 파손 원본을 치우지 못하면 운영자에게 남는 조작이 없다',
        );

        $this->assertFalse(installerStateIsCorrupt(), '치운 뒤에는 파손 플래그가 풀려야 한다');
        $this->assertNotSame(
            [],
            glob(self::$base.'/storage/installer-state.json.corrupt.*') ?: [],
            '원인 분석 근거인 파손 사본은 남아야 한다',
        );
        $this->assertTrue(
            saveInstallationState(DEFAULT_INSTALLATION_STATE),
            '치운 뒤에는 정상 저장이 가능해야 한다 — 그것이 이 탈출구의 목적이다',
        );
    }

    /**
     * 17차 #C5 — 파손이 아닐 때는 아무것도 하지 않는다 (자동 삭제 금지).
     */
    public function test_discard_is_a_no_op_when_the_state_is_healthy(): void
    {
        $state = DEFAULT_INSTALLATION_STATE;
        $state['completed_tasks'] = ['db_migrate'];
        $this->assertTrue(saveInstallationState($state));

        $this->assertFalse(
            installerDiscardCorruptState(),
            '정상 상태를 지우면 진행분이 사라진다 — 파손일 때만 동작해야 한다',
        );
        $this->assertContains('db_migrate', getInstallationState()['completed_tasks'] ?? []);
    }

    /**
     * 17차 #C2 — 저장 실패가 조용히 지나가지 않는다.
     *
     * 이 결함의 실체는 「되돌릴 수 없음」이 아니라 「보이지 않음」이다. 파손 거부는 의도된
     * 동작이므로(13차 #D13) 우회해서는 안 되고, 디스크·권한은 인스톨러가 고칠 수 없다.
     * 그래서 헬퍼는 재시도하지 않고 기록만 남기며, 호출부가 반환값으로 판단할 수 있게 한다.
     */
    public function test_persist_failure_is_reported_not_swallowed(): void
    {
        // 성공 경로는 종전과 같다 — 기록을 남기지 않는다.
        $ok = DEFAULT_INSTALLATION_STATE;
        $this->assertTrue(installerSaveStateOrReport($ok, 'unit_ok'));

        // 파손 상태에서는 저장이 거부된다 (13차 #D13). 그 거부가 보여야 한다.
        installerStateIsCorrupt(true);

        $this->assertFalse(
            installerSaveStateOrReport($ok, 'unit_failure'),
            '거부를 참으로 돌려주면 호출부가 저장됐다고 착각한다',
        );

        $log = (string) @file_get_contents(self::$base.'/storage/logs/installation.log');
        $this->assertStringContainsString(
            'unit_failure',
            $log,
            '저장 실패가 진행 기록에 남지 않으면 운영자에게도 다음 라운드에도 보이지 않는다',
        );

        installerStateIsCorrupt(false);
    }

    /**
     * 17차 #C2 — 결과를 버리면 나쁜 상태가 남는 세 지점이 헬퍼를 경유한다.
     *
     * 나머지 저장 지점은 버려져도 회복 가능하거나 다른 검증이 덮는다(완료 마커는
     * 오케스트레이터가 재조회로 확인한다) — 여기서 고정하는 것은 그 셋뿐이다.
     */
    public function test_consequential_persist_sites_go_through_the_reporting_helper(): void
    {
        $source = $this->strippedSource('public/install/includes/task-runner.php');

        foreach (['task_failure', 'run_exception', 'admin_secret_purge'] as $context) {
            $this->assertStringContainsString(
                'installerSaveStateOrReport(',
                $source,
                '결과를 버리면 고착·비밀 잔류가 오류 없이 남는다',
            );
            $this->assertStringContainsString(
                "'".$context."'",
                $source,
                "저장 실패가 나쁜 상태를 남기는 지점 {$context} 가 헬퍼를 경유하지 않는다",
            );
        }
    }

    /**
     * 17차 #C1 — 그 방어가 형제 지점과 같은 수단이어야 한다 (SSoT).
     *
     * 값 단언만 두면 다른 수단(사전 scrub 등)으로 통과시킨 뒤 형제와 강도가 갈릴 수 있다.
     */
    public function test_state_save_uses_the_same_encoding_flag_as_sibling_encoders(): void
    {
        $source = $this->strippedSource('public/install/includes/installer-state.php');

        // 사전 정규화(installer_utf8_normalize_deep)를 **덧붙이는** 것은 허용한다 — 그것은
        // 치환 플래그를 대체하는 다른 수단이 아니라 그 위에 얹는 상위집합이고(복원 가능한
        // 코드페이지 출력을 한글째 되살린 뒤 남은 바이트만 치환에 맡긴다), 플래그 자체는
        // 아래 정규식이 여전히 요구한다. 금지하려는 것은 「플래그 없이 scrub 만으로 통과」다.
        $this->assertMatchesRegularExpression(
            '/json_encode\(\s*(?:installer_utf8_normalize_deep\(\s*)?\$state\b[^;]*JSON_INVALID_UTF8_SUBSTITUTE/',
            $source,
            '상태 저장이 인스톨러의 다른 json_encode 5곳과 같은 치환 플래그를 써야 한다',
        );
    }
}
