<?php

namespace App\Repositories;

use App\Contracts\Repositories\SitemapUrlRepositoryInterface;
use App\Models\SitemapUrl;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 사이트맵 URL 저장소 구현체
 *
 * sitemap_urls 테이블을 통해 리소스 단위 증분 갱신과 스트리밍 조회를 수행합니다.
 * loc 은 2048자라 unique 인덱스에 직접 넣지 못하므로 sha256 해시(loc_hash)를
 * identity 로 사용합니다. lastmod 는 삽입 전 타임스탬프 문자열로 정규화합니다.
 */
class SitemapUrlRepository implements SitemapUrlRepositoryInterface
{
    /**
     * 청크 삽입 크기 (전체 재생성 시 유계 메모리 보장)
     */
    private const INSERT_CHUNK = 1000;

    /**
     * 스트리밍 조회 청크 크기 (lazyById 키셋 페이징)
     */
    private const STREAM_CHUNK = 1000;

    /**
     * {@inheritDoc}
     */
    public function upsertForResource(string $type, string $id, array $entries): void
    {
        DB::transaction(function () use ($type, $id, $entries): void {
            SitemapUrl::query()
                ->where('resource_type', $type)
                ->where('resource_id', $id)
                ->delete();

            $now = now();
            $rows = [];
            foreach ($entries as $entry) {
                $rows[] = $this->toRow(
                    $type,
                    $id,
                    (string) ($entry['contributor'] ?? ''),
                    $entry,
                    $now,
                );
            }

            if ($rows !== []) {
                SitemapUrl::insert($rows);
            }
        });
    }

    /**
     * {@inheritDoc}
     */
    public function removeForResource(string $type, string $id): void
    {
        SitemapUrl::query()
            ->where('resource_type', $type)
            ->where('resource_id', $id)
            ->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function streamVisible(?string $contributor = null): iterable
    {
        $query = SitemapUrl::query()->where('is_visible', true);

        if ($contributor !== null) {
            $query->where('contributor', $contributor);
        }

        // lazyById 는 id 키셋 페이징이라 결과셋 전체를 메모리/드라이버 버퍼에 적재하지 않습니다.
        return $query->lazyById(self::STREAM_CHUNK);
    }

    /**
     * {@inheritDoc}
     */
    public function countVisible(): int
    {
        return SitemapUrl::query()->where('is_visible', true)->count();
    }

    /**
     * {@inheritDoc}
     */
    public function replaceAllForContributor(string $contributor, iterable $entries): int
    {
        // 기여자 스코프 전량 제거 후 청크 삽입. 대용량(수백만) 삽입을 단일 트랜잭션으로
        // 감싸면 락/메모리 부담이 크므로, 삭제 후 청크별 삽입으로 유계 메모리를 유지합니다.
        SitemapUrl::query()->where('contributor', $contributor)->delete();

        $now = now();
        $batch = [];
        $inserted = 0;
        foreach ($entries as $entry) {
            $batch[] = $this->toRow(
                (string) ($entry['resource_type'] ?? 'unknown'),
                isset($entry['resource_id']) && $entry['resource_id'] !== null
                    ? (string) $entry['resource_id']
                    : null,
                $contributor,
                $entry,
                $now,
            );

            if (count($batch) >= self::INSERT_CHUNK) {
                SitemapUrl::insert($batch);
                $inserted += count($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            SitemapUrl::insert($batch);
            $inserted += count($batch);
        }

        return $inserted;
    }

    /**
     * URL 항목을 sitemap_urls 삽입용 행으로 변환합니다.
     *
     * @param  string  $type  리소스 유형
     * @param  string|null  $id  리소스 PK (문자열, 없으면 null)
     * @param  string  $contributor  기여자 식별자
     * @param  array<string, mixed>  $entry  URL 항목 (loc/lastmod/changefreq/priority)
     * @param  Carbon  $now  타임스탬프 기준값
     * @return array<string, mixed> 삽입용 행
     */
    private function toRow(string $type, ?string $id, string $contributor, array $entry, Carbon $now): array
    {
        $loc = (string) ($entry['loc'] ?? '');

        return [
            'resource_type' => $type,
            'resource_id' => $id,
            'loc' => $loc,
            'loc_hash' => hash('sha256', $loc),
            'lastmod' => $this->normalizeLastmod($entry['lastmod'] ?? null),
            'changefreq' => $entry['changefreq'] ?? null,
            'priority' => $entry['priority'] ?? null,
            'contributor' => $contributor,
            'is_visible' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * lastmod 값을 DB 저장용 타임스탬프 문자열로 정규화합니다.
     *
     * insert() 는 모델 캐스팅을 우회하므로 W3C 문자열/Carbon 을 직접 파싱합니다.
     *
     * @param  mixed  $lastmod  원본 lastmod (문자열/Carbon/null)
     * @return string|null 정규화된 'Y-m-d H:i:s' 또는 null
     */
    private function normalizeLastmod(mixed $lastmod): ?string
    {
        if ($lastmod === null || $lastmod === '') {
            return null;
        }

        try {
            return Carbon::parse($lastmod)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }
}
