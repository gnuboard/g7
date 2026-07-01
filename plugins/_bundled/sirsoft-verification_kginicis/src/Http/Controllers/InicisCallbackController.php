<?php

namespace Plugins\Sirsoft\VerificationKginicis\Http\Controllers;

use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Plugins\Sirsoft\VerificationKginicis\Services\InicisCallbackResolver;

/**
 * 이니시스 매뉴얼 STEP2 콜백 수신 → STEP3 호출 위임 → bridge 페이지 redirect 컨트롤러.
 *
 * 코어 표준 라우트 (`POST /api/identity/callback/{providerId}`) 를 사용할 수 없는 이유는
 * 이니시스가 challenge_id 를 callback 에 echo 하지 않기 때문 (매뉴얼 STEP2 명시).
 * 본 컨트롤러는 InicisCallbackResolver Service 에 비즈니스 로직을 위임하고, 결과를
 * popup-bridge 페이지로 302 redirect 한다.
 *
 * FormRequest 미사용 사유: 외부 PG 가 보내는 임의 필드를 strict validation 으로 차단하면
 * 이니시스 응답을 받을 수 없다. 본 컨트롤러는 raw form POST 를 그대로 Resolver 에 전달.
 *
 * @since 1.0.0-beta.1
 */
class InicisCallbackController extends PublicBaseController
{
    /**
     * @param  InicisCallbackResolver  $resolver  callback 비즈니스 로직 Service
     */
    public function __construct(
        protected readonly InicisCallbackResolver $resolver,
    ) {
        parent::__construct();
    }

    /**
     * 이니시스 콜백 수신 + Resolver 위임 + bridge 페이지로 302 redirect.
     *
     * @param  Request  $request  이니시스 form POST body
     * @return RedirectResponse
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

        $bridgeUrl = url('/plugins/sirsoft-verification_kginicis/plugin/inicis/popup-bridge')
            .'?'.http_build_query($outcome->toBridgeQuery());

        return redirect()->away($bridgeUrl);
    }
}
