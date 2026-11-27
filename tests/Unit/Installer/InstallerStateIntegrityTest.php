<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\CleansTemporaryBasePath;

/**
 * 상태 파일·로그·워커 판정이 조용히 데이터를 잃지 않는다는 계약 (환경 11· 16· 19).
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
 * 효과 요약(마커 아님 — 평문): corrupt_state_is_preserved_and_blocks_writes, log_encoding_defense_is_shared, windows_detection_is_single_source, worker_stale_handles_clock_skew
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class InstallerStateIntegrityTest extends TestCase
{
    use CleansTemporaryBasePath;

    private static string $base = '';

    private static string $skipReason = '';

    private static bool $booted = false;

    /**
     * 프로세스마다 한 번 임시 기준 경로를 정하고 저장 디렉터리를 만든 뒤 설치기 설정, 공용 함수, 상태 헬퍼를 읽어 들인다.
     * 기준 경로 상수가 시스템 임시 경로 밖으로 이미 정의돼 있으면 실제 상태 파일을 지키려고 건너뛰기 사유만 남긴다.
     *
     * @return void 반환값 없음
     */
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

    /**
     * 설치기 런타임을 올리고 건너뛰기 사유가 있으면 건너뛰며, 이전 테스트가 남긴 상태 파일과 사본, 설치 로그를 지운다.
     *
     * @return void 반환값 없음
     */
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
     *
     * @scenario state_file=corrupt
     *
     * @effects corrupt_state_is_preserved_and_blocks_writes
     *
     * @return void 반환값 없음
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
     * 파손 사본 상한(5)에 닿으면 **가장 오래된 것을 내보내고 새 것을 남긴다** — 그리고 그 사실을
     * 로그에 남긴다.
     *
     * 종전에는 상한에서 조용히 `return` 했다. 사본 다섯이 차면 그 뒤 최대 30일(프루너의
     * 백업 등급)동안 **새 파손의 근거가 0** 이고, 그 침묵은 로그에도 없었다. 상한의 목적은
     * 디렉토리 잠식 방지이지 최신 근거 폐기가 아니므로, 상한 유지 + 최신 보존이 맞다.
     *
     * @scenario state_file=corrupt
     *
     * @effects corrupt_state_copy_cap_evicts_the_oldest_and_logs
     *
     * @return void 반환값 없음
     */
    public function test_corrupt_copy_cap_evicts_the_oldest_and_logs(): void
    {
        $prefix = STATE_PATH.INSTALLER_STATE_CORRUPT_INFIX;
        $stamps = ['20260101000000', '20260102000000', '20260103000000', '20260104000000', '20260105000000'];

        foreach ($stamps as $i => $stamp) {
            file_put_contents($prefix.$stamp.'.'.str_repeat((string) $i, 8), '{"old": '.$i);
        }
        $this->assertCount(5, installerListCorruptStateCopies(), '픽스처가 상한에 닿지 않았다');

        $errorLog = self::$base.'/storage/logs/php-error.log';
        $previous = ini_set('error_log', $errorLog);

        try {
            installerPreserveCorruptState('{"completed_tasks": ["db_migrate"], "brand_new": true');
        } finally {
            if ($previous !== false) {
                ini_set('error_log', $previous);
            }
        }

        $copies = installerListCorruptStateCopies();
        $this->assertCount(5, $copies, '상한이 유지되지 않는다 — 디렉토리 잠식 방지가 사라졌다');

        $names = array_map('basename', $copies);
        $this->assertNotContains(
            basename($prefix.'20260101000000.00000000'),
            $names,
            '가장 오래된 사본이 남아 있다 — 새 파손의 근거를 위해 그것이 나가야 한다'
        );

        // meta:allow raw-source-read 생산 소스가 아니라 보존된 상태 파일 사본이다 — 주석 채널과 무관하다.
        $newest = array_filter($copies, static fn (string $path): bool => str_contains((string) file_get_contents($path), 'brand_new'));
        $this->assertCount(1, $newest, '상한에 닿았을 때 새 파손의 근거가 보존되지 않는다');

        // meta:allow raw-source-read 생산 소스가 아니라 이 테스트가 리다이렉트한 PHP error_log 파일이다.
        $logged = is_file($errorLog) ? (string) file_get_contents($errorLog) : '';
        $this->assertStringContainsString(
            '[installer-state]',
            $logged,
            '상한 소진(내보내기)이 로그에 남지 않는다 — 형제(보존 성공)는 남기는데 이 분기만 조용하다'
        );
        $this->assertMatchesRegularExpression('/evict|oldest|cap/i', $logged, '로그가 내보내기 사실을 말하지 않는다');
    }

    /**
     * 사본 나열은 **정렬된** 목록이다 — 내보낼 「가장 오래된 것」은 열거 순서가 아니라 이름
     * (타임스탬프)로 정한다. `scandir`·`glob` 모두 순서를 보장하지 않는다.
     *
     * @scenario state_file=corrupt
     *
     * @effects corrupt_state_copy_listing_is_sorted
     *
     * @return void 반환값 없음
     */
    public function test_corrupt_copy_listing_is_sorted_by_stamp(): void
    {
        $prefix = STATE_PATH.INSTALLER_STATE_CORRUPT_INFIX;
        foreach (['20260105000000', '20260101000000', '20260103000000'] as $stamp) {
            file_put_contents($prefix.$stamp.'.abcdef01', '{');
        }

        $names = array_map('basename', installerListCorruptStateCopies());
        $sorted = $names;
        sort($sorted, SORT_STRING);

        $this->assertSame($sorted, $names, '사본 목록이 정렬돼 있지 않다 — 내보낼 대상이 열거 순서에 좌우된다');
    }

    /**
     * 같은 초 안의 사본 다섯은 **결정적으로** 정렬되고, 상한 소진 시 새 사본이 보존된다.
     *
     * 정렬 키는 파일명(`<YmdHis>.<sha8>`)이라 같은 초 안에서는 sha8 사전순이다 — 생성 순서와
     * 무관하지만 열거 순서에는 좌우되지 않는다. 종전 주석은 이 구간을 「가장 오래된 것」으로
     * 불렀는데, 그 구간에서 실제로 성립하는 약속은 「언제나 같은 하나가 나간다 + 새 사본은
     * 남는다」뿐이다. 정렬 방식은 바꾸지 않는다 — 계약을 사실대로 잠근다.
     *
     * @scenario state_file=corrupt
     *
     * @effects corrupt_state_copy_listing_is_sorted, corrupt_state_copy_cap_evicts_the_oldest_and_logs
     *
     * @return void 반환값 없음
     */
    public function test_same_second_corrupt_copies_sort_deterministically_and_keep_the_new_copy(): void
    {
        $prefix = STATE_PATH.INSTALLER_STATE_CORRUPT_INFIX;
        $stamp = '20260101000000';
        $digests = ['cafebabe', '01234567', 'deadbeef', '89abcdef', '0badf00d'];

        // 열거 순서와 무관함을 재기 위해 사전순이 아닌 순서로 만든다.
        foreach ($digests as $i => $digest) {
            file_put_contents($prefix.$stamp.'.'.$digest, '{"old": '.$i);
        }

        $first = array_map('basename', installerListCorruptStateCopies());
        $second = array_map('basename', installerListCorruptStateCopies());
        $sorted = $first;
        sort($sorted, SORT_STRING);

        $this->assertCount(5, $first);
        $this->assertSame($sorted, $first, '같은 초 안의 목록이 이름 사전순이 아니다');
        $this->assertSame($first, $second, '같은 입력에서 목록이 달라진다 — 내보낼 대상이 실행마다 바뀐다');

        $previous = ini_set('error_log', self::$base.'/storage/logs/php-error.log');

        try {
            installerPreserveCorruptState('{"brand_new": true');
        } finally {
            if ($previous !== false) {
                ini_set('error_log', $previous);
            }
        }

        $after = array_map('basename', installerListCorruptStateCopies());
        $this->assertCount(5, $after, '상한이 유지되지 않는다');
        $this->assertNotContains(
            basename($prefix.$stamp.'.01234567'),
            $after,
            '같은 초 안에서는 이름 사전순 첫 항목(sha8 최소)이 나간다 — 그 규칙이 바뀌었다면 주석과 이 계약을 함께 고친다'
        );

        // meta:allow raw-source-read 생산 소스가 아니라 보존된 상태 파일 사본이다 — 주석 채널과 무관하다.
        $newest = array_filter(installerListCorruptStateCopies(), static fn (string $path): bool => str_contains((string) file_get_contents($path), 'brand_new'));
        $this->assertCount(1, $newest, '상한에 닿았을 때 새 파손의 근거가 보존되지 않는다');
    }

    /**
     * 파손 원문은 로그에 싣지 않는다 — 길이·digest 만.
     *
     * 비밀은 상태 파일에 없어야 하지만 레거시 상태 파일이 남아 있을 수 있다. 종전에는
     * 파싱 실패 메시지가 원문 200 바이트를 무마스킹으로 진행 기록과 PHP 오류 로그에 실었다.
     *
     * @scenario state_file=corrupt
     *
     * @effects corrupt_state_preview_is_not_logged_verbatim
     *
     * @return void 반환값 없음
     */
    public function test_corrupt_state_preview_is_not_logged_verbatim(): void
    {
        $secret = 'SECRET-TOKEN-a1b2c3d4';
        $content = '{"config": {"db_write_password": "'.$secret.'"}, "broken": ';
        file_put_contents(STATE_PATH, $content);

        $errorLog = self::$base.'/storage/logs/php-error.log';
        $previous = ini_set('error_log', $errorLog);

        try {
            getInstallationState();
        } finally {
            if ($previous !== false) {
                ini_set('error_log', $previous);
            }
        }

        $logs = '';
        foreach (glob(self::$base.'/storage/logs/*') ?: [] as $file) {
            // meta:allow raw-source-read 생산 소스가 아니라 이 테스트가 만든 로그 파일이다.
            $logs .= (string) file_get_contents($file);
        }

        $this->assertStringContainsString('Failed to parse state file JSON', $logs, '파손 사실 자체는 로그에 남아야 한다');
        $this->assertStringNotContainsString($secret, $logs, '파손 원문이 로그에 그대로 실린다 — 레거시 상태 파일의 비밀이 로그로 옮겨 간다');
        $this->assertStringContainsString('length='.strlen($content), $logs, '길이가 기록되지 않는다');
        $this->assertStringContainsString('sha1='.substr(sha1($content), 0, 8), $logs, 'digest 가 기록되지 않는다 — 보존 사본(같은 digest)과 대조할 근거가 없다');
    }

    /**
     * `BASE_PATH` 폴백의 `realpath` 가드가 세 파일에서 같은 형태다.
     *
     * `realpath` 는 하드닝 프로필이 막을 수 있고, 이 줄은 단독 로드 경로에서 그 파일이 실행하는
     * 첫 코드다. `config.php` 만 가드를 갖고 형제 둘(`installer-state.php` · `progress-emitter.php`)
     * 은 무가드 사본이었다 — 사본이 갈리면 같은 호스팅에서 진입점에 따라 백지가 갈린다.
     *
     * @effects installer_blocked_function_call_sites_are_guarded
     *
     * @return void 반환값 없음
     */
    public function test_base_path_fallback_guards_realpath_in_every_copy(): void
    {
        $root = dirname(__DIR__, 3).'/public/install/includes/';

        foreach (['config.php', 'installer-state.php', 'progress-emitter.php'] as $file) {
            // meta:allow raw-source-read 소스 계약 — define 블록의 형태를 대조한다.
            $source = (string) file_get_contents($root.$file);

            $this->assertSame(
                1,
                preg_match('/if \(! defined\(\'BASE_PATH\'\)\) \{(.*?)\n\}/s', $source, $m),
                $file.' 에서 BASE_PATH 폴백 블록을 찾지 못했다'
            );
            $this->assertStringContainsString(
                "function_exists('realpath')",
                $m[1],
                $file.' 의 BASE_PATH 폴백이 realpath 를 무가드로 부른다 — 막힌 호스팅에서 그 진입점만 백지가 된다'
            );
        }
    }

    /**
     * #D13 — 상태 파일이 사라지면(초기화) 다시 정상 경로로 돌아온다.
     *
     * @scenario state_file=absent
     *
     * @effects corrupt_state_is_preserved_and_blocks_writes
     *
     * @return void 반환값 없음
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
     * 환경 11 — 로그 인코딩 방어는 단건·배치가 같은 함수를 쓴다.
     *
     * @scenario log_path=batch, text_encoding=invalid_utf8
     *
     * @effects log_encoding_defense_is_shared
     *
     * @return void 반환값 없음
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
     * 환경 16 — Windows 판정은 `isWindows()` 하나다.
     *
     * @effects windows_detection_is_single_source
     *
     * @return void 반환값 없음
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
     * 환경 19 — 시계가 뒤로 가도 stale 판정이 무력화되지 않는다.
     *
     * @scenario clock_direction=backward
     *
     * @effects worker_stale_handles_clock_skew
     *
     * @return void 반환값 없음
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
     * 상태 저장의 인코딩 방어가 인스톨러의 다른 json_encode 와 같은 강도여야 한다.
     *
     * `error_detail` 은 `exec()` 원출력을 담는다 (`task-runner.php` 의 `implode("\n", $output)`).
     * 그 경로에는 `mb_scrub` 이 없다 — 스트리밍 루프에만 있다. Windows CP949 콘솔 한 줄이나
     * 진행바 `\r` 이 바이트 경계에서 잘리면 잘못된 UTF-8 이 그대로 상태에 실린다.
     *
     * 치환 플래그가 없으면 `json_encode` 가 false 를 돌려 **실패 기록 자체가 저장되지 않는다**.
     * 그러면 `installation_status` 는 `running` 인 채 `failed_task` 가 비어, 앞선 두
     * 수정이 각각 닫은 「[건너뛰고 계속] 이 원리상 불가능한」 고착 상태가 재현된다.
     *
     * @scenario state_file=valid, text_encoding=invalid_utf8
     *
     * @effects state_save_survives_invalid_utf8_in_command_output
     *
     * @return void 반환값 없음
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
     * 파손된 상태에서 운영자가 스스로 빠져나올 수 있다.
     *
     * 「파손 원본 위에 덮어쓰지 않는다」를 세운 뒤로, 파손이 나면 초기화·재시작·
     * 중단이 전부 500 이 됐다. `STATE_PATH` 를 지우는 제품 코드는 finalize(설치 완료 게이트 뒤)
     * 하나뿐이라, 운영자는 **파일 시스템 접근 없이는 복구할 수 없었다** — 회귀 테스트조차
     * 복구를 `unlink()` 로만 했다(제품 경로가 없다는 것을 테스트가 인정한 셈).
     *
     * 초기화는 진행분을 **의도적으로 버리는** 유일한 조작이므로 그 자리에서만 원본을 치운다.
     * 덮어쓰기 거부 자체는 그대로 유지된다 — 파손 사본도 남는다.
     *
     * @scenario state_file=corrupt
     *
     * @effects corrupt_state_can_be_discarded_by_an_explicit_reset
     *
     * @return void 반환값 없음
     */
    public function test_corrupt_state_can_be_discarded_by_an_explicit_reset(): void
    {
        file_put_contents(self::$base.'/storage/installer-state.json', '{"completed_tasks": ["db_migrate"');

        // 읽기가 파손을 감지하고 사본을 남긴다 (종전 동작).
        getInstallationState();
        $this->assertTrue(installerStateIsCorrupt());
        $this->assertFalse(
            saveInstallationState(DEFAULT_INSTALLATION_STATE),
            '덮어쓰기 거부는 유지되어야 한다 — 이 변경이 「파손 원본 위에 덮어쓰지 않는다」를 되돌려선 안 된다',
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
     * 파손이 아닐 때는 아무것도 하지 않는다 (자동 삭제 금지).
     *
     * @scenario state_file=valid
     *
     * @effects corrupt_state_can_be_discarded_by_an_explicit_reset
     *
     * @return void 반환값 없음
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
     * 저장 실패가 조용히 지나가지 않는다.
     *
     * 이 결함의 실체는 「되돌릴 수 없음」이 아니라 「보이지 않음」이다. 파손 거부는 의도된
     * 동작이므로 우회해서는 안 되고, 디스크·권한은 인스톨러가 고칠 수 없다.
     * 그래서 헬퍼는 재시도하지 않고 기록만 남기며, 호출부가 반환값으로 판단할 수 있게 한다.
     *
     * @scenario state_file=corrupt
     *
     * @effects state_persist_failure_is_reported_not_swallowed
     *
     * @return void 반환값 없음
     */
    public function test_persist_failure_is_reported_not_swallowed(): void
    {
        // 성공 경로는 종전과 같다 — 기록을 남기지 않는다.
        $ok = DEFAULT_INSTALLATION_STATE;
        $this->assertTrue(installerSaveStateOrReport($ok, 'unit_ok'));

        // 파손 상태에서는 저장이 거부된다. 그 거부가 보여야 한다.
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
     * 결과를 버리면 나쁜 상태가 남는 세 지점이 헬퍼를 경유한다.
     *
     * 나머지 저장 지점은 버려져도 회복 가능하거나 다른 검증이 덮는다(완료 마커는
     * 오케스트레이터가 재조회로 확인한다) — 여기서 고정하는 것은 그 셋뿐이다.
     *
     * @effects state_persist_failure_is_reported_not_swallowed
     *
     * @return void 반환값 없음
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
     * 그 방어가 형제 지점과 같은 수단이어야 한다 (SSoT).
     *
     * 값 단언만 두면 다른 수단(사전 scrub 등)으로 통과시킨 뒤 형제와 강도가 갈릴 수 있다.
     *
     * @scenario text_encoding=invalid_utf8
     *
     * @effects state_save_survives_invalid_utf8_in_command_output
     *
     * @return void 반환값 없음
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
