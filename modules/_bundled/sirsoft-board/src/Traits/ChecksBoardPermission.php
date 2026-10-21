<?php

namespace Modules\Sirsoft\Board\Traits;

use App\Enums\PermissionType;
use App\Helpers\PermissionHelper;
use App\Http\Middleware\PermissionMiddleware;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Modules\Sirsoft\Board\Support\BoardPermissionCacheKeys;

/**
 * 게시판 권한 체크 Trait
 *
 * PermissionMiddleware를 내부적으로 호출하여 권한을 체크합니다.
 * 미들웨어 로직 변경 시 자동으로 동일하게 동작합니다.
 *
 * 게시판 단위 스코프: 게시판 관리 권한(`manager`·`admin.manage`)은 스코프 메타가
 * `resource_route_key = board`, `owner_key = created_by` 다. 게시판 라우트는 `{slug}` 를 쓰므로
 * 미들웨어의 스코프 검사(라우트 Model 바인딩 때만 동작)가 돌지 않는다. 그래서 이 트레이트의
 * 판정(`checkBoardPermission`·`checkBoardPermissions`·`checkPermissionByIdentifier`)이 보유
 * 확인 뒤 게시판 모델로 스코프를 곱한다 — 「본인이 만든 게시판만」으로 위임받은 관리자가
 * 남의 게시판에서 관리 권한자로 판정되지 않는다.
 */
trait ChecksBoardPermission
{
    /**
     * Admin 화면 요청 여부를 컨트롤러 네임스페이스로 판정합니다.
     *
     * 판정 규칙의 단일 지점 — Resource/게이트마다 사설 복제본을 두면 규칙 변경 시
     * 한 곳만 누락되어 경로별 판정이 갈린다.
     *
     * @param  Request  $request  HTTP 요청
     * @return bool Admin 요청 여부
     */
    protected function isAdminRequest(Request $request): bool
    {
        $controller = $request->route()?->getController();

        if (! $controller) {
            return false;
        }

        return str_contains(get_class($controller), '\\Admin\\');
    }

    /**
     * 게시판 권한을 확인합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  string  $action  권한 액션 (예: 'admin.posts.read')
     * @param  PermissionType  $type  권한 타입
     * @return bool 권한 보유 여부
     */
    protected function checkBoardPermission(string $slug, string $action, PermissionType $type = PermissionType::Admin): bool
    {
        $identifier = "sirsoft-board.{$slug}.{$action}";

        return $this->checkPermissionViaMiddleware($identifier, $type)
            && $this->passesBoardScope($slug, $identifier);
    }

    /**
     * 특정 자원에 대한 게시판 권한을 스코프까지 확인합니다.
     *
     * 게시판 라우트는 `{slug}`·`{id}` 를 쓰므로 미들웨어의 스코프 검사(라우트에 Model 이
     * 바인딩될 때만 동작)가 돌지 않는다. 코드 안에서 자원(게시글·댓글 등)을 손에 쥔 판정은
     * 이 메서드로 스코프(self=본인 자원, role=역할을 공유하는 회원의 자원)를 재적용한다.
     *
     * 순서가 계약이다 — 보유 확인을 **먼저** 하고 그다음 스코프를 본다.
     * `PermissionHelper::checkScopeAccess()` 는 권한이 없는 사용자에게도 true 를 주므로
     * (스코프 메타·유효 스코프가 없으면 통과) 순서가 바뀌면 무권한자가 통과한다.
     * 비회원은 스코프를 가질 수 없으므로(코어 7.1.1) 보유 확인만 한다.
     *
     * @param  Model  $resource  판정 대상 자원 (owner_key 컬럼을 가진 모델)
     * @param  string  $slug  게시판 슬러그
     * @param  string  $action  권한 액션 (예: 'posts.read-secret', 'admin.posts.read-secret')
     * @param  PermissionType  $type  권한 타입
     * @return bool 보유하고 그 자원이 스코프 안이면 true
     */
    protected function checkBoardPermissionFor(Model $resource, string $slug, string $action, PermissionType $type = PermissionType::Admin): bool
    {
        if (! $this->checkBoardPermission($slug, $action, $type)) {
            return false;
        }

        if (! Auth::check()) {
            return true;
        }

        return PermissionHelper::checkScopeAccess($resource, "sirsoft-board.{$slug}.{$action}");
    }

