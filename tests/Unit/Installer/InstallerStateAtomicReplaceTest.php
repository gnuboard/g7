<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 상태 파일 원자 교체의 경합 내성 (D4).
 *
 * 배경 (실측 2026-09-11, 단계별 리허설):
 *   이커머스 모듈 설치(44초짜리 태스크) 도중 로그에 1건:
 *     `[installer-state] Failed to rename state tmp file: …installer-state.json.tmp.2d07`
 *   설치는 계속 진행돼 정상 완료했다. 그런데 **그 시점의 상태 저장은 유실됐고**, 반환값을
 *   보지 않는 호출부가 셋 있어(install-process.php 의 진행 갱신 · functions.php · 이 파일의
 *   진행률 저장) 그 유실은 어디에도 드러나지 않는다. 그 상태로 새로고침 재개가 걸리면
 *   재개 지점이 실제 진행보다 뒤로 간다.
 *
 * 원인: POSIX `rename()` 은 대상이 열려 있어도 원자적으로 성공하지만 **Windows 는 대상 파일을
 * 다른 핸들이 `FILE_SHARE_DELETE` 없이 열고 있으면 실패한다.** 인스톨러는 같은 상태 파일을
 * 여러 진입점이 동시에 읽으므로(진행 조회·태스크 실행·세션 초기화) 그 찰나에 부딪힌다.
 *
 * 잠그는 계약:
 *   1. 정상 경로는 첫 시도에 성공하고 내용이 그대로 옮겨진다.
 *   2. **대상이 이미 있어도** 덮어쓴다 (교체이지 생성이 아니다).
 *   3. 원본이 없으면 재시도하지 않는다 — 경합이 아니라 다른 실패다(무의미한 대기 금지).
 *   4. 끝까지 실패하면 false 다 — 재시도는 「없앨 수 있는 실패」만 걷어내지, 권한 소실·
 *      디스크 풀 같은 실패를 감추지 않는다.
 *   5. 상태 저장 경로가 이 헬퍼를 **실제로 지난다**(맨 rename 으로 되돌아가지 않는다).
 *   6. **형제 지점**(runtime.php 의 비밀 채널 쓰기)도 같은 헬퍼를 지난다 — 같은 모양인데
 *      한쪽만 고치면 더 무거운 쪽이 남는다(그 파일은 DB 자격증명·APP_KEY·토큰을 담는다).
 *
 * BASE_PATH 를 temp 로 박으므로 클래스 격리 실행이 필요하다.
 *
 * 효과 요약(마커 아님 — 평문): 상태 파일 교체가 짧은 핸들 경합을 견딘다
 * (installer_state_replace_survives_transient_handle_contention). 표식은 각 테스트 메서드가 단다.
 */
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
class InstallerStateAtomicReplaceTest extends TestCase
{
    use ScansPhpSourceFunctions;

    private static string $sharedBase = '';

    private static string $skipReason = '';

