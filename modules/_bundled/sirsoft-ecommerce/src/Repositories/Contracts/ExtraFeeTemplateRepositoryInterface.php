<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\ExtraFeeTemplate;

/**
 * 추가배송비 템플릿 Repository 인터페이스
 */
interface ExtraFeeTemplateRepositoryInterface
{
    /**
     * ID로 템플릿 조회
     *
     * @param int $id 템플릿 ID
     * @return ExtraFeeTemplate|null
     */
    public function find(int $id): ?ExtraFeeTemplate;

    /**
     * 우편번호로 템플릿 조회
     *
     * @param string $zipcode 우편번호
     * @return ExtraFeeTemplate|null
     */
    public function findByZipcode(string $zipcode): ?ExtraFeeTemplate;

    /**
     * 필터링된 템플릿 목록 조회 (페이지네이션)
     *
     * @param array $filters 필터 조건
     * @param int $perPage 페이지당 개수
     * @return LengthAwarePaginator
     */
    public function getListWithFilters(array $filters, int $perPage = 20): LengthAwarePaginator;

    /**
     * 템플릿 생성
     *
     * @param array $data 템플릿 데이터
     * @return ExtraFeeTemplate
     */
    public function create(array $data): ExtraFeeTemplate;

    /**
     * 템플릿 수정
     *
     * @param ExtraFeeTemplate $template 템플릿 모델
     * @param array $data 수정 데이터
     * @return ExtraFeeTemplate
     */
    public function update(ExtraFeeTemplate $template, array $data): ExtraFeeTemplate;

    /**
     * 템플릿 삭제
     *
     * @param ExtraFeeTemplate $template 템플릿 모델
     * @return bool
     */
    public function delete(ExtraFeeTemplate $template): bool;

    /**
     * 템플릿 사용여부 토글
     *
     * @param ExtraFeeTemplate $template 템플릿 모델
     * @return ExtraFeeTemplate
     */
    public function toggleActive(ExtraFeeTemplate $template): ExtraFeeTemplate;

    /**
     * 템플릿 일괄 삭제
     *
     * @param array $ids 템플릿 ID 배열
     * @return int 삭제된 개수
     */
    public function bulkDelete(array $ids): int;

    /**
     * 템플릿 일괄 사용여부 변경
     *
     * @param array $ids 템플릿 ID 배열
     * @param bool $isActive 사용여부
     * @return int 변경된 개수
     */
    public function bulkToggleActive(array $ids, bool $isActive): int;

    /**
     * 활성화된 템플릿 전체 목록 조회
     *
     * @return Collection
     */
    public function getActiveList(): Collection;

    /**
     * 활성화된 템플릿을 배송정책용 JSON 배열로 반환
     *
     * @return array
     */
    public function getAllAsExtraFeeSettings(): array;

    /**
     * 일괄 등록 (CSV 또는 엑셀 업로드용)
     *
     * @param array $items 템플릿 데이터 배열 [{zipcode, fee, region?, description?}]
     * @return int 등록된 개수
     */
    public function bulkCreate(array $items): int;

    /**
     * 지역별 그룹화된 통계 조회
     *
     * @return array
     */
    public function getStatisticsByRegion(): array;

    /**
     * ID 목록으로 추가비용 템플릿을 조회하고 ID 키 맵으로 반환합니다 (bulk activity log lookup).
     *
     * @param  array<int, int>  $ids  템플릿 ID 목록
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function findByIdsKeyed(array $ids): \Illuminate\Database\Eloquent\Collection;
}
