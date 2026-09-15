<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\MessageBizppurio\Enums\BizppurioTemplateStatus;
use Plugins\Sirsoft\MessageBizppurio\Exceptions\BizppurioApiException;
use Plugins\Sirsoft\MessageBizppurio\Exceptions\BizppurioTemplateStateException;
use Plugins\Sirsoft\MessageBizppurio\Http\Requests\BizppurioTemplateImageRequest;
use Plugins\Sirsoft\MessageBizppurio\Http\Requests\BizppurioTemplateListRequest;
use Plugins\Sirsoft\MessageBizppurio\Http\Requests\RequestBizppurioInspectionRequest;
use Plugins\Sirsoft\MessageBizppurio\Http\Requests\StoreBizppurioTemplateRequest;
use Plugins\Sirsoft\MessageBizppurio\Http\Requests\UpdateBizppurioDeliveryRequest;
use Plugins\Sirsoft\MessageBizppurio\Http\Requests\UpdateBizppurioTemplateRequest;
use Plugins\Sirsoft\MessageBizppurio\Models\BizppurioTemplate;
use Plugins\Sirsoft\MessageBizppurio\Services\BizppurioTemplateService;

/**
 * 비즈뿌리오 알림 템플릿 라이프사이클 컨트롤러 (#597 §3.2).
 *
 * 시스템 등록(draft) → 검수 신청(requested) → 승인(approved) 후 발송 활성화의 전 흐름을
 * 담당한다. 발송 판정은 DB 가 유일한 근거이며(실시간 조회 폐지), 카카오와의 상태 정합은
 * 스케줄러(bizppurio:sync-template-status)와 수동 sync 가 유지한다.
 *
 * 권한(라우트 미들웨어):
 *  - 조회(index/map/show): sirsoft-message_bizppurio.messaging.view
 *  - 변경(store/update/request/cancel/sync/image/destroy): sirsoft-message_bizppurio.messaging.manage
 *
 * 실패 응답 규약:
 *  - kapi 실패(BizppurioApiException) → 422 + errors.bizppurio_message(카카오 사유 원문)·errors.result_code
 *  - 상태 전이 위반(BizppurioTemplateStateException) → 422 + 예외의 메시지 키 해석
 */
class BizppurioTemplateController extends AdminBaseController
{
    /**
     * @param  BizppurioTemplateService  $service  템플릿 라이프사이클 서비스
     */
    public function __construct(
        private readonly BizppurioTemplateService $service,
    ) {
        parent::__construct();
    }

    /**
     * 템플릿 DB 목록을 조회합니다 (관리 화면 — 알림 정의 라벨·소속 조인).
     *
     * 쿼리: status(우리 상태 enum)·search(알림 유형/이름)·page·per_page
     *
     * @param  BizppurioTemplateListRequest  $request  검증된 목록 필터
     * @return JsonResponse data 에 templates·pagination
     */
    public function index(BizppurioTemplateListRequest $request): JsonResponse
    {
        $paginator = $this->service->list($request->filters(), $request->page(), $request->perPage());

        return ResponseHelper::success('messages.success', [
            'templates' => collect($paginator->items())
                ->map(fn (BizppurioTemplate $row) => $this->listRow($row))
                ->all(),
            'pagination' => [
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'has_more_pages' => $paginator->hasMorePages(),
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
            ],
        ]);
    }

    /**
     * 알림 설정 탭 행 표시용 요약 맵을 조회합니다 (notification_type 키).
     *
     * 알림 설정 3면(코어/게시판/이커머스)의 행 하단 UI 가 소비한다. 행 수는 알림 정의
     * 수에 묶인 설정성 데이터라 페이지네이션 없이 전량 내려준다.
     *
     * @return JsonResponse data.templates 에 notification_type 키 요약 맵
     */
    public function map(): JsonResponse
    {
        return ResponseHelper::success('messages.success', [
            'templates' => $this->service->summaryMap(),
        ]);
    }

    /**
     * 템플릿 상세를 조회합니다 (content·inspection_detail 포함).
     *
     * @param  int  $id  템플릿 PK
     * @return JsonResponse data.template 에 상세
     */
    public function show(int $id): JsonResponse
    {
        $template = $this->service->find($id);
        if ($template === null) {
            return ResponseHelper::error('messages.not_found', 404);
        }

        return ResponseHelper::success('messages.success', [
            'template' => $this->detailRow($template),
        ]);
    }

    /**
     * 템플릿을 생성합니다 (status=draft).
     *
     * @param  StoreBizppurioTemplateRequest  $request  검증된 생성 데이터
     * @return JsonResponse data.template 에 생성된 행
     */
    public function store(StoreBizppurioTemplateRequest $request): JsonResponse
    {
        $template = $this->service->create($request->validated());

        return ResponseHelper::success(
            'sirsoft-message_bizppurio::messages.template.created',
            ['template' => $this->detailRow($template)],
            201,
        );
    }

