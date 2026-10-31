<?php

namespace App\Contracts\Repositories\Analytics;

use App\Models\Analytics\AnalyticsActionName;

/**
 * 방문자 통계 이름 사전 Repository 인터페이스
 */
interface ActionNameRepositoryInterface
{
    /**
     * 종류와 이름으로 사전 항목을 찾고 없으면 만듭니다.
     *
     * 같은 이름이 동시에 두 요청에서 들어오면 유니크 제약에 걸립니다. 그 예외를
     * 밖으로 던지면 비콘 하나가 통째로 실패 로그가 되므로, 구현은 제약 위반을
     * 잡아 다시 조회하는 것까지 책임집니다.
     *
     * @param  string  $type  이름 종류 (url, title, download, outlink, search)
     * @param  string  $name  정규화된 이름
     * @param  int|null  $urlPrefix  URL 접두 (0 http, 1 http+www, 2 https, 3 https+www)
     * @return AnalyticsActionName 사전 항목
     */
    public function firstOrCreateByHash(string $type, string $name, ?int $urlPrefix = null): AnalyticsActionName;

    /**
     * ID 목록으로 사전 항목을 조회합니다.
     *
     * @param  array<int, int>  $ids  사전 항목 ID 목록
     * @return array<int, AnalyticsActionName> ID 를 키로 하는 사전 항목 배열
     */
    public function findManyById(array $ids): array;

    /**
     * 사전을 전부 지웁니다.
     *
     * 보존 정리는 사전을 건드리지 않습니다 — 집계가 `action_name_id` 로 이름을
     * 참조하므로 원장이 지워져도 사전은 남아야 합니다. 전체 삭제에서만 부릅니다.
     *
     * @param  int  $chunkSize  한 번에 지울 행 수
     * @return int 지운 행 수
     */
    public function deleteAllInBatches(int $chunkSize = 1000): int;
}
