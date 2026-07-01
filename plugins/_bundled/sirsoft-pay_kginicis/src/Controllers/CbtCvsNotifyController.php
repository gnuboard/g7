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
        return response($body, 200)->header('Content-Type', 'text/plain');
    }
}
