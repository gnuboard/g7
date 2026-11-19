<?php

namespace Plugins\Sirsoft\Sso\Http\Controllers\Scim;

use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\Sso\Exceptions\ScimException;
use Plugins\Sirsoft\Sso\Http\Requests\Scim\ScimListRequest;
use Plugins\Sirsoft\Sso\Services\Provisioning\ScimSchema;

/**
 * SCIM 서버 설명 — ServiceProviderConfig, ResourceTypes, Schemas.
 *
 * @since 1.0.0
 */
class ScimDiscoveryController extends ScimController
{
    /**
     * ServiceProviderConfig.
     *
     * @param  ScimListRequest  $request  요청
     * @return JsonResponse 문서
     */
    public function serviceProviderConfig(ScimListRequest $request): JsonResponse
    {
        return $this->scim(ScimSchema::serviceProviderConfig($this->idp($request)));
    }

    /**
     * ResourceTypes 목록.
     *
     * @param  ScimListRequest  $request  요청
     * @return JsonResponse 목록 응답
     */
    public function resourceTypes(ScimListRequest $request): JsonResponse
    {
        $types = ScimSchema::resourceTypes($this->idp($request));

        return $this->scim(ScimSchema::listResponse($types, count($types), 1));
    }

    /**
     * ResourceType 하나.
     *
     * @param  ScimListRequest  $request  요청
     * @param  string  $idp  IdP 키 (경로)
     * @param  string  $id  User, Group
     * @return JsonResponse 자원
     */
    public function resourceType(ScimListRequest $request, string $idp, string $id): JsonResponse // route-param:unused IdP 는 미들웨어가 토큰으로 확정해 요청 속성에 둔다
    {
        foreach (ScimSchema::resourceTypes($this->idp($request)) as $type) {
            if ($type['id'] === $id) {
                return $this->scim($type);
            }
        }

        throw new ScimException(404, null, __('sirsoft-sso::scim.error.not_found'));
    }

    /**
     * Schemas 목록.
     *
     * @param  ScimListRequest  $request  요청
     * @return JsonResponse 목록 응답
     */
    public function schemas(ScimListRequest $request): JsonResponse
    {
        $schemas = ScimSchema::schemas($this->idp($request));

        return $this->scim(ScimSchema::listResponse($schemas, count($schemas), 1));
    }

    /**
     * Schema 하나.
     *
     * @param  ScimListRequest  $request  요청
     * @param  string  $idp  IdP 키 (경로)
     * @param  string  $id  스키마 URN
     * @return JsonResponse 자원
     */
    public function schema(ScimListRequest $request, string $idp, string $id): JsonResponse // route-param:unused IdP 는 미들웨어가 토큰으로 확정해 요청 속성에 둔다
    {
        foreach (ScimSchema::schemas($this->idp($request)) as $schema) {
            if ($schema['id'] === $id) {
                return $this->scim($schema);
            }
        }

        throw new ScimException(404, null, __('sirsoft-sso::scim.error.not_found'));
    }
}
