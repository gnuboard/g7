<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Http\Controllers;

use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Plugins\Sirsoft\VerificationNhnkcp\Services\KcpCallbackResolver;

/**
 * KCP 표준창 인증 종료 콜백(Ret_URL) 수신 컨트롤러.
 *
 * KCP 표준창은 인증이 끝나면 거래등록 시 지정한 `Ret_URL` 로 form POST 한다. 본 컨트롤러는
 * 판정 로직 전체를 KcpCallbackResolver 에 위임하고, 결과를 브리지 페이지로 302 redirect 한다.
 * PII 는 쿼리에 싣지 않는다 (토큰/실패코드만 전달).
 *
 * FormRequest 미사용 사유: 외부 인증기관이 보내는 임의 필드를 strict validation 으로 차단하면
 * 정상 응답까지 거절된다. raw form POST 를 그대로 Resolver 에 전달한다.
 *
 * @since 1.0.0
 */
class KcpCallbackController extends PublicBaseController
{
    /**
     * @param  KcpCallbackResolver  $resolver  콜백 판정 파이프라인 Service
     */
    public function __construct(
        protected readonly KcpCallbackResolver $resolver,
    ) {
        parent::__construct();
    }

    /**
     * KCP 콜백 수신 + Resolver 위임 + 브리지 페이지로 302 redirect.
     *
     * @param  Request  $request  KCP 표준창이 form POST 한 body
     * @return RedirectResponse 브리지 페이지로의 302 응답
     */
    public function handle(Request $request): RedirectResponse
    {
        $outcome = $this->resolver->resolve(
            callbackInput: $request->all(),
            context: [
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 512),
            ],
        );

        $bridgeUrl = url('/plugins/sirsoft-verification_nhnkcp/plugin/nhnkcp/bridge')
            .'?'.http_build_query($outcome->toBridgeQuery());

        return redirect()->away($bridgeUrl);
    }
}