    /**
     * 템플릿을 수정합니다 (content 변경은 draft/rejected 만).
     *
     * @param  UpdateBizppurioTemplateRequest  $request  검증된 수정 데이터
     * @param  int  $id  템플릿 PK
     * @return JsonResponse data.template 에 갱신된 행
     */
    public function update(UpdateBizppurioTemplateRequest $request, int $id): JsonResponse
    {
        $template = $this->service->find($id);
        if ($template === null) {
            return ResponseHelper::error('messages.not_found', 404);
        }

        return $this->guardLifecycle(fn () => ResponseHelper::success(
            'sirsoft-message_bizppurio::messages.template.updated',
            ['template' => $this->detailRow($this->service->update($template, $request->validated()))],
        ));
    }

    /**
     * 발송 설정(알림톡 사용·대체 SMS·SMS 본문·SMS 단독·활성)을 upsert 합니다.
     *
     * 알림 설정 탭 행 하단 토글의 즉시 저장 경로. 대상 행이 없으면 draft 로 생성한다.
     *
     * @param  UpdateBizppurioDeliveryRequest  $request  검증된 발송 설정
     * @return JsonResponse data.template 에 저장된 행
     */
    public function upsertDelivery(UpdateBizppurioDeliveryRequest $request): JsonResponse
    {
        $template = $this->service->upsertDelivery(
            (string) $request->route('notificationType'),
            $request->deliveryData(),
        );

        return ResponseHelper::success(
            'sirsoft-message_bizppurio::messages.template.updated',
            ['template' => $this->detailRow($template)],
        );
    }

    /**
     * 검수를 신청합니다 (채번 → kapi add/update → request → status=requested).
     *
     * @param  RequestBizppurioInspectionRequest  $request  검수자 전달 의견(comment, 선택 ≤500)
     * @param  int  $id  템플릿 PK
     * @return JsonResponse data.template 에 갱신된 행
     */
    public function requestInspection(RequestBizppurioInspectionRequest $request, int $id): JsonResponse
    {
        $template = $this->service->find($id);
        if ($template === null) {
            return ResponseHelper::error('messages.not_found', 404);
        }

        return $this->guardLifecycle(fn () => ResponseHelper::success(
            'sirsoft-message_bizppurio::messages.template.requested',
            ['template' => $this->detailRow($this->service->requestInspection($template, $request->comment()))],
        ));
    }

    /**
     * 검수 신청을 취소합니다 (REQ→REG, status=draft 복귀).
     *
     * @param  int  $id  템플릿 PK
     * @return JsonResponse data.template 에 갱신된 행
     */
    public function cancelRequest(int $id): JsonResponse
    {
        $template = $this->service->find($id);
        if ($template === null) {
            return ResponseHelper::error('messages.not_found', 404);
        }

        return $this->guardLifecycle(fn () => ResponseHelper::success(
            'sirsoft-message_bizppurio::messages.template.request_cancelled',
            ['template' => $this->detailRow($this->service->cancelRequest($template))],
        ));
    }

    /**
     * 승인을 취소합니다 (승인→등록 복귀 — 알림톡 발송 즉시 차단).
     *
     * @param  int  $id  템플릿 PK
     * @return JsonResponse data.template 에 갱신된 행
     */
    public function cancelApproval(int $id): JsonResponse
    {
        $template = $this->service->find($id);
        if ($template === null) {
            return ResponseHelper::error('messages.not_found', 404);
        }

        return $this->guardLifecycle(fn () => ResponseHelper::success(
            'sirsoft-message_bizppurio::messages.template.approval_cancelled',
            ['template' => $this->detailRow($this->service->cancelApproval($template))],
        ));
    }

    /**
     * 휴면(DMT) 템플릿을 해제합니다.
     *
     * @param  int  $id  템플릿 PK
     * @return JsonResponse data.template 에 갱신된 행
     */
    public function release(int $id): JsonResponse
    {
        $template = $this->service->find($id);
        if ($template === null) {
            return ResponseHelper::error('messages.not_found', 404);
        }

        return $this->guardLifecycle(fn () => ResponseHelper::success(
            'sirsoft-message_bizppurio::messages.template.released',
            ['template' => $this->detailRow($this->service->releaseDormant($template))],
        ));
    }

    /**
     * 단건 상태를 카카오와 동기화합니다 (수동 [새로고침]).
     *
     * @param  int  $id  템플릿 PK
     * @return JsonResponse data.template 에 갱신된 행
     */
    public function sync(int $id): JsonResponse
    {
        $template = $this->service->find($id);
        if ($template === null) {
            return ResponseHelper::error('messages.not_found', 404);
        }

        return $this->guardLifecycle(fn () => ResponseHelper::success(
            'sirsoft-message_bizppurio::messages.template.synced',
            ['template' => $this->detailRow($this->service->sync($template))],
        ));
    }

