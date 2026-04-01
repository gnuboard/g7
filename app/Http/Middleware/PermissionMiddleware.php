<?php

namespace App\Http\Middleware;

use App\Enums\PermissionType;
use App\Helpers\PermissionHelper;
use App\Helpers\ResponseHelper;
use App\Models\Role;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class PermissionMiddleware
{
    /**
     * guest role 캐시
     *
     * @var Role|null
     */
    protected static $guestRoleCache = null;

    /**
     * 특정 권한을 가진 사용자 또는 비회원(guest)의 접근을 허용합니다.
     *
     * 사용법:
     * - permission:admin,core.users.read (admin 타입의 권한 체크)
     * - permission:user,core.users.read (user 타입의 권한 체크)
     * - permission:admin,core.users.read|core.users.create (여러 권한, 기본 AND)
     * - permission:admin,core.users.read|core.users.create,false (여러 권한, OR 조건)
     * - permission:user,sirsoft-board.{slug}.posts.create (동적 파라미터 사용)
     *
     * @param  Request  $request  HTTP 요청
     * @param  Closure  $next  다음 미들웨어
     * @param  string  $type  권한 타입 (admin 또는 user)
     * @param  string  $permission  필요한 권한명 (파이프로 여러 권한 구분)
     * @param  string|null  $requireAll  여러 권한시 모두 필요한지 여부 ('true' 또는 'false')
     * @return Response HTTP 응답
     */
    public function handle(Request $request, Closure $next, string $type, string $permission, ?string $requireAll = null): Response
    {
        // Step 1: 권한 타입 유효성 검증
        if (! PermissionType::isValid($type)) {
            return ResponseHelper::forbidden('auth.invalid_permission_type', [
                'type' => $type,
                'valid_types' => implode(', ', array_map(fn ($case) => $case->value, PermissionType::cases())),
            ]);
        }

        $permissionType = PermissionType::from($type);

        // Step 2: 권한 식별자 동적 파라미터 치환
        $permissions = explode('|', $permission);
        $resolvedPermissions = array_map(
            fn ($perm) => $this->resolvePermissionIdentifier($perm, $request),
            $permissions
        );

        // Step 3: 권한 확인
        $user = $request->user();

        if ($user) {
            $hasPermission = count($resolvedPermissions) === 1
                ? $user->hasPermission($resolvedPermissions[0], $permissionType)
                : $user->hasPermissions($resolvedPermissions, filter_var($requireAll ?? 'true', FILTER_VALIDATE_BOOLEAN), $permissionType);

            if (! $hasPermission) {
                return ResponseHelper::forbidden('auth.permission_denied', [
                    'required_permissions' => implode(', ', $resolvedPermissions),
                ]);
            }
        } else {
            $hasPermission = count($resolvedPermissions) === 1
                ? $this->checkGuestPermission($resolvedPermissions[0], $permissionType)
                : collect($resolvedPermissions)->contains(fn ($perm) => $this->checkGuestPermission($perm, $permissionType));

            if (! $hasPermission) {
                return ResponseHelper::unauthorized('auth.guest_permission_denied', [
                    'required_permissions' => implode(', ', $resolvedPermissions),
                ]);
            }
        }

        // Step 4: scope_type 스코프 체크 (인증된 사용자 + 상세 엔드포인트만)
        if ($user) {
            foreach ($resolvedPermissions as $identifier) {
                $resourceRouteKey = PermissionHelper::getResourceRouteKey($identifier);

                // resource_route_key가 없으면 스코프 체크 불필요
                if (! $resourceRouteKey) {
                    continue;
                }

                // 라우트에서 모델 resolve
                $model = $request->route($resourceRouteKey);

                // 모델이 없으면 목록 엔드포인트 → 스코프 체크 스킵
                if (! $model instanceof Model) {
                    continue;
                }

                // 스코프 접근 체크
                if (! PermissionHelper::checkScopeAccess($model, $identifier, $user)) {
                    return ResponseHelper::forbidden('auth.scope_denied');
                }
            }
        }

        return $next($request);
    }

    /**
     * 비회원(guest) role의 권한을 확인합니다.
     *
     * @param  string  $permission  권한 식별자
     * @param  PermissionType  $type  권한 타입
     * @return bool 권한 존재 여부
     */
    protected function checkGuestPermission(string $permission, PermissionType $type): bool
    {
        $guestRole = $this->getGuestRole();

        if (! $guestRole) {
            return false;
        }

        return $guestRole->permissions()
            ->where('identifier', $permission)
            ->where('type', $type)
            ->exists();
    }

    /**
     * guest role을 캐싱하여 조회합니다.
     *
     * @return Role|null guest 역할
     */
    protected function getGuestRole(): ?Role
    {
        if (self::$guestRoleCache === null) {
            self::$guestRoleCache = Role::where('identifier', 'guest')
                ->with('permissions')
                ->first();
        }

        return self::$guestRoleCache;
    }

    /**
     * 권한 식별자의 동적 파라미터를 URL 파라미터 값으로 치환합니다.
     *
     * 예시: sirsoft-board.{slug}.posts.create + {slug: 'notice'}
     *      → sirsoft-board.notice.posts.create
     *
     * @param  string  $permission  권한 식별자 (예: sirsoft-board.{slug}.posts.create)
     * @param  Request  $request  HTTP 요청 객체
     * @return string 치환된 권한 식별자
     */
    protected function resolvePermissionIdentifier(string $permission, Request $request): string
    {
        // {slug}, {id} 등의 플레이스홀더 찾기
        preg_match_all('/\{(\w+)\}/', $permission, $matches);

        if (empty($matches[1])) {
            return $permission; // 동적 파라미터 없음
        }

        $routeParams = $request->route()->parameters();
        $resolvedPermission = $permission;

        foreach ($matches[1] as $paramName) {
            if (! isset($routeParams[$paramName])) {
                // URL 파라미터가 없는 경우 경고 로그
                Log::warning('Permission parameter not found in route', [
                    'permission' => $permission,
                    'param' => $paramName,
                    'route' => $request->route()->getName(),
                    'available_params' => array_keys($routeParams),
                ]);

                // 원본 반환 (권한 체크 실패로 이어짐)
                return $permission;
            }

            $resolvedPermission = str_replace(
                "{{$paramName}}",
                $routeParams[$paramName],
                $resolvedPermission
            );
        }

        return $resolvedPermission;
    }
}
