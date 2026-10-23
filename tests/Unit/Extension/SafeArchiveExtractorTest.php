<?php

namespace Tests\Unit\Extension;

use App\Extension\Exceptions\UnsafeArchiveException;
use App\Extension\Helpers\SafeArchiveExtractor;
use App\Extension\Helpers\ZipInstallHelper;
use App\Extension\Support\LifecycleWarnings;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 안전한 압축 해제(SafeArchiveExtractor) 회귀 시험.
 *
 * - Windows PowerShell 5.1 이 만든 역슬래시 항목은 `/` 로 정규화해 폴더로 푼다.
 * - `..`·절대경로·드라이브 문자·UNC·널 문자·심볼릭 링크 항목이 하나라도 있으면 아무것도 풀지 않는다.
 * - 확장 zip 설치 경로(ZipInstallHelper)·설치 마법사 함수도 같은 규칙을 따른다.
 *
 * @scenario-manifest tests/scenarios/safe-archive-extraction.yaml
 */
class SafeArchiveExtractorTest extends TestCase
{
    private string $tempDir;

    /**
     * 임시 디렉터리를 만들고 경고 수집기를 비운다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = storage_path('app/temp/test-safe-archive-'.uniqid());
        File::ensureDirectoryExists($this->tempDir);
        LifecycleWarnings::current()->clear();
    }

    /**
     * 임시 디렉터리를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        if (File::isDirectory($this->tempDir)) {
            File::deleteDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    /**
     * 역슬래시 항목을 / 경로의 폴더로 풀고 정규화 경고를 남긴다.
     *
     * @scenario entry=backslash_path, caller=safe_extractor
     *
     * @effects backslash_entries_extracted_as_directories, backslash_normalization_reported_as_warning
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 역슬래시_항목을_슬래시_경로로_풀고_경고를_남긴다(): void
    {
        $zipPath = $this->makeZip(['pkg\\src\\a.php' => '<?php // a', 'pkg/module.json' => '{}']);
        $dest = $this->tempDir.'/out';

        $written = SafeArchiveExtractor::extractZip($zipPath, $dest);

        $this->assertContains('pkg/src/a.php', $written);
        $this->assertFileExists($dest.'/pkg/src/a.php');
        $this->assertSame('<?php // a', File::get($dest.'/pkg/src/a.php'));
        $this->assertSame([], $this->namesWithBackslash($dest), '디스크 파일명에 역슬래시가 남으면 안 된다');

        $warnings = LifecycleWarnings::current()->all();
        $this->assertCount(1, $warnings);
        $this->assertSame('extract', $warnings[0]['step']);
    }

    /**
     * 압축 밖을 가리키는 항목 이름 벡터.
     *
     * @return array<string, array{0: string}> 항목 이름 목록
     */
    public static function unsafeEntryProvider(): array
    {
        return [
            '상위 경로' => ['../x.txt'],
            '중간 상위 경로' => ['a/../../x.txt'],
            '역슬래시 상위 경로' => ['a\\..\\..\\x.txt'],
            '절대 경로' => ['/abs/x.txt'],
            '드라이브 문자' => ['C:\\x.txt'],
            'UNC' => ['\\\\server\\share\\x.txt'],
        ];
    }

