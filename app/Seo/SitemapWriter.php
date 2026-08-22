<?php

namespace App\Seo;

use App\Contracts\Extension\StorageInterface;
use Illuminate\Support\Facades\Log;

/**
 * Sitemap 파일 기록기 (스트리밍 분할 writer)
 *
 * URL 을 한 건씩 받아 자식 sitemap 파일로 분할 기록합니다.
 * sitemaps.org 프로토콜(파일당 50,000 URL / 50MB)을 준수하며,
 * 전체 URL 을 메모리에 적재하지 않습니다.
 *
 * StorageInterface 는 append 스트림을 제공하지 않고 put() 이 매 호출 파일 전체를
 * 덮어쓰므로, 자식 파일 하나 분량만 메모리 버퍼에 쌓았다가 임계 도달 시 flush 합니다.
 * 따라서 최대 메모리 사용량은 "자식 파일 1개 크기"로 제한됩니다.
 *
 * 커밋은 임시 디렉토리(_tmp)에 전량 기록한 뒤 live 로 재배치하고,
 * manifest.json 을 마지막에 기록하는 방식으로 수행합니다(manifest = 커밋 마커).
 */
class SitemapWriter
{
    /**
     * 파일당 URL 수 상한 (sitemaps.org 프로토콜)
     */
    public const HARD_URL_CAP = 50000;

    /**
     * 파일당 바이트 상한 (프로토콜 50MB 에 대한 안전 여유)
     */
    public const MAX_FILE_BYTES = 45 * 1024 * 1024;

    /**
     * 현재 자식 파일 버퍼
     */
    private string $buffer = '';

    /**
     * 현재 자식 파일에 기록된 <url> 블록 수
     */
    private int $currentUrlCount = 0;

    /**
     * 현재 자식 파일 번호 (1부터)
     */
    private int $childIndex = 0;

    /**
     * 현재 자식 파일이 열려 있는지 여부
     */
    private bool $childOpen = false;

    /**
     * 현재 자식 파일의 최신 lastmod
     */
    private ?string $childLastmod = null;

    /**
     * 기록된 자식 파일 목록
     *
     * @var array<int, array{file: string, loc: string, lastmod: ?string, url_count: int, size_bytes: int}>
     */
    private array $children = [];

    /**
     * 전체 <url> 블록 수
     */
    private int $totalUrls = 0;

    /**
     * 전체 자식 파일 바이트 합계
     */
    private int $totalBytes = 0;

    /**
     * 파일당 URL 수 임계
     */
    private readonly int $urlsPerFile;

