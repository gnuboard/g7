<?php

namespace App\Contracts\Repositories;

use App\Models\ScheduleHistory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface ScheduleHistoryRepositoryInterface
{
    /**
     * ID로 실행 이력을 찾습니다.
     *
     * @param  int  $id  이력 ID
     * @return ScheduleHistory|null 찾은 이력 모델 또는 null
     */
    public function findById(int $id): ?ScheduleHistory;

    /**
     * 새로운 실행 이력을 생성합니다.
     *
     * @param  array  $data  이력 생성 데이터
     * @return ScheduleHistory 생성된 이력 모델
     */
    public function create(array $data): ScheduleHistory;

    /**
     * 저장하지 않은 실행 이력 인스턴스를 만듭니다.
     *
     * @param  array  $data  이력 데이터
     * @return ScheduleHistory 저장되지 않은 이력 모델
     */
    public function make(array $data): ScheduleHistory;

    /**
     * 스케줄의 가장 최근 「실행 중」 이력을 찾습니다.
     *
     * @param  int  $scheduleId  스케줄 ID
     * @return ScheduleHistory|null 실행 중 이력 또는 null
     */
    public function findLatestRunning(int $scheduleId): ?ScheduleHistory;

    /**
     * 기존 실행 이력을 업데이트합니다.
     *
     * @param  ScheduleHistory  $history  업데이트할 이력 모델
     * @param  array  $data  업데이트할 데이터
     * @return bool 업데이트 성공 여부
     */
    public function update(ScheduleHistory $history, array $data): bool;

    /**
     * 실행 이력을 삭제합니다.
     *
     * @param  ScheduleHistory  $history  삭제할 이력 모델
     * @return bool 삭제 성공 여부
     */
    public function delete(ScheduleHistory $history): bool;

    /**
     * 특정 스케줄의 실행 이력을 페이지네이션하여 조회합니다.
     *
     * @param  int  $scheduleId  스케줄 ID
     * @param  array  $filters  필터 조건 배열
     * @return LengthAwarePaginator 페이지네이션된 이력 목록
     */
    public function getPaginatedByScheduleId(int $scheduleId, array $filters = []): LengthAwarePaginator;

    /**
     * 특정 스케줄의 최근 실행 이력을 조회합니다.
     *
     * @param  int  $scheduleId  스케줄 ID
     * @param  int  $limit  조회 개수
     * @return Collection 최근 이력 컬렉션
     */
    public function getRecentByScheduleId(int $scheduleId, int $limit = 10): Collection;

    /**
     * 출처별 최근 실행 이력을 조회합니다.
     *
     * 코드로 등록된 예약(`source=code`)은 대응하는 `schedules` 행이 없어 스케줄 ID 로는
     * 뽑을 수 없다 — 웹 크론 러너가 남긴 이력을 화면에 보이려면 이 통로가 필요하다.
     *
     * @param  string  $source  이력 출처 (database|code)
     * @param  int  $limit  최대 건수
     * @return Collection<int, ScheduleHistory> 시각 역순 이력
     */
    public function getRecentBySource(string $source, int $limit = 20): Collection;

    /**
     * 출처를 가리지 않고 최근 실행 이력을 조회합니다 (스케줄 행을 함께 싣는다).
     *
     * 7.2.0 부터 예약 작업 대부분이 `schedules` 행(`source=database`)이라, 코드 예약 이력만
     * 보여 주는 화면은 거의 비어 버린다. 이름 접두사로 걸러 낼 코드 이력을 지정할 수 있다 —
     * 예전 판 웹 러너가 DB 스케줄 이벤트(`schedule:{id}`)에 실행기 이력과 짝으로 남긴 기록이
     * 같은 실행을 두 줄로 보이게 하기 때문이다. 거절 행(`status=rejected`)은 실행이 아니라서 싣지 않는다.
     *
     * @param  int  $limit  최대 건수
     * @param  string|null  $excludeCodeEventPrefix  빼낼 코드 이력의 이벤트 이름 접두사 (null 이면 빼지 않음)
     * @return Collection<int, ScheduleHistory> 시각 역순 이력
     */
    public function getRecentRuns(int $limit = 20, ?string $excludeCodeEventPrefix = null): Collection;

    /**
     * 여러 이력을 일괄 삭제합니다.
     *
     * @param  array  $ids  이력 ID 배열
     * @return int 삭제된 레코드 수
     */
    public function bulkDelete(array $ids): int;

    /**
     * 특정 스케줄의 모든 이력을 삭제합니다.
     *
     * @param  int  $scheduleId  스케줄 ID
     * @return int 삭제된 레코드 수
     */
    public function deleteByScheduleId(int $scheduleId): int;

    /**
     * 특정 기간 이전의 이력을 삭제합니다.
     *
     * @param  int  $days  보관 기간 (일)
     * @return int 삭제된 레코드 수
     */
    public function deleteOlderThan(int $days): int;
}
