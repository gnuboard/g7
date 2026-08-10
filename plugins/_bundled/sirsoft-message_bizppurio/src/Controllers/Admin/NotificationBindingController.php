<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\MessageBizppurio\Concerns\GuardsKakaoRequests;
use Plugins\Sirsoft\MessageBizppurio\Http\Requests\StoreNotificationBindingRequest;
use Plugins\Sirsoft\MessageBizppurio\Services\NotificationBindingService;

/**
 * 알림↔알림톡 템플릿 연동 컨트롤러 (계획서 §6-2, Phase 6 재설계).
 *
 * 알림 설정 알림톡 탭은 코어 기본 목록·편집 모달을 그대로 쓴다(⚑⚑ 결정 A). 연결 템플릿·SMS
 * 대체 입력은 코어 편집 모달에 얹은 전용 칸(플러그인 overlay)에서 하고, 값을 바꾸면 즉시 이
 * 컨트롤러로 저장한다(PO 확정 UX — 별도 저장 버튼 없이 변경 즉시 저장, 코어 저장 버튼과 무관해
 * 코어 템플릿 무오염). 코어 편집 모달·저장 버튼은 전혀 건드리지 않는다.
 *
 * - index/all: 현재 알림톡 연동 맵(전용 칸 프리필)
 * - approvedTemplates: 연결 가능(승인) 템플릿 드롭다운 옵션
 * - store: 연결 템플릿·SMS 대체 즉시 저장(빈 코드=해제)
 *
 * 권한(라우트 미들웨어): 조회 = messaging.view, 저장 = messaging.manage
 */
class NotificationBindingController extends AdminBaseController
{
    use GuardsKakaoRequests;

    /**
     * @param  NotificationBindingService  $service  연동 서비스
     */
    public function __construct(
        private readonly NotificationBindingService $service,
    ) {
        parent::__construct();
    }

    /**
     * 알림톡 채널의 현재 연동 맵을 반환합니다 (전용 칸 프리필).
     *
     * notification_type → 연동 정보(연결 템플릿·SMS 대체) 맵. 전용 칸이 편집 대상 알림의
     * type 으로 조회해 드롭다운·토글 초기값을 채운다.
     *
     * @return JsonResponse data.bindings 에 notification_type 키의 연동 맵
     */
    public function index(): JsonResponse
    {
        // 알림톡 탭 진입 표시용 — 카카오 승인 목록과 대조해 소실(발송 불가) 연동을 함께 표시한다.
        // 카카오 조회 실패 시 서비스가 판정을 생략하므로 이 조회가 목록 표시를 막지 않는다.
        return ResponseHelper::success('messages.success', [
            'bindings' => $this->service->all(withAvailability: true),
        ]);
    }

    /**
     * 연결 가능한(발송 가능/승인) 알림톡 템플릿 목록을 반환합니다 (연동 드롭다운).
     *
     * kapi 실패는 카카오가 준 사유를 그대로 422 로 반환한다.
     *
     * @return JsonResponse data.templates 에 승인 템플릿(code/name) 배열, 실패 시 422
     */
    public function approvedTemplates(): JsonResponse
    {
        return $this->guard(fn () => ResponseHelper::success('messages.success', [
            'templates' => $this->service->approvedTemplates(),
        ]));
    }

    /**
     * 연결 템플릿·SMS 대체를 즉시 저장합니다 (전용 칸 변경 시 자동 호출).
     *
     * 연결 템플릿 코드가 비어 있으면 연동 해제, 있으면 생성/갱신한다("빈 코드=해제" 규칙 —
     * 드롭다운에서 "연결 안 함"을 고르면 해제까지 한 번에 처리). SMS 대체는 연결이 있을 때만
     * 의미가 있다.
     *
     * @param  StoreNotificationBindingRequest  $request  검증된 연동 입력
     * @return JsonResponse 저장 결과 (해제 시에도 200)
     */
    public function store(StoreNotificationBindingRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return $this->guard(function () use ($validated) {
            $this->service->applyFromTemplateSave(
                $validated['notification_type'],
                $validated['template_code'] ?? null,
                $validated['template_name'] ?? null,
                (bool) ($validated['fallback_sms_enabled'] ?? false),
            );

            return ResponseHelper::success('messages.binding.saved', [
                'bindings' => $this->service->all(),
            ]);
        });
    }
}
