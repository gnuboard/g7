<?php

namespace App\Contracts\Repositories;

use App\Models\FailedJob;
use App\Support\Query\BoundedPage;

/**
 * 큐 대기(jobs)·실패(failed_jobs) 표 조회 Repository 인터페이스
 *
 * 두 표는 Laravel 큐 인프라가 쓰고 지우는 표다. 이 저장소는 조회만 한다 — 재시도·삭제는 서비스가
 * 프레임워크 failer(queue.failer)를 거쳐 처리한다(드라이버 중립).
 */
interface JobQueueRepositoryInterface
{
    /**
     * 대기 목록 정렬 허용 열 (`sort_by`). 지정이 없으면 최근 등록순(id 역순)이다.
     *
     * @var list<string>
     */
    public const PENDING_SORT_COLUMNS = ['created_at', 'available_at', 'attempts'];

    /**
     * 실패 목록 정렬 허용 열 (`sort_by`). 지정이 없으면 최근 실패순(failed_at 역순)이다.
     *
     * @var list<string>
     */
    public const FAILED_SORT_COLUMNS = ['failed_at', 'id'];

    /**
     * 대기 작업 한 페이지를 조회합니다.
     *
     * @param  string|null  $database  DB 연결 이름 (null = 기본 DB 연결)
     * @param  string  $table  대기 작업 표 이름
     * @param  array<string, mixed>  $filters  필터 (search_field·search_keyword·queue·sort_by·sort_order)
     * @param  int  $perPage  페이지당 건수
     * @return BoundedPage 대기 작업 행 페이지 (id·queue·payload·attempts·reserved_at·available_at·created_at)
     */
    public function paginatePending(?string $database, string $table, array $filters, int $perPage): BoundedPage;

    /**
     * 대기 작업 건수를 셉니다.
     *
     * @param  string|null  $database  DB 연결 이름 (null = 기본 DB 연결)
     * @param  string  $table  대기 작업 표 이름
     * @return int 대기 건수 (표가 없거나 읽을 수 없으면 0)
     */
    public function countPending(?string $database, string $table): int;

    /**
     * 실패 작업 한 페이지를 조회합니다.
     *
     * @param  array<string, mixed>  $filters  필터 (search_field·search_keyword·queue·connection·failed_start_date·failed_end_date·sort_by·sort_order)
     * @param  int  $perPage  페이지당 건수
     * @return BoundedPage 실패 작업 페이지 (예외 내용은 앞부분만)
     */
    public function paginateFailed(array $filters, int $perPage): BoundedPage;

    /**
     * 실패 작업 1건을 UUID 로 찾습니다.
     *
     * @param  string  $uuid  failed_jobs.uuid
     * @return FailedJob|null 실패 작업 (없으면 null)
     */
    public function findFailedByUuid(string $uuid): ?FailedJob;

    /**
     * 실패 작업 건수를 셉니다.
     *
     * @return int 실패 건수 (표가 없거나 읽을 수 없으면 0)
     */
    public function countFailed(): int;
}
