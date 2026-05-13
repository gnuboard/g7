<?php

namespace Modules\Gnuboard7\HelloModule\Http\Controllers\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Gnuboard7\HelloModule\Http\Requests\Admin\StoreMemoRequest;
use Modules\Gnuboard7\HelloModule\Http\Requests\Admin\UpdateMemoRequest;
use Modules\Gnuboard7\HelloModule\Http\Resources\MemoCollection;
use Modules\Gnuboard7\HelloModule\Http\Resources\MemoResource;
use Modules\Gnuboard7\HelloModule\Services\MemoService;

/**
 * 관리자용 메모 관리 컨트롤러
 *
 * 메모의 CRUD 를 제공합니다.
 */
class MemoController extends AdminBaseController
{
    /**
     * MemoController 생성자
     *
     * @param  MemoService  $memoService  메모 서비스
     */
    public function __construct(
        private MemoService $memoService,
    ) {
        parent::__construct();
    }

    /**
     * 메모 목록을 조회합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return JsonResponse 메모 목록 응답
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = (int) $request->query('per_page', 10);
            $memos = $this->memoService->getMemos($perPage);

            return $this->success(
                'gnuboard7-hello_module::messages.memo.fetch_success',
                new MemoCollection($memos)
            );
        } catch (\Exception $e) {
            return $this->error('gnuboard7-hello_module::messages.memo.fetch_failed', 500, $e->getMessage());
        }
    }

    /**
     * 메모 상세를 조회합니다.
     *
     * @param  int  $id  메모 ID
     * @return JsonResponse 메모 상세 응답
     */
    public function show(int $id): JsonResponse
    {
        try {
            $memo = $this->memoService->getMemo($id);

            return $this->successWithResource(
                'gnuboard7-hello_module::messages.memo.fetch_success',
                new MemoResource($memo)
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->notFound('gnuboard7-hello_module::messages.memo.not_found');
        } catch (\Exception $e) {
            return $this->error('gnuboard7-hello_module::messages.memo.fetch_failed', 500, $e->getMessage());
        }
    }

    /**
     * 메모를 생성합니다.
     *
     * @param  StoreMemoRequest  $request  메모 생성 요청
     * @return JsonResponse 생성된 메모 응답
     */
    public function store(StoreMemoRequest $request): JsonResponse
    {
        try {
            $memo = $this->memoService->createMemo($request->validated());

            return $this->successWithResource(
                'gnuboard7-hello_module::messages.memo.create_success',
                new MemoResource($memo),
                201
            );
        } catch (\Exception $e) {
            return $this->error('gnuboard7-hello_module::messages.memo.create_failed', 500, $e->getMessage());
        }
    }

    /**
     * 메모를 수정합니다.
     *
     * @param  UpdateMemoRequest  $request  메모 수정 요청
     * @param  int  $id  메모 ID
     * @return JsonResponse 수정된 메모 응답
     */
    public function update(UpdateMemoRequest $request, int $id): JsonResponse
    {
        try {
            $memo = $this->memoService->getMemo($id);
            $memo = $this->memoService->updateMemo($memo, $request->validated());

            return $this->successWithResource(
                'gnuboard7-hello_module::messages.memo.update_success',
                new MemoResource($memo)
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->notFound('gnuboard7-hello_module::messages.memo.not_found');
        } catch (\Exception $e) {
            return $this->error('gnuboard7-hello_module::messages.memo.update_failed', 500, $e->getMessage());
        }
    }

    /**
     * 메모를 삭제합니다.
     *
     * @param  int  $id  메모 ID
     * @return JsonResponse 삭제 결과 응답
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $memo = $this->memoService->getMemo($id);
            $this->memoService->deleteMemo($memo);

            return $this->success('gnuboard7-hello_module::messages.memo.delete_success');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->notFound('gnuboard7-hello_module::messages.memo.not_found');
        } catch (\Exception $e) {
            return $this->error('gnuboard7-hello_module::messages.memo.delete_failed', 500, $e->getMessage());
        }
    }
}
