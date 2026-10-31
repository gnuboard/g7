<?php

namespace App\Seo;

use App\Contracts\Extension\StorageInterface;
use App\Support\AssetUrl;
use App\Support\SiteUrl;
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
     * sitemapindex 의 <loc> 값이며, web 라우트와 대응합니다. 자산 URL 모드가
     * 확장자 없는 형태면 `/sitemap-{n}/xml` 을 쓴다 — 그 모드를 고른 사이트는
     * 정적 최적화 블록이 `.xml` 을 가로채는 서버라, 확장자 형태를 <loc> 에 적으면
     * 봇이 따라간 자식이 전부 404 가 되고 색인이 조용히 비어 버린다. gzip 여부는
     * manifest 가 정하므로 그 모드에서는 두 형태가 같은 주소로 모인다
     * (컨트롤러가 URL 접미사를 보지 않는다 — 7차 G3 라운드 B-6).
     *
     * 오리진·접두사는 사이트 주소(`app.url`) 기준 단일 지점(`SiteUrl::absolute()`)이 만든다 —
     * 요청 Host 로 폴백하지 않고, 접두사는 멱등으로 실린다. 여기만 `url()` 이면 색인의 자식
     * 주소는 요청 호스트(콘솔이면 `http://localhost`)를 싣고 자식 안의 항목은 설정 오리진을
     * 실어, 한 사이트맵 안에서 부모와 자식의 오리진이 갈린다 (16차 G3 라운드 B-1).
     *
     * @param  int  $n  자식 번호 (1부터)
     * @param  bool  $gzip  gzip 압축 여부
     * @return string 자식 sitemap 절대 URL
     */
    public static function childUrl(int $n, bool $gzip = false): string
    {
        // 보안: 요청 Host 가 아니라 사이트 주소 기준 — sitemapindex 가 위조 Host 로 굳지 않게 한다.
        if (AssetUrl::isExtensionless()) {
            return SiteUrl::absolute('/sitemap-'.$n.'/xml');
        }

        return SiteUrl::absolute('/'.self::childFilename($n, $gzip));
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
     * 캐시 계약(검증자·304)은 이 스트림에 실리지 않는다 — `indexInfo()` 가 돌려주는 메타를
     * 컨트롤러가 코어 단일 지점(`streamedFileResponse()`)에 넘겨 입힌다.
     *
     * @return StreamedResponse|null 인덱스 스트림 (세트가 없으면 null)
     */
    public function indexResponse(): ?StreamedResponse
    {
        return $this->indexInfo()['response'] ?? null;
    }

    /**
     * sitemapindex 서빙 정보를 반환합니다 (스트림 + 검증자 메타).
     *
     * 검증자는 **manifest** 에서 만든다 — 커밋 시각(`generated_at`)과 파일 이름이면 한 세트를
     * 결정적으로 가리키고, 파일 stat 이 없어 원격 디스크에서도 같다. 종전에는 두 sitemap 통로에
     * 검증자가 하나도 없어 봇이 매번 전문(최대 50MB)을 받아 갔다 — 정적 서빙(링크가 되는
     * 서버)은 같은 파일에 ETag·Last-Modified 를 붙이므로 링크 유무로 동작이 갈리는 상태였다
     * (14차 G3 라운드).
     *
     * @return array{response: StreamedResponse, etag_source: string, mime_type: string, last_modified: int|null}|null 서빙 정보 (세트가 없으면 null)
     */
    public function indexInfo(): ?array
    {
        if (! $this->exists()) {
            return null;
        }

        $response = $this->storage->response(
            self::CATEGORY,
            self::LIVE_DIR.'/'.self::INDEX_FILE,
            self::INDEX_FILE,
            ['Content-Type' => 'application/xml'],
        );

        if ($response === null) {
            return null;
        }

        return [
            'response' => $response,
            ...$this->serveMeta(self::INDEX_FILE),
        ];
    }

    /**
     * 자식 sitemap 을 스트리밍 응답으로 반환합니다.
     *
     * 실제 저장된 파일명은 manifest 에서 조회하므로, 생성 이후 gzip 설정이
     * 바뀌어도 커밋된 세트를 그대로 서빙합니다. 캐시 계약은 `childInfo()` 참조.
     *
     * @param  int  $n  자식 번호 (1부터)
     * @return StreamedResponse|null 자식 스트림 (없으면 null)
     */
    public function childResponse(int $n): ?StreamedResponse
    {
        return $this->childInfo($n)['response'] ?? null;
    }

    /**
     * 자식 sitemap 서빙 정보를 반환합니다 (스트림 + 검증자 메타).
     *
     * @param  int  $n  자식 번호 (1부터)
     * @return array{response: StreamedResponse, etag_source: string, mime_type: string, last_modified: int|null}|null 서빙 정보 (없으면 null)
     */
    public function childInfo(int $n): ?array
    {
        $filename = $this->childFile($n);
        if ($filename === null) {
            return null;
        }

        $headers = ['Content-Type' => 'application/xml'];
        if (str_ends_with($filename, '.gz')) {
            $headers['Content-Encoding'] = 'gzip';
        }

        $response = $this->storage->response(
            self::CATEGORY,
            self::LIVE_DIR.'/'.$filename,
            $filename,
            $headers,
        );

        if ($response === null) {
            return null;
        }

        return [
            'response' => $response,
            ...$this->serveMeta($filename),
        ];
    }

    /**
     * manifest 기준 서빙 검증자 메타를 만듭니다 — 인덱스·자식 두 통로가 같은 값을 쓴다.
     *
     * `.xml` 과 `.xml.gz` 는 다른 표현이므로 파일 이름이 ETag 소스에 들어간다.
     *
     * @param  string  $filename  서빙하는 파일 이름
     * @return array{etag_source: string, mime_type: string, last_modified: int|null}
     */
    private function serveMeta(string $filename): array
    {
        $generatedAt = $this->lastGeneratedAt();
        $lastModified = $generatedAt !== null ? strtotime($generatedAt) : false;

        return [
            'etag_source' => implode('|', ['sitemap', $filename, (string) $generatedAt]),
            'mime_type' => 'application/xml',
            'last_modified' => $lastModified === false ? null : $lastModified,
        ];
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
