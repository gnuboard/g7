<?php

namespace Tests\Unit\Seo;

use App\Contracts\Extension\StorageInterface;
use App\Extension\Storage\CoreStorageDriver;
use App\Seo\SitemapFileStore;
use App\Seo\SitemapWriter;
use App\Seo\SitemapXmlRenderer;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * SitemapWriter 단위 테스트
 *
 * 임계 분할, sitemapindex 생성, gzip, atomic commit(manifest 마지막 기록),
 * stale 파일 정리, 대용량 시 bounded memory 를 검증합니다.
 */
class SitemapWriterTest extends TestCase
{
    private RecordingStorage $storage;

    /**
     * 테스트 초기화 - 가짜 로컬 디스크와 put 순서 기록 스토리지를 준비합니다.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('app.locale', 'ko');
        Config::set('app.supported_locales', ['ko']);

        Storage::fake('local');

        $this->storage = new RecordingStorage(new CoreStorageDriver('local'));
    }

    /**
     * 테스트용 writer 를 생성합니다.
     *
     * @param  int  $urlsPerFile  파일당 URL 수
     * @param  bool  $gzip  gzip 압축 여부
     * @return SitemapWriter writer 인스턴스
     */
    private function makeWriter(int $urlsPerFile = 10, bool $gzip = false): SitemapWriter
    {
        return new SitemapWriter(
            $this->storage,
            SitemapXmlRenderer::fromConfig(),
            $urlsPerFile,
            $gzip,
        );
    }

    /**
     * 커밋된 live 파일 내용을 읽습니다.
     *
     * @param  string  $filename  파일명
     * @return string|null 파일 내용
     */
    private function liveContent(string $filename): ?string
    {
        return $this->storage->get(SitemapFileStore::CATEGORY, SitemapFileStore::LIVE_DIR.'/'.$filename);
    }

    // ─── 분할 ────────────────────────────────────────────

    /**
     * URL 수가 임계를 넘으면 자식 파일로 분할되는지 확인합니다.
     */
    public function test_splits_children_at_threshold(): void
    {
        $writer = $this->makeWriter(urlsPerFile: 10);
        $writer->open();

        // 25건 / 파일당 10건 → 자식 3개 (10, 10, 5)
        for ($i = 1; $i <= 25; $i++) {
            $writer->addUrl(['loc' => url("/p/{$i}"), 'priority' => 0.5]);
        }

        $writer->close();
        $meta = $writer->commit();

        $this->assertSame(3, $meta['child_count']);
        $this->assertSame(25, $meta['url_count']);
        $this->assertSame(10, $meta['children'][0]['url_count']);
        $this->assertSame(10, $meta['children'][1]['url_count']);
        $this->assertSame(5, $meta['children'][2]['url_count']);
    }

    /**
     * 임계에 정확히 맞아떨어지면 빈 자식 파일이 생기지 않는지 확인합니다.
     */
    public function test_exact_threshold_does_not_create_empty_child(): void
    {
        $writer = $this->makeWriter(urlsPerFile: 10);
        $writer->open();

        for ($i = 1; $i <= 20; $i++) {
            $writer->addUrl(['loc' => url("/p/{$i}")]);
        }

        $writer->close();
        $meta = $writer->commit();

        $this->assertSame(2, $meta['child_count']);
    }

