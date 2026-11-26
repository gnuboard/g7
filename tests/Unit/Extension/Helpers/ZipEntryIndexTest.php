<?php

namespace Tests\Unit\Extension\Helpers;

use App\Extension\Helpers\ZipEntryIndex;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsZipFixtures;
use Tests\TestCase;

/**
 * 풀지 않고 zip 을 이름으로 읽는 공용 색인(`ZipEntryIndex`) 단위 테스트.
 *
 * 코어의 「풀지 않고 읽기」 네 곳(번들 인벤토리·PHP 확장 사전 점검·개발자 예약 자리 사전 점검·예약 자리 판정)이
 * 같은 규칙을 따로 갖던 것을 모았다 — 역슬래시 이름 정규화, 원본 인덱스로 읽기, `__MACOSX/` 제외, 감싼 폴더 판정.
 *
 * 트러블슈팅 `[case:backend-75]` 의 회귀 그물이다.
 */
class ZipEntryIndexTest extends TestCase
{
    use BuildsZipFixtures;

    private string $dir;

    /**
     * 임시 폴더를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $this->dir = storage_path('framework/testing/zip-entry-index-'.uniqid());
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
     * zip 모양과 기대 판정 (`config/app.php` 접두사, 감싼 폴더 접두사).
     *
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: string}> 모양 옵션, 기대 prefixFor, 기대 wrapperPrefix
     */
    public static function shapeProvider(): array
    {
        return [
            '감싼 폴더, 폴더 항목 없음' => [['wrapper' => 'g7-7.2.0'], 'g7-7.2.0/', 'g7-7.2.0/'],
            '감싼 폴더 + 폴더 항목(GitHub)' => [['wrapper' => 'g7-7.2.0', 'directoryEntries' => true], 'g7-7.2.0/', 'g7-7.2.0/'],
            '감싼 폴더 역슬래시' => [['wrapper' => 'g7-7.2.0', 'directoryEntries' => true, 'backslash' => true], 'g7-7.2.0/', 'g7-7.2.0/'],
            '감싼 폴더 + __MACOSX' => [['wrapper' => 'g7-7.2.0', 'directoryEntries' => true, 'macosx' => true], 'g7-7.2.0/', 'g7-7.2.0/'],
            '평탄' => [[], '', ''],
            '평탄 + 폴더 항목' => [['directoryEntries' => true], '', ''],
            '평탄 역슬래시' => [['backslash' => true], '', ''],
        ];
    }

    /**
     * 모양과 상관없이 같은 접두사를 판정하고, 정규화한 이름으로 원본 항목 내용을 읽는지 확인합니다.
     *
     * @scenario case=entry_index_shape
     *
     * @effects zip_entry_index_normalizes_and_reads_by_original_index
     *
     * @param  array<string, mixed>  $shape  zip 모양 옵션
     * @param  string  $expectedMarkerPrefix  `config/app.php` 기준 접두사
     * @param  string  $expectedWrapper  감싼 폴더 접두사
     * @return void 반환값 없음
     */
    #[DataProvider('shapeProvider')]
    public function test_모양과_상관없이_접두사를_판정하고_원본_항목을_읽는다(array $shape, string $expectedMarkerPrefix, string $expectedWrapper): void
    {
        $zipPath = $this->dir.'/release.zip';
        $this->makeZipFixture($zipPath, [
            'composer.json' => '{"name":"g7/core"}',
            'config/app.php' => "<?php return ['version' => '7.2.0'];",
            'app/Http/Kernel.php' => '<?php // kernel',
        ], $shape);

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($zipPath) === true);

        try {
            $index = ZipEntryIndex::fromArchive($zip);

            $this->assertSame($expectedMarkerPrefix, $index->prefixFor('config/app.php'));
            $this->assertSame($expectedWrapper, $index->wrapperPrefix());
            $this->assertSame("<?php return ['version' => '7.2.0'];", $index->read($expectedMarkerPrefix.'config/app.php'));
            $this->assertSame('<?php // kernel', $index->read($expectedWrapper.'app/Http/Kernel.php'));
            $this->assertNull($index->read($expectedWrapper.'missing.php'));
            $this->assertNull($index->read($expectedWrapper.'app'), '폴더 항목은 파일로 읽지 않는다');

            $files = $index->files();
            $this->assertContains($expectedWrapper.'app/Http/Kernel.php', $files);
            $this->assertCount(3, $files, '폴더 항목·__MACOSX 부속 항목은 파일 목록에 없다');
            foreach ($files as $name) {
                $this->assertStringNotContainsString('\\', $name, '파일 목록은 정규화된 이름이다');
                $this->assertStringStartsNotWith('__MACOSX', $name);
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * 표식 파일이 루트와 1단계 폴더 양쪽에 있으면 루트(`''`)를 고르고, 없으면 null 을 돌려주는지 확인합니다.
     *
     * @scenario case=entry_index_shape
     *
     * @effects zip_entry_index_normalizes_and_reads_by_original_index
     *
     * @return void 반환값 없음
     */
    public function test_표식_접두사는_루트를_우선하고_없으면_null(): void
    {
        $zipPath = $this->dir.'/mixed.zip';
        $this->makeZipFixture($zipPath, [
            'docs/config/app.php' => 'decoy',
            'config/app.php' => 'root',
        ]);

        $zip = new \ZipArchive;
        $zip->open($zipPath);

        try {
            $index = ZipEntryIndex::fromArchive($zip);
            $this->assertSame('', $index->prefixFor('config/app.php'));
            $this->assertSame('', $index->wrapperPrefix(), '최상위가 둘이면 감싼 폴더가 아니다');
            $this->assertNull($index->prefixFor('artisan'));
            $this->assertNull($index->prefixFor('a/b/config/app.php'), '2단계 이상 깊은 표식은 접두사 후보가 아니다');
        } finally {
            $zip->close();
        }
    }

    /**
     * 위험한 이름(압축 밖 경로)은 예외 없이 색인에서 빠지는지 확인합니다 — 해제가 아니라 읽기 전용 판정이라 거부는 해제기 몫이다.
     *
     * @scenario case=entry_index_shape
     *
     * @effects zip_entry_index_normalizes_and_reads_by_original_index
     *
     * @return void 반환값 없음
     */
    public function test_위험한_이름은_색인에서_빠진다(): void
    {
        $zipPath = $this->dir.'/unsafe.zip';
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('g7-7.2.0/config/app.php', 'ok');
        $zip->addFromString('../evil.php', 'evil');
        $zip->close();

        $zip->open($zipPath);

        try {
            $index = ZipEntryIndex::fromArchive($zip);
            $this->assertSame(['g7-7.2.0/config/app.php'], $index->files());
            $this->assertSame('g7-7.2.0/', $index->wrapperPrefix());
        } finally {
            $zip->close();
        }
    }
}
