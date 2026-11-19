<?php

namespace Plugins\Sirsoft\Sso\Tests\Fixtures\Saml;

use App\Support\SiteUrl;
use Illuminate\Testing\TestResponse;
use Plugins\Sirsoft\Sso\Drivers\Saml\SamlDriver;
use Plugins\Sirsoft\Sso\Drivers\Saml\X509KeyPair;
use Plugins\Sirsoft\Sso\Mock\MockGate;
use Plugins\Sirsoft\Sso\Mock\Saml\MockSamlController;
use Plugins\Sirsoft\Sso\Mock\Saml\MockSamlKeys;
use Plugins\Sirsoft\Sso\Mock\Saml\MockSamlResponses;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;

/**
 * SAML 시험 도우미 — 모의 SAML IdP 행, 응답 변종, SP-initiated 왕복.
 *
 * IdP 메타데이터는 주소 대신 XML 을 설정에 넣는다(시험이 바깥 네트워크를 쓰지 않는다). 메타데이터 주소 받기 시험만 `Http::fake` 로
 * 모의 컨트롤러 응답을 돌려준다. `PluginTestCase` 를 상속한 시험 클래스에서 쓴다.
 *
 * @since 1.0.0
 */
trait SamlFixtures
{
    /** @var array<string, array{certificate: string, private_key: string}> 프로세스 안에서 다시 쓰는 키 쌍 */
    private static array $samlKeyPairs = [];

    /**
     * 모의 IdP 키를 프로세스 안에서 다시 씁니다 (시험마다 캐시가 비어 RSA 를 새로 만드는 부하를 피한다).
     *
     * @return void 반환값 없음
     */
    protected function reuseMockSamlKeys(): void
    {
        $keys = app(MockSamlKeys::class);
        foreach (['primary', 'other', 'rotated', 'expiring'] as $name) {
            self::$samlKeyPairs[$name] ??= $keys->pair($name);
            $keys->put($name, self::$samlKeyPairs[$name]);
        }
    }

    /**
     * 프로세스 안에서 다시 쓰는 SP 키 쌍.
     *
     * @param  string  $slot  구분 이름 (교체 시험은 두 쌍)
     * @return array{certificate: string, private_key: string} 키 쌍
     */
    protected function cachedSpKeyPair(string $slot = 'sp'): array
    {
        if (! isset(self::$samlKeyPairs[$slot])) {
            $pair = X509KeyPair::generate('G7 SAML SP test '.$slot);
            self::$samlKeyPairs[$slot] = ['certificate' => $pair->certificate, 'private_key' => $pair->privateKey];
        }

        return self::$samlKeyPairs[$slot];
    }

    /**
     * 모의 SAML IdP 행을 만들고 게이트를 켭니다.
     *
     * @param  array<string, mixed>  $attributes  더하거나 바꿀 속성 (`config` 는 병합)
     * @param  string  $key  IdP 키
     * @return IdentityProvider 만든 IdP
     */
    protected function mockSamlIdp(array $attributes = [], string $key = 'mock_saml_a'): IdentityProvider
    {
        app(MockGate::class)->enable(30);
        $this->reuseMockSamlKeys();

        // 시험 앱은 활성 플러그인 행으로 확장을 고르지 않아 `lang/` 이 `sirsoft-sso` 이름공간으로 올라오지 않는다 — 화면 문구를
        // 실제 값으로 단언하려고 여기서 등록한다(운영에서는 플러그인 관리자가 같은 이름공간으로 등록한다).
        app('translator')->addNamespace('sirsoft-sso', dirname(__DIR__, 3).'/lang');

        $config = array_merge([
            'idp_metadata_xml' => $this->mockSamlMetadataXml(),
            'name_id_format' => 'persistent',
            'attr_email' => 'email',
            'attr_name' => 'displayName',
            'attr_groups' => 'groups',
            'mfa_authn_contexts' => [MockSamlResponses::MFA_CONTEXT],
        ], $attributes['config'] ?? []);
        unset($attributes['config']);

        return IdentityProvider::query()->create(array_merge([
            'key' => $key,
            'protocol' => SamlDriver::KEY,
            'enabled' => true,
            'label' => ['ko' => '모의 SAML 계정', 'en' => 'Mock SAML account'],
            'config' => $config,
            'owned_domains' => ['corp798.test'],
            'provisioning_mode' => 'jit',
            'require_consent' => false,
        ], $attributes));
    }

