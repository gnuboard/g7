<?php

namespace Plugins\Sirsoft\Koreaexim\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\Koreaexim\Http\Requests\Admin\IndexRateRunRequest;
use Plugins\Sirsoft\Koreaexim\Http\Requests\Admin\StatusRequest;
use Plugins\Sirsoft\Koreaexim\Http\Requests\Admin\StoreRateRunRequest;
use Plugins\Sirsoft\Koreaexim\Http\Resources\RateRunResource;
use Plugins\Sirsoft\Koreaexim\Services\RateRunService;

class RateRunController extends AdminBaseController
{
    /**
     * 처리에 필요한 의존 객체를 주입합니다.
     *
     * @param  RateRunService  $service  service 입력값
     */
    public function __construct(private RateRunService $service)
    {
        parent::__construct();
    }

    /**
     * 관리 상태와 최근 이력·다음 실행 예정 시각을 반환합니다.
     *
     * @param  StatusRequest  $request  request 입력값
     * @return JsonResponse 처리 결과
     */
    public function status(StatusRequest $request): JsonResponse
    {
        $data = $this->service->status();
        foreach (['last_run', 'last_success_run'] as $key) {
            $data[$key] = $data[$key] ? new RateRunResource($data[$key]) : null;
        }
        $data['abilities'] = ['can_manage' => $request->user()->hasPermission('sirsoft-koreaexim.exchange_rate.manage')];

        return ResponseHelper::success('common.success', $data);
    }

    /**
     * 필터에 맞는 실행 이력을 응답합니다.
     *
     * @param  IndexRateRunRequest  $request  request 입력값
     * @return JsonResponse 처리 결과
     */
    public function index(IndexRateRunRequest $request): JsonResponse
    {
        $page = $this->service->paginate($request->validated());

        return ResponseHelper::success('common.success', ['data' => RateRunResource::collection($page->items()),
            'pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'has_more_pages' => $page->hasMorePages()]]);
    }

    /**
     * 실행 한 건과 통화별 상세를 응답합니다.
     *
     * @param  int  $run  run 입력값
     * @return JsonResponse 처리 결과
     */
    public function show(int $run): JsonResponse
    {
        return ResponseHelper::success('common.success', new RateRunResource($this->service->find($run)));
    }

    /**
     * 관리자의 수동 갱신 또는 연결 테스트를 실행합니다.
     *
     * @param  StoreRateRunRequest  $request  request 입력값
     * @return JsonResponse 처리 결과
     */
    public function store(StoreRateRunRequest $request): JsonResponse
    {
        $run = $this->service->manual($request->boolean('dry_run'), $request->user()->id);

        return $run === null ? ResponseHelper::error('sirsoft-koreaexim::messages.busy', 429)
            : ResponseHelper::success('common.success', new RateRunResource($run));
    }
}
