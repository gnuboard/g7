<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Scim;

use Plugins\Sirsoft\Sso\Exceptions\ScimException;
use Plugins\Sirsoft\Sso\Services\Provisioning\ScimSchema;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\ProvisioningTestHelpers;

/**
 * SCIM 서버 설명 문서와 오류 스키마 — ServiceProviderConfig 의 미지원 항목, ResourceTypes, Schemas, 잘못된 필터 400.
 *
 * @since 1.0.0
 */
class ScimSchemaResponsesTest extends PluginTestCase
{
    use ProvisioningTestHelpers;

    /**
     * ServiceProviderConfig — patch, filter 지원, bulk, sort, etag, changePassword 미지원.
     *
     * @scenario resource=ServiceProviderConfig
     *
     * @effects supported_flags
     *
     * @return void 반환값 없음
     */
    public function test_service_provider_config_flags(): void
    {
        $idp = $this->makeIdp();
        $token = $this->issueScimToken($idp);

        $response = $this->scim('GET', $idp, '/ServiceProviderConfig', $token)->assertOk();

        $response->assertJsonPath('schemas.0', ScimSchema::SERVICE_PROVIDER_CONFIG)
            ->assertJsonPath('patch.supported', true)
            ->assertJsonPath('filter.supported', true)
            ->assertJsonPath('filter.maxResults', 100)
            ->assertJsonPath('bulk.supported', false)
            ->assertJsonPath('sort.supported', false)
            ->assertJsonPath('etag.supported', false)
            ->assertJsonPath('changePassword.supported', false)
            ->assertJsonPath('authenticationSchemes.0.type', 'oauthbearertoken');
        $this->assertStringContainsString('application/scim+json', (string) $response->headers->get('Content-Type'));
        $this->assertStringEndsWith('/api/plugins/sirsoft-sso/scim/v2/'.$idp->key.'/ServiceProviderConfig', (string) $response->json('meta.location'));
    }

    /**
     * ResourceTypes, Schemas 는 목록 응답이고 단건도 찾는다.
     *
     * @scenario resource=ResourceTypes|Schemas
     *
     * @effects list_response
     *
     * @return void 반환값 없음
     */
    public function test_resource_types_and_schemas(): void
    {
        $idp = $this->makeIdp();
        $token = $this->issueScimToken($idp);

        $types = $this->scim('GET', $idp, '/ResourceTypes', $token)->assertOk();
        $types->assertJsonPath('schemas.0', ScimSchema::LIST_RESPONSE)->assertJsonPath('totalResults', 2);
        $this->assertSame(['User', 'Group'], array_column($types->json('Resources'), 'id'));
        $this->scim('GET', $idp, '/ResourceTypes/User', $token)->assertOk()->assertJsonPath('endpoint', '/Users');

        $schemas = $this->scim('GET', $idp, '/Schemas', $token)->assertOk();
        $this->assertContains(ScimSchema::ENTERPRISE_USER, array_column($schemas->json('Resources'), 'id'));
        $this->scim('GET', $idp, '/Schemas/'.ScimSchema::GROUP, $token)->assertOk()->assertJsonPath('name', 'Group');
    }

    /**
     * 받지 않는 필터는 400 invalidFilter (SCIM 오류 스키마).
     *
     * @scenario filter=unsupported_operator|unsupported_attribute
     *
     * @effects scim_400_invalid_filter
     *
     * @return void 반환값 없음
     */
    public function test_invalid_filter_is_scim_400(): void
    {
        $idp = $this->makeIdp();
        $token = $this->issueScimToken($idp);

        foreach (['userName co "kim"', 'title eq "부장"', 'userName eq "a" and active eq true'] as $filter) {
            $this->scim('GET', $idp, '/Users?filter='.rawurlencode($filter), $token)
                ->assertStatus(400)
                ->assertJsonPath('schemas.0', ScimException::ERROR_SCHEMA)
                ->assertJsonPath('scimType', 'invalidFilter');
        }
    }

    /**
     * 없는 자원 404, 잘못된 PatchOp 400 — 둘 다 SCIM 오류 스키마.
     *
     * @scenario request=not_found|bad_patch
     *
     * @effects scim_error_schema
     *
     * @return void 반환값 없음
     */
    public function test_not_found_and_bad_patch(): void
    {
        $idp = $this->makeIdp();
        $token = $this->issueScimToken($idp);

        $this->scim('GET', $idp, '/Groups/424242', $token)->assertStatus(404)->assertJsonPath('status', '404');
        $this->scim('PATCH', $idp, '/Users/1', $token, ['schemas' => [ScimSchema::PATCH_OP]])
            ->assertStatus(400)
            ->assertJsonPath('scimType', 'invalidSyntax');
    }
}
