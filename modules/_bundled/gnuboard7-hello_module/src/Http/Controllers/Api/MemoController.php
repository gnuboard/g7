<?php

namespace Modules\Gnuboard7\HelloModule\Http\Controllers\Api;

use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Gnuboard7\HelloModule\Http\Resources\MemoCollection;
use Modules\Gnuboard7\HelloModule\Http\Resources\MemoResource;
use Modules\Gnuboard7\HelloModule\Services\MemoService;

/**
 * 공개용 메모 조회 컨트롤러
 *
 * 비로그인 사용자도 메모 목록 및 상세를 조회할 수 있습니다.
 */
class MemoController extends PublicBaseController
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
}