    /**
     * 게시판 복수 권한을 확인합니다. (기본: OR 조건)
     *
     * @param  string  $slug  게시판 슬러그
     * @param  array  $actions  권한 액션 배열
     * @param  PermissionType  $type  권한 타입
     * @param  bool  $requireAll  모든 권한 필요 여부
     * @return bool 권한 보유 여부
     */
    protected function checkBoardPermissions(string $slug, array $actions, PermissionType $type = PermissionType::Admin, bool $requireAll = false): bool
    {
        // 식별자마다 보유 + 게시판 스코프를 함께 본다 — 묶어서 미들웨어에 넘기면 보유만 OR/AND 로 합쳐져
        // 스코프 밖 게시판의 관리 권한이 OR 의 한 축으로 통과한다.
        $check = fn (string $action): bool => $this->checkBoardPermission($slug, $action, $type);

        return $requireAll
            ? collect($actions)->every($check)
            : collect($actions)->contains($check);
    }

    /**
     * 게시판 관리 권한을 보유했는지 확인합니다.
     *
     * 관리자 페이지 권한(admin.manage)과 사용자 페이지 관리 권한(manager)을
     * 모두 인정합니다. 사용자 페이지 화면은 manager 권한으로 can_manage 를
     * 노출하므로, 실제 처리도 manager 를 인정해야 화면-서버 정합이 맞습니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @return bool 관리 권한 보유 여부
     */
    protected function hasBoardManagePermission(string $slug): bool
    {
        if (! Auth::check()) {
            return false;
        }

        return $this->checkBoardPermission($slug, 'admin.manage')
            || $this->checkBoardPermission($slug, 'manager', PermissionType::User);
    }

    /**
     * 공지 지정 권한 보유 여부를 확인합니다.
     *
     * 게시판 관리 권한(admin.manage / manager)은 상위 권한이므로 함께 인정하고,
     * 공지 등록 권한(posts.write-notice)도 단독으로 인정합니다. 사용자 글쓰기·관리자 글쓰기
     * 경로와 관리자 글쓰기 폼의 공지 체크박스 잠금(can_write_notice)이 모두 이 판정 하나를 쓴다 —
     * 경로마다 사설 판정을 두면 한쪽만 공지 등록 권한을 빠뜨려 같은 권한자가 경로에 따라 막혔다.
     *
     * @param  string  $slug  게시판 슬러그
     * @return bool 공지 지정 가능 여부
     */
    protected function canSetNotice(string $slug): bool
    {
        if (! Auth::check()) {
            return false;
        }

        return $this->hasBoardManagePermission($slug)
            || $this->checkBoardPermission($slug, 'posts.write-notice', PermissionType::User);
    }

