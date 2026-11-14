<?php

namespace Tests\Unit\Support;

use App\Support\PublicDiskHardening;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * 공개 디스크 직접 주소(`/storage`)의 웹서버 보호 규칙 파일.
 *
 * 직접 주소 응답은 서버(PHP)를 거치지 않으므로 응답 헤더를 웹서버 규칙으로 건다. 규칙 파일은 자동으로
 * 만들고, 「공개 디스크로 내보낼 확장자」 설정이 바뀌면 다시 만든다.
 *
 * @scenario-manifest tests/scenarios/public-disk-exportable-extensions.yaml
 */
class PublicDiskHardeningTest extends TestCase
{
    private string $directory;

    /**
     * 임시 공개 디스크 디렉터리를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/public-disk-hardening-'.uniqid());
        mkdir($this->directory, 0777, true);

        Config::set('core.storage.public_asset_extensions', ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif']);
    }

    /**
     * 임시 디렉터리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/{,.}[!.]*', GLOB_BRACE) ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);

        parent::tearDown();
    }

    /**
     * 규칙에는 nosniff, 비이미지 내려받기 강제·sandbox, 모듈 부재 시 차단, PHP 실행 차단이 모두 들어 있다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=webserver_rules__not_exportable
     *
     * @effects webserver_rules_cover_nosniff_attachment_sandbox_and_php_block
     */
    public function test_rules_contain_every_required_directive(): void
    {
        $rules = PublicDiskHardening::htaccess();

        $this->assertStringStartsWith(PublicDiskHardening::MARKER, $rules);
        $this->assertStringContainsString('<IfModule mod_headers.c>', $rules);
        $this->assertStringContainsString('Header always set X-Content-Type-Options "nosniff"', $rules);
        $this->assertStringContainsString('Header always set Content-Disposition "attachment"', $rules);
        $this->assertStringContainsString('sandbox', $rules);
        $this->assertStringContainsString('<IfModule !mod_headers.c>', $rules);
        // 사이트가 이미 쓰는 허용 범위(FileInfo)만 요구한다 — 인증 계열·PHP 설정 지시어는 그 범위를 열지 않은
        // 서버에서 이 디렉터리의 모든 요청(이미지 포함)을 500 으로 만든다.
        $this->assertStringNotContainsString('Require ', $rules);
        $this->assertStringNotContainsString('php_flag', $rules);
        $this->assertStringContainsString('<IfModule mod_rewrite.c>', $rules);
        $this->assertStringContainsString('RewriteEngine On', $rules);
        $this->assertStringContainsString('RewriteRule "(?i)\.(php\d?|phtml|phar|pht)$" - [F]', $rules);
        $this->assertStringContainsString('RewriteRule "(?i)^(?!.*\.(jpg|jpeg|png|gif|webp|avif)$).+$" - [F]', $rules);
        $this->assertStringNotContainsString("\r", $rules);
    }

    /**
     * 인라인으로 두는 형식은 설정 목록 중 래스터 이미지뿐이다 — 운영자가 넣은 비래스터는 내려받기 쪽에 남는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=webserver_rules__operator_added
     *
     * @effects webserver_rules_follow_exportable_setting
     */
    public function test_inline_list_is_the_raster_subset_of_the_setting(): void
    {
        Config::set('core.storage.public_asset_extensions', ['png', 'pdf', 'html', 'svg', 'webp']);

        $this->assertSame(['png', 'webp'], PublicDiskHardening::inlineExtensions());

        $rules = PublicDiskHardening::htaccess();
        $this->assertStringContainsString('(?!.*\.(png|webp)$)', $rules);
        $this->assertStringNotContainsString('pdf', $rules);
        $this->assertStringNotContainsString('svg', $rules);
    }

    /**
     * 래스터가 하나도 없으면 모든 파일이 내려받기 대상이다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=webserver_rules__operator_added
     *
     * @effects webserver_rules_follow_exportable_setting
     */
    public function test_no_raster_in_setting_makes_every_file_a_download(): void
    {
        Config::set('core.storage.public_asset_extensions', ['pdf']);

        $rules = PublicDiskHardening::htaccess();

        $this->assertStringContainsString('<FilesMatch ".+">', $rules);
        $this->assertStringNotContainsString('(?!', $rules);
    }

    /**
     * 파일이 없으면 만들고, 내용이 같으면 그대로 두며, 설정이 바뀌면 다시 쓴다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=webserver_rules__exportable
     *
     * @effects webserver_rules_written_and_refreshed_idempotently
     */
    public function test_ensure_writes_once_and_rewrites_when_the_setting_changes(): void
    {
        $path = $this->directory.'/.htaccess';

        $this->assertTrue(PublicDiskHardening::ensureHtaccess($this->directory));
        $this->assertSame(PublicDiskHardening::htaccess(), file_get_contents($path));

        $mtime = filemtime($path);
        clearstatcache();
        touch($path, $mtime - 100);
        clearstatcache();
        $this->assertTrue(PublicDiskHardening::ensureHtaccess($this->directory));
        clearstatcache();
        $this->assertSame($mtime - 100, filemtime($path), '내용이 같으면 다시 쓰지 않는다');

        Config::set('core.storage.public_asset_extensions', ['png']);
        $this->assertTrue(PublicDiskHardening::ensureHtaccess($this->directory));
        $this->assertStringContainsString('(?!.*\.(png)$)', (string) file_get_contents($path));
    }

