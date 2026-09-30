<?php

namespace Modules\Sirsoft\Board\Http\Controllers\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use Exception;
use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Board\Http\Requests\Admin\BulkDestroyScrapRequest;
use Modules\Sirsoft\Board\Http\Requests\Admin\IndexScrapRequest;
use Modules\Sirsoft\Board\Http\Resources\AdminPostScrapCollection;
use Modules\Sirsoft\Board\Services\PostScrapService;
use Modules\Sirsoft\Board\Traits\ChecksBoardPermission;

/**
 * 관리자용 게시글 스크랩 관리 컨트롤러
 *
 * 전 회원의 스크랩을 한 화면에서 조회하고 개별·일괄 삭제합니다. 게시글 관리가
 * 게시판별 권한(sirsoft-board.{slug}.admin.*)인 것과 달리 스크랩은 회원 소유 데이터라
 * 게시판 경계를 넘어 조회되므로 모듈 레벨 권한(sirsoft-board.scraps.*)을 쓴다.
 */
class PostScrapController extends AdminBaseController
{
    use ChecksBoardPermission;

    /**
     * PostScrapController 생성자
     *
     * @param  PostScrapService  $scrapService  스크랩 서비스
     */
    public function __construct(
        private PostScrapService $scrapService
    ) {
        parent::__construct();
    }

    /**
     * 전 회원의 스크랩 목록을 조회합니다.
     *
     * @param  IndexScrapRequest  $request  목록 조회 요청
     * @return JsonResponse 스크랩 목록 응답
     */
    public function index(IndexScrapRequest $request): JsonResponse
    {
        try {
            if (! $this->checkModulePermission('scraps', 'view')) {
                return $this->forbidden('sirsoft-board::messages.scrap.admin.permission_denied');
            }

            // filters 배열에서 검색 조건 추출 (신고 현황·회원 목록과 동일한 관리자 목록 규약)
            $filtersParam = $request->input('filters', []);

            $filters = [
                'search_field' => $filtersParam[0]['field'] ?? 'all',
                'search' => $filtersParam[0]['value'] ?? null,
                'board_id' => $request->input('board_id'),
                'user_id' => $this->resolveUserId($request),
                'scrapped_at_from' => $request->input('scrapped_at_from'),
                'scrapped_at_to' => $request->input('scrapped_at_to'),
                'sort_order' => $request->input('sort_order'),
            ];

            $perPage = min(max((int) $request->input('per_page', 20), 1), 100);

            $scraps = $this->scrapService->paginateForAdmin($filters, $perPage);

            return $this->success(
                'sirsoft-board::messages.scrap.admin.retrieved',
                (new AdminPostScrapCollection($scraps))->withPermissions()
            );
        } catch (Exception $e) {
            return $this->error('sirsoft-board::messages.scrap.admin.retrieve_failed', 500);
        }
    }

    /**
     * 스크랩 한 건을 삭제합니다 (소유자 무관).
     *
     * @param  int  $id  스크랩 레코드 ID
     * @return JsonResponse 삭제 결과
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            if (! $this->scrapService->removeByAdmin($id)) {
                return $this->notFound('sirsoft-board::messages.scrap.not_found');
            }

            return $this->success('sirsoft-board::messages.scrap.admin.removed');
        } catch (Exception $e) {
            return $this->error('sirsoft-board::messages.scrap.delete_failed', 500);
        }
    }

    /**
     * 선택한 스크랩을 일괄 삭제합니다 (소유자 무관).
     *
     * @param  BulkDestroyScrapRequest  $request  ID 목록 검증 요청
     * @return JsonResponse 삭제 결과 (deleted_count)
     */
    public function bulkDestroy(BulkDestroyScrapRequest $request): JsonResponse
    {
        try {
            $ids = array_map('intval', $request->validated()['ids']);
            $count = $this->scrapService->removeManyByAdmin($ids);

            return $this->success(
                'sirsoft-board::messages.scrap.admin.bulk_removed',
                ['deleted_count' => $count],
                200,
                ['count' => $count]
            );
        } catch (Exception $e) {
            return $this->error('sirsoft-board::messages.scrap.admin.bulk_delete_failed', 500);
        }
    }

    /**
     * 회원 필터를 user_id 로 해석합니다.
     *
     * user_uuid 가 왔는데 그런 회원이 없으면 -1 을 돌려 빈 결과를 만든다 — 필터를
     * 무시하고 전체를 보여주면 "이 회원의 스크랩" 을 요청한 화면에 남의 스크랩이 뜬다.
     *
     * @param  IndexScrapRequest  $request  목록 조회 요청
     * @return int|null 회원 ID (필터 없으면 null)
     */
    private function resolveUserId(IndexScrapRequest $request): ?int
    {
        $userId = $request->input('user_id');

        if (! empty($userId)) {
            return (int) $userId;
        }

        $uuid = $request->input('user_uuid');

        if (empty($uuid)) {
            return null;
        }

        return $this->scrapService->resolveMemberFilterId((string) $uuid);
    }
}
