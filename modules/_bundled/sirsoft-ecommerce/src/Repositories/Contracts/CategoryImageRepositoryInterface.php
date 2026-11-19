<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\CategoryImage;

/**
 * 카테고리 이미지 Repository 인터페이스
 */
interface CategoryImageRepositoryInterface
{
    /**
     * ID로 이미지 조회
     *
     * @param  int  $id  이미지 ID
     * @return CategoryImage|null
     */
    public function findById(int $id): ?CategoryImage;

    /**
     * 해시로 이미지 조회
     *
     * @param  string  $hash  이미지 해시
     * @return CategoryImage|null
     */
    public function findByHash(string $hash): ?CategoryImage;

    /**
     * 카테고리의 모든 이미지 조회
     *
     * @param  int  $categoryId  카테고리 ID
     * @param  string|null  $collection  컬렉션 필터
     * @return Collection
     */
    public function getByCategoryId(int $categoryId, ?string $collection = null): Collection;

    /**
     * temp_key로 임시 이미지 조회
     *
     * @param  string  $tempKey  임시 키
     * @param  string|null  $collection  컬렉션 필터
     * @return Collection
     */
    public function getByTempKey(string $tempKey, ?string $collection = null): Collection;

    /**
     * 카테고리 이미지 생성
     *
     * @param  array  $data  이미지 데이터
     * @return CategoryImage
     */
    public function create(array $data): CategoryImage;

    /**
     * 이미지 수정
     *
     * @param  int  $id  이미지 ID
     * @param  array  $data  수정할 데이터
     * @return CategoryImage
     */
    public function update(int $id, array $data): CategoryImage;

    /**
     * 이미지 삭제
     *
     * @param  int  $id  이미지 ID
     * @return bool
     */
    public function delete(int $id): bool;

    /**
     * 카테고리에 속한 이미지 행을 일괄 삭제
     *
     * 물리 파일 삭제는 호출한 Service 가 담당합니다 (행마다 disk 가 다를 수 있음).
     *
     * @param  int  $categoryId  카테고리 ID
     * @return int 삭제된 행 수
     */
    public function deleteByCategoryId(int $categoryId): int;

    /**
     * 카테고리에 연결되지 않은 채 방치된 임시 이미지를 오래된 순으로 조회
     *
     * 새 카테고리 폼에서 업로드만 하고 저장 없이 닫으면 `temp_key` 가 남은 채
     * `category_id` 가 비어 있는 행과 그 파일이 영구 잔존합니다. 그 회수 대상을 찾습니다.
     * 폼에서 뺀 임시 이미지는 소프트 삭제 행으로 남으므로 그 행도 함께 대상입니다.
     *
     * @param  Carbon  $threshold  기준 시각 (이 시각 이전 업로드가 대상)
     * @param  int  $limit  최대 조회 건수
     * @return Collection<int, CategoryImage> 임시 이미지 목록 (created_at 오름차순, 소프트 삭제 행 포함)
     */
    public function findStaleTempImages(Carbon $threshold, int $limit): Collection;

    /**
     * 이미지 행을 영구 삭제 (소프트 삭제 행 포함)
     *
     * 물리 파일 삭제는 호출한 Service 가 담당합니다.
     *
     * @param  int  $id  이미지 ID
     * @return bool 삭제 여부
     */
    public function forceDeleteById(int $id): bool;

    /**
     * 임시 이미지를 카테고리에 연결
     *
     * @param  string  $tempKey  임시 키
     * @param  int  $categoryId  카테고리 ID
     * @return int 연결된 이미지 수
     */
    public function linkTempImages(string $tempKey, int $categoryId): int;

    /**
     * 카테고리의 최대 sort_order 조회
     *
     * @param  int  $categoryId  카테고리 ID
     * @param  string  $collection  컬렉션명
     * @return int
     */
    public function getMaxSortOrder(int $categoryId, string $collection): int;

    /**
     * temp_key의 최대 sort_order 조회
     *
     * @param  string  $tempKey  임시 키
     * @param  string  $collection  컬렉션명
     * @return int
     */
    public function getMaxSortOrderByTempKey(string $tempKey, string $collection): int;

    /**
     * 순서 변경
     *
     * @param  array<int, int>  $orders  이미지 ID => sort_order 매핑
     * @return bool
     */
    public function reorder(array $orders): bool;

    /**
     * 래스터 이미지 MIME 카테고리 이미지 행 수 (썸네일 미리 만들기 진행률)
     * 임시 업로드(카테고리 미연결) 행은 뺀다 — 저장 전 폼의 미리보기다.
     *
     * @param  list<string>  $mimeTypes  MIME 목록
     * @return int 행 수
     */
    public function countByMimeTypes(array $mimeTypes): int;

    /**
     * 래스터 이미지 MIME 카테고리 이미지 행을 id 순으로 나눠 조회합니다 (썸네일 미리 만들기 — 커서는 마지막 id).
     *
     * @param  list<string>  $mimeTypes  MIME 목록
     * @param  int  $afterId  이 id 보다 큰 행부터
     * @param  int  $limit  최대 행 수
     * @return Collection<int, CategoryImage> 행 (id·disk·path·mime_type·width·height)
     */
    public function chunkByMimeTypes(array $mimeTypes, int $afterId, int $limit): Collection;
}
