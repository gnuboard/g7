<?php

namespace Tests\Unit\Upgrades;

require_once __DIR__.'/../../../upgrades/data/7.1.6/migrations/01_WritePublicDiskHtaccess.php';

use App\Extension\UpgradeContext;
use App\Upgrades\Data\V7_1_6\Migrations\WritePublicDiskHtaccess;
use Tests\TestCase;

/**
 * 7.1.6 업그레이드 스텝 — 공개 디스크 직접 주소의 웹서버 보호 규칙 파일을 만든다.
 *
 * 이미 운영 중인 사이트의 `/storage` 에는 규칙 파일이 없다. 업데이트 한 번으로 보호 규칙이 놓이게 한다.
 *
 * @scenario-manifest tests/scenarios/public-disk-exportable-extensions.yaml
 */
class WritePublicDiskHtaccessTest extends TestCase
{
    private string $storageRoot;

    private string $originalStoragePath;

    /**
     * 저장소 루트를 임시 디렉터리로 바꿉니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->originalStoragePath = $this->app->storagePath();
        $this->storageRoot = $this->originalStoragePath.'/framework/testing/upgrade-716-'.uniqid();
        mkdir($this->storageRoot.'/app/public', 0777, true);
        $this->app->useStoragePath($this->storageRoot);
    }

    /**
     * 저장소 루트를 되돌리고 임시 디렉터리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->app->useStoragePath($this->originalStoragePath);

        foreach (glob($this->storageRoot.'/app/public/{,.}[!.]*', GLOB_BRACE) ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->storageRoot.'/app/public');
        @rmdir($this->storageRoot.'/app');
        @rmdir($this->storageRoot);

        parent::tearDown();
    }

    /**
     * 업그레이드 컨텍스트를 만듭니다.
     *
     * @return UpgradeContext 컨텍스트
     */
    private function context(): UpgradeContext
    {
        return new UpgradeContext('7.1.5', '7.1.6', '7.1.6');
    }

    /**
     * 규칙 파일이 없으면 만든다 — nosniff·내려받기 강제·PHP 실행 차단이 들어 있다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=upgrade_step__not_exportable
     *
     * @effects upgrade_writes_webserver_rules_once
     */
    public function test_writes_the_rules_file_when_missing(): void
    {
        (new WritePublicDiskHtaccess)->run($this->context());

        $rules = (string) file_get_contents($this->storageRoot.'/app/public/.htaccess');

        $this->assertStringStartsWith('# g7-public-disk-hardening v2', $rules);
        $this->assertStringContainsString('X-Content-Type-Options "nosniff"', $rules);
        $this->assertStringContainsString('Content-Disposition "attachment"', $rules);
        $this->assertStringContainsString('<IfModule !mod_headers.c>', $rules);
        $this->assertStringContainsString('RewriteRule "(?i)\.(php\d?|phtml|phar|pht)$" - [F]', $rules);
        $this->assertStringNotContainsString('Require ', $rules);
        $this->assertStringNotContainsString('php_flag', $rules);
        $this->assertStringContainsString('(jpg|jpeg|png|gif|webp|avif)', $rules);
        $this->assertStringNotContainsString("\r", $rules);
    }

    /**
     * 다시 돌려도 이미 놓인 규칙 파일을 건드리지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=upgrade_step__not_exportable
     *
     * @effects upgrade_writes_webserver_rules_once
     */
    public function test_is_idempotent(): void
    {
        $migration = new WritePublicDiskHtaccess;
        $migration->run($this->context());

        $path = $this->storageRoot.'/app/public/.htaccess';
        file_put_contents($path, file_get_contents($path)."# 운영 중 다시 만들어진 내용\n");
        $before = file_get_contents($path);

        $migration->run($this->context());

        $this->assertSame($before, file_get_contents($path));
        $this->assertFileDoesNotExist($path.'.g7-backup');
    }

    /**
     * 표지가 없는 기존 파일은 사본을 남기고 바꾼다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=upgrade_step__not_exportable
     *
     * @effects upgrade_writes_webserver_rules_once
     */
    public function test_backs_up_an_unmarked_file(): void
    {
        $path = $this->storageRoot.'/app/public/.htaccess';
        file_put_contents($path, "Options -Indexes\n");

        (new WritePublicDiskHtaccess)->run($this->context());

        $this->assertStringStartsWith('# g7-public-disk-hardening v2', (string) file_get_contents($path));
        $this->assertSame("Options -Indexes\n", file_get_contents($path.'.g7-backup'));
    }

    /**
     * 공개 디스크 디렉터리가 없으면 아무것도 만들지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=upgrade_step__not_exportable
     *
     * @effects upgrade_writes_webserver_rules_once
     */
    public function test_skips_when_public_disk_directory_is_missing(): void
    {
        @rmdir($this->storageRoot.'/app/public');

        (new WritePublicDiskHtaccess)->run($this->context());

        $this->assertDirectoryDoesNotExist($this->storageRoot.'/app/public');
    }

    /**
     * root 로 실행된 업데이트(sudo)가 만든 규칙 파일은 공개 디스크 디렉터리의 소유자·그룹을 이어받는다.
     *
     * 이 파일은 업데이트 시작 시점에 없으므로 코어 업데이트의 소유권 스냅샷·복원이 닿지 않는다 — 스텝이 직접 맞춘다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=upgrade_step__not_exportable
     *
     * @effects webserver_rules_owned_by_directory_owner
     */
    public function test_rules_written_as_root_take_the_directory_owner(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || ! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            $this->markTestSkipped('소유자 바꾸기는 root 로 실행할 때만 재현됩니다.');
        }

        $directory = $this->storageRoot.'/app/public';
        chown($directory, 65534);
        chgrp($directory, 65534);

        (new WritePublicDiskHtaccess)->run($this->context());

        clearstatcache();
        $this->assertSame(65534, fileowner($directory.'/.htaccess'));
        $this->assertSame(65534, filegroup($directory.'/.htaccess'));
    }
}
