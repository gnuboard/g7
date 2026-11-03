<?php

namespace Plugins\Sirsoft\Popup\Http\Controllers\Admin;

use App\Exceptions\ConcurrentModificationException;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Plugins\Sirsoft\Popup\Http\Requests\Admin\PopupBulkStatusRequest;
use Plugins\Sirsoft\Popup\Http\Requests\Admin\PopupIdsRequest;
use Plugins\Sirsoft\Popup\Http\Requests\Admin\PopupListRequest;
use Plugins\Sirsoft\Popup\Http\Requests\Admin\PopupOrderListRequest;
use Plugins\Sirsoft\Popup\Http\Requests\Admin\PopupReorderRequest;
use Plugins\Sirsoft\Popup\Http\Requests\Admin\PopupSummaryRequest;
use Plugins\Sirsoft\Popup\Http\Requests\Admin\StorePopupRequest;
use Plugins\Sirsoft\Popup\Http\Requests\Admin\UpdatePopupRequest;
use Plugins\Sirsoft\Popup\Http\Resources\PopupCollection;
use Plugins\Sirsoft\Popup\Http\Resources\PopupResource;
use Plugins\Sirsoft\Popup\Models\Popup;
use Plugins\Sirsoft\Popup\Services\PopupService;

/**
 * 관리자 팝업 컨트롤러 — 목록·요약·순서·일괄(노출·복사·삭제)·등록·상세·수정·삭제.
 */
class PopupController extends AdminBaseController
{
    /**
     * PopupController 생성자
     *
     * @param  PopupService  $popups  팝업 서비스
     */
    public function __construct(private PopupService $popups)
    {
        parent::__construct();
    }

    /**
     * 팝업 목록(통계·abilities 포함)을 반환합니다.
     *
     * @param  PopupListRequest  $request  목록 요청
     * @return JsonResponse 목록 응답
     */
    public function index(PopupListRequest $request): JsonResponse
    {
        $collection = (new PopupCollection($this->popups->getList($request->validated())))
            ->withStatistics($this->popups->getStatistics());

        return $this->success('sirsoft-popup::messages.popup.fetched', $collection->toArray($request));
    }

    /**
     * 지금 노출 중인 팝업을 순서대로 반환합니다(목록 상단 「지금 노출 중」).
     *
     * @param  PopupSummaryRequest  $request  요청
     * @return JsonResponse 요약 응답
     */
    public function summary(PopupSummaryRequest $request): JsonResponse
    {
        $rows = $this->popups->getActiveSummary();

        return $this->success('sirsoft-popup::messages.popup.fetched', [
            'data' => $rows->map(fn (Popup $p) => (new PopupResource($p))->toListArray($request))->values()->all(),
        ]);
    }

    /**
     * 팝업 전체를 순서대로 반환합니다(순서 변경 창).
     *
     * @param  PopupOrderListRequest  $request  요청
     * @return JsonResponse 순서 목록 응답
     */
    public function orderList(PopupOrderListRequest $request): JsonResponse
    {
        $rows = $this->popups->getOrderList();

        return $this->success('sirsoft-popup::messages.popup.fetched', [
            'data' => $rows->map(fn (Popup $p) => (new PopupResource($p))->toListArray($request))->values()->all(),
        ]);
    }

    /**
     * 팝업 순서(= 캐러셀 순서)를 바꿉니다.
     *
     * @param  PopupReorderRequest  $request  순서 요청
     * @return JsonResponse 결과 응답
     */
    public function reorder(PopupReorderRequest $request): JsonResponse
    {
        $this->popups->reorder(array_map('intval', $request->validated()['ids']));

        return $this->success('sirsoft-popup::messages.popup.reordered');
    }

    /**
     * 여러 팝업을 노출·비노출로 바꿉니다.
     *
     * @param  PopupBulkStatusRequest  $request  일괄 상태 요청
     * @return JsonResponse 처리 건수 응답
     */
    public function bulkStatus(PopupBulkStatusRequest $request): JsonResponse
    {
        $data = $request->validated();
        $count = $this->popups->bulkSetActive(array_map('intval', $data['ids']), (bool) $data['is_active'], $request->user()?->id);

        return $this->success('sirsoft-popup::messages.popup.bulk_status', ['count' => $count], 200, ['count' => $count]);
    }

