<?php

namespace App\Seo;

use App\Contracts\Extension\StorageInterface;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sitemap 파일 저장소 (읽기측)
 *
 * SitemapWriter 가 비공개 디스크에 커밋한 sitemap 인덱스/자식 파일을 조회하고
 * 스트리밍 응답으로 반환합니다. public/storage 심볼릭 링크에 의존하지 않으며,
 * 자식 파일(최대 50MB)을 메모리에 적재하지 않고 스트리밍합니다.
 *
 * 디스크 레이아웃(카테고리 cache 하위):
 *   sitemap/manifest.json    커밋 마커 + 자식 목록 메타
 *   sitemap/sitemap.xml      sitemapindex
 *   sitemap/sitemap-{n}.xml  자식 sitemap (gzip 시 .xml.gz)
 *   sitemap/_tmp/            생성 중 임시 디렉토리 (커밋 시 정리)
 */
class SitemapFileStore
{
    /**
     * StorageInterface 카테고리
     */
    public const CATEGORY = 'cache';

    /**
     * 커밋된 sitemap 파일 디렉토리 (카테고리 하위 상대 경로)
     */
    public const LIVE_DIR = 'sitemap';

    /**
     * 생성 중 임시 디렉토리 (카테고리 하위 상대 경로)
     */
    public const TMP_DIR = 'sitemap/_tmp';

    /**
     * 커밋 마커 파일명
     */
    public const MANIFEST_FILE = 'manifest.json';

    /**
     * sitemapindex 파일명
     */
    public const INDEX_FILE = 'sitemap.xml';

    /**
     * SitemapFileStore 생성자
     *
     * @param  StorageInterface  $storage  비공개 디스크 스토리지 드라이버
     */
    public function __construct(
        private readonly StorageInterface $storage,
    ) {}

    /**
     * 자식 sitemap 파일명을 반환합니다.
     *
     * 파일명 규칙의 단일 출처이며, SitemapWriter(기록)와 web 라우트(서빙)가 공유합니다.
     *
     * @param  int  $n  자식 번호 (1부터)
     * @param  bool  $gzip  gzip 압축 여부
     * @return string 자식 파일명 (예: sitemap-1.xml, sitemap-1.xml.gz)
     */
    public static function childFilename(int $n, bool $gzip = false): string
    {
        return 'sitemap-'.$n.'.xml'.($gzip ? '.gz' : '');
    }

    /**
     * 자식 sitemap 의 공개 절대 URL 을 반환합니다.
     *
     * sitemapindex 의 <loc> 값이며, web 라우트 `/sitemap-{n}.xml` 과 대응합니다.
     *
     * @param  int  $n  자식 번호 (1부터)
     * @param  bool  $gzip  gzip 압축 여부
     * @return string 자식 sitemap 절대 URL
     */
    public static function childUrl(int $n, bool $gzip = false): string
    {
        return url('/'.self::childFilename($n, $gzip));
    }

    /**
     * 커밋된 sitemap 세트가 존재하는지 확인합니다.
     *
     * manifest.json 존재 = 커밋 완료 신호입니다.
     *
     * @return bool 서빙 가능한 세트 존재 여부
     */
    public function exists(): bool
    {
        return $this->storage->exists(self::CATEGORY, self::LIVE_DIR.'/'.self::MANIFEST_FILE);
    }

    /**
     * manifest 메타데이터를 반환합니다.
     *
     * @return array{generated_at?: string, url_count?: int, child_count?: int, size_bytes?: int, gzip?: bool, children?: array}|null
     *                                                                                                                                manifest 배열 (없거나 손상 시 null)
     */
    public function getManifest(): ?array
    {
        $raw = $this->storage->get(self::CATEGORY, self::LIVE_DIR.'/'.self::MANIFEST_FILE);
        if ($raw === null || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            Log::warning('[SEO] Sitemap manifest 손상', ['path' => self::LIVE_DIR.'/'.self::MANIFEST_FILE]);

            return null;
        }

        return $decoded;
    }

    /**
     * sitemapindex 를 스트리밍 응답으로 반환합니다.
     *
     * @return StreamedResponse|null 인덱스 스트림 (세트가 없으면 null)
     */
    public function indexResponse(): ?StreamedResponse
    {
        if (! $this->exists()) {
            return null;
        }

        return $this->storage->response(
            self::CATEGORY,
            self::LIVE_DIR.'/'.self::INDEX_FILE,
            self::INDEX_FILE,
            ['Content-Type' => 'application/xml'],
        );
    }

    /**
     * 자식 sitemap 을 스트리밍 응답으로 반환합니다.
     *
     * 실제 저장된 파일명은 manifest 에서 조회하므로, 생성 이후 gzip 설정이
     * 바뀌어도 커밋된 세트를 그대로 서빙합니다.
     *
     * @param  int  $n  자식 번호 (1부터)
     * @return StreamedResponse|null 자식 스트림 (없으면 null)
     */
    public function childResponse(int $n): ?StreamedResponse
    {
        $filename = $this->childFile($n);
        if ($filename === null) {
            return null;
        }

        $headers = ['Content-Type' => 'application/xml'];
        if (str_ends_with($filename, '.gz')) {
            $headers['Content-Encoding'] = 'gzip';
        }

        return $this->storage->response(
            self::CATEGORY,
            self::LIVE_DIR.'/'.$filename,
            $filename,
            $headers,
        );
    }

    /**
     * 커밋된 sitemap 의 생성 시각을 반환합니다.
     *
     * @return string|null ISO8601 생성 시각 (세트가 없으면 null)
     */
    public function lastGeneratedAt(): ?string
    {
        $manifest = $this->getManifest();

        return $manifest['generated_at'] ?? null;
    }

    /**
     * 자식 번호에 해당하는 실제 파일명을 반환합니다.
     *
     * @param  int  $n  자식 번호 (1부터)
     * @return string|null 파일명 (존재하지 않으면 null)
     */
    private function childFile(int $n): ?string
    {
        if ($n < 1) {
            return null;
        }

        $manifest = $this->getManifest();
        $children = $manifest['children'] ?? [];

        $filename = $children[$n - 1]['file'] ?? null;
        if (! is_string($filename) || $filename === '') {
            return null;
        }

        if (! $this->storage->exists(self::CATEGORY, self::LIVE_DIR.'/'.$filename)) {
            return null;
        }

        return $filename;
    }
}