    /**
     * 각 자식 파일이 well-formed urlset XML 인지 확인합니다.
     */
    public function test_children_are_well_formed_urlsets(): void
    {
        $writer = $this->makeWriter(urlsPerFile: 10);
        $writer->open();

        for ($i = 1; $i <= 25; $i++) {
            $writer->addUrl(['loc' => url("/p/{$i}"), 'changefreq' => 'daily']);
        }

        $writer->close();
        $meta = $writer->commit();

        foreach ($meta['children'] as $child) {
            $xml = $this->liveContent($child['file']);

            $this->assertNotNull($xml);
            $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $xml);
            $this->assertStringEndsWith('</urlset>', $xml);
            $this->assertLessThanOrEqual(SitemapWriter::HARD_URL_CAP, substr_count($xml, '<loc>'));
            $this->assertNotFalse(simplexml_load_string($xml), '자식 sitemap 이 유효한 XML 이어야 합니다.');
        }
    }

    /**
     * sitemapindex 가 모든 자식을 절대 URL 로 참조하는지 확인합니다.
     */
    public function test_index_references_all_children(): void
    {
        $writer = $this->makeWriter(urlsPerFile: 10);
        $writer->open();

        for ($i = 1; $i <= 25; $i++) {
            $writer->addUrl(['loc' => url("/p/{$i}")]);
        }

        $writer->close();
        $writer->commit();

        $index = $this->liveContent(SitemapFileStore::INDEX_FILE);

        $this->assertNotNull($index);
        $this->assertStringContainsString('<sitemapindex', $index);
        $this->assertSame(3, substr_count($index, '<sitemap>'));
        $this->assertStringContainsString('<loc>'.url('/sitemap-1.xml').'</loc>', $index);
        $this->assertStringContainsString('<loc>'.url('/sitemap-3.xml').'</loc>', $index);
        $this->assertNotFalse(simplexml_load_string($index));
    }

    /**
     * URL 이 하나도 없어도 빈 urlset 자식 1개와 인덱스를 남기는지 확인합니다.
     */
    public function test_empty_set_still_emits_index_and_one_child(): void
    {
        $writer = $this->makeWriter();
        $writer->open();
        $writer->close();
        $meta = $writer->commit();

        $this->assertSame(1, $meta['child_count']);
        $this->assertSame(0, $meta['url_count']);

        $xml = $this->liveContent('sitemap-1.xml');
        $this->assertStringNotContainsString('<url>', $xml);
        $this->assertNotFalse(simplexml_load_string($xml));
    }

    /**
     * loc 이 없는 항목은 무시되는지 확인합니다.
     */
    public function test_entry_without_loc_is_skipped(): void
    {
        $writer = $this->makeWriter();
        $writer->open();
        $writer->addUrl(['changefreq' => 'daily']);
        $writer->close();
        $meta = $writer->commit();

        $this->assertSame(0, $meta['url_count']);
    }

    // ─── 다국어 ──────────────────────────────────────────

    /**
     * 다국어에서는 URL 하나가 로케일 수만큼의 <url> 블록으로 계산되는지 확인합니다.
     */
    public function test_multilingual_counts_url_blocks_per_locale(): void
    {
        Config::set('app.supported_locales', ['ko', 'en']);

        $writer = $this->makeWriter(urlsPerFile: 10);
        $writer->open();

        // 6건 × 2로케일 = 12블록 → 자식 2개
        for ($i = 1; $i <= 6; $i++) {
            $writer->addUrl(['loc' => url("/p/{$i}")]);
        }

        $writer->close();
        $meta = $writer->commit();

        $this->assertSame(12, $meta['url_count']);
        $this->assertSame(2, $meta['child_count']);
    }

    // ─── gzip ────────────────────────────────────────────

    /**
     * gzip 활성 시 .xml.gz 로 압축 저장되고 인덱스 loc 도 .gz 를 가리키는지 확인합니다.
     */
    public function test_gzip_writes_compressed_children(): void
    {
        $writer = $this->makeWriter(urlsPerFile: 10, gzip: true);
        $writer->open();

        for ($i = 1; $i <= 5; $i++) {
            $writer->addUrl(['loc' => url("/p/{$i}")]);
        }

        $writer->close();
        $meta = $writer->commit();

        $this->assertTrue($meta['gzip']);
        $this->assertSame('sitemap-1.xml.gz', $meta['children'][0]['file']);

        $raw = $this->liveContent('sitemap-1.xml.gz');
        $decoded = gzdecode($raw);

        $this->assertNotFalse($decoded, 'gzip 자식 파일이 해제 가능해야 합니다.');
        $this->assertStringEndsWith('</urlset>', $decoded);

        // 인덱스는 비압축이며 .gz 자식을 가리킵니다.
        $index = $this->liveContent(SitemapFileStore::INDEX_FILE);
        $this->assertStringContainsString('<loc>'.url('/sitemap-1.xml.gz').'</loc>', $index);
    }

    /**
     * gzip 비활성이 기본값인지 확인합니다.
     */
    public function test_gzip_disabled_by_default(): void
    {
        $writer = $this->makeWriter(urlsPerFile: 10);
        $writer->open();
        $writer->addUrl(['loc' => url('/p/1')]);
        $writer->close();
        $meta = $writer->commit();

        $this->assertFalse($meta['gzip']);
        $this->assertSame('sitemap-1.xml', $meta['children'][0]['file']);
    }

    // ─── 커밋 ────────────────────────────────────────────

    /**
     * manifest.json 이 커밋의 마지막 기록인지 확인합니다 (커밋 마커).
     */
    public function test_manifest_is_written_last(): void
    {
        $writer = $this->makeWriter(urlsPerFile: 10);
        $writer->open();

        for ($i = 1; $i <= 25; $i++) {
            $writer->addUrl(['loc' => url("/p/{$i}")]);
        }

        $writer->close();
        $writer->commit();

        $manifestPath = SitemapFileStore::LIVE_DIR.'/'.SitemapFileStore::MANIFEST_FILE;

        $this->assertSame($manifestPath, end($this->storage->putOrder));

        // live 자식/인덱스가 모두 manifest 보다 먼저 기록되었는지 확인
        $manifestIndex = array_search($manifestPath, $this->storage->putOrder, true);
        foreach (['sitemap-1.xml', 'sitemap-2.xml', 'sitemap-3.xml', SitemapFileStore::INDEX_FILE] as $file) {
            $position = array_search(SitemapFileStore::LIVE_DIR.'/'.$file, $this->storage->putOrder, true);

            $this->assertNotFalse($position, "{$file} 이 live 에 기록되어야 합니다.");
            $this->assertLessThan($manifestIndex, $position);
        }
    }

    /**
     * 커밋 후 임시 디렉토리가 정리되는지 확인합니다.
     */
    public function test_commit_cleans_tmp_directory(): void
    {
        $writer = $this->makeWriter(urlsPerFile: 10);
        $writer->open();
        $writer->addUrl(['loc' => url('/p/1')]);
        $writer->close();
        $writer->commit();

        $this->assertSame([], $this->storage->files(SitemapFileStore::CATEGORY, SitemapFileStore::TMP_DIR));
    }

    /**
     * 이전 생성물 중 새 세트에 없는 자식 파일이 정리되는지 확인합니다.
     */
    public function test_commit_removes_stale_children(): void
    {
        // 1차: 자식 3개
        $writer = $this->makeWriter(urlsPerFile: 10);
        $writer->open();
        for ($i = 1; $i <= 25; $i++) {
            $writer->addUrl(['loc' => url("/p/{$i}")]);
        }
        $writer->close();
        $writer->commit();

        $this->assertNotNull($this->liveContent('sitemap-3.xml'));

        // 2차: 자식 1개로 축소 → sitemap-2/3 은 stale
        $writer = $this->makeWriter(urlsPerFile: 10);
        $writer->open();
        $writer->addUrl(['loc' => url('/p/1')]);
        $writer->close();
        $meta = $writer->commit();

        $this->assertSame(1, $meta['child_count']);
        $this->assertNotNull($this->liveContent('sitemap-1.xml'));
        $this->assertNull($this->liveContent('sitemap-2.xml'));
        $this->assertNull($this->liveContent('sitemap-3.xml'));
    }

    /**
     * open() 이 이전 실행의 임시 파일을 정리하는지 확인합니다.
     */
    public function test_open_clears_previous_tmp_files(): void
    {
        $this->storage->put(SitemapFileStore::CATEGORY, SitemapFileStore::TMP_DIR.'/sitemap-9.xml', 'stale');

        $writer = $this->makeWriter();
        $writer->open();

        $this->assertNull($this->storage->get(SitemapFileStore::CATEGORY, SitemapFileStore::TMP_DIR.'/sitemap-9.xml'));
    }

    /**
     * manifest 에 세트 메타데이터가 기록되는지 확인합니다.
     */
    public function test_manifest_contains_set_metadata(): void
    {
        $writer = $this->makeWriter(urlsPerFile: 10);
        $writer->open();
        for ($i = 1; $i <= 15; $i++) {
            $writer->addUrl(['loc' => url("/p/{$i}")]);
        }
        $writer->close();
        $writer->commit();

        $manifest = json_decode($this->liveContent(SitemapFileStore::MANIFEST_FILE), true);

        $this->assertSame(2, $manifest['child_count']);
        $this->assertSame(15, $manifest['url_count']);
        $this->assertSame(10, $manifest['urls_per_file']);
        $this->assertNotEmpty($manifest['generated_at']);
        $this->assertCount(2, $manifest['children']);
    }

    // ─── 상한 / 메모리 ────────────────────────────────────

    /**
     * urlsPerFile 이 프로토콜 상한(50,000)을 넘지 못하도록 클램프되는지 확인합니다.
     */
    public function test_urls_per_file_is_clamped_to_protocol_cap(): void
    {
        $writer = $this->makeWriter(urlsPerFile: 999999);
        $writer->open();
        $writer->addUrl(['loc' => url('/p/1')]);
        $writer->close();
        $writer->commit();

        $manifest = json_decode($this->liveContent(SitemapFileStore::MANIFEST_FILE), true);

        $this->assertSame(SitemapWriter::HARD_URL_CAP, $manifest['urls_per_file']);
    }

    /**
     * 대용량(120,001건) 생성 시 자식 수가 정확하고 메모리가 유계인지 확인합니다.
     *
     * 전체 in-memory 적재(회귀)라면 URL 수에 비례해 메모리가 증가합니다.
     *
     * @scale n=1500000 asserts=bounded_peak_memory, child_count_correct
     */
    public function test_large_volume_splits_and_keeps_memory_bounded(): void
    {
        $writer = $this->makeWriter(urlsPerFile: 50000);

        // peak 는 프로세스 전체의 high-water mark 라, 선행 테스트가 올려둔 peak 때문에
        // 델타가 0 으로 나와 거짓 통과할 수 있다. 이 테스트 구간만 측정하도록 리셋한다.
        memory_reset_peak_usage();
        $before = memory_get_peak_usage(true);

        $writer->open();
        for ($i = 1; $i <= 120001; $i++) {
            $writer->addUrl(['loc' => url("/p/{$i}"), 'priority' => 0.5]);
        }
        $writer->close();
        $meta = $writer->commit();

        $growth = memory_get_peak_usage(true) - $before;

        // 120001 = 50000 + 50000 + 20001
        $this->assertSame(3, $meta['child_count']);
        $this->assertSame(120001, $meta['url_count']);
        $this->assertSame(50000, $meta['children'][0]['url_count']);
        $this->assertSame(50000, $meta['children'][1]['url_count']);
        $this->assertSame(20001, $meta['children'][2]['url_count']);

        // 자식 파일 1개 버퍼(수 MB) 수준을 넘지 않아야 합니다.
        // 실측 약 10MB (자식 파일 1개 버퍼 수준). 전체 적재 회귀 시 수십~수백 MB 로 튄다.
        $this->assertLessThan(
            64 * 1024 * 1024,
            $growth,
            '메모리 증가가 자식 파일 1개 버퍼 수준을 넘었습니다 (전체 적재 회귀 의심): '.$growth,
        );
    }

    /**
     * URL 수가 상한에 못 미쳐도 바이트 임계에 도달하면 자식이 분할됩니다.
     *
     * sitemaps.org 는 파일당 50MB 를 제한하므로 URL 수 상한만으로는 프로토콜 위반을 막지 못한다
     * (긴 URL 5만 건이면 50MB 초과). MAX_FILE_BYTES 분기가 그 방어인데, 45MB 를 실제로 채우면
     * 테스트가 과도하게 무거우므로 하위 클래스로 임계값만 낮춰 같은 경로를 검증한다.
     */
    public function test_byte_threshold_splits_child_even_below_url_cap(): void
    {
        $writer = new SmallByteCapSitemapWriter(
            $this->storage,
            SitemapXmlRenderer::fromConfig(),
            10000,  // URL 수 상한 — 이번 케이스에서는 절대 도달하지 않는다
            false,
        );

        $writer->open();
        // URL 블록 1개가 임계(2KB)를 넘도록 긴 loc 를 쓴다 → 매 건마다 flush
        for ($i = 1; $i <= 3; $i++) {
            $writer->addUrl(['loc' => url('/p/'.str_repeat("long-segment-{$i}-", 200))]);
        }
        $writer->close();
        $meta = $writer->commit();

        $this->assertSame(3, $meta['url_count']);
        $this->assertSame(
            3,
            $meta['child_count'],
            'URL 수 상한(10000) 미달이어도 바이트 임계 도달 시 자식이 분할돼야 합니다.'
        );
    }

    /**
     * 바이트 임계로 분할된 자식들도 well-formed urlset 이며 전량 보존됩니다.
     */
    public function test_byte_threshold_split_children_are_well_formed_and_lossless(): void
    {
        $writer = new SmallByteCapSitemapWriter(
            $this->storage,
            SitemapXmlRenderer::fromConfig(),
            10000,
            false,
        );

        $writer->open();
        $locs = [];
        for ($i = 1; $i <= 3; $i++) {
            $loc = url('/p/'.str_repeat("seg-{$i}-", 200));
            $locs[] = $loc;
            $writer->addUrl(['loc' => $loc]);
        }
        $writer->close();
        $meta = $writer->commit();

        $combined = '';
        foreach ($meta['children'] as $child) {
            $xml = $this->liveContent($child['file']);

            $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $xml);
            $this->assertStringContainsString('</urlset>', $xml);
            $combined .= $xml;
        }

        // 분할 과정에서 URL 이 유실되면 안 된다
        foreach ($locs as $loc) {
            $this->assertStringContainsString($loc, $combined);
        }
    }
}

