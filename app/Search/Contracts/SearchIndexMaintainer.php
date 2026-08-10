<?php

namespace App\Search\Contracts;

use App\Search\DTO\SearchIndexHealth;

/**
 * 검색 인덱스 점검·재생성 계약
 *
 * Scout 엔진마다 "인덱스" 의 실체가 다릅니다 — MySQL FULLTEXT 는 테이블에 붙은 인덱스,
 * Meilisearch/Elasticsearch 는 외부 서버의 인덱스입니다. 그래서 코어는 **무엇을 어떻게
 * 점검·재생성할지 모르는 채** 이 계약만 호출하고, 판정 방법은 각 엔진 제공자가 정합니다.
 *
 * 확장(모듈/플러그인)이 자체 검색 엔진을 등록할 때 이 계약의 구현을 함께 등록하면
 * 관리자 화면·커맨드·업데이트 절차가 코어 수정 없이 그 엔진을 다룹니다.
 * 등록은 `core.search.index_maintainers` 필터 훅에 `드라이버명 => 구현 클래스` 로 합니다.
 *
 * ```php
 * HookManager::addFilter('core.search.index_maintainers', function (array $maintainers) {
 *     $maintainers['meilisearch'] = MeilisearchIndexMaintainer::class;
 *
 *     return $maintainers;
 * });
 * ```
 *
 * 구현하지 않은 엔진은 점검 대상에서 제외될 뿐 검색 자체는 정상 동작합니다.
 */
interface SearchIndexMaintainer
{
    /**
     * 이 유지보수기가 담당하는 Scout 드라이버명을 반환합니다.
     *
     * `config('scout.driver')` 값과 대조해 활성 엔진의 유지보수기를 고릅니다.
     *
     * @return string 드라이버명 (예: mysql-fulltext)
     */
    public function driver(): string;

    /**
     * 현재 환경에서 점검을 수행할 수 있는지 반환합니다.
     *
     * 예) FULLTEXT 미지원 DBMS, 외부 검색 서버 미연결 등에서 false.
     *
     * @return bool 점검 가능 여부
     */
    public function isAvailable(): bool;

    /**
     * 점검 불가 사유를 반환합니다.
     *
     * `isAvailable()` 이 false 일 때 운영자에게 보여 줄 문장입니다.
     * 사유를 남기지 않으면 "점검 대상 0" 과 "점검할 수 없었음" 이 구분되지 않습니다.
     *
     * @return string|null 사유 (사용 가능하면 null)
     */
    public function unavailableReason(): ?string;

    /**
     * 관리 중인 인덱스의 건강도를 판정합니다.
     *
     * @param  array<string, mixed>  $filters  엔진별 필터 (예: ['table' => 'pages'])
     * @return array<int, SearchIndexHealth> 인덱스별 판정 결과
     */
    public function inspect(array $filters = []): array;

    /**
     * 재생성이 필요하다고 판정된 인덱스를 재생성합니다.
     *
     * 재생성은 비용이 크고 서비스에 영향을 줄 수 있으므로, 호출자는 반드시
     * 운영자의 명시적 선택을 받은 뒤에만 호출합니다.
     *
     * @param  SearchIndexHealth  $health  재생성 대상 판정 결과
     * @return void
     *
     * @throws \Throwable 재생성 실패 시
     */
    public function rebuild(SearchIndexHealth $health): void;
}
