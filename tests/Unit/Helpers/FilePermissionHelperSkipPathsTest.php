<?php

namespace Tests\Unit\Helpers;

use App\Extension\Helpers\FilePermissionHelper;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * FilePermissionHelper::copyDirectory() 의 skipPaths 단위 테스트 (공개 #146)
 *
 * 코어 업데이트가 배치하지 않기로 한 번들 경로를 복사·orphan 정리 양쪽에서 건너뛰는지 검증합니다.
 */
class FilePermissionHelperSkipPathsTest extends TestCase
{
    /**
     * 테스트에서 만든 임시 디렉토리 목록 (tearDown 에서 정리)
     *
     * @var array<int, string>
     */
    private array $tempDirs = [];

    /**
     * 테스트가 만든 임시 디렉토리를 정리합니다.
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
     * skipPaths 에 든 디렉토리는 통째로 복사되지 않고, 접두사만 같은 형제는 복사됩니다.
     *
     * @effects copy_directory_skips_listed_paths
     *
     * @return void 반환값 없음
     */
    public function test_copy_directory_skip_paths_skip_whole_directory(): void
    {
        $source = $this->createTempDir();
        $dest = $this->createTempDir();

        $this->writeFile($source, 'sirsoft-gdpr/plugin.json', '{}');
        $this->writeFile($source, 'sirsoft-gdpr/src/P.php', '<?php');
        $this->writeFile($source, 'sirsoft-gdpr-ext/plugin.json', '{}');

        FilePermissionHelper::copyDirectory($source, $dest, skipPaths: ['sirsoft-gdpr']);

        $this->assertDirectoryDoesNotExist($dest.DIRECTORY_SEPARATOR.'sirsoft-gdpr');
        $this->assertFileExists($dest.DIRECTORY_SEPARATOR.'sirsoft-gdpr-ext'.DIRECTORY_SEPARATOR.'plugin.json');
    }

    /**
     * skipPaths 는 재귀에 그대로 전달되어 중첩 경로(루트 기준 상대경로)도 건너뛰고,
     * 다른 깊이의 같은 이름은 건너뛰지 않습니다.
     *
     * @effects copy_directory_skips_listed_paths
     *
     * @return void 반환값 없음
     */
    public function test_copy_directory_skip_paths_propagate_to_recursion(): void
    {
        $source = $this->createTempDir();
        $dest = $this->createTempDir();

        $this->writeFile($source, 'a/b/skip.txt', 'x');
        $this->writeFile($source, 'a/keep.txt', 'y');
        $this->writeFile($source, 'b/keep2.txt', 'z');

        FilePermissionHelper::copyDirectory($source, $dest, skipPaths: ['a/b']);

        $this->assertDirectoryDoesNotExist($dest.DIRECTORY_SEPARATOR.'a'.DIRECTORY_SEPARATOR.'b');
        $this->assertFileExists($dest.DIRECTORY_SEPARATOR.'a'.DIRECTORY_SEPARATOR.'keep.txt');
        $this->assertFileExists($dest.DIRECTORY_SEPARATOR.'b'.DIRECTORY_SEPARATOR.'keep2.txt');
    }

    /**
     * removeOrphans 와 함께 써도 대상에 이미 있는 skipPaths 항목은 지우지도 덮어쓰지도 않습니다.
     *
     * @effects copy_directory_skips_listed_paths
     *
     * @return void 반환값 없음
     */
    public function test_copy_directory_skip_paths_are_not_orphan_removed(): void
    {
        $source = $this->createTempDir();
        $dest = $this->createTempDir();

        $this->writeFile($source, 'ext/new.json', 'new');
        $this->writeFile($dest, 'ext/mine.json', 'mine');

        FilePermissionHelper::copyDirectory($source, $dest, removeOrphans: true, skipPaths: ['ext']);

        $this->assertFileExists($dest.DIRECTORY_SEPARATOR.'ext'.DIRECTORY_SEPARATOR.'mine.json');
        $this->assertFileDoesNotExist($dest.DIRECTORY_SEPARATOR.'ext'.DIRECTORY_SEPARATOR.'new.json');
    }

    /**
     * skipPaths 기본값([])이면 기존 동작(전체 복사)이 그대로 유지됩니다 (하위호환).
     *
     * @effects copy_directory_default_unchanged
     *
     * @return void 반환값 없음
     */
    public function test_copy_directory_skip_paths_default_copies_everything(): void
    {
        $source = $this->createTempDir();
        $dest = $this->createTempDir();

        $this->writeFile($source, 'sirsoft-gdpr/plugin.json', '{}');

        FilePermissionHelper::copyDirectory($source, $dest);

        $this->assertFileExists($dest.DIRECTORY_SEPARATOR.'sirsoft-gdpr'.DIRECTORY_SEPARATOR.'plugin.json');
    }

    /**
     * 테스트용 임시 디렉토리를 만듭니다.
     *
     * @return string 만든 디렉토리 경로
     */
    private function createTempDir(): string
    {
        $dir = storage_path('test_fileperm_skip_'.uniqid());
        File::ensureDirectoryExists($dir);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    /**
     * 루트 아래 상대경로에 파일을 씁니다.
     *
     * @param  string  $root  루트 디렉토리
     * @param  string  $relative  슬래시 구분 상대경로
     * @param  string  $content  파일 내용
     * @return void 반환값 없음
     */
    private function writeFile(string $root, string $relative, string $content): void
    {
        $file = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        File::ensureDirectoryExists(dirname($file));
        File::put($file, $content);
    }
}
