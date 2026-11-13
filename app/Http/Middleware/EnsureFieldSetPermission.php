<?php

namespace App\Http\Middleware;

use App\Fields\FieldSetRegistry;
use App\Helpers\PermissionHelper;
use App\Helpers\ResponseHelper;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 항목 집합의 조회·수정 권한 관문.
 *
 * 항목 관리 라우트(`/api/admin/field-sets/{set}/fields…`)는 집합마다 권한이 다르다 — 회원 집합은 환경설정 권한,
 * 확장이 가진 집합은 그 확장이 정한 권한이다. 그래서 고정 권한을 적는 `permission` 미들웨어 대신 이 미들웨어가
 * 라우트의 `{set}` 으로 집합을 찾아 그 집합의 권한을 확인한다.
 *
 * 사용: `field_set.permission:read` 또는 `field_set.permission:manage`.
 *
 * @since 7.2.0
 */
class EnsureFieldSetPermission
{
    /**
     * @param  FieldSetRegistry  $sets  집합 등록소
     */
    public function __construct(
        protected FieldSetRegistry $sets,
    ) {}

    /**
     * 집합이 있고 요청자가 그 집합의 권한을 가졌는지 확인합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  Closure  $next  다음 미들웨어
     * @param  string  $ability  확인할 권한 종류 (read|manage)
     * @return Response HTTP 응답 (집합이 없으면 404, 권한이 없으면 403)
     */
    public function handle(Request $request, Closure $next, string $ability = 'read'): Response
    {
        $set = $this->sets->get((string) $request->route('set'));
        if ($set === null) {
            return ResponseHelper::error('common.not_found', 404);
        }

        $permission = $ability === 'manage' ? $set->managePermission : $set->readPermission;
        if (! PermissionHelper::check($permission, $request->user())) {
            return ResponseHelper::error('common.forbidden', 403);
        }

        return $next($request);
    }
}
