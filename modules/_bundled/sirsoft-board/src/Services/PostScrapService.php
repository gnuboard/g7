<?php

namespace Modules\Sirsoft\Board\Services;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Extension\HookManager;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Exceptions\PostNotFoundException;
use Modules\Sirsoft\Board\Repositories\Contracts\PostRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\PostScrapRepositoryInterface;

/**
 * 게시글 스크랩 서비스
 *
 * 스크랩 토글(등록/해제), 마이페이지 개별·선택 삭제, 관리자 전 회원 조회·삭제를 담당한다.
 * 본인 글 스크랩 차단 로직은 두지 않는다 (개인 북마크는 셀프 어뷰징 우려 없음).
 * use_scrap 게이트는 컨트롤러에서 검증한다 (신고의 use_report 와 동일한 관례).
 */
class PostScrapService
{
    /**
     * PostScrapService 생성자
     *
     * @param  PostScrapRepositoryInterface  $repository  스크랩 리포지토리
     * @param  PostRepositoryInterface  $postRepository  게시글 리포지토리
     * @param  UserRepositoryInterface  $userRepository  코어 사용자 리포지토리 (UUID 변환용)
     */
    public function __construct(
        protected PostScrapRepositoryInterface $repository,
        protected PostRepositoryInterface $postRepository,
        protected UserRepositoryInterface $userRepository
    ) {}

    /**
     * 스크랩을 토글합니다 (있으면 해제, 없으면 등록).
     *
     * 토글 결과에 따라 board_posts.scrap_count 를 원자적으로 증감하며,
     * 레코드 변경과 카운트 증감을 한 트랜잭션으로 묶는다.
     *
     * @param  int  $userId  회원 ID
     * @param  int  $postId  게시글 ID
     * @param  int  $boardId  게시판 ID (등록 시 기록 + 소속 검증)
     * @return array{added: bool} added=true 면 등록, false 면 해제
     *
     * @throws PostNotFoundException postId 가 boardId 게시판 소속이 아닌 경우 (교차 접근 차단)
     */
    public function toggle(int $userId, int $postId, int $boardId): array
    {
        // 교차 접근 차단 — postId 가 실제로 이 게시판 소속인지 검증한다 (중첩 리소스 스코프).
        if (! $this->postRepository->findByBoardId($boardId, $postId)) {
            throw new PostNotFoundException($postId);
        }

        HookManager::doAction('sirsoft-board.scrap.before_toggle', $userId, $postId);

        $result = DB::transaction(function () use ($userId, $postId, $boardId) {
            $toggled = $this->repository->toggle($userId, $postId, $boardId);

            $scrapCount = $toggled['added']
                ? $this->postRepository->incrementScrapCount($postId)
                : $this->postRepository->decrementScrapCount($postId);

            return ['added' => $toggled['added'], 'scrap_count' => $scrapCount];
        });

        HookManager::doAction('sirsoft-board.scrap.after_toggle', $userId, $postId, $result['added']);

        return ['added' => $result['added'], 'scrap_count' => $result['scrap_count']];
    }

    /**
     * 마이페이지에서 스크랩 레코드를 개별 삭제합니다 (본인 소유만).
     *
     * 스크랩 레코드 ID 로 직접 삭제하며, 원글이 남아 있으면 scrap_count 도 함께 감소시킨다.
     * 원글이 이미 삭제됐으면 감소 대상이 없으므로 레코드만 제거한다.
     *
     * @param  int  $id  스크랩 레코드 ID
     * @param  int  $userId  회원 ID (소유권 확인)
     * @return bool 삭제 성공 여부 (본인 소유가 아니면 false)
     */
    public function remove(int $id, int $userId): bool
    {
        return DB::transaction(function () use ($id, $userId) {
            $scrap = $this->repository->deleteByIdAndUser($id, $userId);

            if (! $scrap) {
                return false;
            }

            $this->postRepository->decrementScrapCount($scrap->post_id);

            HookManager::doAction('sirsoft-board.scrap.after_toggle', $userId, $scrap->post_id, false);

            return true;
        });
    }

    /**
     * 마이페이지에서 선택한 스크랩을 일괄 해제합니다 (본인 소유만).
     *
     * 남의 ID·없는 ID 는 저장소의 소유권 필터에서 조용히 빠지며, 반환 건수로만 드러난다.
     * 훅은 트랜잭션 커밋 뒤에 행마다 발화한다 — 롤백된 삭제로 활동 로그가 남는 것을 막는다.
     *
     * @param  array<int, int>  $ids  스크랩 레코드 ID 목록
     * @param  int  $userId  회원 ID (소유권 확인)
     * @return int 실제로 삭제된 건수
     */
    public function removeMany(array $ids, int $userId): int
    {
        $deleted = DB::transaction(function () use ($ids, $userId) {
            $scraps = $this->repository->deleteManyByIdsAndUser($ids, $userId);

            foreach ($scraps as $scrap) {
                $this->postRepository->decrementScrapCount($scrap->post_id);
            }

            return $scraps;
        });

        foreach ($deleted as $scrap) {
            // 새 훅을 만들지 않는다 — 기존 after_toggle(userId, postId, false) 를 행마다 발화해
            // 게시판 활동 로그 리스너의 스크랩 해제 핸들러가 scrap.unscrap 로그를 그대로 남긴다.
            HookManager::doAction('sirsoft-board.scrap.after_toggle', $userId, $scrap->post_id, false);
        }

        return $deleted->count();
    }