    /**
     * SitemapWriter 생성자
     *
     * @param  StorageInterface  $storage  비공개 디스크 스토리지 드라이버
     * @param  SitemapXmlRenderer  $renderer  XML 조각 렌더러 (SitemapGenerator 와 공유)
     * @param  int  $urlsPerFile  파일당 URL 수 (1 ~ HARD_URL_CAP 로 클램프)
     * @param  bool  $gzip  자식 파일 gzip 압축 여부
     */
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly SitemapXmlRenderer $renderer,
        int $urlsPerFile = self::HARD_URL_CAP,
        private readonly bool $gzip = false,
    ) {
        $this->urlsPerFile = max(1, min($urlsPerFile, self::HARD_URL_CAP));
    }

    /**
     * 기록을 시작합니다 (임시 디렉토리 초기화 + 상태 리셋).
     */
    public function open(): void
    {
        $this->purgeTmpDirectory();

        $this->buffer = '';
        $this->currentUrlCount = 0;
        $this->childIndex = 0;
        $this->childOpen = false;
        $this->childLastmod = null;
        $this->children = [];
        $this->totalUrls = 0;
        $this->totalBytes = 0;
    }

    /**
     * URL 항목 하나를 기록합니다.
     *
     * 임계(URL 수 또는 바이트) 도달 시 현재 자식 파일을 flush 합니다.
     *
     * @param  array{loc?: string, lastmod?: string, changefreq?: string, priority?: float}  $entry  URL 항목
     */
    public function addUrl(array $entry): void
    {
        $block = $this->renderer->urlBlock($entry);
        if ($block === '') {
            return;
        }

        if (! $this->childOpen) {
            $this->openNextChild();
        }

        $this->buffer .= $block;
        $this->currentUrlCount += $this->renderer->urlBlockCount();
        $this->totalUrls += $this->renderer->urlBlockCount();

        $lastmod = $entry['lastmod'] ?? null;
        if (is_string($lastmod) && $lastmod !== '' && ($this->childLastmod === null || $lastmod > $this->childLastmod)) {
            $this->childLastmod = $lastmod;
        }

        // static:: 바인딩 — 하위 클래스가 임계값을 낮춰 바이트 분할 분기를 검증할 수 있게 한다
        // (45MB 를 실제로 채우지 않고 같은 경로를 태우기 위함).
        if ($this->currentUrlCount >= $this->urlsPerFile || strlen($this->buffer) >= static::MAX_FILE_BYTES) {
            $this->flushChild();
        }
    }

    /**
     * 기록을 마감합니다 (마지막 자식 flush + sitemapindex 기록).
     *
     * @return array{child_count: int, url_count: int, size_bytes: int, children: array} 기록 메타데이터
     */
    public function close(): array
    {
        // 자식이 하나도 없으면(URL 0건) 빈 urlset 자식 1개를 남겨 인덱스를 유효하게 유지합니다.
        if ($this->childOpen || $this->children === []) {
            if (! $this->childOpen) {
                $this->openNextChild();
            }
            $this->flushChild();
        }

        $indexXml = $this->renderer->sitemapIndex($this->children);
        $this->putOrFail(SitemapFileStore::TMP_DIR.'/'.SitemapFileStore::INDEX_FILE, $indexXml);

        return [
            'child_count' => count($this->children),
            'url_count' => $this->totalUrls,
            'size_bytes' => $this->totalBytes,
            'children' => $this->children,
        ];
    }

    /**
     * 임시 디렉토리의 세트를 live 로 커밋합니다.
     *
     * 순서: (1) 기존 live 파일 목록 확보 → (2) _tmp 파일을 live 로 재배치 →
     * (3) manifest.json 기록(커밋 마커) → (4) 새 세트에 없는 stale 파일 삭제 → (5) _tmp 정리.
     *
     * @return array{generated_at: string, child_count: int, url_count: int, size_bytes: int, gzip: bool, children: array}
     *                                                                                                                     커밋된 sitemap 메타데이터
     *
     * @throws \RuntimeException 파일 기록 실패 시
     */
    public function commit(): array
    {
        $staleFiles = $this->liveFilenames();

        $newFiles = [SitemapFileStore::INDEX_FILE];
        foreach ($this->children as $child) {
            $newFiles[] = $child['file'];
        }

        foreach ($newFiles as $filename) {
            $content = $this->storage->get(SitemapFileStore::CATEGORY, SitemapFileStore::TMP_DIR.'/'.$filename);
            if ($content === null) {
                throw new \RuntimeException(__('exceptions.seo.sitemap_temp_file_unreadable', ['file' => $filename]));
            }

            $this->putOrFail(SitemapFileStore::LIVE_DIR.'/'.$filename, $content);
            unset($content);
        }

        $manifest = [
            'generated_at' => now()->toIso8601String(),
            'child_count' => count($this->children),
            'url_count' => $this->totalUrls,
            'size_bytes' => $this->totalBytes,
            'gzip' => $this->gzip,
            'urls_per_file' => $this->urlsPerFile,
            'children' => $this->children,
        ];

        // manifest 는 마지막에 기록 — 존재 = 커밋 완료 신호
        $this->putOrFail(
            SitemapFileStore::LIVE_DIR.'/'.SitemapFileStore::MANIFEST_FILE,
            json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );

        $this->deleteStaleFiles($staleFiles, $newFiles);

        $this->purgeTmpDirectory();

        return $manifest;
    }

    /**
     * 임시 디렉토리를 정리합니다.
     *
     * 정리 실패는 치명적이지 않습니다 — 임시 파일은 이름 기준으로 덮어쓰이고,
     * 커밋은 children 목록에 있는 파일만 live 로 옮기므로 잔여 파일이 결과에 섞이지 않습니다.
     * 따라서 예외를 전파하지 않고 경고만 남겨 sitemap 생성 자체가 중단되지 않도록 합니다.
     */
    private function purgeTmpDirectory(): void
    {
        try {
            $this->storage->deleteDirectory(SitemapFileStore::CATEGORY, SitemapFileStore::TMP_DIR);
        } catch (\Throwable $e) {
            Log::warning('[SEO] Sitemap 임시 디렉토리 정리 실패 (생성은 계속 진행)', [
                'directory' => SitemapFileStore::TMP_DIR,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 다음 자식 파일을 엽니다 (버퍼에 urlset 헤더 기록).
     */
    private function openNextChild(): void
    {
        $this->childIndex++;
        $this->buffer = $this->renderer->urlsetHeader();
        $this->currentUrlCount = 0;
        $this->childLastmod = null;
        $this->childOpen = true;
    }

    /**
     * 현재 자식 파일 버퍼를 임시 디렉토리에 기록하고 버퍼를 비웁니다.
     *
     * @throws \RuntimeException 파일 기록 실패 시
     */
    private function flushChild(): void
    {
        $this->buffer .= $this->renderer->urlsetFooter();

        $filename = SitemapFileStore::childFilename($this->childIndex, $this->gzip);
        $content = $this->gzip ? gzencode($this->buffer, 6) : $this->buffer;

        if ($content === false) {
            throw new \RuntimeException(__('exceptions.seo.sitemap_gzip_failed', ['file' => $filename]));
        }

        $this->putOrFail(SitemapFileStore::TMP_DIR.'/'.$filename, $content);

        $this->children[] = [
            'file' => $filename,
            'loc' => SitemapFileStore::childUrl($this->childIndex, $this->gzip),
            'lastmod' => $this->childLastmod,
            'url_count' => $this->currentUrlCount,
            'size_bytes' => strlen($content),
        ];
        $this->totalBytes += strlen($content);

        $this->buffer = '';
        $this->currentUrlCount = 0;
        $this->childOpen = false;
    }

    /**
     * live 디렉토리의 파일명 목록을 반환합니다.
     *
     * @return array<int, string> 파일명 배열 (디렉토리 제외)
     */
    private function liveFilenames(): array
    {
        $paths = $this->storage->files(SitemapFileStore::CATEGORY, SitemapFileStore::LIVE_DIR);

        return array_map(static fn (string $path): string => basename($path), $paths);
    }

    /**
     * 새 세트에 포함되지 않은 이전 자식 파일을 삭제합니다.
     *
     * @param  array<int, string>  $staleFiles  커밋 전 live 파일명 목록
     * @param  array<int, string>  $newFiles  새 세트 파일명 목록
     */
    private function deleteStaleFiles(array $staleFiles, array $newFiles): void
    {
        $keep = array_merge($newFiles, [SitemapFileStore::MANIFEST_FILE]);

        foreach ($staleFiles as $filename) {
            if (in_array($filename, $keep, true)) {
                continue;
            }

            $this->storage->delete(SitemapFileStore::CATEGORY, SitemapFileStore::LIVE_DIR.'/'.$filename);
        }
    }

    /**
     * 파일을 기록하고 실패 시 예외를 던집니다.
     *
     * @param  string  $path  카테고리 하위 상대 경로
     * @param  string  $content  파일 내용
     *
     * @throws \RuntimeException 기록 실패 시
     */
    private function putOrFail(string $path, string $content): void
    {
        if (! $this->storage->put(SitemapFileStore::CATEGORY, $path, $content)) {
            Log::error('[SEO] Sitemap 파일 기록 실패', ['path' => $path]);

            throw new \RuntimeException(__('exceptions.seo.sitemap_write_failed', ['path' => $path]));
        }
    }
}
