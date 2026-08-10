<?php

namespace App\Http\Controllers\Concerns;

use App\Search\SearchIndexMaintenanceManager;

/**
 * 관리자 화면의 설치·업데이트 응답에 "검색 인덱스 재생성" 선택 결과를 싣는 트레이트.
 *
 * 재생성 비용은 엔진마다 다르지만(테이블 잠금 / 전체 재색인) 어느 쪽이든 운영 중인
 * 사이트에 영향을 줍니다. 그래서 **운영자가 화면에서 체크했을 때만** 수행하고,
 * 체크하지 않았을 때는 점검 결과만 실어 보내 화면이 "지금 색인이 누락된 인덱스가 있다"
 * 는 사실을 알릴 수 있게 합니다 — 색인이 비면 검색이 오류 없이 0건을 돌려주므로
 * 알려 주지 않으면 운영자가 알 방법이 없습니다.
 */
trait RebuildsSearchIndexOnDemand
{
    /**
     * 요청이 있었으면 재생성하고, 없었으면 점검 결과만 반환합니다.
     *
     * @param  bool  $requested  재생성 요청 여부
     * @return array<string, mixed>|null 응답에 실을 페이로드 (점검 불가 엔진이면 null)
     */
    protected function rebuildSearchIndexIfRequested(bool $requested): ?array
    {
        $manager = app(SearchIndexMaintenanceManager::class);

        // 점검을 제공하지 않는 엔진에서는 실을 정보가 없다 (검색 자체는 정상 동작)
        if (! $manager->hasMaintainer() || $manager->unavailableReason() !== null) {
            return null;
        }

        if ($requested) {
            return ['rebuilt' => true] + $manager->repairStale()->toArray();
        }

        $stale = array_values(array_filter($manager->inspect(), fn ($health) => $health->needsRebuild()));

        return [
            'rebuilt' => false,
            'driver' => $manager->driver(),
            'stale' => array_map(fn ($health) => $health->identifier, $stale),
            'stale_count' => count($stale),
        ];
    }
}
