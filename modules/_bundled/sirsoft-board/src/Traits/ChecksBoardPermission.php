<?php

namespace Modules\Sirsoft\Board\Traits;

use App\Enums\PermissionType;
use App\Http\Middleware\PermissionMiddleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * 게시판 권한 체크 Trait
 *
 * PermissionMiddleware를 내부적으로 호출하여 권한을 체크합니다.
 * 미들웨어 로직 변경 시 자동으로 동일하게 동작합니다.
 */
trait ChecksBoardPermission
{
    /**
     * 게시판 권한을 확인합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  string  $action  권한 액션 (예: 'admin.posts.read')
     * @param  PermissionType  $type  권한 타입
     * @return bool
     */
    protected function checkBoardPermission(string $slug, string $action, PermissionType $type = PermissionType::Admin): bool
    {
        return $this->checkPermissionViaMiddleware("sirsoft-board.{$slug}.{$action}", $type);
    }

    /**
     * 게시판 복수 권한을 확인합니다. (기본: OR 조건)
     *
     * @param  string  $slug  게시판 슬러그
     * @param  array  $actions  권한 액션 배열
     * @param  PermissionType  $type  권한 타입
     * @param  bool  $requireAll  모든 권한 필요 여부
     * @return bool
     */
    protected function checkBoardPermissions(string $slug, array $actions, PermissionType $type = PermissionType::Admin, bool $requireAll = false): bool
    {
        $permissions = implode('|', array_map(fn ($action) => "sirsoft-board.{$slug}.{$action}", $actions));

        return $this->checkPermissionViaMiddleware($permissions, $type, $requireAll);
    }

    /**
     * 모듈 레벨 권한을 확인합니다.
     *
     * @param  string  $resource  리소스명 (예: 'boards')
     * @param  string  $action  권한 액션 (예: 'create')
     * @param  PermissionType  $type  권한 타입
     * @return bool
     */
    protected function checkModulePermission(string $resource, string $action, PermissionType $type = PermissionType::Admin): bool
    {
        return $this->checkPermissionViaMiddleware("sirsoft-board.{$resource}.{$action}", $type);
    }

    /**
     * 게시판 권한 확인 후 실패 시 403 반환합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  string  $action  권한 액션
     * @param  PermissionType  $type  권한 타입
     * @return JsonResponse|null
     */
    protected function authorizeOrFail(string $slug, string $action, PermissionType $type = PermissionType::Admin): ?JsonResponse
    {
        if (! $this->checkBoardPermission($slug, $action, $type)) {
            return $this->forbidden('sirsoft-board::messages.permissions.access_denied');
        }

        return null;
    }

    /**
     * 게시판 복수 권한 확인 후 실패 시 403 반환합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  array  $actions  권한 액션 배열
     * @param  PermissionType  $type  권한 타입
     * @param  bool  $requireAll  모든 권한 필요 여부
     * @return JsonResponse|null
     */
    protected function authorizeAnyOrFail(string $slug, array $actions, PermissionType $type = PermissionType::Admin, bool $requireAll = false): ?JsonResponse
    {
        if (! $this->checkBoardPermissions($slug, $actions, $type, $requireAll)) {
            return $this->forbidden('sirsoft-board::messages.permissions.access_denied');
        }

        return null;
    }

    /**
     * 모듈 레벨 권한 확인 후 실패 시 403 반환합니다.
     *
     * @param  string  $resource  리소스명
     * @param  string  $action  권한 액션
     * @param  PermissionType  $type  권한 타입
     * @return JsonResponse|null
     */
    protected function authorizeModuleOrFail(string $resource, string $action, PermissionType $type = PermissionType::Admin): ?JsonResponse
    {
        if (! $this->checkModulePermission($resource, $action, $type)) {
            return $this->forbidden('sirsoft-board::messages.permissions.access_denied');
        }

        return null;
    }

    /**
     * 권한 식별자로 직접 권한을 확인합니다.
     *
     * Admin/User 페이지에 따라 자동으로 권한 타입을 결정합니다.
     *
     * @param  string  $identifier  권한 식별자 (예: 'sirsoft-board.notice.posts.read')
     * @return bool
     */
    protected function checkPermissionByIdentifier(string $identifier): bool
    {
        // 권한 타입 자동 결정: admin. 포함 시 Admin, 아니면 User
        $type = str_contains($identifier, '.admin.') ? PermissionType::Admin : PermissionType::User;

        return $this->checkPermissionViaMiddleware($identifier, $type);
    }

    /**
     * 권한 캐시를 초기화합니다.
     *
     * 테스트 환경에서 동일 Request 인스턴스가 재사용되는 경우 호출합니다.
     *
     * @return void
     */
    public static function clearPermissionCache(): void
    {
        request()->attributes->remove('_board_permission_cache');
    }

    /**
     * PermissionMiddleware를 통해 권한을 확인합니다.
     *
     * 동일 요청 내에서 같은 권한 조합에 대한 결과를 캐싱합니다.
     * 캐시는 request()->instance()를 키로 사용하여 요청 단위로 자동 격리됩니다.
     *
     * @param  string  $permission  권한 식별자 (파이프로 복수 권한 구분)
     * @param  PermissionType  $type  권한 타입
     * @param  bool  $requireAll  모든 권한 필요 여부
     * @return bool
     */
    protected function checkPermissionViaMiddleware(string $permission, PermissionType $type, bool $requireAll = true): bool
    {
        $request = request();
        $userId = Auth::id() ?? 'guest';
        $cacheKey = $userId . '|' . $permission . '|' . $type->value . '|' . ($requireAll ? '1' : '0');

        // 요청 인스턴스에 권한 캐시를 저장하여 요청 단위로 자동 격리
        $cache = $request->attributes->get('_board_permission_cache', []);

        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $middleware = app(PermissionMiddleware::class);
        $passed = false;

        $middleware->handle(
            $request,
            function () use (&$passed) {
                $passed = true;

                return response('ok');
            },
            $type->value,
            $permission,
            $requireAll ? 'true' : 'false'
        );

        $cache[$cacheKey] = $passed;
        $request->attributes->set('_board_permission_cache', $cache);

        return $passed;
    }
}
