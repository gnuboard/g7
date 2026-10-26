<?php

namespace Plugins\Sirsoft\Sso\Http\Controllers\Scim;

use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\Sso\Http\Requests\Scim\ScimListRequest;
use Plugins\Sirsoft\Sso\Http\Requests\Scim\ScimPatchRequest;
use Plugins\Sirsoft\Sso\Http\Requests\Scim\ScimUserRequest;
use Plugins\Sirsoft\Sso\Services\Provisioning\ScimUserService;

/**
 * SCIM 2.0 `/Users` — 목록, 조회, 생성, 교체, 부분 수정, 삭제(= 퇴사 처리).
 *
 * @since 1.0.0
 */
class ScimUserController extends ScimController
{
    /**
     * @param  ScimUserService  $users  SCIM Users 서비스
     */
    public function __construct(private readonly ScimUserService $users) {}

    /**
     * 목록.
     *
     * @param  ScimListRequest  $request  요청
     * @return JsonResponse 목록 응답
     */
    public function index(ScimListRequest $request): JsonResponse
    {
        return $this->scim($this->users->list(
            $this->idp($request),
            $request->query('filter'),
            $request->query('startIndex'),
            $request->query('count'),
        ));
    }

    /**
     * 조회.
     *
     * @param  ScimListRequest  $request  요청
     * @param  string  $idp  IdP 키 (경로)
     * @param  string  $id  자원 id
     * @return JsonResponse 자원
     */
    public function show(ScimListRequest $request, string $idp, string $id): JsonResponse // route-param:unused IdP 는 미들웨어가 토큰으로 확정해 요청 속성에 둔다
    {
        return $this->scim($this->users->get($this->idp($request), $id));
    }

    /**
     * 생성.
     *
     * @param  ScimUserRequest  $request  요청
     * @return JsonResponse 201 자원
     */
    public function store(ScimUserRequest $request): JsonResponse
    {
        $resource = $this->users->create($this->idp($request), $request->json()->all());

        return $this->scim($resource, 201, ['Location' => (string) $resource['meta']['location']]);
    }

    /**
     * 교체.
     *
     * @param  ScimUserRequest  $request  요청
     * @param  string  $idp  IdP 키 (경로)
     * @param  string  $id  자원 id
     * @return JsonResponse 자원
     */
    public function update(ScimUserRequest $request, string $idp, string $id): JsonResponse // route-param:unused IdP 는 미들웨어가 토큰으로 확정해 요청 속성에 둔다
    {
        return $this->scim($this->users->replace($this->idp($request), $id, $request->json()->all()));
    }

    /**
     * 부분 수정.
     *
     * @param  ScimPatchRequest  $request  요청
     * @param  string  $idp  IdP 키 (경로)
     * @param  string  $id  자원 id
     * @return JsonResponse 자원
     */
    public function patch(ScimPatchRequest $request, string $idp, string $id): JsonResponse // route-param:unused IdP 는 미들웨어가 토큰으로 확정해 요청 속성에 둔다
    {
        $operations = $request->json('Operations');

        return $this->scim($this->users->patch($this->idp($request), $id, is_array($operations) ? $operations : []));
    }

    /**
     * 삭제 — 퇴사 처리(차단 + 토큰 회수). 회원은 지우지 않는다.
     *
     * @param  ScimListRequest  $request  요청
     * @param  string  $idp  IdP 키 (경로)
     * @param  string  $id  자원 id
     * @return JsonResponse 204
     */
    public function destroy(ScimListRequest $request, string $idp, string $id): JsonResponse // route-param:unused IdP 는 미들웨어가 토큰으로 확정해 요청 속성에 둔다
    {
        $this->users->delete($this->idp($request), $id);

        return $this->scim(null, 204);
    }
}
