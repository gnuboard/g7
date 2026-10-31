<?php

namespace Plugins\Sirsoft\Sso\Tests\Unit\Saml;

use App\Extension\HookManager;
use App\Support\SiteUrl;
use DOMDocument;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Plugins\Sirsoft\Sso\Drivers\Saml\MetadataRefreshCommand;
use Plugins\Sirsoft\Sso\Drivers\Saml\SamlDriver;
use Plugins\Sirsoft\Sso\Drivers\Saml\SamlMetadata;
use Plugins\Sirsoft\Sso\Mock\MockGate;
use Plugins\Sirsoft\Sso\Mock\Saml\MockSamlController;
use Plugins\Sirsoft\Sso\Services\ProtocolRegistry;
use Plugins\Sirsoft\Sso\Tests\Fixtures\Saml\SamlFixtures;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;

/**
 * SAML 메타데이터 — SP 메타데이터 XML(스키마 검증, ACS, SLS, 인증서), IdP 메타데이터 받기·읽기·인증서 만료일, 매일 갱신 명령과
 * 만료 임박 훅, SP 키 생성, 설정 검증.
 *
 * @since 1.0.0
 */
class SamlMetadataTest extends PluginTestCase
{
    use SamlFixtures;

    /**
     * 레지스트리의 SAML 드라이버.
     *
     * @return SamlDriver 드라이버
     */
    private function driver(): SamlDriver
    {
        return app(ProtocolRegistry::class)->get('saml');
    }

    /**
     * 모의 IdP 메타데이터 주소로 가는 서버 간 요청을 모의 컨트롤러로 돌려줍니다.
     *
     * @return void 반환값 없음
     */
    private function fakeMetadataHttp(): void
    {
        Http::fake(function (ClientRequest $request) {
            if (! str_starts_with($request->url(), MockSamlController::entityId().'/metadata')) {
                return null;
            }
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $response = app(MockSamlController::class)->metadata(Request::create($request->url(), 'GET', $query));

            return Http::response((string) $response->getContent(), $response->getStatusCode(), ['Content-Type' => 'application/samlmetadata+xml']);
        });
    }

    /**
     * SP 메타데이터 주소는 스키마에 맞는 XML 과 사이트 주소 기준 ACS·SLS, SP 키가 있으면 인증서를 낸다.
     *
     * @scenario route=sp_metadata, sp_key=none|present
     *
     * @effects content_type_xml, entity_id, acs_sls_site_url, certificate_published
     *
     * @return void 반환값 없음
     */
    public function test_sp_metadata_route(): void
    {
        $idp = $this->mockSamlIdp();

        $response = $this->get('/plugins/sirsoft-sso/saml/'.$idp->key.'/metadata');

        $response->assertStatus(200);
        $this->assertStringStartsWith('application/samlmetadata+xml', (string) $response->headers->get('Content-Type'));
        $xml = (string) $response->getContent();
        $dom = new DOMDocument;
        $this->assertTrue($dom->loadXML($xml));
        $this->assertSame(SamlDriver::spEntityId($idp), $dom->documentElement->getAttribute('entityID'));
        $this->assertStringContainsString('Location="'.SiteUrl::absolute(SamlDriver::acsPath($idp)).'"', $xml);
        $this->assertStringContainsString('Location="'.SiteUrl::absolute(SamlDriver::slsPath($idp)).'"', $xml);
        $this->assertStringNotContainsString('X509Certificate', $xml);

        $keys = $this->cachedSpKeyPair();
        $idp->forceFill(['secrets' => ['sp_certificate' => $keys['certificate'], 'sp_private_key' => $keys['private_key']]])->save();
        $this->assertStringContainsString('X509Certificate', (string) $this->get('/plugins/sirsoft-sso/saml/'.$idp->key.'/metadata')->getContent());
    }

