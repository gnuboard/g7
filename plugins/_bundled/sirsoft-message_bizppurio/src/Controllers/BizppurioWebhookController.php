<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Controllers;

use App\Helpers\ResponseHelper;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\MessageBizppurio\Http\Requests\BizppurioWebhookRequest;
use Plugins\Sirsoft\MessageBizppurio\Services\WebhookReportService;

/**
 * 비즈뿌리오 webhook(URL PUSH) 리포트 수신 컨트롤러.
 *
 * 인증은 라우트의 IP 화이트리스트 미들웨어가 담당한다. 검증된 리포트를
 * WebhookReportService 에 위임해 상태전이·replay 멱등·잔액부족 알림을 처리한다.
 *
 * 항상 200 으로 응답한다 — 비즈뿌리오가 실패 응답을 재전송(=중복 리포트)하지 않도록
 * 하기 위함이며, 위조/미매칭도 성공 수신으로 흡수한다(replay 멱등이 재처리를 막음).
 */
class BizppurioWebhookController
{
    /**
     * @param  WebhookReportService  $reports  리포트 처리 서비스
     */
    public function __construct(
        private readonly WebhookReportService $reports,
    ) {}

    /**
     * webhook 리포트를 수신해 발송 이력 상태를 갱신합니다.
     *
     * @param  BizppurioWebhookRequest  $request  검증된 리포트
     * @return JsonResponse 항상 200
     */
    public function handle(BizppurioWebhookRequest $request): JsonResponse
    {
        $this->reports->apply([
            'REFKEY' => $request->input('REFKEY'),
            'RESULT' => $request->input('RESULT'),
            'MEDIA' => $request->input('MEDIA'),
            'TELRES' => $request->input('TELRES'),
            'KAORES' => $request->input('KAORES'),
            'raw' => $request->all(),
        ]);

        return ResponseHelper::success('sirsoft-message_bizppurio::messages.webhook.received');
    }
}