    /**
     * 회원의 스크랩 목록을 최근 스크랩순으로 조회합니다.
     *
     * @param  int  $userId  회원 ID
     * @param  int  $perPage  페이지당 개수
     * @param  string|null  $search  검색어
     * @param  string  $searchField  검색 대상 (title_content|title|content)
     * @return LengthAwarePaginator 페이지네이션된 스크랩 목록
     */
    public function paginateByUser(
        int $userId,
        int $perPage = 20,
        ?string $search = null,
        string $searchField = PostScrapRepositoryInterface::SEARCH_FIELD_DEFAULT
    ): LengthAwarePaginator {
        return $this->repository->paginateByUser($userId, $perPage, $search, $searchField);
    }

    /**
     * 관리자용 전 회원 스크랩 목록을 조회합니다.
     *
     * @param  array<string, mixed>  $filters  필터 (search_field, search, board_id, user_id, 기간, 정렬)
     * @param  int  $perPage  페이지당 개수
     * @return Paginator 페이지네이션된 스크랩 목록
     */
    public function paginateForAdmin(array $filters, int $perPage = 20): Paginator
    {
        return $this->repository->paginateForAdmin($filters, $perPage);
    }

    /**
     * 관리자가 스크랩 한 건을 삭제합니다 (소유자 무관).
     *
     * after_toggle 훅의 $userId 는 로그인 관리자가 아니라 **스크랩 소유자**다 —
     * 활동 로그가 "누구의 스크랩이 해제됐는가" 를 가리켜야 회원 활동 이력이 일관된다
     * (관리자 행위 자체는 관리자 활동 로그가 별도로 남긴다).
     *
     * @param  int  $id  스크랩 레코드 ID
     * @return bool 삭제 성공 여부 (없으면 false)
     */
    public function removeByAdmin(int $id): bool
    {
        $scrap = DB::transaction(function () use ($id) {
            $scrap = $this->repository->deleteById($id);

            if (! $scrap) {
                return null;
            }

            $this->postRepository->decrementScrapCount($scrap->post_id);

            return $scrap;
        });

        if (! $scrap) {
            return false;
        }

        HookManager::doAction('sirsoft-board.scrap.after_toggle', (int) $scrap->user_id, $scrap->post_id, false);

        return true;
    }

    /**
     * 관리자가 선택한 스크랩을 일괄 삭제합니다 (소유자 무관).
     *
     * @param  array<int, int>  $ids  스크랩 레코드 ID 목록
     * @return int 실제로 삭제된 건수
     */
    public function removeManyByAdmin(array $ids): int
    {
        $deleted = DB::transaction(function () use ($ids) {
            $scraps = $this->repository->deleteManyByIds($ids);

            foreach ($scraps as $scrap) {
                $this->postRepository->decrementScrapCount($scrap->post_id);
            }

            return $scraps;
        });

        foreach ($deleted as $scrap) {
            HookManager::doAction('sirsoft-board.scrap.after_toggle', (int) $scrap->user_id, $scrap->post_id, false);
        }

        return $deleted->count();
    }

    /**
     * 회원 UUID 를 스크랩 목록 필터용 회원 ID 로 해석합니다.
     *
     * 없는 UUID 는 -1 을 돌린다 — 필터를 무시하고 null 을 돌리면 "이 회원의 스크랩" 을
     * 요청한 화면에 전 회원 스크랩이 뜬다. 빈 결과가 올바른 답이다.
     *
     * @param  string  $uuid  회원 UUID
     * @return int 회원 ID (없으면 -1)
     */
    public function resolveMemberFilterId(string $uuid): int
    {
        return (int) ($this->userRepository->findByUuid($uuid)?->id ?? -1);
    }

    /**
     * 회원의 스크랩 요약(건수·최근 스크랩 시각)을 조회합니다.
     *
     * @param  int  $userId  회원 ID
     * @return array{count: int, latest_scrapped_at: Carbon|null} 요약
     */
    public function summaryForUser(int $userId): array
    {
        return $this->repository->summaryByUser($userId);
    }

    /**
     * 회원이 특정 게시글을 스크랩했는지 확인합니다.
     *
     * @param  int  $userId  회원 ID
     * @param  int  $postId  게시글 ID
     * @return bool 스크랩 여부
     */
    public function isScraped(int $userId, int $postId): bool
    {
        return $this->repository->isScraped($userId, $postId);
    }
}
