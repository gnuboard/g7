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
