<?php

namespace Tests\Unit\Upgrades;

use App\Extension\UpgradeContext;
use App\Upgrades\Data\V7_2_0\Migrations\ReconcileRootFilesPlacedByLegacyUpdate;
use App\Upgrades\Data\V7_2_0\Migrations\RestoreOperatorFaviconFromBackup;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 7.2.0 데이터 스텝 12·13 — 7.1.x 부모 프로세스의 파일 적용이 남긴 결과를 바로잡는다.
 *
 * 7.1.x → 7.2.0 업데이트의 파일 적용은 7.1.x 코드가 한다. 그래서 7.2.0 이 고친 판정(운영자 파비콘 보존, 설치용
 * 압축 풀기 파일 보호, 루트 `.htaccess` 열거)이 그 전환에서 돌지 않았다(7.1.6 → 7.2.0 실측):
 *  - 운영자 `public/favicon.ico` 가 7.2.0 기본 아이콘으로 덮였다 → 12 가 이번 업데이트의 백업에서 되살린다.
 *  - 설치가 끝난 사이트 루트에 `g7-setup.php` 가 놓였다 → 13 이 지운다.
 *  - 루트 `index.php` 만 놓이고 `.htaccess` 가 빠졌다 → 13 이 배포본 압축 해제본에서 놓는다.
 *
 * 시나리오 SSoT: tests/scenarios/upgrade-720-legacy-updater-file-fixups.yaml
 */
class Upgrade720LegacyUpdaterFileFixupsTest extends TestCase
{
    /** 7.2.0 기본 아이콘 대용 본문 */
    private const DEFAULT_ICO = "ICO-7.2.0-default\0\1\2";

    /** 운영자 아이콘 대용 본문 */
    private const OPERATOR_ICO = "ICO-operator\0\9\8\7";

    /** 압축 풀기 파일 머리 */
    private const SETUP_HEAD = "<?php\n\ndeclare(strict_types=1);\n\n/**\n * G7 압축 풀기 부트스트랩 (ZIP Bootstrap) — FTP 전용 계정용 단일 진입 파일\n */\n\nconst G7_SETUP_TOKEN_FILE = 'g7-setup-token.txt';\nconst G7_SETUP_STATE_FILE = 'g7-setup-state.json';\n";

    /** 임시 설치 루트 */
    private string $root;

    /** 임시 코어 백업 루트 */
    private string $backups;

    /** 임시 코어 업데이트 대기 루트 */
    private string $pending;