    /**
     * 이미지형 템플릿용 이미지를 업로드합니다 (kapi 업로드 프록시).
     *
     * @param  BizppurioTemplateImageRequest  $request  검증된 이미지 파일
     * @return JsonResponse data.url 에 카카오 이미지 URL
     */
    public function uploadImage(BizppurioTemplateImageRequest $request): JsonResponse
    {
        return $this->guardLifecycle(fn () => ResponseHelper::success(
            'sirsoft-message_bizppurio::messages.template.image_uploaded',
            ['url' => $this->service->uploadImage($request->file('image'))],
        ));
    }

    /**
     * 템플릿을 삭제합니다 (카카오측은 삭제 가능 상태일 때만 동반 삭제).
     *
     * @param  int  $id  템플릿 PK
     * @return JsonResponse data 에 kakao_deleted·kakao_skip_reason
     */
    public function destroy(int $id): JsonResponse
    {
        $template = $this->service->find($id);
        if ($template === null) {
            return ResponseHelper::error('messages.not_found', 404);
        }

        return ResponseHelper::success(
            'sirsoft-message_bizppurio::messages.template.deleted',
            $this->service->delete($template),
        );
    }

    /**
     * 라이프사이클 액션을 실행하고 도메인 실패를 422 로 매핑합니다.
     *
     * - BizppurioApiException: 카카오가 준 사유 원문(bizppurio_message)·결과코드를 errors 로 노출
     *   (관리자 전용 면 — 조치 근거 제공, TokenCheckController 규약과 동일)
     * - BizppurioTemplateStateException: 예외가 든 메시지 키를 해석해 422
     * 그 외 예외는 잡지 않는다 — 인프라 장애·코드 결함은 500 으로 드러나야 한다.
     *
     * @param  callable():JsonResponse  $callback  라이프사이클 액션
     * @return JsonResponse 성공 응답 또는 422
     */
    private function guardLifecycle(callable $callback): JsonResponse
    {
        try {
            return $callback();
        } catch (BizppurioApiException $e) {
            return ResponseHelper::error(
                'sirsoft-message_bizppurio::messages.error.kakao_request_failed',
                422,
                [
                    'bizppurio_message' => $e->getMessage(),
                    'result_code' => $e->getResultCode(),
                ],
            );
        } catch (BizppurioTemplateStateException $e) {
            return ResponseHelper::error($e->getMessageKey(), 422, null, $e->getMessageParams());
        }
    }

    /**
     * 관리 화면 목록 행을 직렬화합니다 (조인된 정의 라벨·소속 포함, 대형 JSON 제외).
     *
     * @param  BizppurioTemplate  $row  목록 행 (definition_* 조인 별칭 포함)
     * @return array<string, mixed> 직렬화된 행
     */
    private function listRow(BizppurioTemplate $row): array
    {
        $definitionName = $row->getAttribute('definition_name');
        $definitionVariables = $row->getAttribute('definition_variables');

        return [
            'id' => $row->id,
            'notification_type' => $row->notification_type,
            'definition_name' => is_string($definitionName) ? (json_decode($definitionName, true) ?: null) : null,
            'definition_variables' => is_string($definitionVariables) ? (json_decode($definitionVariables, true) ?: []) : [],
            'definition_extension_type' => $row->getAttribute('definition_extension_type'),
            'definition_extension_identifier' => $row->getAttribute('definition_extension_identifier'),
            'alimtalk_enabled' => $row->alimtalk_enabled,
            'template_code' => $row->template_code,
            'status' => $row->status->value,
            'has_content' => (bool) $row->getAttribute('has_content'),
            'requested_at' => $row->requested_at?->toIso8601String(),
            'approved_at' => $row->approved_at?->toIso8601String(),
            'last_synced_at' => $row->last_synced_at?->toIso8601String(),
            'fallback_sms_enabled' => $row->fallback_sms_enabled,
            'sms_only' => $row->sms_only,
            'is_active' => $row->is_active,
        ];
    }

    /**
     * 템플릿 상세를 직렬화합니다 (content·승인 스냅샷·반려 사유 포함).
     *
     * @param  BizppurioTemplate  $template  대상 행
     * @return array<string, mixed> 직렬화된 상세
     */
    private function detailRow(BizppurioTemplate $template): array
    {
        return [
            'id' => $template->id,
            'notification_type' => $template->notification_type,
            'alimtalk_enabled' => $template->alimtalk_enabled,
            'template_code' => $template->template_code,
            'sender_key' => $template->sender_key,
            'content' => $template->content,
            'approved_content' => $template->approved_content,
            'status' => $template->status->value,
            'is_approved' => $template->status === BizppurioTemplateStatus::Approved,
            'inspection_detail' => $template->inspection_detail,
            'requested_at' => $template->requested_at?->toIso8601String(),
            'approved_at' => $template->approved_at?->toIso8601String(),
            'last_synced_at' => $template->last_synced_at?->toIso8601String(),
            'fallback_sms_enabled' => $template->fallback_sms_enabled,
            'sms_body' => $template->sms_body,
            'sms_only' => $template->sms_only,
            'is_active' => $template->is_active,
        ];
    }
}
