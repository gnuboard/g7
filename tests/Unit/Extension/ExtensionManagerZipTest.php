<?php

namespace Tests\Unit\Extension;

use App\Extension\ExtensionManager;
use Illuminate\Support\Facades\File;
use Tests\Concerns\BuildsZipFixtures;
use Tests\TestCase;

/**
 * ExtensionManager 의 외부 ZIP 추출/manifest 검증 단위 테스트
 *
 * - extractFromZip(): 평탄 루트 / wrapper 디렉토리 구조 모두 지원
 * - prepareZipSource(): manifest 존재/해석/identifier/version 검증 경로
 */
class ExtensionManagerZipTest extends TestCase
{
    use BuildsZipFixtures;

    private ExtensionManager $extensionManager;

    /** @var array<string> 테스트 종료 시 정리할 임시 디렉토리 목록 */
    private array $tempDirs = [];

    /**
     * 확장 매니저를 컨테이너에서 꺼내 둡니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->extensionManager = app(ExtensionManager::class);
    }

    /**
     * 테스트가 만든 임시 디렉토리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            if (File::isDirectory($dir)) {
                File::deleteDirectory($dir);
            }
        }
        parent::tearDown();
    }

    /**
     * 루트 레벨에 manifest 가 있는 평탄 ZIP 을 추출하고 그 디렉토리를 반환하는지 검증합니다.
     *
     * @scenario case=extract_root
     *
     * @effects zip_update_extracts_flat_and_wrapped_root
     *
     * @return void 반환값 없음
     */
    public function test_extract_from_zip_returns_extract_dir_for_flat_layout(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $tempDir = $this->makeTempDir('flat');
        $zipPath = $tempDir.DIRECTORY_SEPARATOR.'flat.zip';

        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('module.json', '{"identifier":"sirsoft-example","version":"1.0.0"}');
        $zip->addFromString('composer.json', '{}');
        $zip->close();

        $destDir = $this->makeTempDir('flat_dest');
        $extractedDir = $this->extensionManager->extractFromZip($zipPath, $destDir);

        $this->assertSame($destDir.DIRECTORY_SEPARATOR.'extracted', $extractedDir);
        $this->assertTrue(File::exists($extractedDir.DIRECTORY_SEPARATOR.'module.json'));
    }

    /**
     * owner-repo-hash/ 래퍼 디렉토리가 1개만 있는 ZIP 은 그 내부를 source 로 반환하는지 검증합니다.
     *
     * @scenario case=extract_root
     *
     * @effects zip_update_extracts_flat_and_wrapped_root
     *
     * @return void 반환값 없음
     */
    public function test_extract_from_zip_unwraps_single_wrapper_directory(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $tempDir = $this->makeTempDir('wrap');
        $zipPath = $tempDir.DIRECTORY_SEPARATOR.'wrap.zip';

        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('sirsoft-example-abc123/module.json', '{"identifier":"sirsoft-example","version":"1.0.0"}');
        $zip->addFromString('sirsoft-example-abc123/README.md', '# example');
        $zip->close();

        $destDir = $this->makeTempDir('wrap_dest');
        $extractedDir = $this->extensionManager->extractFromZip($zipPath, $destDir);

        $expected = $destDir.DIRECTORY_SEPARATOR.'extracted'.DIRECTORY_SEPARATOR.'sirsoft-example-abc123';
        $this->assertSame(realpath($expected), realpath($extractedDir));
        $this->assertTrue(File::exists($extractedDir.DIRECTORY_SEPARATOR.'module.json'));
    }

    /**
     * ZIP 파일이 존재하지 않으면 RuntimeException 이 발생하는지 검증합니다.
     *
     * @scenario case=prepare_rejected
     *
     * @effects zip_update_rejects_missing_zip_or_invalid_manifest
     *
     * @return void 반환값 없음
     */
    public function test_extract_from_zip_throws_when_zip_missing(): void
    {
        $destDir = $this->makeTempDir('missing_dest');

        $this->expectException(\RuntimeException::class);
        $this->extensionManager->extractFromZip($destDir.DIRECTORY_SEPARATOR.'nonexistent.zip', $destDir);
    }

    /**
     * prepareZipSource 가 유효한 ZIP + manifest 조합에서 version/manifest 를 반환하는지 검증합니다.
     *
     * @scenario case=prepare_valid
     *
     * @effects zip_update_returns_version_and_manifest
     *
     * @return void 반환값 없음
     */
    public function test_prepare_zip_source_returns_version_and_manifest(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $tempDir = $this->makeTempDir('prep');
        $zipPath = $tempDir.DIRECTORY_SEPARATOR.'ext.zip';

        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('module.json', json_encode([
            'identifier' => 'sirsoft-example',
            'version' => '2.3.4',
            'name' => 'Example',
        ]));
        $zip->close();

        $result = $this->extensionManager->prepareZipSource($zipPath, 'sirsoft-example', 'module.json');

        $this->tempDirs[] = $result['temp_dir']; // 함수 내부에서 생성한 temp_dir 정리 등록

        $this->assertSame('2.3.4', $result['to_version']);
        $this->assertSame('sirsoft-example', $result['manifest']['identifier']);
        $this->assertTrue(File::isDirectory($result['temp_dir']));
        $this->assertTrue(File::exists($result['extracted_dir'].DIRECTORY_SEPARATOR.'module.json'));
    }