    /**
     * 데이터 스텝을 싣고 임시 설치·백업·대기 루트를 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 업그레이드 데이터 스텝은 오토로드되지 않는다 — 실행 시점과 같은 방식으로 싣는다.
        require_once base_path('upgrades/data/7.2.0/migrations/12_RestoreOperatorFaviconFromBackup.php');
        require_once base_path('upgrades/data/7.2.0/migrations/13_ReconcileRootFilesPlacedByLegacyUpdate.php');

        $base = storage_path('framework/testing/legacy-updater-fixups-'.uniqid());
        $this->root = $base.'/site';
        $this->backups = $base.'/core_backups';
        $this->pending = $base.'/core_pending';
        File::ensureDirectoryExists($this->root);
    }

    /**
     * 임시 루트를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory(dirname($this->root));

        parent::tearDown();
    }

    // ── 12: 운영자 파비콘 ─────────────────────────────────────────────────

    /**
     * 7.1.6 → 7.2.0 실측 재현: 업데이트가 운영자 아이콘을 기본 아이콘으로 덮었으면 이번 업데이트 백업에서 되살린다.
     *
     * @scenario case=favicon_overwritten_operator_ico
     *
     * @effects operator_favicon_restored_from_backup
     *
     * @return void 반환값 없음
     */
    public function test_operator_favicon_overwritten_by_update_is_restored_from_backup(): void
    {
        $this->seedUpdatedFavicon();
        $this->makeBackup('core_20261117_150000', '7.1.6', '7.2.0', self::OPERATOR_ICO);

        $this->runFavicon();

        $this->assertSame(self::OPERATOR_ICO, $this->read('public/favicon.ico'), '업데이트가 덮은 운영자 파비콘을 되살리지 못했다');

        // 사이트 아이콘 파일이 아예 없으면 새로 만들어 되살린다(root 실행이면 `public/` 소유자로 맞춘다 — 소유권은 POSIX root 에서만 바뀐다)
        unlink($this->path('public/favicon.ico'));
        $this->runFavicon();

        $this->assertSame(self::OPERATOR_ICO, $this->read('public/favicon.ico'), '없어진 사이트 아이콘을 되살리지 못했다');
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->assertSame(fileowner($this->path('public')), fileowner($this->path('public/favicon.ico')), '새로 만든 아이콘의 소유자를 public/ 에 맞추지 않았다');
        }
    }

    /**
     * 업데이트 전 파일이 7.1.x 의 빈 기본 파일이면 새 기본 아이콘을 그대로 둔다.
     *
     * @scenario case=favicon_empty_legacy_default
     *
     * @effects empty_legacy_favicon_becomes_new_default
     *
     * @return void 반환값 없음
     */
    public function test_empty_legacy_favicon_keeps_new_default(): void
    {
        $this->seedUpdatedFavicon();
        $this->makeBackup('core_20261117_150000', '7.1.6', '7.2.0', '');

        $this->runFavicon();

        $this->assertSame(self::DEFAULT_ICO, $this->read('public/favicon.ico'));
    }

    /**
     * 이번 실행(from → to)과 다른 백업·목록 파일이 없는 백업은 쓰지 않고, 맞는 백업이 여럿이면 가장 새 것을 쓴다.
     *
     * @scenario case=favicon_backup_selection
     *
     * @effects only_matching_newest_backup_is_used
     *
     * @return void 반환값 없음
     */
    public function test_only_newest_backup_of_this_run_is_used(): void
    {
        $this->seedUpdatedFavicon();
        $this->makeBackup('core_20261101_100000', '7.1.6', '7.2.0', 'ICO-older-operator');
        $this->makeBackup('core_20261117_150000', '7.1.6', '7.2.0', self::OPERATOR_ICO);
        $this->makeBackup('core_20261118_090000', '7.1.5', '7.1.6', 'ICO-other-run');
        $this->makeBackup('core_20261118_100000', null, null, 'ICO-no-manifest');

        $this->runFavicon();

        $this->assertSame(self::OPERATOR_ICO, $this->read('public/favicon.ico'));

        // 이번 실행의 백업이 없으면 판정 근거가 없다 — 아무것도 바꾸지 않는다.
        $this->seedUpdatedFavicon();
        $this->runFavicon(new UpgradeContext('7.2.0', '7.2.0', '7.2.0'));

        $this->assertSame(self::DEFAULT_ICO, $this->read('public/favicon.ico'), '이번 실행이 아닌 백업으로 파비콘을 바꿨다');
    }

    /**
     * 지금 파일이 기본 아이콘도 업데이트 전 파일도 아니면(업데이트 뒤 누군가 넣은 파일) 건드리지 않고, 되살린 뒤 다시
     * 돌려도 바꾸지 않는다.
     *
     * @scenario case=favicon_unrelated_current
     *
     * @effects unrelated_current_favicon_kept, favicon_restore_is_idempotent
     *
     * @return void 반환값 없음
     */
    public function test_unrelated_current_favicon_is_kept_and_rerun_is_noop(): void
    {
        $this->seedUpdatedFavicon();
        $this->write('public/favicon.ico', 'ICO-placed-after-update');
        $this->makeBackup('core_20261117_150000', '7.1.6', '7.2.0', self::OPERATOR_ICO);

        $this->runFavicon();

        $this->assertSame('ICO-placed-after-update', $this->read('public/favicon.ico'));

        $this->write('public/favicon.ico', self::DEFAULT_ICO);
        $this->runFavicon();
        $this->runFavicon();

        $this->assertSame(self::OPERATOR_ICO, $this->read('public/favicon.ico'));
    }

    // ── 13: 루트 압축 풀기 파일 ───────────────────────────────────────────

    /**
     * 설치가 끝난 사이트 루트의 그누보드7 압축 풀기 파일은 지운다 — 설치 표식 파일·`.env` 표식 모두.
     *
     * @scenario case=setup_file_installed
     *
     * @effects installed_site_setup_file_removed
     *
     * @return void 반환값 없음
     */
    public function test_setup_file_is_removed_on_installed_site(): void
    {
        $this->write('storage/app/g7_installed', '');
        $this->write('g7-setup.php', self::SETUP_HEAD.str_repeat("// body\n", 50));

        $this->runRootFiles();

        $this->assertFileDoesNotExist($this->path('g7-setup.php'), '설치가 끝난 사이트에 압축 풀기 파일이 남았다');

        File::delete($this->path('storage/app/g7_installed'));
        $this->write('.env', "APP_NAME=G7\nINSTALLER_COMPLETED=false\nINSTALLER_COMPLETED=\"true\"\n");
        $this->write('g7-setup.php', self::SETUP_HEAD);

        $this->runRootFiles();

        $this->assertFileDoesNotExist($this->path('g7-setup.php'), '.env 설치 표식을 읽지 못했다');
    }

    /**
     * 설치 표식이 없는 사이트, 그누보드7 압축 풀기 파일이 아닌 같은 이름의 파일은 그대로 둔다.
     *
     * @scenario case=setup_file_guarded
     *
     * @effects setup_file_kept_when_not_installed_or_foreign
     *
     * @return void 반환값 없음
     */
    public function test_setup_file_is_kept_when_not_installed_or_not_g7_bootstrap(): void
    {
        $this->write('g7-setup.php', self::SETUP_HEAD);
        $this->write('.env', "INSTALLER_COMPLETED=false\n");

        $this->runRootFiles();

        $this->assertFileExists($this->path('g7-setup.php'), '설치 중인 사이트의 압축 풀기 파일을 지웠다');

        $this->write('storage/app/g7_installed', '');
        $this->write('g7-setup.php', "<?php\n// 운영자가 만든 설정 스크립트\n");

        $this->runRootFiles();

        $this->assertFileExists($this->path('g7-setup.php'), '운영자 파일을 지웠다');
    }

    // ── 13: 루트 보호 규칙 ───────────────────────────────────────────────

    /**
     * 7.1.6 → 7.2.0 실측 재현: 루트 `index.php` 만 놓이고 `.htaccess` 가 빠졌으면 배포본 압축 해제본(래퍼 디렉토리
     * 포함)에서 루트 `index.php` 가 같은 배포본의 `.htaccess` 를 놓는다. 다시 돌려도 바꾸지 않는다.
     *
     * @scenario case=root_htaccess_missing
     *
     * @effects missing_root_htaccess_placed_from_release, root_htaccess_fix_is_idempotent
     *
     * @return void 반환값 없음
     */
    public function test_missing_root_htaccess_is_placed_from_release(): void
    {
        $this->write('index.php', "<?php // root entry 7.2.0\n");
        // 더 오래된 대기 디렉토리 — 다른 판 배포본(루트 index.php 가 다름)
        $this->write('../core_pending/core_20261117_140000/index.php', "<?php // other release\n");
        $this->write('../core_pending/core_20261117_140000/.htaccess', "# other release rules\n");
        $this->write('../core_pending/core_20261117_150000/extracted/gnuboard-g7-abc123/index.php', "<?php // root entry 7.2.0\n");
        $this->write('../core_pending/core_20261117_150000/extracted/gnuboard-g7-abc123/.htaccess', "# BEGIN G7\n# release rules\n# END G7\n");

        $this->runRootFiles();

        $this->assertSame("# BEGIN G7\n# release rules\n# END G7\n", $this->read('.htaccess'), '빠진 루트 .htaccess 를 이번 배포본에서 놓지 못했다');

        $this->runRootFiles();
        $this->assertSame("# BEGIN G7\n# release rules\n# END G7\n", $this->read('.htaccess'));

        // `--source` 복제본(대기 디렉토리 바로 아래)도 찾는다
        File::delete($this->path('.htaccess'));
        File::deleteDirectory($this->pending);
        $this->write('../core_pending/core_20261117_160000/index.php', "<?php // root entry 7.2.0\n");
        $this->write('../core_pending/core_20261117_160000/.htaccess', "# source copy rules\n");

        $this->runRootFiles();

        $this->assertSame("# source copy rules\n", $this->read('.htaccess'));
    }

    /**
     * 이미 있는 `.htaccess`(운영자 파일)는 건드리지 않고, 루트 `index.php` 가 같은 배포본을 찾지 못하면 놓지 않는다.
     *
     * @scenario case=root_htaccess_guarded
     *
     * @effects existing_root_htaccess_kept, unmatched_release_not_used
     *
     * @return void 반환값 없음
     */
    public function test_existing_htaccess_kept_and_unmatched_release_not_used(): void
    {
        $this->write('index.php', "<?php // root entry 7.2.0\n");
        $this->write('.htaccess', "# operator rules\n");
        $this->write('../core_pending/core_20261117_150000/extracted/index.php', "<?php // root entry 7.2.0\n");
        $this->write('../core_pending/core_20261117_150000/extracted/.htaccess', "# release rules\n");

        $this->runRootFiles();

        $this->assertSame("# operator rules\n", $this->read('.htaccess'), '운영자 루트 .htaccess 를 덮었다');

        File::delete($this->path('.htaccess'));
        $this->write('index.php', "<?php // operator customised root entry\n");

        $this->runRootFiles();

        $this->assertFileDoesNotExist($this->path('.htaccess'), '루트 index.php 가 다른 배포본의 .htaccess 를 놓았다');
    }

    /**
     * 백업 디렉토리 하나를 만든다(7.1.x `createBackup` + `writeNewFilesManifest` 의 모양).
     *
     * @param  string  $name  백업 디렉토리 이름
     * @param  string|null  $from  목록 파일의 from_version (null 이면 목록 파일 없음)
     * @param  string|null  $to  목록 파일의 to_version
     * @param  string  $favicon  백업된 public/favicon.ico 본문
     * @return void 반환값 없음
     */
    private function makeBackup(string $name, ?string $from, ?string $to, string $favicon): void
    {
        $dir = $this->backups.'/'.$name;
        File::ensureDirectoryExists($dir.'/public');
        File::put($dir.'/public/favicon.ico', $favicon);

        if ($from !== null) {
            File::put($dir.'/_new_files_manifest.json', json_encode([
                'version' => 1,
                'created_at' => '2026-11-17T15:00:00+09:00',
                'from_version' => $from,
                'to_version' => $to,
                'new_files' => [],
                'new_dirs' => [],
            ]));
        }
    }

    /**
     * 업데이트 뒤의 파비콘 상태(기본 아이콘이 사이트 아이콘·번들 아이콘 두 곳에 있음)를 만든다.
     *
     * @return void 반환값 없음
     */
    private function seedUpdatedFavicon(): void
    {
        $this->write('public/favicon.ico', self::DEFAULT_ICO);
        $this->write('public/assets/core/favicon/favicon.ico', self::DEFAULT_ICO);
    }

    /**
     * 파비콘 스텝을 실행한다.
     *
     * @param  UpgradeContext|null  $context  컨텍스트 (null 이면 7.1.6 → 7.2.0)
     * @return void 반환값 없음
     */
    private function runFavicon(?UpgradeContext $context = null): void
    {
        (new RestoreOperatorFaviconFromBackup($this->root, $this->backups))->run($context ?? new UpgradeContext('7.1.6', '7.2.0', '7.2.0'));
    }

    /**
     * 루트 파일 스텝을 실행한다.
     *
     * @return void 반환값 없음
     */
    private function runRootFiles(): void
    {
        (new ReconcileRootFilesPlacedByLegacyUpdate($this->root, $this->pending))->run(new UpgradeContext('7.1.6', '7.2.0', '7.2.0'));
    }

    /**
     * 임시 설치 루트 기준 파일을 쓴다(`../` 로 형제 루트에도 쓴다).
     *
     * @param  string  $relative  상대 경로
     * @param  string  $content  본문
     * @return void 반환값 없음
     */
    private function write(string $relative, string $content): void
    {
        File::ensureDirectoryExists(dirname($this->path($relative)));
        File::put($this->path($relative), $content);
    }

    /**
     * 임시 설치 루트 기준 파일을 읽는다.
     *
     * @param  string  $relative  상대 경로
     * @return string 본문
     */
    private function read(string $relative): string
    {
        return (string) file_get_contents($this->path($relative));
    }

    /**
     * 임시 설치 루트 기준 절대 경로.
     *
     * @param  string  $relative  상대 경로
     * @return string 절대 경로
     */
    private function path(string $relative): string
    {
        return $this->root.'/'.$relative;
    }
}