/**
 * 바이트 임계를 2KB 로 낮춘 writer (테스트 전용).
 *
 * MAX_FILE_BYTES 분기는 static:: 바인딩이므로 상수 재정의만으로 임계값을 바꿀 수 있다.
 */
class SmallByteCapSitemapWriter extends SitemapWriter
{
    public const MAX_FILE_BYTES = 2048;
}

/**
 * put() 호출 경로를 순서대로 기록하는 StorageInterface 데코레이터 (테스트 전용).
 *
 * manifest.json 이 커밋의 마지막 기록인지 검증하기 위해 사용합니다.
 */
class RecordingStorage implements StorageInterface
{
    /**
     * put 이 호출된 경로 목록 (호출 순서)
     *
     * @var array<int, string>
     */
    public array $putOrder = [];

    /**
     * RecordingStorage 생성자
     *
     * @param  StorageInterface  $inner  위임 대상 스토리지
     */
    public function __construct(private StorageInterface $inner) {}

    /**
     * {@inheritDoc}
     */
    public function put(string $category, string $path, mixed $content): bool
    {
        $this->putOrder[] = $path;

        return $this->inner->put($category, $path, $content);
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $category, string $path): ?string
    {
        return $this->inner->get($category, $path);
    }

    /**
     * {@inheritDoc}
     */
    public function exists(string $category, string $path): bool
    {
        return $this->inner->exists($category, $path);
    }

