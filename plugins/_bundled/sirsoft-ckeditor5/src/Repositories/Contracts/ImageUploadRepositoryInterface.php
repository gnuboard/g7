<?php

namespace Plugins\Sirsoft\Ckeditor5\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Plugins\Sirsoft\Ckeditor5\Models\Ckeditor5ImageUpload;

/**
 * CKEditor5 이미지 업로드 Repository 인터페이스
 */
interface ImageUploadRepositoryInterface
{
    /**
     * 해시로 이미지 조회
     *
     * @param  string  $hash  이미지 해시 (12자)
     * @return Ckeditor5ImageUpload|null
     */
    public function findByHash(string $hash): ?Ckeditor5ImageUpload;

    /**
     * 이미지 기록 생성
     *
     * @param  array  $data  이미지 데이터
     * @return Ckeditor5ImageUpload
     */
    public function create(array $data): Ckeditor5ImageUpload;

    /**
     * 기준 시각보다 오래된 업로드를 오래된 순으로 조회합니다.
     *
     * 정리 후보 조회 전용이라 판정·삭제에 필요한 컬럼만 읽습니다.
     *
     * @param  Carbon  $threshold  기준 시각 (이 시각 이전 업로드가 대상)
     * @param  int  $limit  최대 조회 건수
     * @return Collection<int, Ckeditor5ImageUpload> 업로드 목록 (created_at 오름차순)
     */
    public function findOlderThan(Carbon $threshold, int $limit): Collection;

    /**
     * 기준 시각보다 오래된 업로드 건수를 반환합니다.
     *
     * @param  Carbon  $threshold  기준 시각
     * @return int 건수
     */
    public function countOlderThan(Carbon $threshold): int;

    /**
     * 관리 화면용 업로드 목록을 페이지네이션 조회합니다.
     *
     * @param  array  $filters  필터 (search / date_from / date_to / sort_by / sort_order)
     * @param  int  $perPage  페이지 크기
     * @return LengthAwarePaginator 페이지네이터
     */
    public function paginateForAdmin(array $filters, int $perPage): LengthAwarePaginator;

    /**
     * 참조 판정 스캔 윈도우를 조회합니다 (최신순 상한).
     *
     * 참조 여부는 저장 컬럼이 아니라 매 조회 시 판정하므로, 참조 상태 필터를 쓸 때는
     * 전수가 아니라 최신 N 건만 훑습니다. 상한을 넘긴 사실은 응답 메타로 알립니다.
     *
     * @param  array  $filters  필터 (search / date_from / date_to)
     * @param  int  $limit  스캔 윈도우 상한
     * @return Collection<int, Ckeditor5ImageUpload> 업로드 목록 (created_at 내림차순)
     */
    public function findScanWindow(array $filters, int $limit): Collection;

    /**
     * ID 목록으로 업로드를 조회합니다.
     *
     * @param  array<int, int>  $ids  업로드 ID 목록
     * @return Collection<int, Ckeditor5ImageUpload> 업로드 목록
     */
    public function findManyByIds(array $ids): Collection;

    /**
     * ID 로 업로드 단건을 조회합니다.
     *
     * @param  int  $id  업로드 ID
     * @return Ckeditor5ImageUpload|null
     */
    public function findById(int $id): ?Ckeditor5ImageUpload;

    /**
     * 업로드 기록을 삭제합니다.
     *
     * @param  Ckeditor5ImageUpload  $upload  업로드 기록
     * @return bool 삭제 성공 여부
     */
    public function delete(Ckeditor5ImageUpload $upload): bool;
}
