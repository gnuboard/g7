<?php

namespace App\Repositories\Analytics;

use App\Contracts\Repositories\Analytics\ActionNameRepositoryInterface;
use App\Models\Analytics\AnalyticsActionName;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * 방문자 통계 이름 사전 Repository
 */
class ActionNameRepository implements ActionNameRepositoryInterface
{
    /**
     * 종류와 이름으로 사전 항목을 찾고 없으면 만듭니다.
     *
     * 같은 페이지를 여러 방문자가 동시에 열면 두 요청이 같은 이름을 함께 만들려
     * 합니다. 하나는 유니크 제약에 걸리는데, 그 예외를 그대로 두면 비콘 하나가
     * 통째로 실패 로그가 됩니다. 제약 위반은 「누군가 방금 만들었다」는 뜻이므로
     * 다시 조회해서 그 행을 씁니다.
     *
     * @param  string  $type  이름 종류 (url, title, download, outlink, search)
     * @param  string  $name  정규화된 이름
     * @param  int|null  $urlPrefix  URL 접두 (0 http, 1 http+www, 2 https, 3 https+www)
     * @return AnalyticsActionName 사전 항목
     */
    public function firstOrCreateByHash(string $type, string $name, ?int $urlPrefix = null): AnalyticsActionName
    {
        $hash = $this->hash($type, $name);

        $existing = AnalyticsActionName::query()
            ->where('type', $type)
            ->where('name_hash', $hash)
            ->first();

        if ($existing) {
            return $existing;
        }

        try {
            return AnalyticsActionName::query()->create([
                'type' => $type,
                'name' => $name,
                'name_hash' => $hash,
                'url_prefix' => $urlPrefix,
                'created_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException $e) {
            $raced = AnalyticsActionName::query()
                ->where('type', $type)
                ->where('name_hash', $hash)
                ->first();

            if ($raced) {
                return $raced;
            }

            throw $e;
        }
    }

    /**
     * ID 목록으로 사전 항목을 조회합니다.
     *
     * @param  array<int, int>  $ids  사전 항목 ID 목록
     * @return array<int, AnalyticsActionName> ID 를 키로 하는 사전 항목 배열
     */
    public function findManyById(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));

        if ($ids === []) {
            return [];
        }

        return AnalyticsActionName::query()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id')
            ->all();
    }

    /**
     * 사전을 전부 지웁니다.
     *
     * @param  int  $chunkSize  한 번에 지울 행 수
     * @return int 지운 행 수
     */
    public function deleteAllInBatches(int $chunkSize = 1000): int
    {
        $deleted = 0;

        do {
            $count = AnalyticsActionName::query()->limit(max(1, $chunkSize))->delete();
            $deleted += $count;
        } while ($count > 0);

        return $deleted;
    }

    /**
     * 종류와 이름으로 사전 해시를 만듭니다.
     *
     * @param  string  $type  이름 종류
     * @param  string  $name  정규화된 이름
     * @return string 32자 해시
     */
    private function hash(string $type, string $name): string
    {
        return md5($type.'|'.$name);
    }
}
