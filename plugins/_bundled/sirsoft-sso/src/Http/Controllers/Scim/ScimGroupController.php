<?php

namespace Plugins\Sirsoft\Sso\Http\Controllers\Scim;

use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\Sso\Http\Requests\Scim\ScimGroupRequest;
use Plugins\Sirsoft\Sso\Http\Requests\Scim\ScimListRequest;
use Plugins\Sirsoft\Sso\Http\Requests\Scim\ScimPatchRequest;
use Plugins\Sirsoft\Sso\Services\Provisioning\ScimGroupService;

/**
 * SCIM 2.0 `/Groups` — 역할 매핑의 입력으로만 쓰는 IdP 그룹.
 *
 * @since 1.0.0
 */
class ScimGroupController extends ScimController
{
    /**
     * @param  ScimGroupService  $groups  SCIM Groups 서비스
     */
    public function __construct(private readonly ScimGroupService $groups) {}

    /**
     * 목록.
     *
     * @param  ScimListRequest  $request  요청
     * @return JsonResponse 목록 응답
     */
    public function index(ScimListRequest $request): JsonResponse
    {
        return $this->scim($this->groups->list(
            $this->idp($request),
            $request->query('filter'),
            $request->query('startIndex'),
            $request->query('count'),
            ! $request->excludesMembers(),
        ));
    }

    /**
     * 조회.
     *
     * @param  ScimListRequest  $request  요청
     * @param  string  $idp  IdP 키 (경로)
     * @param  string  $id  그룹 id
     * @return JsonResponse 자원
     */
    public function show(ScimListRequest $request, string $idp, string $id): JsonResponse // route-param:unused IdP 는 미들웨어가 토큰으로 확정해 요청 속성에 둔다
    {
        return $this->scim($this->groups->get($this->idp($request), $id, ! $request->excludesMembers()));
    }

    /**
     * 생성.
     *
     * @param  ScimGroupRequest  $request  요청
     * @return JsonResponse 201 자원
     */
    public function store(ScimGroupRequest $request): JsonResponse
    {
        $resource = $this->groups->create($this->idp($request), $request->json()->all());

        return $this->scim($resource, 201, ['Location' => (string) $resource['meta']['location']]);
    }

    /**
     * 교체.
     *
     * @param  ScimGroupRequest  $request  요청
     * @param  string  $idp  IdP 키 (경로)
     * @param  string  $id  그룹 id
     * @return JsonResponse 자원
     */
    public function update(ScimGroupRequest $request, string $idp, string $id): JsonResponse // route-param:unused IdP 는 미들웨어가 토큰으로 확정해 요청 속성에 둔다
    {
        return $this->scim($this->groups->replace($this->idp($request), $id, $request->json()->all()));
    }

    /**
     * 부분 수정.
     *
     * @param  ScimPatchRequest  $request  요청
     * @param  string  $idp  IdP 키 (경로)
     * @param  string  $id  그룹 id
     * @return JsonResponse 자원
     */
    public function patch(ScimPatchRequest $request, string $idp, string $id): JsonResponse // route-param:unused IdP 는 미들웨어가 토큰으로 확정해 요청 속성에 둔다
    {
        $operations = $request->json('Operations');

        return $this->scim($this->groups->patch($this->idp($request), $id, is_array($operations) ? $operations : []));
    }

    /**
     * 삭제.
     *
     * @param  ScimListRequest  $request  요청
     * @param  string  $idp  IdP 키 (경로)
     * @param  string  $id  그룹 id
     * @return JsonResponse 204
     */
    public function destroy(ScimListRequest $request, string $idp, string $id): JsonResponse // route-param:unused IdP 는 미들웨어가 토큰으로 확정해 요청 속성에 둔다
    {
        $this->groups->delete($this->idp($request), $id);

        return $this->scim(null, 204);
    }
}