    /**
     * 표지가 없는 기존 파일은 사본을 남기고 덮어쓴다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=webserver_rules__exportable
     *
     * @effects webserver_rules_written_and_refreshed_idempotently
     */
    public function test_ensure_overwrites_an_unmarked_file_and_keeps_a_copy(): void
    {
        $path = $this->directory.'/.htaccess';
        file_put_contents($path, "Options -Indexes\n");

        $this->assertTrue(PublicDiskHardening::ensureHtaccess($this->directory));

        $this->assertStringStartsWith(PublicDiskHardening::MARKER, (string) file_get_contents($path));
        $this->assertSame("Options -Indexes\n", file_get_contents($path.'.g7-backup'));
    }

    /**
     * 규칙 파일을 웹 계정이 고쳐 쓸 수 없어도(root 로 실행된 업데이트가 만든 파일) 디렉터리에 쓸 수 있으면 새 내용으로 바꾼다.
     *
     * 파일 권한만 막히고 디렉터리 쓰기는 되는 상태를 0444 로 재현한다(root 는 권한 검사를 건너뛰므로 제외).
     *
     * @return void 반환값 없음
     *
     * @scenario case=webserver_rules__operator_added
     *
     * @effects webserver_rules_replaced_when_file_not_writable
     */
    public function test_ensure_replaces_a_rules_file_it_cannot_write_in_place(): void
    {
        $this->skipUnlessPosixNonRoot();

        $path = $this->directory.'/.htaccess';
        $this->assertTrue(PublicDiskHardening::ensureHtaccess($this->directory));
        chmod($path, 0444);

        Config::set('core.storage.public_asset_extensions', ['png']);
        $this->assertTrue(PublicDiskHardening::ensureHtaccess($this->directory));

        clearstatcache();
        $this->assertStringContainsString('(?!.*\.(png)$)', (string) file_get_contents($path));
        $this->assertSame(0444, fileperms($path) & 0777, '바꾼 파일은 원래 권한을 이어받는다');
        $this->assertSame([], glob($this->directory.'/.htaccess.tmp-*'), '임시 파일이 남지 않는다');
    }

    /**
     * 디렉터리에도 쓸 수 없으면 false 를 돌려준다(호출부가 경고한다).
     *
     * @return void 반환값 없음
     *
     * @scenario case=webserver_rules__operator_added
     *
     * @effects webserver_rules_replaced_when_file_not_writable
     */
    public function test_ensure_reports_failure_when_the_directory_is_not_writable(): void
    {
        $this->skipUnlessPosixNonRoot();

        $path = $this->directory.'/.htaccess';
        $this->assertTrue(PublicDiskHardening::ensureHtaccess($this->directory));
        chmod($path, 0444);
        chmod($this->directory, 0555);

        try {
            Config::set('core.storage.public_asset_extensions', ['png']);
            $this->assertFalse(PublicDiskHardening::ensureHtaccess($this->directory));
            $this->assertStringNotContainsString('(?!.*\.(png)$)', (string) file_get_contents($path));
        } finally {
            chmod($this->directory, 0777);
            chmod($path, 0644);
        }
    }

    /**
     * root 로 실행되면(sudo 코어 업데이트) 새로 쓴 규칙 파일을 공개 디스크 디렉터리의 소유자·그룹으로 맞춘다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=webserver_rules__exportable
     *
     * @effects webserver_rules_owned_by_directory_owner
     */
    public function test_rules_written_as_root_take_the_directory_owner(): void
    {
        $this->skipUnlessRoot();

        chown($this->directory, 65534);
        chgrp($this->directory, 65534);

        $this->assertTrue(PublicDiskHardening::ensureHtaccess($this->directory));

        clearstatcache();
        $this->assertSame(65534, fileowner($this->directory.'/.htaccess'));
        $this->assertSame(65534, filegroup($this->directory.'/.htaccess'));
    }

    /**
     * 내용이 이미 같아도 root 소유로 남은 규칙 파일(이전 업데이트가 남김)은 디렉터리 소유자로 바로잡는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=webserver_rules__exportable
     *
     * @effects webserver_rules_owned_by_directory_owner
     */
    public function test_root_owned_rules_with_current_content_get_the_directory_owner(): void
    {
        $this->skipUnlessRoot();

        $path = $this->directory.'/.htaccess';
        file_put_contents($path, PublicDiskHardening::htaccess());
        chown($this->directory, 65534);
        chgrp($this->directory, 65534);
        clearstatcache();
        $this->assertSame(0, fileowner($path));

        $this->assertTrue(PublicDiskHardening::ensureHtaccess($this->directory));

        clearstatcache();
        $this->assertSame(65534, fileowner($path));
        $this->assertSame(65534, filegroup($path));
    }

    /**
     * POSIX 권한 검사가 걸리는 환경(Windows 아님, root 아님)이 아니면 건너뜁니다.
     *
     * @return void 반환값 없음
     */
    private function skipUnlessPosixNonRoot(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || ! function_exists('posix_geteuid') || posix_geteuid() === 0) {
            $this->markTestSkipped('POSIX 파일 권한 검사가 걸리는 일반 계정에서만 재현됩니다.');
        }
    }

    /**
     * root 로 실행 중이 아니면 건너뜁니다(소유자 바꾸기는 root 만 할 수 있다).
     *
     * @return void 반환값 없음
     */
    private function skipUnlessRoot(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || ! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            $this->markTestSkipped('소유자 바꾸기는 root 로 실행할 때만 재현됩니다.');
        }
    }

    /**
     * 공개 디스크 디렉터리가 없으면 아무것도 만들지 않고 false 를 돌려준다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=webserver_rules__exportable
     *
     * @effects webserver_rules_written_and_refreshed_idempotently
     */
    public function test_ensure_skips_when_the_directory_is_missing(): void
    {
        $missing = $this->directory.'/nope';

        $this->assertFalse(PublicDiskHardening::ensureHtaccess($missing));
        $this->assertDirectoryDoesNotExist($missing);
    }
}