    /**
     * 여러 팝업을 복사합니다(복사본은 비활성·맨 뒤).
     *
     * @param  PopupIdsRequest  $request  일괄 요청
     * @return JsonResponse 복사 결과 응답
     */
    public function bulkCopy(PopupIdsRequest $request): JsonResponse
    {
        $result = $this->popups->bulkCopy(array_map('intval', $request->validated()['ids']), $request->user()?->id);
        $count = count($result['copies']);
        $failed = count($result['failed']);
        $data = ['count' => $count, 'copies' => $result['copies'], 'failed_ids' => $result['failed']];

        if ($failed > 0) {
            return $this->success('sirsoft-popup::messages.popup.bulk_copied_partial', $data, 200, ['count' => $count, 'failed' => $failed]);
        }

        return $this->success('sirsoft-popup::messages.popup.bulk_copied', $data, 200, ['count' => $count]);
    }

    /**
     * 여러 팝업을 영구 삭제합니다.
     *
     * @param  PopupIdsRequest  $request  일괄 요청
     * @return JsonResponse 처리 건수 응답
     */
    public function bulkDestroy(PopupIdsRequest $request): JsonResponse
    {
        $count = $this->popups->bulkDelete(array_map('intval', $request->validated()['ids']));

        return $this->success('sirsoft-popup::messages.popup.bulk_deleted', ['count' => $count], 200, ['count' => $count]);
    }

    /**
     * 팝업을 등록합니다.
     *
     * @param  StorePopupRequest  $request  등록 요청
     * @return JsonResponse 등록한 팝업 응답
     */
    public function store(StorePopupRequest $request): JsonResponse
    {
        $popup = $this->popups->create($request->validated(), $request->user()?->id);

        return $this->success('sirsoft-popup::messages.popup.created', $this->withSanitized($request, $popup), 201);
    }

    /**
     * 팝업 상세를 반환합니다.
     *
     * @param  Popup  $popup  대상 팝업 (라우트 모델 바인딩)
     * @return JsonResponse 상세 응답
     */
    public function show(Popup $popup): JsonResponse
    {
        return $this->successWithResource('sirsoft-popup::messages.popup.fetched', new PopupResource($this->popups->find($popup->id)));
    }

    /**
     * 팝업을 수정합니다(다른 관리자가 먼저 저장했으면 409).
     *
     * @param  UpdatePopupRequest  $request  수정 요청
     * @param  Popup  $popup  대상 팝업
     * @return JsonResponse 수정한 팝업 응답
     */
    public function update(UpdatePopupRequest $request, Popup $popup): JsonResponse
    {
        try {
            $updated = $this->popups->update($popup, $request->validated(), $request->user()?->id);
        } catch (ConcurrentModificationException) {
            // 검증 뒤 잠금을 잡기 전에 다른 관리자가 저장한 경우 — 검증 단계 409 와 같은 응답
            return $this->error('sirsoft-popup::messages.popup.conflict', 409);
        }

        return $this->success('sirsoft-popup::messages.popup.updated', $this->withSanitized($request, $updated));
    }

    /**
     * 팝업을 영구 삭제합니다(이미지 파일 포함).
     *
     * @param  Popup  $popup  대상 팝업
     * @return JsonResponse 결과 응답
     */
    public function destroy(Popup $popup): JsonResponse
    {
        $this->popups->delete($popup);

        return $this->success('sirsoft-popup::messages.popup.deleted');
    }

    /**
     * 상세 응답에 본문 정화로 내용이 바뀌었는지(`sanitized`)를 더합니다.
     *
     * @param  Request  $request  요청
     * @param  Popup  $popup  저장한 팝업
     * @return array<string, mixed> 상세 응답
     */
    private function withSanitized(Request $request, Popup $popup): array
    {
        return (new PopupResource($popup))->toArray($request) + ['sanitized' => $this->popups->bodyWasSanitized()];
    }
}
