<?php

namespace Plugins\Sirsoft\Sso\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Plugins\Sirsoft\Sso\Drivers\Saml\SamlSpService;
use Plugins\Sirsoft\Sso\Http\Requests\SamlAcsRequest;
use Plugins\Sirsoft\Sso\Http\Requests\SamlSlsRequest;
use Plugins\Sirsoft\Sso\Services\LoginFlowService;
use Symfony\Component\HttpFoundation\Response;

/**
 * SAML SP 주소 — 메타데이터, ACS(로그인 응답 수신), SLS(로그아웃 수신).
 *
 * IdP 는 CSRF 토큰을 보내지 않는다. 위조 방어는 `SamlSpService` 가 맡는다(state·묶음 쿠키, IdP-initiated 허용, 응답 1회, 서명).
 *
 * @since 1.0.0
 */
class SamlController extends Controller
{
    /**
     * @param  SamlSpService  $sp  SAML SP 처리
     */
    public function __construct(private readonly SamlSpService $sp) {}

    /**
     * SP 메타데이터 XML.
     *
     * @param  string  $idp  IdP 키
     * @return Response XML (SAML IdP 가 아니면 404)
     */
    public function metadata(string $idp): Response
    {
        $xml = $this->sp->metadata($idp);
        if ($xml === null) {
            abort(404);
        }

        // file-serving:allow 파일이 아니라 IdP 설정에서 그 자리에서 만든 SAML 메타데이터 문서다 — 설정이 바뀌면 곧바로 달라져야 하므로 `no-store`.
        return response($xml, 200, [
            'Content-Type' => 'application/samlmetadata+xml; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * ACS — IdP 의 로그인 응답.
     *
     * 값 형식이 비정상이면 IdP 응답을 읽지 않고 로그인 화면 오류로 보낸다.
     *
     * @param  SamlAcsRequest  $request  요청
     * @param  string  $idp  IdP 키
     * @return RedirectResponse 다음 화면
     */
    public function acs(SamlAcsRequest $request, string $idp): RedirectResponse
    {
        if (! $request->isWellFormed()) {
            return redirect()->to(LoginFlowService::errorUrl('saml_invalid'));
        }

        return $this->sp->acs($idp, $request);
    }

    /**
     * SLS — IdP 발 로그아웃 요청 또는 로그아웃 요청의 답.
     *
     * @param  SamlSlsRequest  $request  요청
     * @param  string  $idp  IdP 키
     * @return Response 응답 (형식 오류 400, SAML IdP 가 아니면 404)
     */
    public function sls(SamlSlsRequest $request, string $idp): Response
    {
        if (! $request->isWellFormed()) {
            return response('', 400, ['Cache-Control' => 'no-store']);
        }

        $response = $this->sp->sls($idp, $request);
        if ($response === null) {
            abort(404);
        }

        return $response;
    }
}