    /**
     * {@inheritDoc}
     */
    public function delete(string $category, string $path): bool
    {
        return $this->inner->delete($category, $path);
    }

    /**
     * {@inheritDoc}
     */
    public function url(string $category, string $path): ?string
    {
        return $this->inner->url($category, $path);
    }

    /**
     * {@inheritDoc}
     */
    public function files(string $category, string $directory = ''): array
    {
        return $this->inner->files($category, $directory);
    }

    /**
     * {@inheritDoc}
     */
    public function deleteDirectory(string $category, string $directory = ''): bool
    {
        return $this->inner->deleteDirectory($category, $directory);
    }

    /**
     * {@inheritDoc}
     */
    public function getBasePath(string $category): string
    {
        return $this->inner->getBasePath($category);
    }

    /**
     * {@inheritDoc}
     */
    public function getDisk(): string
    {
        return $this->inner->getDisk();
    }

    /**
     * {@inheritDoc}
     */
    public function deleteAll(string $category): bool
    {
        return $this->inner->deleteAll($category);
    }

    /**
     * {@inheritDoc}
     */
    public function response(string $category, string $path, string $filename, array $headers = []): ?StreamedResponse
    {
        return $this->inner->response($category, $path, $filename, $headers);
    }

    /**
     * {@inheritDoc}
     */
    public function withDisk(string $disk): static
    {
        $clone = clone $this;
        $clone->inner = $this->inner->withDisk($disk);

        return $clone;
    }

    /**
     * {@inheritDoc}
     */
    public function download(string $category, string $path, string $filename, array $headers = []): ?StreamedResponse
    {
        return $this->inner->download($category, $path, $filename, $headers);
    }
}
