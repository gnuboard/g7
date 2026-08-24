<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\MessageBizppurio\Http\Requests\DispatchResultLookupRequest;
use Plugins\Sirsoft\MessageBizppurio\Services\DispatchResultService;

/**
 * 코어 알림 발송 이력 화면용 비즈뿌리오 발송 결과 조회 컨트롤러 (A-2 표시 주입).
 *
 * 코어 "알림 발송 이력" 화면(sirsoft-admin_basic)에 plugin overlay 로 얹은 결과 컬럼이, 현재
 * 페이지의 코어 알림 로그 id 배열을 이 API 로 넘겨 비즈뿌리오 결과(상태·사유·잔액부족·대체발송)를
 * 한 번에 조회한다. 코어 화면·코어 앱·코어 테이블은 전혀 건드리지 않는다(연결 표식은 우리 dispatch
 * 쪽에만 있고, 이 API 가 그것을 읽어 로그 id 키 맵으로 돌려준다).
 *
 * 권한(라우트 미들웨어): 조회 = messaging.view.
 */
class DispatchResultController extends AdminBaseController
{
    /**
     * @param  DispatchResultService  $service  결과 조회 서비스
     */
    public function __construct(
        private readonly DispatchResultService $service,
    ) {
        parent::__construct();
    }

    /**
     * 코어 알림 로그 id 배열에 대한 비즈뿌리오 결과 맵을 반환합니다.
     *
     * @param  DispatchResultLookupRequest  $request  검증된 로그 id 목록
     * @return JsonResponse data.results 에 notification_log_id → 결과 맵
     */
    public function lookup(DispatchResultLookupRequest $request): JsonResponse
    {
        return ResponseHelper::success('messages.success', [
            'results' => $this->service->resultsForLogIds($request->logIds()),
        ]);
    }

    /**
     * 최근 연결된 비즈뿌리오 결과 맵을 반환합니다 (코어 이력 화면 결과 컬럼용, 타이밍 무관).
     *
     * 파라미터 없이 GET 으로 최근 결과 맵을 받아 화면이 row.id 로 매칭한다(kginicis test-map 선례).
     * 목록 data_source(notificationLogs) 로드 순서에 의존하지 않아, 결과 컬럼이 비는 문제를 원천 차단.
     *
     * @return JsonResponse data.results 에 notification_log_id → 결과 맵
     */
    public function recent(): JsonResponse
    {
        return ResponseHelper::success('messages.success', [
            'results' => $this->service->recentResults(),
        ]);
    }
}