    /**
     * 압축 밖 경로 항목이 하나라도 있으면 안전한 항목까지 아무것도 풀지 않는다.
     *
     * @scenario entry=outside_path, caller=safe_extractor
     *
     * @effects unsafe_entry_rejects_whole_archive
     *
     * @param  string  $entry  거부돼야 할 항목 이름
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('unsafeEntryProvider')]
    public function 압축_밖_경로_항목이_있으면_아무것도_풀지_않는다(string $entry): void
    {
        $zipPath = $this->makeZip(['safe.txt' => 'ok', $entry => 'evil']);
        $dest = $this->tempDir.'/out';

        try {
            SafeArchiveExtractor::extractZip($zipPath, $dest);
            $this->fail('UnsafeArchiveException 이 나야 한다: '.$entry);
        } catch (UnsafeArchiveException $e) {
            $this->assertSame($entry, $e->entry);
        }

        $this->assertFileDoesNotExist($dest.'/safe.txt', '거부되면 안전한 항목도 풀지 않는다');
        $this->assertFileDoesNotExist($this->tempDir.'/x.txt');
    }

    /**
     * 유닉스 심볼릭 링크 항목은 거부한다.
     *
     * @scenario entry=symlink, caller=safe_extractor
     *
     * @effects unsafe_entry_rejects_whole_archive
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 심볼릭_링크_항목은_거부한다(): void
    {
        $zipPath = $this->tempDir.'/link.zip';
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('safe.txt', 'ok');
        $zip->addFromString('link', '/etc/passwd');
        $zip->setExternalAttributesName('link', \ZipArchive::OPSYS_UNIX, (0120777) << 16);
        $zip->close();

        $this->expectException(UnsafeArchiveException::class);

        SafeArchiveExtractor::extractZip($zipPath, $this->tempDir.'/out');
    }

    /**
     * 정상 zip 은 디렉터리 항목·빈 파일·./ 접두까지 그대로 풀고 경고를 남기지 않는다.
     *
     * @scenario entry=normal_zip, caller=safe_extractor
     *
     * @effects normal_archive_extracted_unchanged
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 정상_zip_은_디렉터리_항목과_빈_파일까지_그대로_푼다(): void
    {
        $zipPath = $this->tempDir.'/normal.zip';
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addEmptyDir('pkg/empty');
        $zip->addFromString('pkg/a.txt', 'A');
        $zip->addFromString('pkg/sub/b.txt', '');
        $zip->addFromString('./pkg/c.txt', 'C');
        $zip->close();

        $written = SafeArchiveExtractor::extractZip($zipPath, $this->tempDir.'/out');

        sort($written);
        $this->assertSame(['pkg/a.txt', 'pkg/c.txt', 'pkg/sub/b.txt'], $written);
        $this->assertDirectoryExists($this->tempDir.'/out/pkg/empty');
        $this->assertSame('', File::get($this->tempDir.'/out/pkg/sub/b.txt'));
        $this->assertTrue(LifecycleWarnings::current()->isEmpty());
    }

    /**
     * tar.gz(GeoIP 갱신 경로)도 같은 규칙으로 푼다.
     *
     * @scenario entry=normal_tar_gz, caller=safe_extractor
     *
     * @effects normal_archive_extracted_unchanged
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function tar_gz_도_같은_규칙으로_푼다(): void
    {
        $tarPath = $this->tempDir.'/geo.tar';
        $phar = new \PharData($tarPath);
        $phar->addFromString('GeoLite2-City_20261022/GeoLite2-City.mmdb', 'mmdb');
        unset($phar);
        // PharData::compress() 는 Windows PHP 에서 읽을 수 없는 gz 를 만든다 — gzencode 로 감싼다
        File::put($tarPath.'.gz', gzencode(File::get($tarPath)));

        $written = SafeArchiveExtractor::extractTarGz($tarPath.'.gz', $this->tempDir.'/out');

        $this->assertSame(['GeoLite2-City_20261022/GeoLite2-City.mmdb'], $written);
        $this->assertSame('mmdb', File::get($this->tempDir.'/out/GeoLite2-City_20261022/GeoLite2-City.mmdb'));
    }

    /**
     * tar 링크 항목 종류 — typeflag 1(하드 링크)·2(심볼릭 링크).
     *
     * @return array<string, array{0: string}> 시험 이름 → typeflag
     */
    public static function tarLinkTypes(): array
    {
        return [
            'symlink' => ['2'],
            'hardlink' => ['1'],
        ];
    }

    /**
     * tar.gz 안에 링크 항목이 있으면 아무것도 풀지 않고 거부한다.
     *
     * PharData 는 링크 항목을 일반 파일로 보여 줘(isLink 거짓) 이름 검사만으로는 거를 수 없다 — 헤더 typeflag 로 판정.
     *
     * @scenario entry=tar_link, caller=safe_extractor
     *
     * @effects unsafe_entry_rejects_whole_archive
     *
     * @param  string  $type  tar 항목 종류 표시(typeflag)
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('tarLinkTypes')]
    public function tar_gz_링크_항목은_거부한다(string $type): void
    {
        $tar = $this->tarHeader('GeoLite2-City_20261022/GeoLite2-City.mmdb', '0', 4).str_pad('mmdb', 512, "\0")
            .$this->tarHeader('GeoLite2-City_20261022/escape', $type, 0, '../../../escape.txt')
            .str_repeat("\0", 1024);
        $gzPath = $this->tempDir.'/geo-link.tar.gz';
        File::put($gzPath, gzencode($tar));

        try {
            SafeArchiveExtractor::extractTarGz($gzPath, $this->tempDir.'/out');
            $this->fail('링크 항목을 거부하지 않았습니다.');
        } catch (UnsafeArchiveException $e) {
            $this->assertStringContainsString('GeoLite2-City_20261022/escape', $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->tempDir.'/out/GeoLite2-City_20261022/GeoLite2-City.mmdb');
    }

    /**
     * ustar 헤더 한 블록을 만듭니다.
     *
     * @param  string  $name  항목 이름
     * @param  string  $type  항목 종류 표시(typeflag)
     * @param  int  $size  내용 크기
     * @param  string  $link  링크 대상
     * @return string 512바이트 헤더
     */
    private function tarHeader(string $name, string $type, int $size, string $link = ''): string
    {
        $octal = fn (int $n, int $len) => str_pad(decoct($n), $len - 1, '0', STR_PAD_LEFT)."\0";
        $header = str_pad($name, 100, "\0").$octal(0644, 8).$octal(0, 8).$octal(0, 8).$octal($size, 12).$octal(0, 12)
            .'        '.$type.str_pad($link, 100, "\0")."ustar\0".'00';
        $header = str_pad($header, 512, "\0");
        $sum = array_sum(array_map('ord', str_split($header)));

        return substr_replace($header, str_pad(decoct($sum), 6, '0', STR_PAD_LEFT)."\0 ", 148, 8);
    }

