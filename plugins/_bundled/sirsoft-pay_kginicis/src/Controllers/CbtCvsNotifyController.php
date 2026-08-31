<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use Illuminate\Http\Response;
use Plugins\Sirsoft\PayKginicis\Http\Requests\CbtCvsNotifyRequest;
use Plugins\Sirsoft\PayKginicis\Services\CbtCvsOperationsService;

/**
 * KG 이니시스 CBT 편의점 입금 NOTI 수신 컨트롤러.
 */
class CbtCvsNotifyController
{
    public function __construct(
        private readonly CbtCvsOperationsService $operationsService,
    ) {}

    /**
     * KG 이니시스 CBT 편의점 입금 통보를 수신한다.
     *
     * @param  CbtCvsNotifyRequest  $request
     * @return Response
     */
    public function handle(CbtCvsNotifyRequest $request): Response
    {
        $result = $this->operationsService->handleNotify($request->all(), 'kg', $request->ip());

        return $this->plain((string) ($result['body'] ?? 'FAIL'));
    }

    private function plain(string $body): Response
    {
        // file-serving:allow 파일이 아니라 이 자리에서 만든 통보 응답 본문을 돌려준다 — 서빙 계약
        // (nosniff·ETag·304·압축 제외) 의 대상이 아니다. 표식이 없으면 소스 계약 검사가 이
        // 메서드를 파일 서빙으로 읽는다. 형제 인증 브리지 둘과 같은 처분이며, 이 4개는
        // `src/Controllers` 를 써서 계약 모집단 자체에 없었다 (23차 G3 라운드 B23-6).
        return response($body, 200)->header('Content-Type', 'text/plain');
    }
}
