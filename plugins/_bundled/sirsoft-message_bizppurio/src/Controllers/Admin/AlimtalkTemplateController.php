<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\MessageBizppurio\Concerns\GuardsKakaoRequests;
use Plugins\Sirsoft\MessageBizppurio\Services\AlimtalkTemplateService;

/**
 * 알림톡 작성 모달 참조 조회 컨트롤러 (#597).
 *
 * 알림톡 템플릿 작성 모달의 발신프로필 셀렉트·카테고리 셀렉트가 소비하는 kapi 조회를
 * 위임한다. 템플릿 목록/상세의 실시간 조회 화면(구 Phase 5)은 DB 기반 라이프사이클
 * (BizppurioTemplateController)로 대체되어 제거됐다.
 *
 * 권한(라우트 미들웨어): 조회(categories/profiles) = messaging.view
 *
 * kapi 실패는 BizppurioApiException 으로 전달되므로, guard 로 감싸 카카오가 준
 * 실패 사유(message)를 그대로 422 로 반환한다.
 */
class AlimtalkTemplateController extends AdminBaseController
{
    use GuardsKakaoRequests;

    /**
     * @param  AlimtalkTemplateService  $service  발신프로필·카테고리 조회 서비스
     */
    public function __construct(
        private readonly AlimtalkTemplateService $service,
    ) {
        parent::__construct();
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
}
