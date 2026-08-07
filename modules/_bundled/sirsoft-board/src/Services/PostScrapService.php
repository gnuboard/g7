<?php

namespace Modules\Sirsoft\Board\Services;

use App\Extension\HookManager;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Exceptions\PostNotFoundException;
use Modules\Sirsoft\Board\Repositories\Contracts\PostRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\PostScrapRepositoryInterface;

/**
 * 게시글 스크랩 서비스
 *
 * 스크랩 토글(등록/해제)과 마이페이지 개별 삭제를 담당한다.
 * 본인 글 스크랩 차단 로직은 두지 않는다 (개인 북마크는 셀프 어뷰징 우려 없음).
 * use_scrap 게이트는 컨트롤러에서 검증한다 (신고의 use_report 와 동일한 관례).
 */
class PostScrapService
{
    public function __construct(
        protected PostScrapRepositoryInterface $repository,
        protected PostRepositoryInterface $postRepository
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
     * 회원의 스크랩 목록을 최근 스크랩순으로 조회합니다.
     *
     * @param  int  $userId  회원 ID
     * @param  int  $perPage  페이지당 개수
     * @param  string|null  $search  게시글 제목 검색어
     * @return LengthAwarePaginator 페이지네이션된 스크랩 목록
     */
    public function paginateByUser(int $userId, int $perPage = 20, ?string $search = null): LengthAwarePaginator
    {
        return $this->repository->paginateByUser($userId, $perPage, $search);
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
