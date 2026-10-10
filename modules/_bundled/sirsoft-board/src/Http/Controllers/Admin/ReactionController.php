<?php

namespace Modules\Sirsoft\Board\Http\Controllers\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use Exception;
use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Board\Http\Requests\Admin\BulkDestroyReactionRequest;
use Modules\Sirsoft\Board\Http\Requests\Admin\IndexReactionRequest;
use Modules\Sirsoft\Board\Http\Resources\AdminReactionCollection;
use Modules\Sirsoft\Board\Services\ReactionService;
use Modules\Sirsoft\Board\Traits\ChecksBoardPermission;

/**
 * 관리자용 반응 관리 컨트롤러
 *
 * 전 회원의 게시글·댓글 반응을 한 화면에서 조회하고 개별·일괄 삭제합니다. 게시글 관리가
 * 게시판별 권한(sirsoft-board.{slug}.admin.*)인 것과 달리 반응은 회원 소유 데이터라
 * 게시판 경계를 넘어 조회되므로 모듈 레벨 권한(sirsoft-board.reactions.*)을 쓴다.
 */
class ReactionController extends AdminBaseController
{
    use ChecksBoardPermission;

    /**
     * ReactionController 생성자
     *
     * @param  ReactionService  $reactionService  반응 서비스
     */
    public function __construct(
        private ReactionService $reactionService
    ) {
        parent::__construct();
    }

    /**
     * 전 회원의 반응 목록을 조회합니다.
     *
     * @param  IndexReactionRequest  $request  목록 조회 요청
     * @return JsonResponse 반응 목록 응답
     */
    public function index(IndexReactionRequest $request): JsonResponse
    {
        try {
            if (! $this->checkModulePermission('reactions', 'view')) {
                return $this->forbidden('sirsoft-board::messages.reaction.admin.permission_denied');
            }

            // filters 배열에서 검색 조건 추출 (신고 현황·스크랩 관리와 동일한 관리자 목록 규약)
            $filtersParam = $request->input('filters', []);

            $filters = [
                'search_field' => $filtersParam[0]['field'] ?? 'all',
                'search' => $filtersParam[0]['value'] ?? null,
                'board_id' => $request->input('board_id'),
                'user_id' => $this->resolveUserId($request),
                'reaction_type_id' => $request->input('reaction_type_id'),
                'target_type' => $request->input('target_type'),
                'reacted_at_from' => $request->input('reacted_at_from'),
                'reacted_at_to' => $request->input('reacted_at_to'),
                'sort_order' => $request->input('sort_order'),
            ];

            $perPage = min(max((int) $request->input('per_page', 20), 1), 100);

            $reactions = $this->reactionService->paginateForAdmin($filters, $perPage);

            return $this->success(
                'sirsoft-board::messages.reaction.admin.retrieved',
                (new AdminReactionCollection($reactions))->withPermissions()
            );
        } catch (Exception $e) {
            return $this->error('sirsoft-board::messages.reaction.admin.retrieve_failed', 500);
        }
    }

    /**
     * 반응 한 건을 삭제합니다 (소유자 무관).
     *
     * @param  int  $id  반응 레코드 ID
     * @return JsonResponse 삭제 결과
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            if (! $this->reactionService->removeByAdmin($id)) {
                return $this->notFound('sirsoft-board::messages.reaction.not_found');
            }

            return $this->success('sirsoft-board::messages.reaction.admin.removed');
        } catch (Exception $e) {
            return $this->error('sirsoft-board::messages.reaction.delete_failed', 500);
        }
    }

    /**
     * 선택한 반응을 일괄 삭제합니다 (소유자 무관).
     *
     * @param  BulkDestroyReactionRequest  $request  ID 목록 검증 요청
     * @return JsonResponse 삭제 결과 (deleted_count)
     */
    public function bulkDestroy(BulkDestroyReactionRequest $request): JsonResponse
    {
        try {
            $ids = array_map('intval', $request->validated()['ids']);
            $count = $this->reactionService->removeManyByAdmin($ids);

            return $this->success(
                'sirsoft-board::messages.reaction.admin.bulk_removed',
                ['deleted_count' => $count],
                200,
                ['count' => $count]
            );
        } catch (Exception $e) {
            return $this->error('sirsoft-board::messages.reaction.admin.bulk_delete_failed', 500);
        }
    }

    /**
     * 회원 필터를 user_id 로 해석합니다.
     *
     * user_uuid 가 왔는데 그런 회원이 없으면 -1 을 돌려 빈 결과를 만든다 — 필터를
     * 무시하고 전체를 보여주면 "이 회원의 반응" 을 요청한 화면에 남의 반응이 뜬다.
     *
     * @param  IndexReactionRequest  $request  목록 조회 요청
     * @return int|null 회원 ID (필터 없으면 null)
     */
    private function resolveUserId(IndexReactionRequest $request): ?int
    {
        $userId = $request->input('user_id');

        if (! empty($userId)) {
            return (int) $userId;
        }

        $uuid = $request->input('user_uuid');

        if (empty($uuid)) {
            return null;
        }

        return $this->reactionService->resolveMemberFilterId((string) $uuid);
    }
}
