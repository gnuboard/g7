<?php

namespace Plugins\Sirsoft\Popup\Repositories\Contracts;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Plugins\Sirsoft\Popup\Enums\PopupStatus;
use Plugins\Sirsoft\Popup\Models\Popup;

/**
 * 팝업 Repository 계약 — 팝업 쿼리는 모두 여기를 거친다.
 */
interface PopupRepositoryInterface
{
    /**
     * 관리자 목록(상태·기기·검색 필터, 정렬, 쪽)을 조회합니다.
     *
     * @param  array<string, mixed>  $filters  검증된 목록 필터
     * @param  CarbonInterface  $now  상태 판정 기준 시각
     * @return LengthAwarePaginator 팝업 목록
     */
    public function paginateForAdmin(array $filters, CarbonInterface $now): LengthAwarePaginator;

    /**
     * 상태별 건수를 반환합니다.
     *
     * @param  CarbonInterface  $now  상태 판정 기준 시각
     * @return array{total: int, statuses: array<string, int>} 통계
     */
    public function statistics(CarbonInterface $now): array;

    /**
     * 팝업을 이미지와 함께 찾습니다.
     *
     * @param  int  $id  팝업 ID
     * @return Popup 팝업
     */
    public function findOrFail(int $id): Popup;

    /**
     * 여러 팝업을 이미지와 함께 찾습니다(id 순).
     *
     * @param  array<int, int>  $ids  팝업 ID 목록
     * @return Collection<int, Popup> 팝업 목록
     */
    public function findMany(array $ids): Collection;

    /**
     * 활동 로그 이름 해석용으로 관리용 제목만 찾습니다.
     *
     * @param  int  $id  팝업 ID
     * @return string|null 관리용 제목 (없으면 null)
     */
    public function findTitle(int $id): ?string;

    /**
     * 편집을 직렬화하려고 팝업 행을 잠가 찾습니다(트랜잭션 안에서).
     *
     * @param  int  $id  팝업 ID
     * @return Popup 최신 팝업
     */
    public function findForUpdate(int $id): Popup;

    /**
     * 팝업을 만듭니다.
     *
     * @param  array<string, mixed>  $data  저장할 값
     * @return Popup 만든 팝업
     */
    public function create(array $data): Popup;

    /**
     * 팝업을 고치고 세대 번호를 올립니다.
     *
     * @param  Popup  $popup  대상 팝업
     * @param  array<string, mixed>  $data  바꿀 값
     * @return Popup 고친 팝업
     */
    public function update(Popup $popup, array $data): Popup;

    /**
     * 이미지 위 글 상자만 바꿉니다 — 같은 저장 안의 뒷정리라 세대 번호는 올리지 않습니다.
     *
     * @param  Popup  $popup  대상 팝업
     * @param  array<string, mixed>|null  $overlays  글 상자 값
     * @return Popup 고친 팝업 (pcImage·mobileImage 로드)
     */
    public function replaceOverlays(Popup $popup, ?array $overlays): Popup;

    /**
     * 팝업 행을 지웁니다.
     *
     * @param  Popup  $popup  대상 팝업
     * @return bool 삭제 여부
     */
    public function delete(Popup $popup): bool;

    /**
     * 맨 뒤 순서 값을 반환합니다.
     *
     * @return int 다음 순서 값
     */
    public function nextSortOrder(): int;

    /**
     * 팝업 전체를 순서대로 반환합니다(순서 변경 창).
     *
     * @return Collection<int, Popup> 팝업 목록
     */
    public function listAllInOrder(): Collection;

    /**
     * 팝업 ID 전체를 반환합니다(순서 변경 검증).
     *
     * @return array<int, int> 팝업 ID 목록
     */
    public function allIds(): array;

    /**
     * 주어진 순서로 sort_order 를 0 부터 다시 매깁니다.
     *
     * @param  array<int, int>  $ids  새 순서의 팝업 ID 목록
     * @return void 반환값 없음
     */
    public function reorder(array $ids): void;

    /**
     * 여러 팝업의 사용 여부를 바꿉니다.
     *
     * @param  array<int, int>  $ids  팝업 ID 목록
     * @param  bool  $isActive  사용 여부
     * @param  int|null  $actorId  바꾼 회원 ID
     * @return int 바뀐 건수
     */
    public function bulkSetActive(array $ids, bool $isActive, ?int $actorId): int;

    /**
     * 지금 노출 중인 팝업을 순서대로 반환합니다(관리자 목록 상단 요약).
     *
     * @param  CarbonInterface  $now  기준 시각
     * @return Collection<int, Popup> 노출 중 팝업
     */
    public function activeInOrder(CarbonInterface $now): Collection;

    /**
     * 공개 캐시에 담을 노출 후보(사용 중 + 아직 안 끝난 팝업, 기간 포함)를 대상별로 반환합니다.
     *
     * @param  array<int, string>  $audiences  요청자에게 맞는 노출 대상 값 (예: all·guest)
     * @param  CarbonInterface  $now  기준 시각
     * @return Collection<int, Popup> 노출 후보
     */
    public function exposureCandidates(array $audiences, CarbonInterface $now): Collection;

    /**
     * 다음 상태 전환 시각(가장 가까운 예약 시작 또는 종료 직후)을 반환합니다.
     *
     * @param  CarbonInterface  $now  기준 시각
     * @return CarbonInterface|null 다음 전환 시각 (없으면 null)
     */
    public function nextTransitionAt(CarbonInterface $now): ?CarbonInterface;

    /**
     * 노출 상태 조건을 쿼리에 겁니다 — `Popup::statusAt()` 과 같은 규칙.
     *
     * @param  Builder  $query  팝업 쿼리
     * @param  PopupStatus  $status  거를 상태
     * @param  CarbonInterface  $now  기준 시각
     * @return Builder 조건을 건 쿼리
     */
    public function applyStatusFilter(Builder $query, PopupStatus $status, CarbonInterface $now): Builder;
}
