<?php

namespace Tests\Unit\Extension;

use App\Extension\ExtensionManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsZipFixtures;
use Tests\TestCase;

/**
 * 확장 GitHub 내려받기(`downloadAndExtractFromGitHub`)가 푼 결과에서 소스 루트를 고르는 규칙 회귀 테스트.
 *
 * 예전에는 해제 결과의 **첫 폴더**를 개수 확인 없이 소스로 골랐다. 감싸지 않은(평탄) ZIP 이면 manifest 가 있는
 * 루트 대신 첫 하위 폴더(`src/`)를, Mac 재압축 ZIP 이면 `__MACOSX/` 를 골랐다. 이제 `--zip` 경로와 같은
 * `resolveExtractedRoot()` 규칙(폴더 1개·파일 0개일 때만 감싼 폴더로 본다)을 쓴다.
 */
class ExtensionManagerGithubExtractRootTest extends TestCase
{
    use BuildsZipFixtures;

    private string $dest;

    /**
     * 임시 내려받기 폴더를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $this->dest = storage_path('framework/testing/ext-github-root-'.uniqid());
        File::ensureDirectoryExists($this->dest);
    }

    /**
     * 임시 폴더를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->dest);

        parent::tearDown();
    }

    /**
     * 내려받는 ZIP 모양과 기대하는 소스 루트(해제 폴더 기준 상대 경로, 빈 문자열이면 해제 폴더 자신).
     *
     * @return array<string, array{0: array<string, mixed>, 1: string}> 모양 옵션과 기대 루트
     */
    public static function archiveShapeProvider(): array
    {
        return [
            'GitHub zipball(감싼 폴더+폴더 항목)' => [['wrapper' => 'acme-acme-board-abc123', 'directoryEntries' => true], 'acme-acme-board-abc123'],
            '감싸지 않은 ZIP' => [['directoryEntries' => true], ''],
            'Mac 재압축(__MACOSX 부속 폴더)' => [['wrapper' => 'acme-acme-board-abc123', 'directoryEntries' => true, 'macosx' => true], 'acme-acme-board-abc123'],
        ];
    }

    /**
     * 내려받은 ZIP 모양에 상관없이 manifest 가 있는 폴더를 소스 루트로 돌려주는지 확인합니다.
     *
     * @scenario case=github_extract_root
     *
     * @effects github_extract_root_matches_resolve_extracted_root
     *
     * @param  array<string, mixed>  $shape  ZIP 모양 옵션
     * @param  string  $expectedRelative  해제 폴더 기준 기대 루트
     * @return void 반환값 없음
     */
    #[DataProvider('archiveShapeProvider')]
    public function test_소스_루트는_감싼_폴더_판정_규칙을_따른다(array $shape, string $expectedRelative): void
    {
        $zipPath = $this->dest.'/fixture.zip';
        $this->makeZipFixture($zipPath, [
            'module.json' => '{"identifier":"acme-board","version":"1.2.3"}',
            'src/Board.php' => '<?php',
        ], $shape);
        $bytes = (string) file_get_contents($zipPath);
        unlink($zipPath);

        Http::fake([
            'api.github.com/repos/acme/acme-board/git/refs/tags/*' => Http::response(['ref' => 'refs/tags/v1.2.3'], 200),
            'api.github.com/repos/acme/acme-board/zipball/*' => Http::response($bytes, 200, ['Content-Type' => 'application/zip']),
        ]);

        $source = app(ExtensionManager::class)->downloadAndExtractFromGitHub('acme', 'acme-board', '1.2.3', $this->dest);

        $expected = $this->dest.DIRECTORY_SEPARATOR.'extracted'.($expectedRelative === '' ? '' : DIRECTORY_SEPARATOR.$expectedRelative);
        $this->assertSame(realpath($expected), realpath($source), '소스 루트를 잘못 골랐다');
        $this->assertFileExists($source.DIRECTORY_SEPARATOR.'module.json');
    }
}