    /**
     * Mac 에서 다시 압축한 확장 ZIP(감싼 폴더 + 폴더 항목 + `__MACOSX/` 부속 폴더)도 manifest 를 찾는지 검증합니다.
     *
     * 부속 폴더가 함께 풀리면 최상위 폴더가 둘이 되어 감싼 폴더를 벗기지 못하고 `module:update --zip` 이
     * 「manifest 없음」으로 멈췄다(#971).
     *
     * @scenario case=zip_update_macosx
     *
     * @effects zip_update_finds_manifest_in_mac_zip
     *
     * @return void 반환값 없음
     */
    public function test_prepare_zip_source_finds_manifest_in_mac_shaped_zip(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $tempDir = $this->makeTempDir('mac');
        $zipPath = $tempDir.DIRECTORY_SEPARATOR.'ext-mac.zip';
        $this->makeZipFixture($zipPath, [
            'module.json' => json_encode(['identifier' => 'sirsoft-example', 'version' => '2.3.5', 'name' => 'Example']),
            'src/Example.php' => '<?php',
        ], ['wrapper' => 'sirsoft-example', 'directoryEntries' => true, 'macosx' => true]);

        $result = $this->extensionManager->prepareZipSource($zipPath, 'sirsoft-example', 'module.json');
        $this->tempDirs[] = $result['temp_dir'];

        $this->assertSame('2.3.5', $result['to_version']);
        $this->assertTrue(File::exists($result['extracted_dir'].DIRECTORY_SEPARATOR.'module.json'));
        $this->assertDirectoryDoesNotExist($result['temp_dir'].DIRECTORY_SEPARATOR.'extracted'.DIRECTORY_SEPARATOR.'__MACOSX', '부속 폴더가 함께 풀렸다');
    }

    /**
     * manifest 파일이 없는 경우 RuntimeException + 임시 디렉토리 자동 정리가 발생하는지 검증합니다.
     *
     * @scenario case=prepare_rejected
     *
     * @effects zip_update_rejects_missing_zip_or_invalid_manifest
     *
     * @return void 반환값 없음
     */
    public function test_prepare_zip_source_throws_when_manifest_missing(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $tempDir = $this->makeTempDir('no_manifest');
        $zipPath = $tempDir.DIRECTORY_SEPARATOR.'no_manifest.zip';

        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('README.md', '# no manifest');
        $zip->close();

        $this->expectException(\RuntimeException::class);
        $this->extensionManager->prepareZipSource($zipPath, 'sirsoft-example', 'module.json');
    }

    /**
     * identifier 가 일치하지 않으면 RuntimeException 이 발생하는지 검증합니다.
     *
     * @scenario case=prepare_rejected
     *
     * @effects zip_update_rejects_missing_zip_or_invalid_manifest
     *
     * @return void 반환값 없음
     */
    public function test_prepare_zip_source_throws_on_identifier_mismatch(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $tempDir = $this->makeTempDir('id_mismatch');
        $zipPath = $tempDir.DIRECTORY_SEPARATOR.'id_mismatch.zip';

        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('module.json', json_encode([
            'identifier' => 'sirsoft-other',
            'version' => '1.0.0',
        ]));
        $zip->close();

        $this->expectException(\RuntimeException::class);
        $this->extensionManager->prepareZipSource($zipPath, 'sirsoft-example', 'module.json');
    }

    /**
     * manifest 에 version 필드가 없으면 RuntimeException 이 발생하는지 검증합니다.
     *
     * @scenario case=prepare_rejected
     *
     * @effects zip_update_rejects_missing_zip_or_invalid_manifest
     *
     * @return void 반환값 없음
     */
    public function test_prepare_zip_source_throws_when_version_missing(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $tempDir = $this->makeTempDir('no_version');
        $zipPath = $tempDir.DIRECTORY_SEPARATOR.'no_version.zip';

        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('module.json', json_encode([
            'identifier' => 'sirsoft-example',
        ]));
        $zip->close();

        $this->expectException(\RuntimeException::class);
        $this->extensionManager->prepareZipSource($zipPath, 'sirsoft-example', 'module.json');
    }

    /**
     * 임시 디렉토리를 생성하고 tearDown 시 정리되도록 등록합니다.
     *
     * @param  string  $suffix  디렉토리 이름에 붙일 구분자
     * @return string 만든 디렉토리 절대 경로
     */
    private function makeTempDir(string $suffix): string
    {
        $dir = storage_path('test_ext_zip_'.$suffix.'_'.uniqid());
        File::ensureDirectoryExists($dir);
        $this->tempDirs[] = $dir;

        return $dir;
    }
}
