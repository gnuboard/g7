<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Saml;

use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Sirsoft\Sso\Drivers\Saml\SamlDriver;
use Plugins\Sirsoft\Sso\Services\ProtocolRegistry;
use Plugins\Sirsoft\Sso\Tests\Fixtures\Saml\SamlFixtures;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\PolicyFixtures;

/**
 * SAML IdP 의 붙여 넣은 메타데이터 XML 을 저장 때 실제로 읽어 본다 — 깨졌으면 `idp_metadata_xml` 칸 422.
 *
 * 저장 검사가 빈 값과 크기만 봐서 닫히지 않은 XML 이 「저장했습니다」로 통과하고, 실패는 로그인 때야 `saml_invalid` 로 드러났다
 * (F1 §10 M10 T4 실측, 계획 기대 422). 붙여 넣은 XML 은 주소보다 먼저 쓰이므로 주소가 함께 있어도 읽어 본다.
 * 읽기는 로그인 때와 같은 라이브러리 파서(`SamlMetadata::parse`)이고, DOCTYPE·엔티티가 든 XML 은 거절한다(XXE).
 *
 * @scenario-manifest plugins/_bundled/sirsoft-sso/tests/scenarios/saml-metadata-validation.yaml
 *
 * @since 1.0.0
 */
class SamlMetadataValidationTest extends PluginTestCase
{
    use PolicyFixtures;
    use SamlFixtures;

    /** API 접두 */
    private const BASE = '/api/plugins/sirsoft-sso/admin/idps';

    /**
     * SAML 드라이버를 꺼냅니다.
     *
     * @return SamlDriver 드라이버
     */
    private function driver(): SamlDriver
    {
        return app(ProtocolRegistry::class)->get('saml');
    }

    /**
     * 깨진 메타데이터 XML 변종 — 저장 검사가 `idp_metadata_xml` 칸 오류를 낸다.
     *
     * @return array<string, array{0: string}> 변종
     */
    public static function brokenMetadata(): array
    {
        $md = 'xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata"';

        return [
            'unclosed' => ['<md:EntityDescriptor '.$md.' entityID="broken"><md:IDPSSODescriptor'],
            'not_xml' => ['이건 XML 이 아닙니다'],
            'no_entity_descriptor' => ['<root><child/></root>'],
            'no_sso_service' => ['<md:EntityDescriptor '.$md.' entityID="https://idp.corp798.test"><md:IDPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol"/></md:EntityDescriptor>'],
            'no_signing_certificate' => ['<md:EntityDescriptor '.$md.' entityID="https://idp.corp798.test"><md:IDPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol"><md:SingleSignOnService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location="https://idp.corp798.test/sso"/></md:IDPSSODescriptor></md:EntityDescriptor>'],
            'garbage_certificate' => ['<md:EntityDescriptor '.$md.' xmlns:ds="http://www.w3.org/2000/09/xmldsig#" entityID="https://idp.corp798.test"><md:IDPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol"><md:KeyDescriptor use="signing"><ds:KeyInfo><ds:X509Data><ds:X509Certificate>bm90IGEgY2VydGlmaWNhdGU=</ds:X509Certificate></ds:X509Data></ds:KeyInfo></md:KeyDescriptor><md:SingleSignOnService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location="https://idp.corp798.test/sso"/></md:IDPSSODescriptor></md:EntityDescriptor>'],
            'doctype_entity' => ['<?xml version="1.0"?><!DOCTYPE md [<!ENTITY x SYSTEM "file:///etc/passwd">]><md:EntityDescriptor '.$md.' entityID="&x;"/>'],
        ];
    }

    /**
     * 깨진 XML 은 저장 검사에서 `idp_metadata_xml` 칸 오류 — 주소가 함께 있어도 붙여 넣은 XML 을 읽어 본다.
     *
     * @scenario check=broken_metadata
     *
     * @effects metadata_xml_field_error
     *
     * @param  string  $xml  깨진 메타데이터
     * @return void 반환값 없음
     */
    #[DataProvider('brokenMetadata')]
    public function test_broken_metadata_xml_is_rejected(string $xml): void
    {
        $alone = $this->driver()->validateConfig(['idp_metadata_xml' => $xml], []);
        $this->assertSame('sirsoft-sso::saml.validation.metadata_invalid', $alone['idp_metadata_xml'] ?? null);

        $withUrl = $this->driver()->validateConfig(['idp_metadata_url' => 'https://idp.corp798.test/metadata', 'idp_metadata_xml' => $xml], []);
        $this->assertSame('sirsoft-sso::saml.validation.metadata_invalid', $withUrl['idp_metadata_xml'] ?? null);
    }

    /**
     * 정상 메타데이터(모의 IdP 가 내는 것)는 메타데이터 칸 오류가 없고, 주소만 있으면 저장 때 받지 않는다(받기는 연결 시험·로그인 때).
     *
     * @scenario check=valid_metadata
     *
     * @effects valid_metadata_accepted
     *
     * @return void 반환값 없음
     */
    public function test_valid_metadata_is_accepted(): void
    {
        $errors = $this->driver()->validateConfig(['idp_metadata_xml' => $this->mockSamlMetadataXml()], []);
        $this->assertArrayNotHasKey('idp_metadata_xml', $errors);
        $this->assertArrayNotHasKey('idp_metadata_url', $errors);

        $this->assertSame([], $this->driver()->validateConfig(['idp_metadata_url' => 'https://idp.corp798.test/metadata'], []));
    }

    /**
     * 관리자 화면 저장 — 켜진 SAML IdP 에 깨진 XML 을 넣으면 422 `config_invalid` + 칸 오류이고 값은 그대로, 정상 XML 은 저장된다.
     *
     * @scenario check=admin_api
     *
     * @effects config_invalid_422_with_field, config_unchanged_on_reject, valid_metadata_accepted
     *
     * @return void 반환값 없음
     */
    public function test_admin_save_rejects_broken_metadata(): void
    {
        $this->loadPluginLang();
        Sanctum::actingAs($this->makeSuper());
        $idp = $this->mockSamlIdp();
        // 저장된 값 기준으로 비교한다(DB JSON 은 키 순서를 바꿔 돌려준다)
        $before = $idp->fresh()->config;

        $broken = array_merge((array) $idp->config, ['idp_metadata_url' => '', 'idp_metadata_xml' => self::brokenMetadata()['unclosed'][0]]);
        $response = $this->putJson(self::BASE.'/'.$idp->id, ['config' => $broken]);

        $response->assertStatus(422);
        $this->assertSame('config_invalid', $response->json('errors.code'));
        $this->assertArrayHasKey('idp_metadata_xml', $response->json('errors.fields'));
        $this->assertSame($before, $idp->fresh()->config);

        $valid = array_merge((array) $idp->config, ['idp_metadata_url' => '', 'idp_metadata_xml' => $this->mockSamlMetadataXml()]);
        $this->putJson(self::BASE.'/'.$idp->id, ['config' => $valid])->assertOk();
        // 요청 입력은 앞뒤 공백이 잘린다(TrimStrings) — 저장값은 잘린 XML
        $this->assertSame(trim($this->mockSamlMetadataXml()), $idp->fresh()->configValue('idp_metadata_xml'));
    }
}