    /**
     * 확장 zip 설치(ZipInstallHelper)도 역슬래시 항목을 정규화한다.
     *
     * @scenario entry=backslash_path, caller=zip_install_helper
     *
     * @effects backslash_entries_extracted_as_directories
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 확장_zip_설치_경로도_역슬래시를_정규화한다(): void
    {
        $zipPath = $this->makeZip(['sample-module\\module.json' => '{"identifier":"sample-module"}']);

        $result = ZipInstallHelper::extractAndValidate($zipPath, $this->tempDir.'/out', 'module.json', 'modules');

        $this->assertSame('sample-module', $result['identifier']);
        $this->assertSame([], $this->namesWithBackslash($this->tempDir.'/out'));
    }

    /**
     * 확장 zip 설치(ZipInstallHelper)도 압축 밖 경로를 거부한다.
     *
     * @scenario entry=outside_path, caller=zip_install_helper
     *
     * @effects unsafe_entry_rejects_whole_archive
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 확장_zip_설치_경로도_압축_밖_경로를_거부한다(): void
    {
        $zipPath = $this->makeZip(['module.json' => '{"identifier":"x"}', '../escape.php' => '<?php']);

        $this->expectException(UnsafeArchiveException::class);

        ZipInstallHelper::extractZip($zipPath, $this->tempDir.'/out');
    }

    /**
     * 설치 마법사 함수도 같은 벡터를 거부한다.
     *
     * @scenario entry=outside_path, caller=install_wizard
     *
     * @effects unsafe_entry_rejects_whole_archive
     *
     * @param  string  $entry  거부돼야 할 항목 이름
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('unsafeEntryProvider')]
    public function 설치_마법사_함수도_같은_항목을_거부한다(string $entry): void
    {
        require_once base_path('public/install/includes/safe-archive-extractor.php');

        $zipPath = $this->makeZip(['safe.txt' => 'ok', $entry => 'evil']);
        $dest = $this->tempDir.'/out';

        $result = g7InstallSafeExtractZip($zipPath, $dest);

        $this->assertFalse($result['success']);
        $this->assertSame('unsafe_entry', $result['error']);
        $this->assertSame($entry, $result['unsafe_entry']);
        $this->assertFileDoesNotExist($dest.'/safe.txt');
    }

    /**
     * 설치 마법사 함수도 역슬래시를 정규화하고 심볼릭 링크를 거부한다.
     *
     * @scenario entry=backslash_path, caller=install_wizard
     *
     * @effects backslash_entries_extracted_as_directories, unsafe_entry_rejects_whole_archive
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 설치_마법사_함수도_역슬래시를_정규화하고_심볼릭_링크를_거부한다(): void
    {
        require_once base_path('public/install/includes/safe-archive-extractor.php');

        $zipPath = $this->makeZip(['vendor\\pkg\\a.php' => 'A', 'vendor/autoload.php' => 'B']);
        $result = g7InstallSafeExtractZip($zipPath, $this->tempDir.'/out');

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['normalized']);
        $this->assertFileExists($this->tempDir.'/out/vendor/pkg/a.php');
        $this->assertSame([], $this->namesWithBackslash($this->tempDir.'/out'));

        $linkZip = $this->tempDir.'/link.zip';
        $zip = new \ZipArchive;
        $zip->open($linkZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('link', '/etc/passwd');
        $zip->setExternalAttributesName('link', \ZipArchive::OPSYS_UNIX, (0120777) << 16);
        $zip->close();

        $this->assertSame('link', g7InstallFindUnsafeZipEntry($linkZip));
    }

    /**
     * 시험용 zip 을 만듭니다 (항목 이름을 그대로 기록).
     *
     * @param  array<string, string>  $files  항목 이름 => 내용
     * @return string zip 경로
     */
    private function makeZip(array $files): string
    {
        $zipPath = $this->tempDir.'/'.uniqid('t', true).'.zip';
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $zipPath;
    }

    /**
     * 디렉터리 아래 이름에 역슬래시가 든 파일·폴더 목록.
     *
     * @param  string  $dir  검사할 디렉터리
     * @return array<int, string> 역슬래시가 든 이름
     */
    private function namesWithBackslash(string $dir): array
    {
        $found = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $item) {
            if (str_contains($item->getFilename(), '\\')) {
                $found[] = $item->getFilename();
            }
        }

        return $found;
    }
}
