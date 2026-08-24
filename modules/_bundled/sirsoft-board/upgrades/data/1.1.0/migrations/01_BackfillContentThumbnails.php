<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftBoard\V1_1_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 기존 게시글의 본문 첫 내부 이미지 URL 을 content_thumbnail_url 에 백필합니다.
 *
 * 1.1.0 이전에 작성된 게시글은 저장 시점 추출(모델 saving 이벤트)을 거치지 않아
 * 캐시가 비어 있다 — 이미지 첨부 없이 본문에만 이미지를 넣은 글이 목록 썸네일
 * 폴백의 수혜를 받으려면 이 백필이 필요하다 (공개 이슈 #22 제보 시나리오).
 *
 * 멱등: whereNull 필터가 이미 채워진 행을 제외한다. "후보 없음" 행은 null 유지라
 * 재실행 시 재스캔되지만 결과는 불변이다.
 *
 * 훅 미적용: 과거 데이터 일괄 처리에 확장 개입은 불요하다 — 이후 글 수정 시
 * 모델 saving 이벤트가 필터 훅 포함으로 재계산한다.
 *
 * V-1 안전: 추출·정규화 로직은 코어 HtmlImageExtractor / TrustedScriptHosts 의
 * 1.1.0 시점 동결 사본이다(버전 스냅샷 규약 — 코어/모듈 클래스 미참조).
 */
class BackfillContentThumbnails implements DataMigration
{
    private const POSTS_TABLE = 'board_posts';

    /**
     * 캐시 컬럼 상한 (board_posts.content_thumbnail_url string(1000) 정합 — 1.1.0 동결).
     */
    private const MAX_URL_LENGTH = 1000;

    public function name(): string
    {
        return 'BackfillContentThumbnails';
    }

    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable(self::POSTS_TABLE)
            || ! Schema::hasColumn(self::POSTS_TABLE, 'content_thumbnail_url')) {
            $context->logger->warning('[board:1.1.0] board_posts.content_thumbnail_url 미존재 — 스킵');

            return;
        }

        $filled = 0;
        $noCandidate = 0;

        // text 모드 본문은 이스케이프 렌더(이미지 미표시)이므로 html 모드만 대상
        DB::table(self::POSTS_TABLE)
            ->whereNull('content_thumbnail_url')
            ->where('content_mode', 'html')
            ->whereNotNull('content')
            ->where('content', '!=', '')
            ->orderBy('id')
            ->select('id', 'content')
            ->chunkById(200, function ($posts) use (&$filled, &$noCandidate) {
                foreach ($posts as $post) {
                    $url = $this->firstInternalImageUrl((string) $post->content);

                    if ($url === null) {
                        $noCandidate++;

                        continue;
                    }

                    DB::table(self::POSTS_TABLE)
                        ->where('id', $post->id)
                        ->update(['content_thumbnail_url' => $url]);

                    $filled++;
                }
            });

        $context->logger->info("[board:1.1.0] 본문 썸네일 백필: 채움 {$filled} / 후보 없음 {$noCandidate}");
    }

    /**
     * 본문 HTML 에서 첫 번째 내부 이미지 URL 을 추출합니다 (1.1.0 동결 사본).
     *
     * @param  string  $html  본문 HTML
     * @return string|null 첫 내부 이미지 URL (없으면 null)
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
