<?php

namespace Modules\Sirsoft\Board\Http\Controllers\User;

use App\Http\Controllers\Api\Base\AuthBaseController;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Board\Exceptions\PostNotFoundException;
use Modules\Sirsoft\Board\Http\Requests\User\BulkDestroyPostScrapRequest;
use Modules\Sirsoft\Board\Http\Requests\User\IndexPostScrapRequest;
use Modules\Sirsoft\Board\Http\Resources\PostScrapCollection;
use Modules\Sirsoft\Board\Repositories\Contracts\PostScrapRepositoryInterface;
use Modules\Sirsoft\Board\Services\BoardService;
use Modules\Sirsoft\Board\Services\PostScrapService;

/**
 * 사용자용 게시글 스크랩 컨트롤러
 *
 * 게시글 스크랩 토글(상세)과 마이페이지 목록 조회·개별 삭제를 제공합니다.
 * 모든 액션은 로그인 회원 전용(auth:sanctum 미들웨어).
 */
class PostScrapController extends AuthBaseController
{
    /**
     * @param  PostScrapService  $scrapService  스크랩 서비스 (토글·스코프 검증·목록·삭제)
     * @param  BoardService  $boardService  게시판 서비스 (slug → 게시판 해석)
     */
    public function __construct(
        private PostScrapService $scrapService,
        private BoardService $boardService
    ) {
        parent::__construct();
    }

    /**
     * 게시글 스크랩을 토글합니다 (상세 버튼 전용).
     *
     * postId 가 실제로 slug 게시판 소속인지 검증해 다른 게시판의 게시글을 스크랩하는
     * 교차 접근을 차단한다 (신고 컨트롤러와 동일한 스코프 방어).
     *
     * @param  string  $slug  게시판 slug
     * @param  int  $postId  게시글 ID
     * @return JsonResponse 토글 결과
     */
    public function toggle(string $slug, int $postId): JsonResponse
    {
        try {
            $this->logApiUsage('scrap.toggle');

            // 신고와 동일하게 slug 로만 게시판을 해석한다 (읽기 스코프와 무관).
            $board = $this->boardService->getBoardBySlug($slug, checkScope: false);

            if (! $board) {
                return $this->notFound('sirsoft-board::messages.scrap.post_not_found');
            }

            if (! $board->use_scrap) {
                return $this->forbidden('sirsoft-board::messages.scrap.scrap_disabled');
            }

            // 스코프 검증(postId 가 이 게시판 소속인지)은 Service 가 수행하며, 미소속 시 예외를 던진다.
            $result = $this->scrapService->toggle(Auth::id(), $postId, $board->id);

            $messageKey = $result['added']
                ? 'sirsoft-board::messages.scrap.added'
                : 'sirsoft-board::messages.scrap.removed';

            return $this->success($messageKey, [
                'added' => $result['added'],
                'scrap_count' => $result['scrap_count'],
            ]);
        } catch (PostNotFoundException $e) {
            return $this->notFound('sirsoft-board::messages.scrap.post_not_found');
        } catch (Exception $e) {
            return $this->error('sirsoft-board::messages.scrap.toggle_failed', 500);
        }
    }

    /**
     * 로그인 회원의 스크랩 목록을 조회합니다 (마이페이지).
     *
     * @param  IndexPostScrapRequest  $request  페이지네이션 검증 요청
     * @return JsonResponse 스크랩 목록
     */
    public function index(IndexPostScrapRequest $request): JsonResponse
    {
        try {
            $this->logApiUsage('scrap.index');

            $validated = $request->validated();
            $perPage = (int) ($validated['per_page'] ?? 20);
            $search = $validated['search'] ?? null;
            $searchField = $validated['search_field'] ?? PostScrapRepositoryInterface::SEARCH_FIELD_DEFAULT;
            $scraps = $this->scrapService->paginateByUser(Auth::id(), $perPage, $search, $searchField);

            return $this->successWithResource(
                'sirsoft-board::messages.scrap.retrieved',
                new PostScrapCollection($scraps)
            );
        } catch (Exception $e) {
            return $this->error('sirsoft-board::messages.scrap.retrieve_failed', 500);
        }
    }

    /**
     * 스크랩을 개별 삭제합니다 (마이페이지 ✕, 본인 소유 레코드만).
     *
     * @param  int  $id  스크랩 레코드 ID
     * @return JsonResponse 삭제 결과
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $this->logApiUsage('scrap.destroy');

            $deleted = $this->scrapService->remove($id, Auth::id());

            if (! $deleted) {
                return $this->notFound('sirsoft-board::messages.scrap.not_found');
            }

            return $this->success('sirsoft-board::messages.scrap.removed');
        } catch (Exception $e) {
            return $this->error('sirsoft-board::messages.scrap.delete_failed', 500);
        }
    }

    /**
     * 선택한 스크랩을 일괄 해제합니다 (마이페이지 선택 삭제, 본인 소유 레코드만).
     *
     * 남의 ID·없는 ID 는 서비스의 소유권 필터에서 조용히 빠지며 deleted_count 로만 드러난다
     * — 422 로 되돌리면 "그 ID 가 존재하는가" 가 남의 스크랩 ID 탐색 통로가 된다.
     *
     * @param  BulkDestroyPostScrapRequest  $request  ID 목록 검증 요청
     * @return JsonResponse 삭제 결과 (deleted_count)
     */
    public function bulkDestroy(BulkDestroyPostScrapRequest $request): JsonResponse
    {
        try {
            $this->logApiUsage('scrap.bulk_destroy');

            $ids = array_map('intval', $request->validated()['ids']);
            $count = $this->scrapService->removeMany($ids, Auth::id());

            return $this->success(
                'sirsoft-board::messages.scrap.bulk_removed',
                ['deleted_count' => $count],
                200,
                ['count' => $count]
            );
        } catch (Exception $e) {
            return $this->error('sirsoft-board::messages.scrap.delete_failed', 500);
        }
    }
}