    /**
     * 모의 IdP 메타데이터 XML.
     *
     * @param  array<int, string>  $extraKeys  더 실을 키 이름 (expiring, rotated)
     * @return string 메타데이터 XML
     */
    protected function mockSamlMetadataXml(array $extraKeys = []): string
    {
        $keys = app(MockSamlKeys::class);
        $certificates = [$keys->pair('primary')['certificate']];
        foreach ($extraKeys as $name) {
            $certificates[] = $keys->pair($name)['certificate'];
        }

        $entity = MockSamlController::entityId();

        return MockSamlResponses::metadata($entity, $entity.'/sso', $entity.'/slo', $certificates);
    }

    /**
     * 모의 IdP 가 낼 응답(base64)을 만듭니다.
     *
     * @param  IdentityProvider  $idp  SP 쪽 IdP
     * @param  string  $variant  변종
     * @param  string|null  $requestId  AuthnRequest ID (IdP-initiated 면 null)
     * @param  array<string, mixed>  $overrides  값 덮어쓰기 (name_id, email, name, groups, sign_response …)
     * @return string base64 로 감싼 응답
     */
    protected function samlResponse(IdentityProvider $idp, string $variant = 'ok', ?string $requestId = null, array $overrides = []): string
    {
        $keys = app(MockSamlKeys::class);
        $params = array_merge([
            'issuer' => MockSamlController::entityId(),
            'destination' => SiteUrl::absolute(SamlDriver::acsPath($idp)),
            'audience' => SamlDriver::spEntityId($idp),
            'in_response_to' => $requestId,
            'name_id' => 'saml-emp-001',
            'email' => 'saml.kim@corp798.test',
            'name' => '김에스',
            'groups' => ['인사팀'],
            'session_index' => '_sess_'.bin2hex(random_bytes(6)),
            'sp_certificate' => $idp->secret('sp_certificate'),
        ], $overrides);

        return base64_encode(MockSamlResponses::response($variant, $params, $keys->pair(), $keys->pair('other')));
    }

    /**
     * SP-initiated 로그인을 시작하고 AuthnRequest ID 와 RelayState 를 꺼냅니다.
     *
     * @param  IdentityProvider  $idp  대상 IdP
     * @param  string  $startQuery  시작 쿼리
     * @return array{response: TestResponse, request_id: string, relay_state: string, xml: string} 시작 결과
     */
    protected function startSaml(IdentityProvider $idp, string $startQuery = 'guard=user'): array
    {
        $start = $this->withBrowserBind()->get('/plugins/sirsoft-sso/login/'.$idp->key.'?'.$startQuery);
        parse_str((string) parse_url((string) $start->headers->get('Location'), PHP_URL_QUERY), $query);

        $xml = isset($query['SAMLRequest']) ? (string) gzinflate((string) base64_decode((string) $query['SAMLRequest'])) : '';
        preg_match('/\sID="([^"]+)"/', $xml, $id);

        return [
            'response' => $start,
            'request_id' => $id[1] ?? '',
            'relay_state' => (string) ($query['RelayState'] ?? ''),
            'xml' => $xml,
        ];
    }

    /**
     * 같은 브라우저(묶음 쿠키)로 ACS 에 응답을 POST 합니다.
     *
     * @param  IdentityProvider  $idp  대상 IdP
     * @param  string  $samlResponse  base64 응답
     * @param  string|null  $relayState  복귀 값(RelayState)
     * @return TestResponse ACS 응답
     */
    protected function postAcs(IdentityProvider $idp, string $samlResponse, ?string $relayState): TestResponse
    {
        $body = ['SAMLResponse' => $samlResponse];
        if ($relayState !== null) {
            $body['RelayState'] = $relayState;
        }

        return $this->withBrowserBind()->post(SamlDriver::acsPath($idp), $body);
    }

    /**
     * SP-initiated 왕복 — 시작 → 모의 응답 → ACS.
     *
     * @param  IdentityProvider  $idp  대상 IdP
     * @param  string  $variant  변종
     * @param  array<string, mixed>  $overrides  응답 값 덮어쓰기
     * @param  string  $startQuery  시작 쿼리
     * @return TestResponse ACS 응답
     */
    protected function samlRoundTrip(IdentityProvider $idp, string $variant = 'ok', array $overrides = [], string $startQuery = 'guard=user'): TestResponse
    {
        $start = $this->startSaml($idp, $startQuery);

        return $this->postAcs($idp, $this->samlResponse($idp, $variant, $start['request_id'], $overrides), $start['relay_state']);
    }
}
