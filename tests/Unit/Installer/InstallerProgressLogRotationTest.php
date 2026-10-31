<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 진행 기록 파일의 회전 (D2 L11).
 *
 * 배경:
 *   `storage/logs/installation.log` 에는 회전도 상한도 없었다. 새 설치 진입에서만 지워지고
 *   (install-process.php) 재시도·재개는 그대로 이어 쓰므로, 실패한 설치를 반복하면 단조
 *   증가한다. 실측(2026-09-11): **한 번의 정상 설치**가 이미 13,017 줄 · 1.1 MB 를 만든다.
 *
 *   파일이 커지면 두 곳이 함께 무거워진다 — 서버는 꼬리를 읽어 응답에 싣고, 화면은 재개할 때
 *   그 전량을 한 프레임에 그린다.
 *
 * 잠그는 계약:
 *   1. 상한을 넘기면 `.1` 로 **한 번** 회전한다 (세대를 늘리지 않는다 — 기존 `.1` 은 덮는다).
 *   2. 회전 뒤 새 파일의 첫 줄이 그 사실을 말한다 — 그러지 않으면 운영자에게는 기록이 사라진 것이다.
 *   3. 회전 뒤 줄 수 집계가 **새 파일 기준**으로 다시 센다 (클라이언트 offset 보정의 근거).
 *   4. 상한 아래에서는 아무 일도 하지 않는다.
 *   5. 회전 실패가 설치를 멈추지 않는다 (best-effort) — 로그는 설치의 목적이 아니다.
 *
 * BASE_PATH 를 temp 로 박으므로 클래스 격리 실행이 필요하다.
 *
 * 효과 요약(마커 아님 — 평문): 진행 기록 파일이 상한을 넘기면 한 번 회전한다
 * (installation_log_rotates_before_it_grows_unbounded). 표식은 각 테스트 메서드가 단다.
 */
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
class InstallerProgressLogRotationTest extends TestCase
{
    private static string $sharedBase = '';

    private static string $skipReason = '';

    /**
     * 시스템 임시 폴더 아래에 BASE_PATH 와 로그 폴더를 세우고, 회전 상한을 4 KB 로 낮춘 뒤 설치기 설정과 상태 모듈을 불러온다. BASE_PATH 가 임시 폴더 밖이면 건너뛸 사유만 남긴다.
     *
     * @return void 반환값 없음
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $tempPrefix = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
        if (defined('BASE_PATH')) {
            $resolved = realpath((string) BASE_PATH) ?: (string) BASE_PATH;
            if (strpos($resolved, $tempPrefix) !== 0) {
                self::$skipReason = 'BASE_PATH ('.$resolved.') 가 시스템 temp 하위가 아님 — 격리 실행 필요: '.
                    'php artisan test --filter=InstallerProgressLogRotationTest';

                return;
            }
            self::$sharedBase = (string) BASE_PATH;
        } else {
            self::$sharedBase = sys_get_temp_dir().'/g7-installer-logrotate-'.bin2hex(random_bytes(4));
            define('BASE_PATH', self::$sharedBase);
        }

        if (! is_dir(self::$sharedBase.'/storage/logs')) {
            mkdir(self::$sharedBase.'/storage/logs', 0755, true);
        }

        if (! isset($_SERVER['SCRIPT_NAME'])) {
            $_SERVER['SCRIPT_NAME'] = '/install/index.php';
        }

        // 상한을 테스트용으로 낮춘다 — 8 MB 를 실제로 쌓으면 이 스위트가 느려지고
        // 디스크를 쓴다. 회전의 **행위**를 재는 것이 목적이지 상한값을 재는 것이 아니다.
        // (상한값 자체는 installer-state.php 의 기본값 주석이 근거와 함께 적는다.)
        if (! defined('INSTALLER_LOG_ROTATE_BYTES')) {
            define('INSTALLER_LOG_ROTATE_BYTES', 4096);
        }

        require_once dirname(__DIR__, 3).'/public/install/includes/config.php';
        require_once dirname(__DIR__, 3).'/public/install/includes/installer-state.php';
    }

    /**
     * 임시 BASE_PATH 아래에 남은 진행 기록 파일과 회전본을 지운다.
     *
     * @return void 반환값 없음
     */
    public static function tearDownAfterClass(): void
    {
        if (self::$skipReason === '' && self::$sharedBase !== '') {
            foreach (self::files() as $path) {
                if (file_exists($path)) {
                    @unlink($path);
                }
            }
        }
        parent::tearDownAfterClass();
    }

