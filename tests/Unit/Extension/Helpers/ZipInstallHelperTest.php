<?php

namespace Tests\Unit\Extension\Helpers;

use App\Extension\Helpers\ZipInstallHelper;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ZipInstallHelperTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = storage_path('app/temp/test-zip-helper-'.uniqid());
        File::ensureDirectoryExists($this->tempDir);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->tempDir)) {
            File::deleteDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    // ──────────────────────────────────────────
    // extractZip
    // ──────────────────────────────────────────

    #[Test]
    public function extractZip_유효한_zip_파일을_추출합니다(): void
    {
        $zipPath = $this->createTestZip(['module.json' => '{"identifier":"test-module"}']);
        $extractPath = $this->tempDir.'/extracted';

        ZipInstallHelper::extractZip($zipPath, $extractPath);

        $this->assertTrue(File::exists($extractPath.'/module.json'));
    }

    #[Test]
    public function extractZip_존재하지_않는_파일이면_RuntimeException을_던집니다(): void
    {
        $this->expectException(\RuntimeException::class);

        ZipInstallHelper::extractZip('/nonexistent/path.zip', $this->tempDir.'/extracted');
    }

    #[Test]
    public function extractZip_손상된_zip이면_RuntimeException을_던집니다(): void
    {
        $fakePath = $this->tempDir.'/corrupted.zip';
        File::put($fakePath, 'this is not a valid zip file');

        $this->expectException(\RuntimeException::class);

        ZipInstallHelper::extractZip($fakePath, $this->tempDir.'/extracted');
    }

    // ──────────────────────────────────────────
    // findManifest
    // ──────────────────────────────────────────

    #[Test]
    public function findManifest_루트에_있는_manifest를_찾습니다(): void
    {
        File::put($this->tempDir.'/module.json', '{}');

        $result = ZipInstallHelper::findManifest($this->tempDir, 'module.json');

        $this->assertNotNull($result);
        $this->assertStringEndsWith('module.json', $result);
    }

    #[Test]
    public function findManifest_1단계_하위_디렉토리에서_manifest를_찾습니다(): void
    {
        $subDir = $this->tempDir.'/owner-repo-abc123';
        File::ensureDirectoryExists($subDir);
        File::put($subDir.'/module.json', '{}');

        $result = ZipInstallHelper::findManifest($this->tempDir, 'module.json');

        $this->assertNotNull($result);
        $this->assertStringContainsString('owner-repo-abc123', $result);
    }

    #[Test]
    public function findManifest_manifest가_없으면_null을_반환합니다(): void
    {
        $result = ZipInstallHelper::findManifest($this->tempDir, 'module.json');

        $this->assertNull($result);
    }

    #[Test]
    public function findManifest_2단계_이상_하위에서는_찾지_않습니다(): void
    {
        $deepDir = $this->tempDir.'/level1/level2';
        File::ensureDirectoryExists($deepDir);
        File::put($deepDir.'/module.json', '{}');

        $result = ZipInstallHelper::findManifest($this->tempDir, 'module.json');

        $this->assertNull($result);
    }

    // ──────────────────────────────────────────
    // findAndValidateManifest
    // ──────────────────────────────────────────

    #[Test]
    public function findAndValidateManifest_유효한_모듈_manifest를_검증합니다(): void
    {
        File::put($this->tempDir.'/module.json', json_encode([
            'identifier' => 'test-module',
            'name' => 'Test Module',
        ]));

        $result = ZipInstallHelper::findAndValidateManifest($this->tempDir, 'module.json', 'modules');

        $this->assertSame('test-module', $result['identifier']);
        $this->assertArrayHasKey('config', $result);
        $this->assertArrayHasKey('sourcePath', $result);
        $this->assertSame('Test Module', $result['config']['name']);
    }

    #[Test]
    public function findAndValidateManifest_유효한_플러그인_manifest를_검증합니다(): void
    {
        File::put($this->tempDir.'/plugin.json', json_encode([
            'identifier' => 'test-plugin',
            'name' => 'Test Plugin',
        ]));

        $result = ZipInstallHelper::findAndValidateManifest($this->tempDir, 'plugin.json', 'plugins');

        $this->assertSame('test-plugin', $result['identifier']);
    }

    #[Test]
    public function findAndValidateManifest_유효한_템플릿_manifest를_검증합니다(): void
    {
        File::put($this->tempDir.'/template.json', json_encode([
            'identifier' => 'test-template',
            'name' => 'Test Template',
        ]));

        $result = ZipInstallHelper::findAndValidateManifest($this->tempDir, 'template.json', 'templates');

        $this->assertSame('test-template', $result['identifier']);
    }

    #[Test]
    public function findAndValidateManifest_manifest가_없으면_RuntimeException을_던집니다(): void
    {
        $this->expectException(\RuntimeException::class);

        ZipInstallHelper::findAndValidateManifest($this->tempDir, 'module.json', 'modules');
    }

    #[Test]
    public function findAndValidateManifest_identifier가_누락되면_RuntimeException을_던집니다(): void
    {
        File::put($this->tempDir.'/module.json', json_encode([
            'name' => 'No Identifier Module',
        ]));

        $this->expectException(\RuntimeException::class);

        ZipInstallHelper::findAndValidateManifest($this->tempDir, 'module.json', 'modules');
    }

    #[Test]
    public function findAndValidateManifest_잘못된_json이면_RuntimeException을_던집니다(): void
    {
        File::put($this->tempDir.'/module.json', 'this is not valid json {{{');

        $this->expectException(\RuntimeException::class);

        ZipInstallHelper::findAndValidateManifest($this->tempDir, 'module.json', 'modules');
    }

    #[Test]
    public function findAndValidateManifest_github_아카이브_구조를_처리합니다(): void
    {
        // GitHub ZIP은 owner-repo-hash/ 형태로 추출됨
        $subDir = $this->tempDir.'/owner-repo-abc123';
        File::ensureDirectoryExists($subDir);
        File::put($subDir.'/module.json', json_encode([
            'identifier' => 'github-module',
        ]));

        $result = ZipInstallHelper::findAndValidateManifest($this->tempDir, 'module.json', 'modules');

        $this->assertSame('github-module', $result['identifier']);
        $this->assertSame(realpath($subDir), realpath($result['sourcePath']));
    }

    // ──────────────────────────────────────────
    // extractAndValidate
    // ──────────────────────────────────────────

    #[Test]
    public function extractAndValidate_zip_추출_후_manifest를_검증합니다(): void
    {
        $zipPath = $this->createTestZip(['module.json' => json_encode([
            'identifier' => 'zip-module',
            'name' => 'ZIP Module',
        ])]);
        $extractPath = $this->tempDir.'/extract';

        $result = ZipInstallHelper::extractAndValidate($zipPath, $extractPath, 'module.json', 'modules');

        $this->assertSame('zip-module', $result['identifier']);
        $this->assertSame('ZIP Module', $result['config']['name']);
    }

    // ──────────────────────────────────────────
    // moveToPending
    // ──────────────────────────────────────────

    #[Test]
    public function moveToPending_소스를_pending_디렉토리로_이동합니다(): void
    {
        // 소스 준비
        $sourcePath = $this->tempDir.'/source';
        File::ensureDirectoryExists($sourcePath);
        File::put($sourcePath.'/module.json', '{"identifier":"test"}');

        $pendingBase = $this->tempDir.'/pending';

        $result = ZipInstallHelper::moveToPending($sourcePath, $pendingBase, 'test-module');

        $this->assertSame($pendingBase.'/test-module', $result);
        $this->assertTrue(File::exists($result.'/module.json'));
        $this->assertFalse(File::isDirectory($sourcePath));
    }

    #[Test]
    public function moveToPending_이미_존재하는_대상을_덮어씁니다(): void
    {
        // 소스 준비
        $sourcePath = $this->tempDir.'/source';
        File::ensureDirectoryExists($sourcePath);
        File::put($sourcePath.'/new_file.txt', 'new content');

        // 기존 pending 대상 준비
        $pendingBase = $this->tempDir.'/pending';
        $existingTarget = $pendingBase.'/test-module';
        File::ensureDirectoryExists($existingTarget);
        File::put($existingTarget.'/old_file.txt', 'old content');

        $result = ZipInstallHelper::moveToPending($sourcePath, $pendingBase, 'test-module');

        $this->assertTrue(File::exists($result.'/new_file.txt'));
        $this->assertFalse(File::exists($result.'/old_file.txt'));
    }

    // ──────────────────────────────────────────
    // 헬퍼 메서드
    // ──────────────────────────────────────────

    /**
     * 테스트용 ZIP 파일을 생성합니다.
     *
     * @param array<string, string> $files 파일명 => 내용 매핑
     * @return string 생성된 ZIP 파일 경로
     */
    private function createTestZip(array $files): string
    {
        $zipPath = $this->tempDir.'/test.zip';
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE);

        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }

        $zip->close();

        return $zipPath;
    }
}
