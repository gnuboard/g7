<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\CleansTemporaryBasePath;

/**
 * 인스톨러의 호스팅 이식성 회귀 가드.
 *
 * 다루는 축:
 *  - 공백을 포함한 실행 경로 (Windows 기본 설치 위치)
 *  - 실제 쓰기 가능 여부 (Windows NTFS ACL 은 `is_writable()` 이 보지 않는다)
 *  - vendor 번들 백업의 잔재 정리·위치·실패 보고
 *  - finalize 직전의 `.env` 보존
 *
 * @effects installer_survives_windows_and_shared_hosting_constraints
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class InstallerHostingPortabilityTest extends TestCase
{
    use CleansTemporaryBasePath;

    private string $tempBase = '';

    private string $skipReason = '';

    protected function setUp(): void
    {
        parent::setUp();

        $projectRoot = dirname(__DIR__, 3);
        $this->tempBase = sys_get_temp_dir().'/g7-hosting-portability-'.bin2hex(random_bytes(4));
        @mkdir($this->tempBase.'/storage/installer', 0755, true);

        require_once $projectRoot.'/public/install/includes/binary-path-policy.php';

        // BASE_PATH 는 상수라 한 프로세스에서 한 번만 정의된다. 임시 루트로 잡히지 않으면
        // functions.php 의 권한 검사가 실 저장소를 대상으로 돌게 되므로 그 축만 건너뛴다.
        if (! defined('BASE_PATH')) {
            define('BASE_PATH', $this->tempBase);
        }

        $tempPrefix = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
        $resolved = realpath((string) BASE_PATH) ?: (string) BASE_PATH;

        if (strpos($resolved, $tempPrefix) !== 0) {
            $this->skipReason = 'BASE_PATH ('.$resolved.') 가 시스템 temp 하위가 아님 — '
                .'격리 실행: php vendor/bin/phpunit --filter=InstallerHostingPortabilityTest';

            return;
        }

        $this->tempBase = (string) BASE_PATH;

        require_once __DIR__.'/stubs/lang_stub.php';
        require_once $projectRoot.'/public/install/includes/functions.php';
        require_once $projectRoot.'/public/install/includes/installer-runtime.php';
        require_once $projectRoot.'/public/install/includes/vendor-bundle-installer.php';
    }

    protected function tearDown(): void
    {
        if ($this->skipReason === '' && is_dir($this->tempBase)) {
            $this->removeTree($this->tempBase);
        }

        parent::tearDown();
    }

    private function requireTempBase(): void
    {
        if ($this->skipReason !== '') {
            $this->markTestSkipped($this->skipReason);
        }
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir.DIRECTORY_SEPARATOR.$item;
            is_dir($path) && ! is_link($path) ? $this->removeTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    // ========================================================================
    // 환경#22 — 공백을 포함한 실행 경로 (Windows 기본 설치 위치)
    // ========================================================================

    /**
     * 인용부호로 감싼 경로는 공백을 포함해도 통과한다.
     *
     * `C:\Program Files\PHP\php.exe` 는 Windows 기본 설치 위치다. 형태 규칙이 공백을 무조건
     * 차단하면 그 입력은 조용히 무시되고 `'php'` 로 되돌아간다 — 운영자에게는 자기가 넣은
     * 경로가 왜 없는 셈이 되는지 드러나지 않는다.
     */
    public function test_quoted_path_with_spaces_is_accepted(): void
    {
        $this->assertTrue(installer_binary_path_shape_ok('"C:\\Program Files\\PHP\\php.exe"'));
        $this->assertTrue(installer_binary_path_shape_ok('"/opt/my php/bin/php"'));
        $this->assertTrue(installer_is_composer_binary_path('"C:\\Program Files\\Composer\\composer.phar"'));
    }

    /**
     * 인용은 공백만 열어 준다 — 다른 셸 메타문자는 그대로 막힌다.
     */
    public function test_quoting_does_not_open_a_bypass(): void
    {
        foreach ([
            '"/usr/bin/php;id"' => '세미콜론',
            '"/usr/bin/`id`"' => '백틱',
            '"/usr/bin/$(id)"' => '명령 치환',
            '"-r phpinfo();"' => '하이픈 선두',
            '"bin/composer"' => '상대경로',
            '"/usr/local/../tmp/php"' => '.. 세그먼트',
            '""' => '빈 인용',
            '"/usr/bin/php' => '닫히지 않은 인용',
        ] as $token => $reason) {
            $this->assertFalse(
                installer_binary_path_shape_ok((string) $token),
                "인용이 검사를 우회했다 ({$reason}): {$token}"
            );
        }
    }

    /**
     * 인용하지 않은 공백은 종전대로 거부된다 (토큰 분리 모호성).
     */
    public function test_unquoted_space_is_still_rejected(): void
    {
        $this->assertFalse(installer_binary_path_shape_ok('C:\\Program Files\\PHP\\php.exe'));
    }

    /**
     * 인용된 두 토큰 쌍이 해석되고, 결과는 따옴표를 벗긴 실제 경로다.
     */
    public function test_quoted_pair_resolves_to_unquoted_paths(): void
    {
        $resolved = installer_resolve_php_composer_pair(
            '"C:\\Program Files\\PHP\\php.exe" "C:\\Program Files\\Composer\\composer.phar"'
        );

        $this->assertSame([
            'php' => 'C:\\Program Files\\PHP\\php.exe',
            'composer' => 'C:\\Program Files\\Composer\\composer.phar',
        ], $resolved);
    }

    /**
     * 인용된 PHP + 인용 없는 Composer 처럼 섞여도 해석된다.
     */
    public function test_mixed_quoting_pair_resolves(): void
    {
        $resolved = installer_resolve_php_composer_pair('"C:\\Program Files\\PHP\\php.exe" C:/composer/composer.phar');

        $this->assertSame([
            'php' => 'C:\\Program Files\\PHP\\php.exe',
            'composer' => 'C:/composer/composer.phar',
        ], $resolved);
    }

    /**
     * 인용 뒤에 공백 없이 글자가 붙으면 경계가 모호하므로 거부한다.
     */
    public function test_pair_rejects_ambiguous_quote_boundary(): void
    {
        $this->assertNull(installer_resolve_php_composer_pair('"/usr/bin/php"x /usr/bin/composer.phar'));
    }

    // ========================================================================
    // 환경#15 — 권한 판정은 실제 쓰기로 한다
    // ========================================================================

    public function test_write_probe_accepts_a_writable_directory(): void
    {
        $this->requireTempBase();

        $dir = $this->tempBase.'/writable-dir';
        @mkdir($dir, 0755, true);

        $this->assertTrue(installerDirectoryIsWritable($dir));
    }

    public function test_write_probe_rejects_a_missing_directory(): void
    {
        $this->requireTempBase();

        $this->assertFalse(installerDirectoryIsWritable($this->tempBase.'/no-such-dir'));
    }

    /**
     * probe 는 흔적을 남기지 않는다 — 남기면 다음 검사가 그 파일을 세거나 배포본에 섞인다.
     */
    public function test_write_probe_leaves_no_residue(): void
    {
        $this->requireTempBase();

        $dir = $this->tempBase.'/probe-residue';
        @mkdir($dir, 0755, true);

        installerDirectoryIsWritable($dir);

        $this->assertSame(['.', '..'], scandir($dir), 'probe 파일이 남았다');
    }

    /**
     * 파일 경로는 디렉토리가 아니므로 통과하지 않는다.
     */
    public function test_write_probe_rejects_a_file_path(): void
    {
        $this->requireTempBase();

        $file = $this->tempBase.'/plain.txt';
        file_put_contents($file, 'x');

        $this->assertFalse(installerDirectoryIsWritable($file));
    }

    // ========================================================================
    // 환경#23 / D14 / D15 — vendor 번들 백업
    // ========================================================================

    /**
     * 백업은 vendor/ 밖(storage/installer)에 만든다.
     *
     * vendor/ 안에 두면 이미 깊은 패키지 경로에 한 단계가 더 붙어 Windows 의 260자 한계를
     * 그대로 넘긴다 — `rename()` 이 조용히 실패해 "부분 이동" 으로 끝난다.
     */
    public function test_backup_directory_lives_outside_vendor(): void
    {
        $this->requireTempBase();

        $vendorDir = $this->tempBase.'/vendor';
        @mkdir($vendorDir, 0755, true);

        $resolved = resolveVendorBundleBackupDir($this->tempBase, $vendorDir, '.bundle_backup_20260824_000000');

        $this->assertStringNotContainsString(
            DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR,
            $resolved,
            '백업이 vendor/ 안에 만들어진다'
        );
        $this->assertStringContainsString('storage', $resolved);
        $this->assertStringContainsString('vendor-backup-', $resolved);
    }

    /**
     * storage/installer 를 쓸 수 없으면 종전 위치로 떨어진다 (기능 축소 없음).
     */
    public function test_backup_directory_falls_back_into_vendor_when_storage_unusable(): void
    {
        $this->requireTempBase();

        // storage 자리를 **파일**로 막아 디렉토리 생성이 불가능하게 만든다.
        $blocked = $this->tempBase.'/blocked-root';
        @mkdir($blocked, 0755, true);
        file_put_contents($blocked.'/storage', 'not a directory');

        $vendorDir = $blocked.'/vendor';
        @mkdir($vendorDir, 0755, true);

        $resolved = resolveVendorBundleBackupDir($blocked, $vendorDir, '.bundle_backup_x');

        $this->assertSame($vendorDir.DIRECTORY_SEPARATOR.'.bundle_backup_x', $resolved);
    }

    /**
     * 지난 실행이 남긴 백업을 진입 시점에 치운다.
     *
     * 복원은 같은 프로세스에서 추출이 false 를 돌려준 경우에만 돈다 — 프로세스가 그냥 죽으면
     * 백업이 남고, 재시도가 그것까지 새 백업으로 옮겨 담아 중첩된다.
     */
    public function test_stale_backups_are_cleaned_before_a_new_run(): void
    {
        $this->requireTempBase();

        $vendorDir = $this->tempBase.'/vendor';
        $legacy = $vendorDir.'/.bundle_backup_20260101_000000';
        $current = $this->tempBase.'/storage/installer/vendor-backup-20260102_000000';

        @mkdir($legacy.'/nested', 0755, true);
        file_put_contents($legacy.'/nested/keep.txt', 'x');
        @mkdir($current, 0755, true);
        file_put_contents($current.'/keep.txt', 'x');

        $removed = cleanupStaleVendorBundleBackups($vendorDir, $this->tempBase);

        $this->assertSame(2, $removed);
        $this->assertDirectoryDoesNotExist($legacy, '종전 위치의 잔재가 남았다');
        $this->assertDirectoryDoesNotExist($current, '현재 위치의 잔재가 남았다');
    }

    /**
     * 실제 vendor/ 항목은 정리 대상이 아니다 (오탐 방지).
     */
    public function test_cleanup_does_not_touch_real_vendor_packages(): void
    {
        $this->requireTempBase();

        $vendorDir = $this->tempBase.'/vendor';
        @mkdir($vendorDir.'/acme/widget', 0755, true);
        file_put_contents($vendorDir.'/acme/widget/file.php', '<?php');

        cleanupStaleVendorBundleBackups($vendorDir, $this->tempBase);

        $this->assertFileExists($vendorDir.'/acme/widget/file.php');
    }

    // ========================================================================
    // D8 — finalize 가 기존 `.env` 를 지우기 전에 한 벌 남긴다
    // ========================================================================

    public function test_existing_env_is_preserved_before_regeneration(): void
    {
        $this->requireTempBase();

        $envPath = $this->tempBase.'/.env';
        $original = "APP_ENV=production\nMAIL_MAILER=ses\nREDIS_PASSWORD=operator-secret\n";
        file_put_contents($envPath, $original);

        $backup = backupExistingEnvFile($envPath);

        $this->assertNotNull($backup, '백업이 만들어지지 않았다');
        $this->assertStringStartsWith($envPath.'.bak.', $backup);
        // meta:allow raw-source-read 생산 소스가 아니라 방금 만든 temp `.env` 사본을 읽는다 (주석 채널과 무관)
        $this->assertSame($original, file_get_contents($backup));
    }

    public function test_env_backup_is_a_no_op_when_there_is_nothing_to_preserve(): void
    {
        $this->requireTempBase();

        $this->assertNull(backupExistingEnvFile($this->tempBase.'/.env-absent'));
    }

    /**
     * 같은 초에 두 번 불려도 앞의 사본을 덮어쓰지 않는다.
     */
    public function test_env_backup_does_not_overwrite_an_earlier_copy(): void
    {
        $this->requireTempBase();

        $envPath = $this->tempBase.'/.env';
        file_put_contents($envPath, "FIRST=1\n");
        $first = backupExistingEnvFile($envPath);

        file_put_contents($envPath, "SECOND=2\n");
        $second = backupExistingEnvFile($envPath);

        $this->assertNotSame($first, $second);
        // meta:allow raw-source-read 생산 소스가 아니라 방금 만든 temp `.env` 사본을 읽는다 (주석 채널과 무관)
        $this->assertSame("FIRST=1\n", file_get_contents($first));
        $this->assertSame("SECOND=2\n", file_get_contents($second));
    }
}
