<?php

namespace Plugins\Sirsoft\Sso\Mock\Saml;

use App\Contracts\Extension\CacheInterface;
use App\Support\SiteUrl;
use DOMDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Plugins\Sirsoft\Sso\Drivers\Saml\SamlDriver;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Services\SafeRedirect;
use Plugins\Sirsoft\Sso\Support\BridgeScript;

/**
 * 모의 SAML IdP — 메타데이터, 로그인 승인 화면(SP-initiated, IdP-initiated), 로그아웃.
 *
 * 실제 SAML 드라이버가 메타데이터 주소만 이 IdP 로 바꿔 왕복한다. 게이트가 꺼져 있으면 모든 주소가 404 다 — 판정은 라우트군
 * 미들웨어 `MockGateMiddleware` 한 곳이 맡는다(라우트 이름 `mock.saml.*`).
 *
 * @since 1.0.0
 */
class MockSamlController extends Controller
{
    /** 마지막 응답 보관(초) — `replay` 변종이 같은 응답을 다시 보낸다 */
    private const LAST_RESPONSE_TTL = 600;

    /** SP ACS 경로 형식 */
    private const ACS_PATTERN = '#^/plugins/sirsoft-sso/saml/([a-z][a-z0-9_]{1,49})/acs$#';

    /**
     * @param  MockSamlKeys  $keys  서명 키
     * @param  CacheInterface  $cache  플러그인 캐시
     */
    public function __construct(
        private readonly MockSamlKeys $keys,
        private readonly CacheInterface $cache,
    ) {}

    /**
     * 모의 IdP 의 Entity ID.
     *
     * @return string Entity ID
     */
    public static function entityId(): string
    {
        return SiteUrl::absolute('/plugins/sirsoft-sso/mock/saml');
    }

    /**
     * 모의 IdP 메타데이터 주소.
     *
     * @param  string  $variant  메타데이터 변종
     * @return string 주소
     */
    public static function metadataUrl(string $variant = 'ok'): string
    {
        return SiteUrl::absolute('/plugins/sirsoft-sso/mock/saml/metadata'.($variant !== 'ok' ? '?variant='.rawurlencode($variant) : ''));
    }