    /**
     * SAML 이 아닌 IdP 키와 게이트가 꺼진 모의 IdP 는 404 다(꺼진 실제 IdP 는 켜기 전 등록을 위해 낸다).
     *
     * @scenario route=sp_metadata, idp=oidc|mock_gate_off|disabled
     *
     * @effects not_found, disabled_still_served
     *
     * @return void 반환값 없음
     */
    public function test_sp_metadata_visibility(): void
    {
        $oidc = $this->mockOidcIdp();
        $this->get('/plugins/sirsoft-sso/saml/'.$oidc->key.'/metadata')->assertStatus(404);

        $disabled = $this->mockSamlIdp(['enabled' => false, 'key' => 'corp_saml']);
        $this->get('/plugins/sirsoft-sso/saml/'.$disabled->key.'/metadata')->assertStatus(200);

        $mock = $this->mockSamlIdp();
        app(MockGate::class)->disable();
        $this->get('/plugins/sirsoft-sso/saml/'.$mock->key.'/metadata')->assertStatus(404);
    }

    /**
     * IdP 메타데이터를 주소에서 받아 읽고(Entity ID, SSO 주소, 서명 인증서), 가장 이른 인증서 만료일을 낸다.
     *
     * @scenario idp_metadata=url, variant=ok|cert_expiring_7d
     *
     * @effects entity_id_parsed, earliest_cert_expiry
     *
     * @return void 반환값 없음
     */
    public function test_idp_metadata_from_url_and_cert_expiry(): void
    {
        $this->fakeMetadataHttp();
        $idp = $this->mockSamlIdp(['config' => ['idp_metadata_xml' => null, 'idp_metadata_url' => MockSamlController::metadataUrl('cert_expiring_7d')]]);

        $info = app(SamlMetadata::class)->idp($idp, true);

        $this->assertSame(MockSamlController::entityId(), $info['entityId']);
        $this->assertSame(MockSamlController::entityId().'/sso', $info['singleSignOnService']['url']);
        $this->assertCount(2, SamlMetadata::signingCertificates($info), '주 인증서 + 7일 뒤 만료 인증서');
        $expires = SamlMetadata::earliestExpiry(SamlMetadata::signingCertificates($info));
        $this->assertNotNull($expires);
        $this->assertTrue($expires->between(now()->addDays(6), now()->addDays(8)));
    }

    /**
     * 갱신 명령은 갱신 시각과 인증서 만료일을 기록하고, 남은 날이 알림 일수와 같으면 만료 임박 훅을 한 번 낸다.
     *
     * @scenario command=metadata-refresh, days_left=7
     *
     * @effects refreshed_at_saved, cert_expires_at_saved, cert_expiring_hook_once
     *
     * @return void 반환값 없음
     */
    public function test_refresh_command_records_expiry_and_fires_hook(): void
    {
        $this->fakeMetadataHttp();
        $idp = $this->mockSamlIdp(['config' => ['idp_metadata_xml' => null, 'idp_metadata_url' => MockSamlController::metadataUrl('cert_expiring_7d')]]);
        $fired = [];
        HookManager::addAction(MetadataRefreshCommand::ACTION_CERT_EXPIRING, function ($hookIdp, $days) use (&$fired) {
            $fired[] = [$hookIdp->key, $days];
        });

        $this->assertSame(0, Artisan::call('sirsoft-sso:metadata-refresh', ['--idp' => $idp->key]));

        $fresh = $idp->fresh();
        $this->assertNotNull($fresh->metadata_refreshed_at);
        $this->assertNotNull($fresh->cert_expires_at);
        $this->assertSame([[$idp->key, 7]], $fired);
    }

    /**
     * 메타데이터를 받지 못해도 명령은 성공 코드로 끝나 다른 스케줄을 막지 않는다.
     *
     * @scenario command=metadata-refresh, metadata=unreachable
     *
     * @effects exit_zero, fields_unchanged
     *
     * @return void 반환값 없음
     */
    public function test_refresh_failure_does_not_throw(): void
    {
        Http::fake(fn () => Http::response('', 500));
        $idp = $this->mockSamlIdp(['config' => ['idp_metadata_xml' => null, 'idp_metadata_url' => 'https://idp.corp798.test/metadata']]);

        $this->assertSame(0, Artisan::call('sirsoft-sso:metadata-refresh'));
        $this->assertNull($idp->fresh()->metadata_refreshed_at);
    }