    /**
     * 이 테스트가 다루는 현재 진행 기록 파일과 회전본의 경로를 돌려준다.
     *
     * @return array{0: string, 1: string} [현재 파일, 회전본]
     */
    private static function files(): array
    {
        return [
            self::$sharedBase.'/storage/logs/installation.log',
            self::$sharedBase.'/storage/logs/installation.log.1',
        ];
    }

    /**
     * 건너뛸 사유가 있으면 건너뛰고, 아니면 앞 테스트가 남긴 기록 파일과 회전본을 지워 빈 상태에서 시작한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (self::$skipReason !== '') {
            $this->markTestSkipped(self::$skipReason);
        }

        foreach (self::files() as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * 상한을 확실히 넘기는 크기로 채운다.
     *
     * @return void 반환값 없음
     */
    private function fillPastLimit(): void
    {
        [$log] = self::files();
        file_put_contents($log, str_repeat("[2026-09-11 00:00:00.000] 채움 줄\n", 400));

        $this->assertGreaterThanOrEqual(
            INSTALLER_LOG_ROTATE_BYTES,
            filesize($log),
            '표본이 상한을 넘지 못했다 — 이 테스트가 재는 상태가 만들어지지 않았다'
        );
    }
    /**
     * 상한을 넘긴 파일에 한 줄을 더 쓰면 이전 내용이 회전본으로 그대로 옮겨지고, 새 파일은 작아지며 방금 쓴 줄을 담는지 확인한다.
     *
     * @scenario progress_log_volume=rotated
     *
     * @effects installation_log_rotates_before_it_grows_unbounded
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function it_rotates_once_when_the_file_passes_the_limit(): void
    {
        [$log, $rotated] = self::files();
        $this->fillPastLimit();
        $before = filesize($log);

        addLog('회전 뒤 첫 줄');

        $this->assertFileExists($rotated, '상한을 넘겼는데 회전본이 없다 — 파일이 무한히 자란다');
        $this->assertSame($before, filesize($rotated), '회전본이 이전 내용 그대로여야 한다 — 기록을 버리지 않는다');
        $this->assertLessThan($before, filesize($log), '새 파일이 이전 크기를 그대로 물려받았다');
        // meta:allow raw-source-read — 소스가 아니라 진행 기록 파일(산출물)을 읽는다.
        $this->assertStringContainsString('회전 뒤 첫 줄', (string) file_get_contents($log));
    }

    /**
     * 회전 사실을 새 파일이 말한다 — 말하지 않으면 운영자에게는 기록이 **사라진** 것이다.
     *
     * @scenario progress_log_volume=rotated
     *
     * @effects installation_log_rotates_before_it_grows_unbounded
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function the_new_file_says_that_the_previous_record_was_moved(): void
    {
        $this->fillPastLimit();

        addLog('이어지는 줄');

        [$log] = self::files();
        // meta:allow raw-source-read — 소스가 아니라 진행 기록 파일(산출물)을 읽는다.
        $lines = array_values(array_filter(
            preg_split('/\r\n|\n|\r/', (string) file_get_contents($log)) ?: [],
            static fn (string $line): bool => trim($line) !== ''
        ));

        $this->assertNotEmpty($lines);
        $this->assertStringContainsString(
            'installation.log.1',
            $lines[0],
            '회전 안내가 새 파일의 첫 줄에 없다 — 어디로 갔는지 알 수 없으면 기록이 사라진 것과 같다'
        );
    }

    /**
     * 회전 뒤 총 줄 수가 새 파일 기준으로 **다시 센다**. 클라이언트는 이 값이 작아지는 것을
     * 보고 offset 을 되돌린다 — 그러지 않으면 회전 뒤의 줄이 한 줄도 실리지 않는다.
     *
     * @scenario progress_log_volume=rotated
     *
     * @effects installation_log_rotates_before_it_grows_unbounded
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function the_line_count_restarts_from_the_new_file(): void
    {
        $this->fillPastLimit();
        $beforeCount = getInstallationLogCount();
        $this->assertGreaterThan(100, $beforeCount, '표본이 충분히 쌓이지 않았다');

        addLog('첫 줄');
        addLog('둘째 줄');

        $afterCount = getInstallationLogCount();
        $this->assertLessThan($beforeCount, $afterCount, '회전했는데 줄 수가 줄지 않았다 — 클라이언트 보정이 발동하지 않는다');
        // 회전 안내 1줄 + 방금 쓴 2줄
        $this->assertSame(3, $afterCount);
    }
    /**
     * 이미 회전본이 있을 때 다시 회전하면 옛 회전본을 덮고 두 번째 세대 파일을 만들지 않는지 확인한다.
     *
     * @scenario progress_log_volume=rotated
     *
     * @effects installation_log_rotates_before_it_grows_unbounded
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function it_replaces_the_previous_archive_instead_of_keeping_generations(): void
    {
        [$log, $rotated] = self::files();
        file_put_contents($rotated, "오래된 회전본\n");

        $this->fillPastLimit();
        addLog('새 줄');

        $this->assertFileExists($rotated);
        // meta:allow raw-source-read — 소스가 아니라 회전본(산출물)을 읽는다.
        $this->assertStringNotContainsString(
            '오래된 회전본',
            (string) file_get_contents($rotated),
            '세대를 쌓으면 상한을 둔 의미가 없다 — 기존 .1 은 덮는다'
        );
        $this->assertFileDoesNotExist($log.'.2', '세대가 늘어났다');
    }
    /**
     * 상한 아래의 짧은 기록에서는 회전본이 생기지 않는지 확인한다.
     *
     * @scenario progress_log_volume=small
     *
     * @effects installation_log_rotates_before_it_grows_unbounded
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function it_does_nothing_below_the_limit(): void
    {
        [$log, $rotated] = self::files();
        addLog('짧은 줄');

        $this->assertFileExists($log);
        $this->assertFileDoesNotExist($rotated, '상한 아래에서 회전하면 정상 설치의 기록이 매번 쪼개진다');
    }
    /**
     * 없는 파일에 회전을 시도하면 오류 없이 거짓을 돌려주고 파일도 만들지 않는지 확인한다.
     *
     * @scenario progress_log_volume=small
     *
     * @effects installation_log_rotates_before_it_grows_unbounded
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function a_missing_file_is_not_an_error(): void
    {
        [$log] = self::files();

        $this->assertFalse(
            installerRotateProgressLogIfLarge($log),
            '없는 파일에 대한 회전 시도가 참을 돌려주면 호출부가 회전했다고 믿는다'
        );
        $this->assertFileDoesNotExist($log);
    }

    /**
     * 배치 통로도 같은 지점을 지난다 — 한쪽 통로로만 쓰는 모드에서 파일이 조용히 자라면
     * 회전을 넣은 의미가 절반만 성립한다.
     *
     * @scenario progress_log_volume=rotated
     *
     * @effects installation_log_rotates_before_it_grows_unbounded
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function the_batch_writer_rotates_too(): void
    {
        [, $rotated] = self::files();
        $this->fillPastLimit();

        addLogBatch(['배치 첫 줄', '배치 둘째 줄']);

        $this->assertFileExists($rotated, '배치 쓰기 경로에 회전이 걸리지 않았다');
        $this->assertSame(3, getInstallationLogCount(), '회전 안내 1줄 + 배치 2줄이어야 한다');
    }
}