    /**
     * 시스템 임시 폴더 아래에 BASE_PATH 와 storage 폴더를 세우고, 교체 재시도 간격을 0 으로 낮춘 뒤 설치기 설정과 상태 모듈을 불러온다. BASE_PATH 가 임시 폴더 밖이면 건너뛸 사유만 남긴다.
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
                    'php artisan test --filter=InstallerStateAtomicReplaceTest';

                return;
            }
            self::$sharedBase = (string) BASE_PATH;
        } else {
            self::$sharedBase = sys_get_temp_dir().'/g7-installer-atomic-'.bin2hex(random_bytes(4));
            define('BASE_PATH', self::$sharedBase);
        }

        foreach (['/storage/logs', '/storage'] as $dir) {
            if (! is_dir(self::$sharedBase.$dir)) {
                mkdir(self::$sharedBase.$dir, 0755, true);
            }
        }

        if (! isset($_SERVER['SCRIPT_NAME'])) {
            $_SERVER['SCRIPT_NAME'] = '/install/index.php';
        }

        // 재시도 간격을 0 으로 낮춘다 — 이 테스트가 재는 것은 **행위**이지 대기 시간이 아니다.
        if (! defined('INSTALLER_STATE_RENAME_DELAY_US')) {
            define('INSTALLER_STATE_RENAME_DELAY_US', 0);
        }

        require_once dirname(__DIR__, 3).'/public/install/includes/config.php';
        require_once dirname(__DIR__, 3).'/public/install/includes/installer-state.php';
    }

    /**
     * 임시 storage 폴더 바로 아래에 남은 픽스처 파일을 지운다.
     *
     * @return void 반환값 없음
     */
    public static function tearDownAfterClass(): void
    {
        if (self::$skipReason === '' && self::$sharedBase !== '') {
            foreach (glob(self::$sharedBase.'/storage/*') ?: [] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }
        parent::tearDownAfterClass();
    }

    /**
     * 클래스 준비 단계에서 건너뛸 사유가 정해졌으면 각 테스트를 건너뛴다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (self::$skipReason !== '') {
            $this->markTestSkipped(self::$skipReason);
        }
    }

    /**
     * 임시 storage 폴더 아래의 픽스처 파일 경로를 만든다.
     *
     * @param  string  $name  픽스처 파일 이름
     * @return string 픽스처 파일의 절대 경로
     */
    private function tmp(string $name): string
    {
        return self::$sharedBase.'/storage/'.$name;
    }

    /**
     * 실패를 **일부러** 만드는 케이스에서 PHP 경고를 삼킨다.
     *
     * 프로덕션은 `@rename` 으로 이미 억제하지만, PHPUnit 의 전역 오류 핸들러는 `@` 보다
     * 먼저 잡아 그 테스트를 warning 으로 표시한다 — 의도한 실패가 「경고 난 테스트」로
     * 보이면 진짜 경고와 구분되지 않는다. 이 구간에서만 핸들러를 끈다.
     *
     * @template T
     *
     * @param  callable():T  $fn  경고를 삼킨 채 실행할 함수
     * @return T 전달한 함수의 반환값
     */
    private function withoutPhpWarnings(callable $fn)
    {
        set_error_handler(static fn (): bool => true, E_WARNING | E_NOTICE);
        try {
            return $fn();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * 정상 경로에서 교체 헬퍼가 첫 시도에 성공하고, 원본을 없애며 내용을 그대로 옮기는지 확인한다.
     *
     * @scenario task_outcome=done
     *
     * @effects installer_state_replace_survives_transient_handle_contention
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function it_moves_the_file_and_keeps_the_contents(): void
    {
        $from = $this->tmp('atomic-from-'.bin2hex(random_bytes(3)));
        $to = $this->tmp('atomic-to-'.bin2hex(random_bytes(3)));
        file_put_contents($from, '{"current_step":3}');

        $this->assertTrue(installerReplaceFileWithRetry($from, $to));
        $this->assertFileDoesNotExist($from, '원본이 남으면 tmp 잔해가 쌓인다');
        // meta:allow raw-source-read — 소스가 아니라 교체한 임시 픽스처 파일의 내용을 되읽는다.
        $this->assertSame('{"current_step":3}', (string) file_get_contents($to));
    }

    /**
     * 교체이지 생성이 아니다 — 대상이 이미 있는 것이 **정상 경로**다(상태 파일은 매번 덮인다).
     *
     * @scenario task_outcome=done
     *
     * @effects installer_state_replace_survives_transient_handle_contention
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function it_replaces_an_existing_destination(): void
    {
        $from = $this->tmp('atomic-from2-'.bin2hex(random_bytes(3)));
        $to = $this->tmp('atomic-to2-'.bin2hex(random_bytes(3)));
        file_put_contents($to, 'old');
        file_put_contents($from, 'new');

        $this->assertTrue(installerReplaceFileWithRetry($from, $to));
        $this->assertSame('new', (string) file_get_contents($to), '덮어쓰지 못하면 상태가 과거에 멈춘다');
    }

    /**
     * 원본이 없으면 **재시도하지 않는다** — 그것은 경합이 아니라 다른 실패이고, 기다려도
     * 달라지지 않는다. 설치 경로에서 무의미한 대기를 만들지 않기 위한 계약이다.
     *
     * @scenario task_outcome=done
     *
     * @effects installer_state_replace_survives_transient_handle_contention
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function it_does_not_retry_when_the_source_is_absent(): void
    {
        $from = $this->tmp('atomic-missing-'.bin2hex(random_bytes(3)));
        $to = $this->tmp('atomic-to3-'.bin2hex(random_bytes(3)));

        $startedAt = microtime(true);
        $this->assertFalse($this->withoutPhpWarnings(
            static fn (): bool => installerReplaceFileWithRetry($from, $to)
        ));
        $elapsedMs = (microtime(true) - $startedAt) * 1000;

        $this->assertLessThan(
            200,
            $elapsedMs,
            '원본 부재에서 재시도 대기를 다 쓰면 실패할 저장마다 설치가 그만큼 늦어진다'
        );
    }

    /**
     * 재시도로 없앨 수 없는 실패는 **그대로 false** 다 — 재시도가 실패를 감추지 않는다.
     * 대상 경로가 디렉터리면 rename 은 어떤 시도에서도 성공하지 않는다.
     *
     * @scenario task_outcome=done
     *
     * @effects installer_state_replace_survives_transient_handle_contention
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function it_still_fails_for_an_unrecoverable_destination(): void
    {
        $from = $this->tmp('atomic-from4-'.bin2hex(random_bytes(3)));
        $to = $this->tmp('atomic-dir-'.bin2hex(random_bytes(3)));
        file_put_contents($from, 'x');
        mkdir($to.'/child', 0755, true);   // 비어 있지 않은 디렉터리 → 교체 불가

        $this->assertFalse($this->withoutPhpWarnings(
            static fn (): bool => installerReplaceFileWithRetry($from, $to)
        ));
        $this->assertFileExists($from, '실패했으면 원본을 남겨 호출부가 정리하게 둔다');

        @rmdir($to.'/child');
        @rmdir($to);
        @unlink($from);
    }

    /**
     * 상태 저장 경로가 이 헬퍼를 **실제로 지난다**. 위 단언은 헬퍼만 재므로, 저장부가 맨
     * `rename` 으로 되돌아가도 전부 초록일 수 있다.
     *
     * @scenario task_outcome=done
     *
     * @effects installer_state_replace_survives_transient_handle_contention
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function the_state_save_path_uses_the_retrying_replace(): void
    {
        $source = $this->sourceWithoutComments(dirname(__DIR__, 3).'/public/install/includes/installer-state.php');
        $at = strpos($source, 'function saveInstallationState');
        $this->assertNotFalse($at);

        // 고정 길이로 자르지 않는다 — 이 파일의 주석은 한글이라 **바이트** 길이가 글자 수의
        // 세 배다. 넉넉해 보이는 상수(4,000)가 실제로는 함수 중간에서 끊겨, 아래 단언이
        // 「배선이 없다」로 붉어졌다(이 테스트를 쓰면서 실제로 겪었다).
        // 다음 최상위 선언까지를 함수 본문으로 본다.
        $next = strpos($source, "\nif (! function_exists(", $at);
        $body = $next === false ? substr($source, $at) : substr($source, $at, $next - $at);
        $this->assertGreaterThan(
            1000,
            strlen($body),
            '함수 본문을 제대로 자르지 못했다 — 이 단언이 빈 문자열을 검사하면 의미가 없다'
        );

        $this->assertStringContainsString(
            'installerReplaceFileWithRetry(',
            $body,
            '상태 저장이 재시도 없는 rename 으로 되돌아갔다 — 짧은 핸들 경합 한 번에 그 저장이 조용히 유실된다'
        );
    }

    /**
     * **형제 지점**도 같은 헬퍼를 지난다 — runtime.php 의 비밀 채널 쓰기.
     *
     * `writeInstallerRuntime()` 은 상태 저장과 같은 모양(tmp 에 쓰고 rename 으로 확정)이고,
     * 그 함수의 주석은 스스로 「형제(installer-state.php)와 같은 규율」이라고 세 번 적는다.
     * 한쪽만 재시도를 얻으면 그 선언이 거짓이 되고, 더 나쁜 쪽이 남는다 — runtime.php 는
     * **모든 요청이 `@include` 로 읽는** 파일이라 경합 창이 더 길고, 담는 것이 DB 자격증명·
     * APP_KEY·stepwise 토큰이라 쓰기 실패가 곧 그 단계의 실패다(상태 저장은 유실돼도 설치가
     * 굴러갔다 — 그래서 조용했다).
     *
     * `function_exists` 가드를 함께 확인한다. 이 헬퍼는 형제 파일에 있고, runtime.php 를
     * 단독으로 require 하는 진입 경로가 있으므로 가드 없이 부르면 그 경로가 치명 오류로 죽는다.
     *
     * @scenario task_outcome=done
     *
     * @effects installer_state_replace_survives_transient_handle_contention
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function the_runtime_secret_write_uses_the_retrying_replace(): void
    {
        $source = $this->sourceWithoutComments(dirname(__DIR__, 3).'/public/install/includes/installer-runtime.php');
        $at = strpos($source, 'function writeInstallerRuntime');
        $this->assertNotFalse($at);

        // 형제 테스트와 같은 이유로 고정 길이를 쓰지 않는다 — 한글 주석은 바이트가 글자 수의
        // 세 배라 넉넉해 보이는 상수가 함수 중간을 자른다. 다음 최상위 선언까지를 본문으로 본다.
        $next = strpos($source, "\nif (! function_exists(", $at);
        $body = $next === false ? substr($source, $at) : substr($source, $at, $next - $at);
        $this->assertGreaterThan(
            1000,
            strlen($body),
            '함수 본문을 제대로 자르지 못했다 — 이 단언이 빈 문자열을 검사하면 의미가 없다'
        );

        $this->assertStringContainsString(
            'installerReplaceFileWithRetry(',
            $body,
            '비밀 채널 쓰기가 재시도 없는 rename 으로 남았다 — 형제만 고치면 더 무거운 쪽이 그대로다'
        );
        $this->assertStringContainsString(
            "function_exists('installerReplaceFileWithRetry')",
            $body,
            '헬퍼는 형제 파일에 있다 — 가드 없이 부르면 runtime.php 를 단독 require 하는 경로가 죽는다'
        );
    }

    /**
     * 저장 전체가 끝단까지 성공한다 (헬퍼 교체가 저장 경로를 깨지 않았다는 회귀 축).
     *
     * @scenario task_outcome=done
     *
     * @effects installer_state_replace_survives_transient_handle_contention
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function saving_state_writes_the_file_atomically(): void
    {
        $this->assertTrue(saveInstallationState(['current_step' => 2, 'installation_status' => 'not_started']));

        $this->assertFileExists(STATE_PATH);
        // meta:allow raw-source-read — 소스가 아니라 저장된 상태 파일(JSON)을 읽는다.
        $decoded = json_decode((string) file_get_contents(STATE_PATH), true);
        $this->assertSame(2, $decoded['current_step'] ?? null);

        // tmp 잔해가 남지 않는다
        $leftovers = glob(STATE_PATH.INSTALLER_STATE_TMP_INFIX.'*') ?: [];
        $this->assertSame([], $leftovers, '교체 뒤 임시 파일이 남으면 다음 스윕까지 쌓인다');
    }
}