    /**
     * 모듈 레벨 권한을 확인합니다.
     *
     * @param  string  $resource  리소스명 (예: 'boards')
     * @param  string  $action  권한 액션 (예: 'create')
     * @param  PermissionType  $type  권한 타입
     * @return bool 권한 보유 여부
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
     * @return ?JsonResponse 권한이 없으면 403 응답, 있으면 null
     */
    protected function authorizeOrFail(string $slug, string $action, PermissionType $type = PermissionType::Admin): ?JsonResponse
    {
        if (! $this->checkBoardPermission($slug, $action, $type)) {
            return $this->forbidden('sirsoft-board::messages.permission.denied');
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
     * @return ?JsonResponse 권한이 없으면 403 응답, 있으면 null
     */
    protected function authorizeAnyOrFail(string $slug, array $actions, PermissionType $type = PermissionType::Admin, bool $requireAll = false): ?JsonResponse
    {
        if (! $this->checkBoardPermissions($slug, $actions, $type, $requireAll)) {
            return $this->forbidden('sirsoft-board::messages.permission.denied');
        }

        return null;
    }

    /**
     * 모듈 레벨 권한 확인 후 실패 시 403 반환합니다.
     *
     * @param  string  $resource  리소스명
     * @param  string  $action  권한 액션
     * @param  PermissionType  $type  권한 타입
     * @return ?JsonResponse 권한이 없으면 403 응답, 있으면 null
     */
    protected function authorizeModuleOrFail(string $resource, string $action, PermissionType $type = PermissionType::Admin): ?JsonResponse
    {
        if (! $this->checkModulePermission($resource, $action, $type)) {
            return $this->forbidden('sirsoft-board::messages.permission.denied');
        }

        return null;
    }

    /**
     * 권한 식별자로 직접 권한을 확인합니다.
     *
     * Admin/User 페이지에 따라 자동으로 권한 타입을 결정합니다.
     *
     * @param  string  $identifier  권한 식별자 (예: 'sirsoft-board.notice.posts.read')
     * @return bool 권한 보유 여부
     */
    protected function checkPermissionByIdentifier(string $identifier): bool
    {
        // 권한 타입 자동 결정: admin. 포함 시 Admin, 아니면 User
        $type = str_contains($identifier, '.admin.') ? PermissionType::Admin : PermissionType::User;

        if (! $this->checkPermissionViaMiddleware($identifier, $type)) {
            return false;
        }

        // 게시판별 식별자(sirsoft-board.{slug}.{정의된 액션})면 게시판 스코프를 곱한다.
        // 모듈 단위 식별자(sirsoft-board.boards.*·reports.* 등)는 게시판 슬러그가 아니므로 대상이 아니다.
        $slug = $this->parseBoardSlugFromIdentifier($identifier);

        return $slug === null || $this->passesBoardScope($slug, $identifier);
    }

    /**
     * 게시판별 권한 식별자에서 게시판 슬러그를 뽑습니다.
     *
     * `sirsoft-board.{slug}.{action}` 의 action 이 게시판별 권한 정의
     * (`config('sirsoft-board.board_permission_definitions')`)의 키일 때만 게시판 식별자로 본다.
     *
     * @param  string  $identifier  권한 식별자
     * @return string|null 게시판 슬러그 (게시판별 식별자가 아니면 null)
     */
    protected function parseBoardSlugFromIdentifier(string $identifier): ?string
    {
        if (! preg_match('/^sirsoft-board\.([^.]+)\.(.+)$/', $identifier, $m)) {
            return null;
        }

        $definitions = config('sirsoft-board.board_permission_definitions', []);

        return array_key_exists($m[2], $definitions) ? $m[1] : null;
    }

    /**
     * 게시판 단위 스코프를 통과하는지 확인합니다 (보유 확인을 통과한 뒤에만 부른다).
     *
     * 권한의 `resource_route_key` 가 `board` 일 때만 게시판 모델로 `checkScopeAccess` 를 본다
     * (`manager`·`admin.manage`). 그 밖의 권한(게시글·댓글·첨부 단위)은 자원을 손에 쥔 쪽이
     * `checkBoardPermissionFor()` 로 판정한다. 비회원은 스코프를 가질 수 없으므로 통과한다.
     * 게시판을 찾지 못하면 거부한다(fail-closed).
     *
     * @param  string  $slug  게시판 슬러그
     * @param  string  $identifier  권한 식별자
     * @return bool 스코프 안이면 true
     */
    protected function passesBoardScope(string $slug, string $identifier): bool
    {
        if (! Auth::check()) {
            return true;
        }

        if (PermissionHelper::getResourceRouteKey($identifier) !== 'board') {
            return true;
        }

        $request = request();
        $cacheKey = Auth::id().'|scope|'.$identifier;
        $cache = $request->attributes->get(BoardPermissionCacheKeys::PERMISSION, []);

        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $board = $this->resolveBoardForScope($slug);
        $passed = $board !== null && PermissionHelper::checkScopeAccess($board, $identifier);

        $cache = $request->attributes->get(BoardPermissionCacheKeys::PERMISSION, []);
        $cache[$cacheKey] = $passed;
        $request->attributes->set(BoardPermissionCacheKeys::PERMISSION, $cache);

        return $passed;
    }

    /**
     * 게시판 스코프 판정용 게시판 모델을 요청 단위 캐시로 조회합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @return Board|null 게시판 모델 (없으면 null)
     */
    protected function resolveBoardForScope(string $slug): ?Board
    {
        $request = request();
        $boards = $request->attributes->get(BoardPermissionCacheKeys::BOARD_MODEL, []);

        if (! array_key_exists($slug, $boards)) {
            $boards[$slug] = app(BoardRepositoryInterface::class)->findBySlug($slug) ?? false;
            $request->attributes->set(BoardPermissionCacheKeys::BOARD_MODEL, $boards);
        }

        return $boards[$slug] ?: null;
    }

    /**
     * 권한 캐시를 초기화합니다.
     *
     * 테스트 환경에서 동일 Request 인스턴스가 재사용되는 경우 호출합니다.
     *
     * @return void 반환값 없음
     */
    public static function clearPermissionCache(): void
    {
        request()->attributes->remove(BoardPermissionCacheKeys::PERMISSION);
        request()->attributes->remove(BoardPermissionCacheKeys::ABILITIES);
        request()->attributes->remove(BoardPermissionCacheKeys::BOARD_MODEL);
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
     * @return bool 권한 보유 여부
     */
    protected function checkPermissionViaMiddleware(string $permission, PermissionType $type, bool $requireAll = true): bool
    {
        $request = request();
        $userId = Auth::id() ?? 'guest';
        $cacheKey = $userId.'|'.$permission.'|'.$type->value.'|'.($requireAll ? '1' : '0');

        // 요청 인스턴스에 권한 캐시를 저장하여 요청 단위로 자동 격리
        $cache = $request->attributes->get(BoardPermissionCacheKeys::PERMISSION, []);

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
        $request->attributes->set(BoardPermissionCacheKeys::PERMISSION, $cache);

        return $passed;
    }
}
