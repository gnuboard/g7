<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\Export;

/**
 * 내보내기 작업 Repository 인터페이스
 */
interface ExportRepositoryInterface
{
    /**
     * 작업을 만듭니다.
     *
     * @param  array<string, mixed>  $data  작업 속성
     * @return Export 생성된 작업
     */
    public function create(array $data): Export;

    /**
     * ID 로 작업을 찾습니다(삭제된 작업 제외).
     *
     * @param  int  $id  작업 ID
     * @return Export|null 작업
     */
    public function findById(int $id): ?Export;

    /**
     * 작업 행을 이 트랜잭션이 끝날 때까지 잠급니다(같은 작업에 대한 동시 처리를 한 줄로 세운다).
     *
     * @param  int  $id  작업 ID
     * @return void
     */
    public function lockById(int $id): void;

    /**
     * 같은 요청자·같은 중복 방지 키의 작업을 찾습니다(삭제된 작업 포함 — 키는 한 번만 쓴다).
     *
     * @param  int  $userId  요청자 ID
     * @param  string  $idempotencyKey  중복 방지 키
     * @return Export|null 작업
     */
    public function findByIdempotencyKey(int $userId, string $idempotencyKey): ?Export;

    /**
     * 요청자의 대기·진행중 작업 수를 셉니다.
     *
     * @param  int  $userId  요청자 ID
     * @return int 활성 작업 수
     */
    public function countActiveByUser(int $userId): int;

    /**
     * 관리 화면 목록을 조회합니다. 보는 사람의 `exports.read` 범위(본인/전체)를 적용합니다.
     *
     * @param  array<string, mixed>  $filters  검증된 목록 필터
     * @param  int  $perPage  쪽당 개수
     * @param  User|null  $viewer  보는 사람
     * @return LengthAwarePaginator 작업 목록
     */
    public function paginateForAdmin(array $filters, int $perPage, ?User $viewer): LengthAwarePaginator;

    /**
     * 대기 상태인 작업을 진행중으로 원자적으로 바꿉니다.
     *
     * @param  int  $id  작업 ID
     * @param  CarbonInterface  $now  시작 시각
     * @return bool 이번 호출이 상태를 바꿨으면 true (이미 다른 워커가 집었거나 삭제됐으면 false)
     */
    public function claimForProcessing(int $id, CarbonInterface $now): bool;

    /**
     * 작업 속성을 갱신합니다.
     *
     * @param  Export  $export  작업
     * @param  array<string, mixed>  $data  바꿀 속성
     * @return Export 갱신된 작업
     */
    public function update(Export $export, array $data): Export;

    /**
     * 처리한 행 수를 갱신합니다(진행률).
     *
     * @param  int  $id  작업 ID
     * @param  int  $processedRows  처리한 행 수
     * @return void
     */
    public function updateProgress(int $id, int $processedRows): void;

    /**
     * 다운로드 횟수 캐시를 1 올립니다.
     *
     * @param  int  $id  작업 ID
     * @return void
     */
    public function incrementDownloadCount(int $id): void;

    /**
     * 보관 기간이 지난 완료 작업을 가져옵니다.
     *
     * @param  CarbonInterface  $now  기준 시각
     * @param  int  $limit  한 번에 가져올 최대 수
     * @return Collection<int, Export> 만료 대상
     */
    public function getExpiredCompleted(CarbonInterface $now, int $limit = 500): Collection;

    /**
     * 진행중인 채로 기준 시각보다 오래된 작업을 가져옵니다(프로세스가 죽은 작업).
     *
     * @param  CarbonInterface  $startedBefore  이 시각 이전에 시작한 작업
     * @param  int  $limit  한 번에 가져올 최대 수
     * @return Collection<int, Export> 멈춘 작업
     */
    public function getStaleProcessing(CarbonInterface $startedBefore, int $limit = 500): Collection;

    /**
     * 삭제됐지만 파일 경로가 남은 작업(삭제할 때 파일을 못 지움)을 반환합니다.
     *
     * @param  int  $limit  최대 개수
     * @return Collection<int, Export> 작업
     */
    public function getTrashedWithFile(int $limit = 500): Collection;

    /**
     * 기준 시각보다 오래 대기 중인 작업이 있는지 반환합니다(큐 워커 미동작 경고용).
     *
     * @param  CarbonInterface  $requestedBefore  이 시각 이전에 요청된 작업
     * @return bool 오래된 대기 작업 존재 여부
     */
    public function hasStalePending(CarbonInterface $requestedBefore): bool;

    /**
     * 주어진 ID 중 아직 삭제되지 않은 작업을 가져옵니다.
     *
     * @param  array<int, int>  $ids  작업 ID 목록
     * @return Collection<int, Export> 작업 목록
     */
    public function findManyByIds(array $ids): Collection;

    /**
     * 작업을 소프트 삭제합니다.
     *
     * @param  Export  $export  작업
     * @return void
     */
    public function softDelete(Export $export): void;
}
