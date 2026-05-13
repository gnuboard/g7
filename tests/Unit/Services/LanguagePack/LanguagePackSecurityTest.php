<?php

namespace Tests\Unit\Services\LanguagePack;

use App\Models\User;
use App\Services\LanguagePackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

/**
 * 언어팩 설치 보안 검증 테스트.
 *
 * 계획서 §16.12 — ZIP slip / PHP 외부 위치 / eval 패턴 차단을 검증합니다.
 */
class LanguagePackSecurityTest extends TestCase
{
    use RefreshDatabase;

    private LanguagePackService $service;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(LanguagePackService::class);
        $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('lp_security_', true);
        File::ensureDirectoryExists($this->tempDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempDir);
        // _pending 루트는 보존하고 (Git 추적 .gitignore/.gitkeep 유지) 서브디렉토리만 정리
        $pendingRoot = base_path('lang-packs/_pending');
        if (File::isDirectory($pendingRoot)) {
            foreach (File::directories($pendingRoot) as $sub) {
                File::deleteDirectory($sub);
            }
        }
        parent::tearDown();
    }

    /**
     * 주어진 콘텐츠로 ZIP 파일을 만듭니다.
     *
     * @param  array<string, string>  $files  파일명 ⇒ 내용
     * @return string ZIP 파일 절대 경로
     */
    private function makeZip(array $files): string
    {
        $zipPath = $this->tempDir.DIRECTORY_SEPARATOR.uniqid('pack_', true).'.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $zipPath;
    }

    /**
     * 유효한 manifest JSON 문자열을 반환합니다.
     *
     * @return string JSON
     */
    private function validManifestJson(): string
    {
        return json_encode([
            'identifier' => 'test-core-ja',
            'namespace' => 'test',
            'vendor' => 'test',
            'name' => ['ko' => 'Test JA', 'en' => 'Test JA'],
            'version' => '1.0.0',
            'scope' => 'core',
            'target_identifier' => null,
            'locale' => 'ja',
            'locale_native_name' => '日本語',
            'g7_version' => '>=7.0.0-beta.4',
        ]);
    }

    public function test_php_outside_backend_directory_rejected(): void
    {
        $zip = $this->makeZip([
            'language-pack.json' => $this->validManifestJson(),
            'evil.php' => "<?php echo 'hello';",
        ]);

        $file = new UploadedFile($zip, 'pack.zip', 'application/zip', null, true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/backend/i');

        $this->service->installFromFile($file, false, null);
    }

    public function test_unsafe_php_pattern_in_backend_rejected(): void
    {
        $zip = $this->makeZip([
            'language-pack.json' => $this->validManifestJson(),
            'backend/messages.php' => "<?php\neval(\$_GET['c']);\nreturn [];\n",
        ]);

        $file = new UploadedFile($zip, 'pack.zip', 'application/zip', null, true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/허용되지 않/u');

        $this->service->installFromFile($file, false, null);
    }

    public function test_safe_backend_php_accepted(): void
    {
        $zip = $this->makeZip([
            'language-pack.json' => $this->validManifestJson(),
            'backend/messages.php' => "<?php\nreturn ['hello' => 'こんにちは'];\n",
        ]);

        $file = new UploadedFile($zip, 'pack.zip', 'application/zip', null, true);

        $pack = $this->service->installFromFile($file, false, null);

        $this->assertSame('test-core-ja', $pack->identifier);

        // 활성 디렉토리 정리
        File::deleteDirectory(base_path('lang-packs/test-core-ja'));
    }
}
