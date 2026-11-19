<?php

namespace Modules\Sirsoft\Ecommerce\Contracts\Catalog;

use Modules\Sirsoft\Ecommerce\Contracts\Catalog\Data\CategorySummary;

/**
 * 상품 카테고리 조회 공개 계약
 *
 * 다른 확장이 카테고리를 고르거나 이름·주소를 보일 때 쓴다. 서비스 컨테이너로 주입받는다(`app(CategoryDirectory::class)`).
 *
 * @since 1.4.0
 */
interface CategoryDirectory
{
    /** 계약 판 — 메서드 추가는 판을 올리지 않는다(구현은 이커머스만) */
    public const VERSION = 1;

    /**
     * slug 로 카테고리를 찾습니다.
     *
     * @param  string  $slug  카테고리 slug
     * @return CategorySummary|null 없으면 null
     */
    public function findBySlug(string $slug): ?CategorySummary;

    /**
     * id 로 카테고리를 찾습니다.
     *
     * @param  int  $id  카테고리 id
     * @return CategorySummary|null 없으면 null
     */
    public function findById(int $id): ?CategorySummary;

    /**
     * id 묶음으로 카테고리를 찾습니다. 없는 id 는 결과에서 빠집니다.
     *
     * @param  array<int, int>  $ids  카테고리 id 목록
     * @return array<int, CategorySummary> id => 요약
     */
    public function findByIds(array $ids): array;

    /**
     * slug 묶음의 경로 이름(최상위부터 이은 이름)을 돌려줍니다.
     *
     * @param  array<int, string>  $slugs  카테고리 slug 목록
     * @return array<string, string> slug => 경로 이름
     */
    public function pathLabelsBySlugs(array $slugs): array;

    /**
     * 카테고리를 검색합니다 — 이름(현재 언어)·slug 부분 일치(대소문자 무시), 깊이·정렬 순서 순.
     *
     * @param  string  $keyword  검색어(빈 값이면 앞에서부터)
     * @param  int  $limit  최대 건수
     * @return array<int, CategorySummary> 요약 목록
     */
    public function search(string $keyword, int $limit): array;
}
