<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftPage\V1_1_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 기존 페이지의 본문 첫 내부 이미지 URL 을 content_thumbnail_url 에 백필합니다.
 *
 * 1.1.0 이전에 작성된 페이지는 저장 시점 추출(모델 saving 이벤트)을 거치지 않아
 * 캐시가 비어 있다 — 백필해야 기존 페이지의 og:image 가 채워진다.
 *
 * 멱등: whereNull 필터가 이미 채워진 행을 제외한다. text 모드 본문은 이스케이프
 * 렌더(이미지 미표시)이므로 html 모드만 대상이다.
 *
 * V-1 안전: 추출·정규화·다국어 JSON 순회 로직은 코어 HtmlImageExtractor /
 * TrustedScriptHosts / 모델 saving 콜백의 1.1.0 시점 동결 사본이다
 * (버전 스냅샷 규약 — 코어/모듈 클래스 미참조).
 */
class BackfillPageContentThumbnails implements DataMigration
{
    private const PAGES_TABLE = 'pages';

    /**
     * 캐시 컬럼 상한 (string(1000) 정합 — 1.1.0 동결).
     */
    private const MAX_URL_LENGTH = 1000;

    public function name(): string
    {
        return 'BackfillPageContentThumbnails';
    }

    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable(self::PAGES_TABLE)
            || ! Schema::hasColumn(self::PAGES_TABLE, 'content_thumbnail_url')) {
            $context->logger->warning('[page:1.1.0] pages.content_thumbnail_url 미존재 — 스킵');

            return;
        }

        $filled = 0;
        $noCandidate = 0;

        // text 모드 본문은 이스케이프 렌더(이미지 미표시)이므로 html 모드만 대상
        DB::table(self::PAGES_TABLE)
            ->whereNull('content_thumbnail_url')
            ->where('content_mode', 'html')
            ->whereNotNull('content')
            ->where('content', '!=', '')
            ->orderBy('id')
            ->select('id', 'content')
            ->chunkById(200, function ($pages) use (&$filled, &$noCandidate) {
                foreach ($pages as $page) {
                    $url = $this->firstInternalFromContent((string) $page->content);

                    if ($url === null) {
                        $noCandidate++;

                        continue;
                    }

                    DB::table(self::PAGES_TABLE)
                        ->where('id', $page->id)
                        ->update(['content_thumbnail_url' => $url]);

                    $filled++;
                }
            });

        $context->logger->info("[page:1.1.0] 본문 썸네일 백필: 채움 {$filled} / 후보 없음 {$noCandidate}");
    }

    /**
     * 다국어 JSON(또는 레거시 평문) 본문에서 첫 내부 이미지 URL 을 추출합니다 (동결 사본).
     *
     * @param  string  $rawContent  DB 원시 content 값
     * @return string|null 첫 내부 이미지 URL (없으면 null)
     */
    private function firstInternalFromContent(string $rawContent): ?string
    {
        if (trim($rawContent) === '') {
            return null;
        }

        $decoded = json_decode($rawContent, true);

        $htmls = is_array($decoded)
            ? array_values(array_filter(
                [$decoded[config('app.locale')] ?? null, ...array_values($decoded)],
                static fn ($html) => is_string($html) && $html !== ''
            ))
            : [$rawContent];

        foreach ($htmls as $html) {
            $url = $this->firstInternalImageUrl($html);

            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    /**
     * 단일 HTML 에서 첫 내부 이미지 URL 을 추출합니다 (동결 사본).
     *
     * @param  string  $html  본문 HTML
     * @return string|null 첫 내부 이미지 URL
     */
    private function firstInternalImageUrl(string $html): ?string
    {
        foreach ($this->imageSources($html) as $src) {
            $resolved = $this->resolveInternal($src);

            if ($resolved === null || mb_strlen($resolved) > self::MAX_URL_LENGTH) {
                continue;
            }

            return $resolved;
        }

        return null;
    }

    /**
     * HTML 에서 모든 img src 를 문서 순서로 수집합니다 (동결 사본).
     *
     * @param  string  $html  본문 HTML
     * @return array<int, string> src 목록
     */
    private function imageSources(string $html): array
    {
        if (trim($html) === '' || ! class_exists(\DOMDocument::class)) {
            return [];
        }

        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadHTML(
                '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><div>'
                    .$html.'</div></body></html>',
                LIBXML_NOERROR | LIBXML_NOWARNING
            );

            if (! $loaded) {
                return [];
            }

            $sources = [];

            foreach ($document->getElementsByTagName('img') as $img) {
                if (! $img instanceof \DOMElement) {
                    continue;
                }

                $src = trim($img->getAttribute('src'));

                if ($src !== '') {
                    $sources[] = $src;
                }
            }

            return $sources;
        } catch (\Throwable) {
            return [];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * src 후보를 내부 URL 로 해석합니다 (동결 사본 — 정규화 규칙 포함).
     *
     * @param  string  $src  img src 속성값
     * @return string|null 내부 URL (외부/비허용 스킴이면 null)
     */
    private function resolveInternal(string $src): ?string
    {
        $trimmed = trim($src);

        if ($trimmed === '') {
            return null;
        }

        $normalized = $this->normalizeForOriginCheck($trimmed);

        if ($normalized === '' || str_starts_with($normalized, '//')) {
            return null;
        }

        if (str_starts_with($normalized, '/')) {
            return $normalized;
        }

        $scheme = parse_url($normalized, PHP_URL_SCHEME);

        if (is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true)) {
            $host = parse_url($normalized, PHP_URL_HOST);
            $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

            if (is_string($host) && is_string($appHost) && strcasecmp($host, $appHost) === 0) {
                $path = parse_url($normalized, PHP_URL_PATH);
                $query = parse_url($normalized, PHP_URL_QUERY);

                return (is_string($path) && $path !== '' ? $path : '/')
                    .(is_string($query) && $query !== '' ? '?'.$query : '');
            }
        }

        return null;
    }

    /**
     * origin 판정 전 브라우저 URL 파서 동형 정규화 (동결 사본).
     *
     * @param  string  $url  원본 URL
     * @return string 정규화된 URL
     */
    private function normalizeForOriginCheck(string $url): string
    {
        $stripped = str_replace(["\t", "\n", "\r"], '', $url);
        $slashed = str_replace('\\', '/', $stripped);

        return preg_replace('#^([a-z][a-z0-9+.\-]*:)?/{2,}#i', '$1//', $slashed) ?? $slashed;
    }
}
