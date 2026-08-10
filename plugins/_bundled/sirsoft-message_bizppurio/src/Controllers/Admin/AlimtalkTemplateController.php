<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Plugins\Sirsoft\MessageBizppurio\Concerns\GuardsKakaoRequests;
use Plugins\Sirsoft\MessageBizppurio\Services\AlimtalkTemplateService;
use Plugins\Sirsoft\MessageBizppurio\Services\NotificationBindingService;

/**
 * 알림톡 템플릿 조회 컨트롤러 (Phase 5).
 *
 * 카카오 관리 API(kapi)로 알림톡 템플릿을 실시간 조회한다(목록·상세·카테고리·발신프로필).
 * 등록·수정·삭제·검수·상태변경은 비즈뿌리오 콘솔로 위임하며, 이 화면은 조회 + 알림 연결만
 * 담당한다. 템플릿은 DB 에 저장하지 않고 목록/상세를 매 요청 실시간 조회한다.
 *
 * 권한(라우트 미들웨어):
 * - 조회(list/detail/categories/profiles): sirsoft-message_bizppurio.messaging.view
 *
 * kapi 실패는 BizppurioApiException 으로 전달되므로, 각 액션에서 catch 하여 카카오가 준
 * 실패 사유(message)를 그대로 422 로 반환한다(운영자가 조회 실패 원인을 바로 확인).
 */
class AlimtalkTemplateController extends AdminBaseController
{
    use GuardsKakaoRequests;

    /**
     * @param  AlimtalkTemplateService  $service  알림톡 템플릿 서비스
     * @param  NotificationBindingService  $bindings  연동 서비스(발송 내용 캐시 초기화 위임)
     */
    public function __construct(
        private readonly AlimtalkTemplateService $service,
        private readonly NotificationBindingService $bindings,
    ) {
        parent::__construct();
    }

    /**
     * 알림톡 템플릿 목록을 실시간 조회합니다.
     *
     * 쿼리: status(templateStatus)·keyword(2~50자)·page·count
     *
     * @param  Request  $request  HTTP 요청
     * @return JsonResponse data 에 templates·pagination
     */
    public function index(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            $result = $this->service->list([
                'status' => $request->query('status'),
                'keyword' => $request->query('keyword'),
                'page' => $request->query('page'),
                'count' => $request->query('count'),
            ]);

            return ResponseHelper::success('messages.success', $result);
        });
    }

    /**
     * 알림톡 템플릿 상세를 실시간 조회합니다.
     *
     * @param  string  $templateCode  템플릿 코드
     * @return JsonResponse data.template 에 배지·가능 액션이 부가된 상세
     */
    public function show(string $templateCode): JsonResponse
    {
        return $this->guard(fn () => ResponseHelper::success('messages.success', [
            'template' => $this->service->detail($templateCode),
        ]));
    }

    /**
     * 템플릿 등록에 사용할 카테고리 전체를 조회합니다.
     *
     * @return JsonResponse data.categories 에 대분류·소분류 목록
     */
    public function categories(): JsonResponse
    {
        return $this->guard(fn () => ResponseHelper::success('messages.success', [
            'categories' => $this->service->categories(),
        ]));
    }

    /**
     * 발신프로필(사용중) 정보를 조회합니다.
     *
     * @return JsonResponse data.profiles 에 발신프로필 상태 정보
     */
    public function profiles(): JsonResponse
    {
        return $this->guard(fn () => ResponseHelper::success('messages.success', [
            'profiles' => $this->service->senderProfiles(),
        ]));
    }

    /**
     * 발송용 템플릿 내용 캐시를 초기화합니다 (관리자 수동 갱신).
     *
     * 카카오에서 템플릿 내용을 방금 변경해 캐시 만료(기본 1시간)를 기다리지 않고 즉시
     * 반영하고 싶을 때 호출한다. 연결된 모든 알림톡 템플릿의 캐시를 비워, 다음 발송에서
     * 최신 내용으로 재조회되게 한다. kapi 호출 없이 로컬 캐시만 비우므로 rate limit 영향 없음.
     *
     * @return JsonResponse data.cleared 에 초기화한 캐시 수
     */
    public function clearCache(): JsonResponse
    {
        $cleared = $this->bindings->clearTemplateContentCache();

        return ResponseHelper::success('messages.cache.cleared', [
            'cleared' => $cleared,
        ]);
    }

    // kapi 호출을 감싸 BizppurioApiException 을 422 응답으로 변환하는 guard() 는
    // GuardsKakaoRequests 트레이트로 이관(연동 컨트롤러와 공유).
    // 카카오가 준 실패 사유(message)를 그대로 노출해 운영자가 조회 실패 원인을 즉시 파악한다.
}
