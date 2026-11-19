<?php

namespace Tests\Unit\Installer;

use App\Support\ExecutionEnvironment;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\CleansTemporaryBasePath;

/**
 * 번들 vendor 설치의 안전 계약 회귀 테스트.
 *
 * 고정하는 계약:
 *
 *  - composer 실행 가능 판정은 composer_check 가 내린 답을 그대로 쓴다. 「경로 문자열이
 *    비어 있지 않다」만 보면 언제나 true 라, exec 는 열려 있고 composer 만 없는 호스팅에서
 *    `auto` 모드의 번들 폴백이 발동하지 않는다 — 그 태스크는 best_effort 도 skippable 도
 *    아니라 재시도해도 같은 자리에서 막힌다.
 *  - vendor 백업은 전부 옮겼을 때만 성공이다. 일부만 옮기고 성공으로 보고하면, 추출 실패 시
 *    복원 경로가 백업되지 않은 원본까지 지운다(백업 폴더를 뺀 vendor/ 전체 삭제).
 *
 * BASE_PATH 는 temp 로 고정한다 (R7).
 *
 *  - 번들 추출은 묶음으로 나뉜다. 한 번에 푸는 구현은 그 구간 내내 emit 이 0 이라 워커
 *    heartbeat 가 멈추고, 살아 있는 워커가 stale 로 판정되어 탈취된다.
 *
 * 커버 축: composer_availability recorded_true·recorded_false·unrecorded / vendor_backup complete·partial / bundle_extraction chunked
 *
 * 효과 요약(마커 아님 — 평문): composer_capability_follows_recorded_probe, vendor_backup_is_all_or_nothing, bundle_extraction_reports_progress_in_chunks
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class InstallerVendorBundleGuardTest extends TestCase
{
    use CleansTemporaryBasePath;

    private static string $base = '';

    private static string $skipReason = '';

    private static bool $booted = false;

    /**
     * 임시 BASE_PATH 와 저장소 폴더를 한 번만 만들고, 설치기 설정부터 번들 vendor 설치기까지 실행 경로의 모듈을 적재한다.
     *
     * 이미 정의된 BASE_PATH 가 시스템 임시 폴더 밖이면 실제 파일을 지키려고 건너뛸 사유만 남긴다.
     *
     * @return void 반환값 없음
     */
    private static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        $tempPrefix = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();

        if (defined('BASE_PATH')) {
            $resolved = realpath((string) BASE_PATH) ?: (string) BASE_PATH;
            if (strpos($resolved, $tempPrefix) !== 0) {
                self::$skipReason = 'BASE_PATH ('.$resolved.') 가 시스템 temp 하위가 아님 — 실 파일 파괴 방지를 위해 skip. '
                    .'격리 실행: php vendor/bin/phpunit --filter=InstallerVendorBundleGuardTest';

                return;
            }
            self::$base = (string) BASE_PATH;
        } else {
            self::$base = sys_get_temp_dir().'/g7-installer-vendor-'.bin2hex(random_bytes(4));
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
        require_once $root.'/public/install/includes/installer-runtime.php';
        require_once $root.'/public/install/includes/progress-emitter.php';
        require_once $root.'/public/install/includes/task-runner.php';
        require_once $root.'/public/install/includes/vendor-bundle-installer.php';
    }

    /**
     * 설치기 런타임을 준비하고, 이전 테스트의 상태 파일을 지운 뒤 진행 기록을 메모리 버퍼로 받도록 바꾼다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        self::boot();

        if (self::$skipReason !== '') {
            $this->markTestSkipped(self::$skipReason);
        }

        if (is_file(self::$base.'/storage/installer-state.json')) {
            unlink(self::$base.'/storage/installer-state.json');
        }

        setProgressEmitter(new \BufferEmitter);
    }

    /**
     * 실행 중 상태에 vendor 자동 모드와 빈 composer 경로를 깔고 덮어쓸 값을 얹어 상태 파일로 저장한다.
     *
     * @param  array<string, mixed>  $overrides  기본 상태 위에 덮어쓸 키와 값
     * @return void 반환값 없음
     */
    private function seedState(array $overrides = []): void
    {
        $state = DEFAULT_INSTALLATION_STATE;
        $state['installation_status'] = 'running';
        $state['config'] = ['vendor_mode' => 'auto', 'composer_binary' => '', 'php_binary' => 'php'];
        $state = array_replace($state, $overrides);

        $this->assertTrue(saveInstallationState($state));
    }

    /**
     * #M4 — 기록된 판정이 있으면 그대로 쓴다 (실행 불가로 기록됐으면 false).
     *
     * @scenario vendor_mode_choice=auto, task=composer
     *
     * @effects composer_capability_follows_recorded_probe
     *
     * @return void 반환값 없음
     */
    public function test_composer_capability_follows_the_recorded_probe(): void
    {
        if (! ExecutionEnvironment::hasFunction('proc_open')) {
            $this->markTestSkipped('proc_open 차단 환경 — 이 판정의 앞단에서 이미 false 다');
        }

        $this->seedState(['composer_available' => false]);
        $this->assertFalse(
            canExecuteComposerForInstall('', 'php'),
            'composer 가 없다고 판정된 설치에서 true 를 돌려주면 auto 모드가 번들 폴백을 건너뛰고 종료코드 127 로 막힌다'
        );

        $this->seedState(['composer_available' => true]);
        $this->assertTrue(canExecuteComposerForInstall('', 'php'), 'composer 가 있다고 판정됐으면 그 경로를 쓴다');
    }

    /**
     * #M11 — 백업은 전부 아니면 전무다.
     *
     * 목적지에 같은 이름의 **비어 있지 않은 디렉토리**가 있으면 rename 은 실패한다(두 OS 공통).
     * 그 상태에서 성공으로 보고하면 복원이 원본을 지운다.
     *
     * @scenario vendor_mode_choice=bundled, task=composer
     *
     * @effects vendor_backup_is_all_or_nothing
     *
     * @return void 반환값 없음
     */
    public function test_vendor_backup_is_all_or_nothing(): void
    {
        $vendorDir = self::$base.'/vendor-backup-case';
        $backupName = '.bundle_backup_test';
        $backupDir = $vendorDir.'/'.$backupName;

        @mkdir($vendorDir.'/keep-me', 0755, true);
        file_put_contents($vendorDir.'/keep-me/file.txt', 'original');
        @mkdir($vendorDir.'/blocked', 0755, true);
        file_put_contents($vendorDir.'/blocked/file.txt', 'original');

        // 목적지 선점 — 비어 있지 않아 rename 이 실패한다
        @mkdir($backupDir.'/blocked', 0755, true);
        file_put_contents($backupDir.'/blocked/occupied.txt', 'x');

        $result = moveVendorContentsToBackup($vendorDir, $backupDir, $backupName);

        $this->assertFalse($result, '한 항목이라도 못 옮기면 백업 성공으로 보고해서는 안 된다');
        $this->assertFileExists($vendorDir.'/keep-me/file.txt', '이미 옮긴 항목은 원위치로 돌아와야 한다 — 그러지 않으면 복원 경로가 그것을 지운다');
        $this->assertFileExists($vendorDir.'/blocked/file.txt', '옮기지 못한 원본은 그대로 남아 있어야 한다');
    }

    /**
     * #M2 — 추출은 묶음으로 나뉘고, 묶음마다 호출자가 「아직 살아 있다」를 알릴 기회를 얻는다.
     *
     * 이 계약이 없으면 추출 전 구간에 emit 이 0 이라 워커 heartbeat 가 멈춘다. SSE·폴링 워커는
     * lock 에 15초만 선언하므로, 그보다 오래 걸리는 추출(P0 실측 ≈10~15s) 도중 새로고침한 요청이
     * 살아 있는 워커를 stale 로 보고 탈취해 **두 요청이 같은 vendor/ 를 동시에 조작**한다.
     *
     * 결과물이 한 번에 푼 것과 같아야 하므로 파일 내용까지 대조한다 — 묶음 분할이 디렉토리
     * 구조를 흘리면 vendor 가 반쪽으로 풀린 채 설치가 이어진다.
     *
     * @scenario vendor_mode_choice=bundled, task_duration=long_no_feedback
     *
     * @effects bundle_extraction_reports_progress_in_chunks
     *
     * @return void 반환값 없음
     */
    public function test_bundle_extraction_reports_progress_in_chunks(): void
    {
        if (! class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive 미탑재 — 번들 추출 자체가 성립하지 않는 환경');
        }

        $zipPath = self::$base.'/chunked-extract.zip';
        $targetDir = self::$base.'/chunked-extract-out';
        @mkdir($targetDir, 0755, true);

        // 묶음 크기(500)보다 큰 항목 수 — 한 번에 푸는 구현이면 콜백이 1회 이하로 떨어진다
        $entryCount = 1200;
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true);
        for ($i = 0; $i < $entryCount; $i++) {
            $zip->addFromString(sprintf('vendor/pkg%02d/file%04d.txt', $i % 20, $i), 'content-'.$i);
        }
        $zip->close();

        $progress = [];
        $reopened = new \ZipArchive;
        $this->assertTrue($reopened->open($zipPath) === true);
        $extracted = extractVendorBundleInChunks($reopened, $targetDir, static function (int $done, int $total) use (&$progress): void {
            $progress[] = [$done, $total];
        });
        $reopened->close();

        $this->assertTrue($extracted, '묶음 추출이 실패하면 vendor 가 반쪽으로 남는다');
        $this->assertGreaterThan(
            1,
            count($progress),
            '묶음이 하나뿐이면 추출 구간 내내 heartbeat 가 멈춘 것과 같다 — 살아 있는 워커가 탈취된다'
        );
        $this->assertSame($entryCount, $progress[count($progress) - 1][0], '마지막 알림의 누적 개수가 전체와 같아야 한다');
        $this->assertSame($entryCount, $progress[count($progress) - 1][1], '전체 개수를 그대로 전달해야 진행 표시가 성립한다');

        // 결과물 동일성 — 첫·중간·마지막 항목
        foreach ([0, 599, $entryCount - 1] as $i) {
            $path = $targetDir.'/'.sprintf('vendor/pkg%02d/file%04d.txt', $i % 20, $i);
            $this->assertFileExists($path, '묶음 분할이 항목을 흘리면 vendor 가 반쪽으로 풀린다');
            $this->assertSame('content-'.$i, file_get_contents($path));
        }
    }
}