    /**
     * SP 키 생성은 짝이 맞는 2048비트 키와 10년 인증서를 만든다.
     *
     * @scenario sp_keys=generate
     *
     * @effects key_matches_certificate, ten_years
     *
     * @return void 반환값 없음
     */
    public function test_generate_sp_keys(): void
    {
        $idp = $this->mockSamlIdp();

        $keys = $this->driver()->generateSpKeys($idp);

        $this->assertTrue(openssl_x509_check_private_key($keys['certificate'], $keys['private_key']));
        $this->assertSame(2048, openssl_pkey_get_details(openssl_pkey_get_private($keys['private_key']))['bits']);
        $this->assertTrue(now()->addYears(9)->lessThan($keys['expires_at']));
        $this->assertSame([], $this->driver()->validateConfig(['idp_metadata_url' => 'https://idp.corp798.test/m', 'sign_authn_requests' => true], ['sp_certificate' => $keys['certificate'], 'sp_private_key' => $keys['private_key']]));
    }

    /**
     * 설정 검증 — 메타데이터 필수, 어설션 서명 끄기 거절, 키 없는 서명·암호화, 키 짝 불일치, 속성 subject.
     *
     * @scenario validate_config=true
     *
     * @effects error_keys
     *
     * @return void 반환값 없음
     */
    public function test_validate_config(): void
    {
        $errors = $this->driver()->validateConfig(['want_assertions_signed' => false, 'want_assertions_encrypted' => true, 'subject_source' => 'attribute'], []);

        $this->assertEqualsCanonicalizing(['idp_metadata_url', 'want_assertions_signed', 'sp_private_key', 'subject_attribute'], array_keys($errors));

        $a = $this->cachedSpKeyPair();
        $b = $this->cachedSpKeyPair('sp_new');
        $mismatch = $this->driver()->validateConfig(['idp_metadata_url' => 'https://idp.corp798.test/m'], ['sp_certificate' => $a['certificate'], 'sp_private_key' => $b['private_key']]);
        $this->assertSame(['sp_certificate'], array_keys($mismatch));
    }

    /**
     * 사이트 호스트에 밑줄이 있으면 라이브러리가 모든 SAML 요청을 설정 오류로 거절하므로, 연결 시험이 그것을 먼저 알린다.
     *
     * @scenario test_connection=true, site_host=underscore
     *
     * @effects site_url_check_failed, login_start_saml_invalid
     *
     * @return void 반환값 없음
     */
    public function test_underscore_site_host_is_reported(): void
    {
        config(['app.url' => 'https://dev_site.test']);
        $idp = $this->mockSamlIdp();

        $result = $this->driver()->testConnection($idp);

        $this->assertFalse($result->ok);
        $this->assertSame(['site_url'], array_column($result->checks, 'key'));

        $start = $this->withBrowserBind()->get('/plugins/sirsoft-sso/login/'.$idp->key);
        $this->assertSame('saml_invalid', $this->redirectQuery($start)['sso_error'] ?? null, '로그인 시작은 오류 화면으로 돌아온다');
    }

    /**
     * 연결 시험 — 메타데이터, 인증서, SSO 주소, SP 메타데이터 주소 항목.
     *
     * @scenario test_connection=true
     *
     * @effects checks_reported
     *
     * @return void 반환값 없음
     */
    public function test_connection_checks(): void
    {
        $idp = $this->mockSamlIdp();

        $result = $this->driver()->testConnection($idp);

        $this->assertTrue($result->ok);
        $this->assertSame(['metadata', 'certificate', 'sso_url', 'sp_metadata'], array_column($result->checks, 'key'));
        $this->assertSame(['entity_id', 'acs_url', 'sls_url', 'metadata_url', 'name_id_format'], array_column($this->driver()->serviceProviderInfo($idp), 'key'));
    }
}
