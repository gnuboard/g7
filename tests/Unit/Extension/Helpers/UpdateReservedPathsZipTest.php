<?php

namespace Tests\Unit\Extension\Helpers;

use App\Extension\Helpers\UpdateReservedPaths;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsZipFixtures;
use Tests\TestCase;

/**
 * zip 배포본의 예약 자리 파일 판정(`UpdateReservedPaths::findInZip`)이 감싼 폴더가 있을 때만 첫 구간을 벗기는지 (#971).
 *
 * 예전에는 모든 항목의 첫 구간을 무조건 벗긴 경로도 함께 봐서, 감싸지 않은 zip 의 `X/custom/a.php` 를 최상위
 * 예약 자리 `custom/` 파일로 오탐할 수 있었다. 이제 모든 항목이 같은 최상위 폴더 하나를 공유할 때만 벗긴다.
 */
class UpdateReservedPathsZipTest extends TestCase
{
    use BuildsZipFixtures;

    private string $dir;

    /**
     * 임시 폴더를 만들고 예약 경로 목록을 기본값으로 고정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        config(['app.update.reserved_paths' => ['routes/custom.php', 'app/Custom', 'custom']]);
        $this->dir = storage_path('framework/testing/reserved-zip-'.uniqid());
        File::ensureDirectoryExists($this->dir);
    }

    /**
     * 임시 폴더를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    /**
     * zip 모양별 파일 목록과 기대 판정.
     *
     * @return array<string, array{0: array<string, string>, 1: array<string, mixed>, 2: array<int, string>}> 파일, 모양 옵션, 기대 목록
     */
    public static function shapeProvider(): array
    {
        $files = ['composer.json' => '{}', 'routes/custom.php' => '<?php', 'app/Http/Kernel.php' => '<?php'];

        return [
            '감싼 zip' => [$files, ['wrapper' => 'g7-7.2.0'], ['routes/custom.php']],
            '감싼 zip + 폴더 항목 + __MACOSX' => [$files, ['wrapper' => 'g7-7.2.0', 'directoryEntries' => true, 'macosx' => true], ['routes/custom.php']],
            '감싼 zip 역슬래시' => [$files, ['wrapper' => 'g7-7.2.0', 'directoryEntries' => true, 'backslash' => true], ['routes/custom.php']],
            '평탄 zip' => [$files, [], ['routes/custom.php']],
            '평탄 zip 의 하위 custom 폴더는 예약 자리가 아니다' => [['composer.json' => '{}', 'resources/custom/a.php' => '<?php', 'modules/custom/b.php' => '<?php'], ['directoryEntries' => true], []],
        ];
    }

    /**
     * zip 모양에 상관없이 배포본 루트 기준 예약 자리 파일만 찾는지 확인합니다.
     *
     * @scenario case=reserved_zip_shape
     *
     * @effects reserved_zip_scan_strips_only_shared_wrapper
     *
     * @param  array<string, string>  $files  배포본 루트 기준 파일
     * @param  array<string, mixed>  $shape  zip 모양 옵션
     * @param  array<int, string>  $expected  기대 예약 자리 파일
     * @return void 반환값 없음
     */
    #[DataProvider('shapeProvider')]
    public function test_예약_자리_판정은_공유된_감싼_폴더만_벗긴다(array $files, array $shape, array $expected): void
    {
        $zipPath = $this->dir.'/release.zip';
        $this->makeZipFixture($zipPath, $files, $shape);

        $this->assertSame($expected, UpdateReservedPaths::findInZip($zipPath));
    }
}