    /**
     * IdP 메타데이터 — `?variant=cert_expiring_7d`(7일 뒤 만료 인증서를 함께), `cert_rotated`(교체 예정 인증서를 함께).
     *
     * 응답은 늘 주 키로 서명하고 주 인증서는 모든 변종에 실린다 — 변종이 바뀌어도 로그인은 이어진다.
     *
     * @param  Request  $request  요청
     * @return Response XML
     */
    public function metadata(Request $request): Response
    {
        $variant = in_array($request->query('variant'), MockSamlResponses::METADATA_VARIANTS, true) ? (string) $request->query('variant') : 'ok';
        $certificates = [$this->keys->pair('primary')['certificate']];
        if ($variant === 'cert_expiring_7d') {
            $certificates[] = $this->keys->pair('expiring')['certificate'];
        } elseif ($variant === 'cert_rotated') {
            $certificates[] = $this->keys->pair('rotated')['certificate'];
        }

        $xml = MockSamlResponses::metadata(self::entityId(), self::entityId().'/sso', self::entityId().'/slo', $certificates);

        return response($xml, 200, ['Content-Type' => 'application/samlmetadata+xml; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    /**
     * SP 의 AuthnRequest 를 받아 승인 화면을 그립니다 (Redirect: GET deflate, POST: base64).
     *
     * @param  Request  $request  요청
     * @return Response 화면
     */
    public function sso(Request $request): Response
    {
        $raw = (string) $request->input('SAMLRequest', '');
        $decoded = base64_decode($raw, true);
        if ($decoded === false || $decoded === '') {
            abort(400);
        }
        $xml = $request->isMethod('GET') ? @gzinflate($decoded) : $decoded;
        $root = is_string($xml) ? $this->load($xml) : null;
        if ($root === null || $root->localName !== 'AuthnRequest') {
            abort(400);
        }

        $acs = (string) $root->getAttribute('AssertionConsumerServiceURL');
        $idp = $this->idpForAcs($acs);
        if ($idp === null) {
            abort(400);
        }

        $issuer = $root->getElementsByTagNameNS(MockSamlResponses::NS_SAML, 'Issuer')->item(0);

        return $this->consentPage([
            'request_id' => (string) $root->getAttribute('ID'),
            'acs' => $acs,
            'audience' => $issuer !== null ? trim((string) $issuer->textContent) : SamlDriver::spEntityId($idp),
            'relay_state' => (string) $request->input('RelayState', ''),
            'force_authn' => $root->getAttribute('ForceAuthn') === 'true' ? '1' : '0',
        ]);
    }

    /**
     * IdP 가 시작한 로그인(IdP-initiated) 승인 화면 — `?idp=<SP 쪽 IdP 키>&RelayState=<복귀 경로>`.
     *
     * @param  Request  $request  요청
     * @return Response 화면
     */
    public function initiate(Request $request): Response
    {
        $idp = $this->samlIdp((string) $request->query('idp', ''));
        if ($idp === null) {
            abort(404);
        }

        return $this->consentPage([
            'request_id' => '',
            'acs' => SiteUrl::absolute(SamlDriver::acsPath($idp)),
            'audience' => SamlDriver::spEntityId($idp),
            'relay_state' => SafeRedirect::path(is_string($request->query('RelayState')) ? $request->query('RelayState') : null),
            'force_authn' => '0',
        ]);
    }

    /**
     * 승인 처리 — 고른 변종의 응답을 SP ACS 로 자동 제출합니다.
     *
     * @param  Request  $request  요청
     * @return Response 자동 제출 폼
     */
    public function approve(Request $request): Response
    {
        $acs = (string) $request->input('acs', '');
        $idp = $this->idpForAcs($acs);
        if ($idp === null) {
            abort(400);
        }

        $variant = in_array($request->input('variant'), MockSamlResponses::VARIANTS, true) ? (string) $request->input('variant') : 'ok';
        $requestId = (string) $request->input('request_id', '');
        $email = mb_strtolower(trim((string) $request->input('email', 'meas798.saml@corp798.test')));
        $nameId = trim((string) $request->input('name_id', ''));
        $lastKey = 'mock_saml_last:'.$idp->key;

        if ($request->input('decision') !== 'approve') {
            $params = $this->params($idp, $acs, $request, $requestId, $email, $nameId) + ['sub_status' => MockSamlResponses::STATUS_REQUEST_DENIED];
            $encoded = base64_encode(MockSamlResponses::response('status_responder', $params, $this->keys->pair()));
        } elseif ($variant === 'replay' && is_string($this->cache->get($lastKey))) {
            $encoded = (string) $this->cache->get($lastKey);
        } else {
            $xml = MockSamlResponses::response($variant === 'replay' ? 'ok' : $variant, $this->params($idp, $acs, $request, $requestId, $email, $nameId), $this->keys->pair(), $this->keys->pair('other'));
            $encoded = base64_encode($xml);
            if (in_array($variant, ['ok', 'replay'], true)) {
                $this->cache->put($lastKey, $encoded, self::LAST_RESPONSE_TTL);
            }
        }

        $fields = ['SAMLResponse' => $encoded];
        $relay = (string) $request->input('relay_state', '');
        if ($relay !== '') {
            $fields['RelayState'] = $relay;
        }

        return response(SamlDriver::autoSubmitHtml($acs, $fields), 200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    /**
     * SP 가 보낸 LogoutRequest 에 LogoutResponse 로 답합니다.
     *
     * @param  Request  $request  요청
     * @return RedirectResponse|Response SP SLS 로 이동, SP 를 모르면 안내
     */
    public function slo(Request $request): RedirectResponse|Response
    {
        $decoded = base64_decode((string) $request->query('SAMLRequest', ''), true);
        $xml = $decoded !== false ? @gzinflate($decoded) : false;
        $root = is_string($xml) ? $this->load($xml) : null;
        if ($root === null || $root->localName !== 'LogoutRequest') {
            return response('<!doctype html><meta charset="utf-8"><title>Mock SAML IdP</title><p>signed out</p>', 200, ['Content-Type' => 'text/html; charset=utf-8']);
        }

        $issuerNode = $root->getElementsByTagNameNS(MockSamlResponses::NS_SAML, 'Issuer')->item(0);
        $idp = $this->idpForSpEntity($issuerNode !== null ? trim((string) $issuerNode->textContent) : '');
        if ($idp === null) {
            abort(400);
        }

        $relay = $request->query('RelayState');

        return redirect()->away(MockSamlResponses::logoutResponseUrl(
            SiteUrl::absolute(SamlDriver::slsPath($idp)),
            self::entityId(),
            (string) $root->getAttribute('ID'),
            is_string($relay) ? $relay : null,
        ));
    }

    /**
     * 응답 값을 모읍니다.
     *
     * @param  IdentityProvider  $idp  SP 쪽 IdP
     * @param  string  $acs  ACS 주소
     * @param  Request  $request  승인 요청
     * @param  string  $requestId  AuthnRequest ID (IdP-initiated 면 빈 값)
     * @param  string  $email  이메일
     * @param  string  $nameId  NameID 입력값
     * @return array<string, mixed> 값
     */
    private function params(IdentityProvider $idp, string $acs, Request $request, string $requestId, string $email, string $nameId): array
    {
        $groups = array_values(array_filter(array_map('trim', explode(',', (string) $request->input('groups', '')))));

        return [
            'issuer' => self::entityId(),
            'destination' => $acs,
            'audience' => (string) $request->input('audience', SamlDriver::spEntityId($idp)),
            'in_response_to' => $requestId !== '' ? $requestId : null,
            'name_id' => $nameId !== '' ? mb_substr($nameId, 0, 191) : 'mock-'.substr(hash('sha256', $email), 0, 24),
            'email' => $email,
            'name' => mb_substr(trim((string) $request->input('name', '')) ?: '모의 직원', 0, 100),
            'groups' => $groups,
            'session_index' => '_mock_session_'.Str::random(20),
            'sp_certificate' => $idp->secret('sp_certificate'),
        ];
    }

    /**
     * 승인 화면을 그립니다.
     *
     * @param  array<string, string>  $hidden  숨은 값
     * @return Response 화면
     */
    private function consentPage(array $hidden): Response
    {
        $fields = '';
        foreach ($hidden as $name => $value) {
            $fields .= '<input type="hidden" name="'.e($name).'" value="'.e($value).'">';
        }

        $options = '';
        foreach (MockSamlResponses::VARIANTS as $variant) {
            $options .= '<option value="'.e($variant).'">'.e($variant).'</option>';
        }

        $html = '<!doctype html><html lang="'.e(app()->getLocale()).'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            // 모양은 정적 파일이 준다 — 인라인 <style> 은 스타일 보안 정책(style-src 'self')에서 막힌다(#163)
            .'<title>Mock SAML IdP</title>'.BridgeScript::stylesheetTag('mock-idp.css').'</head><body>'
            .'<h1>Mock SAML IdP</h1><p>'.e(__('sirsoft-sso::saml.mock.notice')).'</p>'
            .'<form method="post" action="'.e(self::entityId().'/approve').'">'.$fields
            .'<label for="variant">variant</label><select id="variant" name="variant">'.$options.'</select>'
            .'<label for="email">email</label><input id="email" name="email" value="meas798.saml@corp798.test">'
            .'<label for="name_id">NameID</label><input id="name_id" name="name_id" placeholder="(email hash)">'
            .'<label for="name">name</label><input id="name" name="name" value="모의 직원">'
            .'<label for="groups">groups</label><input id="groups" name="groups" placeholder="인사팀,개발팀">'
            .'<button type="submit" name="decision" value="approve">Approve</button> '
            .'<button type="submit" name="decision" value="deny">Deny</button></form></body></html>';

        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    /**
     * ACS 주소에서 SP 쪽 모의 SAML IdP 를 찾습니다 (모의 IdP 가 남의 주소로 응답을 보내지 않게).
     *
     * @param  string  $acs  ACS 절대 주소
     * @return IdentityProvider|null IdP
     */
    private function idpForAcs(string $acs): ?IdentityProvider
    {
        $path = SafeRedirect::path($acs, '');
        if ($path === '' || SiteUrl::absolute($path) !== $acs || preg_match(self::ACS_PATTERN, $path, $m) !== 1) {
            return null;
        }

        return $this->samlIdp($m[1]);
    }

    /**
     * SP Entity ID 로 모의 SAML IdP 를 찾습니다.
     *
     * @param  string  $entityId  SP Entity ID
     * @return IdentityProvider|null IdP
     */
    private function idpForSpEntity(string $entityId): ?IdentityProvider
    {
        foreach (IdentityProvider::query()->where('protocol', SamlDriver::KEY)->where('key', 'like', IdentityProvider::MOCK_PREFIX.'%')->get() as $idp) {
            if (SamlDriver::spEntityId($idp) === $entityId) {
                return $idp;
            }
        }

        return null;
    }

    /**
     * 모의 SAML IdP 행 (키 접두 `mock_`, 프로토콜 saml).
     *
     * @param  string  $key  키
     * @return IdentityProvider|null IdP
     */
    private function samlIdp(string $key): ?IdentityProvider
    {
        if (! str_starts_with($key, IdentityProvider::MOCK_PREFIX)) {
            return null;
        }

        return IdentityProvider::query()->where('key', $key)->where('protocol', SamlDriver::KEY)->first();
    }

    /**
     * XML 을 읽습니다 (외부 개체 없음).
     *
     * @param  string  $xml  XML
     * @return \DOMElement|null 루트
     */
    private function load(string $xml): ?\DOMElement
    {
        if ($xml === '' || strlen($xml) > 65536 || str_contains($xml, '<!DOCTYPE')) {
            return null;
        }

        $doc = new DOMDocument;
        if (! @$doc->loadXML($xml, LIBXML_NONET)) {
            return null;
        }

        return $doc->documentElement;
    }
}
