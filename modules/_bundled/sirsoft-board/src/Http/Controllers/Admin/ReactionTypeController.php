<?php

namespace Modules\Sirsoft\Board\Http\Controllers\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Board\Http\Resources\ReactionTypeResource;
use Modules\Sirsoft\Board\Repositories\Contracts\ReactionTypeRepositoryInterface;

/**
 * 관리자용 반응 유형 컨트롤러
 *
 * 게시판 설정 화면의 "사용할 반응 유형" 체크박스 옵션 소스로 활성 유형 목록을 제공합니다.
 * 유형 CRUD 는 이번 범위가 아니며(이슈 #525 확정 01), 목록 조회 전용 API 입니다.
 */
class ReactionTypeController extends AdminBaseController
{
    /**
     * ReactionTypeController 생성자
     *
     * @param  ReactionTypeRepositoryInterface  $reactionTypeRepository  반응 유형 Repository
     */
    public function __construct(
        private ReactionTypeRepositoryInterface $reactionTypeRepository,
    ) {
        parent::__construct();
    }

    /**
     * 활성 반응 유형 전체를 display_order 순으로 반환합니다.
     *
     * @return JsonResponse 반응 유형 목록 응답
     */
    public function index(): JsonResponse
    {
        try {
            $types = $this->reactionTypeRepository->getActive();

            return $this->success(
                'sirsoft-board::messages.reaction.list_success',
                ['reaction_types' => ReactionTypeResource::collection($types)]
            );
        } catch (\Exception $e) {
            Log::error('반응 유형 목록 조회 실패', ['error' => $e->getMessage()]);

            return $this->error('sirsoft-board::messages.reaction.failed', 500);
        }
    }
}
