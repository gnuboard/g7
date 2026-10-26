<?php

namespace Plugins\Sirsoft\Sso\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\Sso\Http\Requests\Admin\LogListRequest;
use Plugins\Sirsoft\Sso\Services\Admin\LogAdminService;

/**
 * 관리자 「이력」 API — 로그인 시도, SCIM 수신.
 *
 * 이력에는 토큰, 어설션 원문, SCIM 본문이 없다(남기지 않는다).
 *
 * @since 1.0.0
 */
class LogController extends AdminBaseController
{
    /**
     * @param  LogAdminService  $logs  이력 조회
     */
    public function __construct(private readonly LogAdminService $logs)
    {
        parent::__construct();
    }

    /**
     * 로그인 시도 목록.
     *
     * @param  LogListRequest  $request  검증된 조건
     * @return JsonResponse `{data, pagination}`
     */
    public function attempts(LogListRequest $request): JsonResponse
    {
        return ResponseHelper::pluginSuccess('sirsoft-sso', 'admin.logs.listed', $this->page($this->logs->attempts($request->validated())));
    }

    /**
     * SCIM 수신 이력 목록.
     *
     * @param  LogListRequest  $request  검증된 조건
     * @return JsonResponse `{data, pagination}`
     */
    public function scim(LogListRequest $request): JsonResponse
    {
        return ResponseHelper::pluginSuccess('sirsoft-sso', 'admin.logs.listed', $this->page($this->logs->scimEvents($request->validated())));
    }

    /**
     * 페이지를 응답 모양으로.
     *
     * @param  LengthAwarePaginator  $page  페이지
     * @return array{data: array<int, mixed>, pagination: array<string, int>} 본문
     */
    private function page(LengthAwarePaginator $page): array
    {
        return [
            'data' => collect($page->items())->map(fn ($row) => is_object($row) && method_exists($row, 'toArray') ? $row->toArray() : (array) $row)->values()->all(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ];
    }
}
