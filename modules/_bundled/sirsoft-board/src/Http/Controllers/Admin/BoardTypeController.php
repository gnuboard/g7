<?php

namespace Modules\Sirsoft\Board\Http\Controllers\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Board\Exceptions\BoardTypeOperationException;
use Modules\Sirsoft\Board\Http\Requests\StoreBoardTypeRequest;
use Modules\Sirsoft\Board\Http\Requests\UpdateBoardTypeRequest;
use Modules\Sirsoft\Board\Http\Resources\BoardTypeResource;
use Modules\Sirsoft\Board\Services\BoardTypeService;

class BoardTypeController extends AdminBaseController
{
    public function __construct(
        private BoardTypeService $boardTypeService
    ) {
        parent::__construct();
    }

    /**
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $boardTypes = $this->boardTypeService->getBoardTypes();

        return $this->successWithResource(
            'sirsoft-board::messages.board_type.list',
            BoardTypeResource::collection($boardTypes)
        );
    }

    /**
     * @param  StoreBoardTypeRequest  $request
     * @return JsonResponse
     */
    public function store(StoreBoardTypeRequest $request): JsonResponse
    {
        $boardType = $this->boardTypeService->createBoardType($request->validated());

        return $this->successWithResource(
            'sirsoft-board::messages.board_type.created',
            new BoardTypeResource($boardType),
            201
        );
    }

    /**
     * @param  UpdateBoardTypeRequest  $request
     * @param  int  $id
     * @return JsonResponse
     */
    public function update(UpdateBoardTypeRequest $request, int $id): JsonResponse
    {
        try {
            $boardType = $this->boardTypeService->updateBoardType($id, $request->validated());

            return $this->successWithResource(
                'sirsoft-board::messages.board_type.updated',
                new BoardTypeResource($boardType)
            );
        } catch (ModelNotFoundException) {
            return $this->notFound('sirsoft-board::messages.board_type.not_found');
        }
    }

    /**
     * @param  int  $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $this->boardTypeService->deleteBoardType($id);

            return $this->success('sirsoft-board::messages.board_type.deleted');
        } catch (ModelNotFoundException) {
            return $this->notFound('sirsoft-board::messages.board_type.not_found');
        } catch (BoardTypeOperationException $e) {
            // 삭제 불가 사유(사용 중/기본 유형)를 전용 키로 안내 — 예외 메시지 원문을
            // 키 자리에 넘기면(종전 동작) 키 해석에 실패해 원문이 그대로 노출된다.
            return $this->error($e->getMessageKey(), 422, null, $e->getMessageParams());
        } catch (\Exception $e) {
            // 서버 결함/인프라 장애 — 4xx 로 뭉개면 장애가 입력 오류로 위장된다
            return $this->error('sirsoft-board::messages.errors.operation_failed', 500);
        }
    }
}
