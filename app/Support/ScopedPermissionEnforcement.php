<?php

namespace App\Support;

use App\Contracts\Repositories\PermissionRepositoryInterface;
use App\Extension\HookManager;
use App\Helpers\PermissionHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use ReflectionMethod;

/**
 * 스코프가 붙은 권한 부여가 실제로 어디선가 강제되는지 확인합니다.
 *
 * 권한의 self·role 스코프는 세 자리에서만 강제된다.
 *
 * - (a) 라우트 모델 바인딩 — 권한에 `resource_route_key`·`owner_key` 가 있고 라우트가 그 이름의
 *   파라미터를 Model 로 바인딩하면 PermissionMiddleware Step 4 가 상세 경로를 좁힌다.
 * - (b) 서비스·게이트 재적용 — 라우트가 `{slug}`·`{id}` 처럼 모델을 바인딩하지 않으면 Step 4 는
 *   조용히 건너뛴다. 그 경로는 서비스·게이트가 대상을 조회한 뒤 `checkScopeAccess` 등으로 다시 판정해야 한다.
 * - (c) 저장소 쿼리 스코프 — 목록·통계처럼 대상이 여럿인 경로, 또는 소유 컬럼이 둘 이상이라 단일
 *   owner_key 로 표현할 수 없는 권한은 저장소가 직접 조건을 건다.
 *
 * 어느 쪽도 아니면 스코프를 붙여도 아무것도 좁혀지지 않는다 — 운영자는 「본인만」 으로 설정했다고
 * 믿지만 실제로는 전체 접근이다. `core.notification-logs.read` 가 메타 없이 self 로 부여되고
 * 보조 경로(대시보드 최근 알림)에 쿼리 스코프가 빠져, 위임 관리자가 전체 회원의 알림을 보던
 * 전례가 있다(KVE-2026-2374). 게시판·이커머스에서는 스코프 메타가 선언됐는데 라우트가 모델을
 * 바인딩하지 않아 실제로는 적용되지 않던 경로가 여럿 있었다(7.1.1).
 *
 * 그래서 (b)·(c) 로 강제하는 경로를 **강제 지점 원장**({@see self::LEDGER})에 등록한다. 원장은
 * 런타임 동작을 바꾸지 않는다 — 테스트 감사({@see self::auditRoutes()}, {@see self::auditEnforcers()})가
 * 「스코프 메타가 있는 권한을 거는데 모델 바인딩도 원장 등록도 없는 라우트」와 「원장에 적혔지만
 * 사라졌거나 스코프 판정을 하지 않는 메서드」를 찾아낸다. 확장은 필터 훅
 * `core.permission.scope_enforcement_ledger` 로 자기 항목을 더한다.
 *
 * 비회원(guest) 역할은 스코프를 가질 수 없다(7.1.1) — 비회원에게는 「본인」 을 판정할 신원이 없다.
 * 남아 있는 부여는 {@see self::findGuestScopedGrants()} 가 드러낸다.
 */
class ScopedPermissionEnforcement
{
    /**
     * 라우트 모델 바인딩으로 강제된다(PermissionMiddleware Step 4 가 자동 판정).
     */
    public const MODE_ROUTE = 'route';

    /**
     * 서비스·게이트가 대상을 조회한 뒤 스코프를 다시 판정한다.
     */
    public const MODE_SERVICE = 'service';

    /**
     * 저장소 쿼리가 스코프 조건을 건다.
     */
    public const MODE_QUERY = 'query';

    /**
     * 원장 항목이 가질 수 있는 강제 방식.
     *
     * @var array<int, string>
     */
    public const MODES = [self::MODE_ROUTE, self::MODE_SERVICE, self::MODE_QUERY];

    /**
     * 저장소 쿼리 스코프가 정본인 권한 식별자 (하위 호환 — 새 항목은 {@see self::LEDGER} 에 MODE_QUERY 로 적는다).
     *
     * 여기 올리는 권한은 그 권한으로 열리는 **모든** 조회 경로의 저장소가 스코프를 걸어야 한다 —
     * 목록에만 걸고 보조 경로(최근·통계·대시보드)에 빠뜨리면 그 경로가 우회로가 된다.
     *
     * @var array<int, string>
     */
    public const QUERY_SCOPED_PERMISSIONS = [
        // 발신자·수신자 두 소유 컬럼 — NotificationLogRepository::applyNotificationLogScope
        'core.notification-logs.read',
    ];

    /**
     * 코어 강제 지점 원장.
     *
     * 키는 권한 식별자다. `{slug}` 같은 자리표시자와 `*` 를 쓸 수 있다. 값:
     *
     * - `mode`: {@see self::MODES} 중 하나
     * - `routes`: 이 권한을 거는데 모델을 바인딩하지 않는 라우트 이름 패턴(`*` 허용) — 감사가 위반에서 뺀다
     * - `paths`: 스코프를 실제로 판정하는 메서드(`FQCN::method`) — 감사가 존재와 판정 여부를 확인한다
     * - `exempt`(선택): `['라우트 이름 패턴' => '사유']` — 범위로 좁힐 소유 대상이 없는 라우트(예: manifest 선언만 주는
     *   목록, 다른 회원이 쓰는 값인지 알려 줘야 하는 존재 확인). 사유가 비면 감사 위반이다
     *
     * @var array<string, array{mode: string, routes: array<int, string>, paths: array<int, string>, exempt?: array<string, string>}>
     */
    public const LEDGER = [
        // 발신자·수신자 두 소유 컬럼이라 owner_key 하나로 표현할 수 없다 — 목록·대시보드 최근 알림 모두 저장소 스코프
        'core.notification-logs.read' => [
            'mode' => self::MODE_QUERY,
            'routes' => [
                'api.admin.notification-logs.index',
                'api.admin.dashboard.recent-notifications',
            ],
            'paths' => [
                'App\Repositories\NotificationLogRepository::getPaginated',
                'App\Repositories\NotificationLogRepository::getRecent',
            ],
        ],
        // 대시보드 최근 활동 — 상세 경로가 없어 저장소 쿼리 스코프가 정본
        'core.dashboard.activities' => [
            'mode' => self::MODE_QUERY,
            'routes' => ['api.admin.dashboard.activities'],
            'paths' => ['App\Repositories\ActivityLogRepository::getRecent'],
        ],
        'core.activities.read' => [
            'mode' => self::MODE_QUERY,
            'routes' => ['api.admin.activity-logs.index'],
            'paths' => ['App\Repositories\ActivityLogRepository::getPaginated'],
        ],
        'core.menus.read' => [
            'mode' => self::MODE_QUERY,
            // 목록·계층·확장별 조회 — 계층·확장별은 7.1.1 원장 감사로 발견해 같은 범위를 걸었다
            'routes' => ['api.admin.menus.index', 'api.admin.menus.hierarchy', 'api.admin.menus.by-extension'],
            'paths' => [
                'App\Repositories\MenuRepository::getFilteredTopLevelMenus',
                'App\Repositories\MenuRepository::getTopLevelMenus',
                'App\Repositories\MenuRepository::getMenusByExtension',
            ],
            'exempt' => [
                // core.modules.read|core.menus.read OR — menus.read 만 가진 관리자에게는 모듈 manifest 가 선언한 커스텀 메뉴만 준다
                'api.admin.modules.index' => '모듈 manifest 의 커스텀 메뉴 선언만 반환 — DB 소유 행이 아니라 범위로 좁힐 소유자가 없다',
            ],
        ],
        // 순서·역할 일괄 변경은 대상 메뉴 전량을 단언하고 하나라도 범위 밖이면 전량 거부
        'core.menus.update' => [
            'mode' => self::MODE_SERVICE,
            'routes' => ['api.admin.menus.update-order', 'api.admin.menus.bulk-roles'],
            'paths' => [
                'App\Services\MenuService::assertMenusWithinScope',
                'App\Services\MenuService::assertOrderTargetsWithinScope',
                'App\Services\MenuService::updateMenuOrderWithHierarchy',
                'App\Services\MenuService::bulkUpdateRoles',
                'App\Services\MenuService::getManageableMenuIds',
            ],
        ],
        'core.attachments.update' => [
            'mode' => self::MODE_SERVICE,
            'routes' => ['api.admin.attachments.reorder'],
            'paths' => [
                'App\Services\AttachmentService::assertReorderWithinScope',
                'App\Services\AttachmentService::reorder',
            ],
        ],
        // 회원 조회 보조 경로(목록·통계·언어 분포·최근·검색)는 모두 UserRepository::scopedUserQuery 한 자리를 지난다.
        // 개인정보 명단(휴면·비밀번호 만료·연간 고지 — 확장 라우트 포함)은 「전체 범위만」 열람이라 범위가 좁으면 거부한다.
        'core.users.read' => [
            'mode' => self::MODE_QUERY,
            'routes' => [
                'api.admin.users.index',
                'api.admin.users.statistics',
                'api.admin.users.recent',
                'api.admin.users.search',
                'api.admin.privacy.dormant-targets',
                'api.admin.privacy.password-expiry-targets',
                // 확장 라우트가 코어 권한·코어 판정(canViewTargetList)을 쓰는 연간 고지 명단 — 강제 코드가 코어에 있어 코어 원장에 둔다
                'api.plugins.*.admin.annual-notice.targets',
            ],
            'paths' => [
                'App\Repositories\UserRepository::scopedUserQuery',
                'App\Repositories\UserRepository::getPaginatedUsers',
                'App\Repositories\UserRepository::getStatistics',
                'App\Repositories\UserRepository::getUsersByLanguage',
                'App\Repositories\UserRepository::getRecentUsers',
                'App\Repositories\UserRepository::searchByKeyword',
                'App\Privacy\PrivacyRetentionManager::canViewTargetList',
            ],
            'exempt' => [
                'api.admin.users.check-email' => '이메일 사용 가능 여부(불리언) — 다른 회원이 쓰는 이메일도 「사용 불가」 로 알려야 회원 생성 폼이 동작하는 본질적 존재 확인',
            ],
        ],
        // 일괄 상태·역할 변경은 상세 경로와 같은 판정(checkScopeAccess)으로 대상별 재적용
        'core.users.update' => [
            'mode' => self::MODE_SERVICE,
            'routes' => ['api.admin.users.bulk-status', 'api.admin.users.bulk-roles'],
            'paths' => [
                'App\Services\UserService::bulkUpdateStatus',
                'App\Services\UserService::bulkUpdateRoles',
            ],
        ],
    ];

    /**
     * 메서드 본문에 있으면 「스코프를 판정한다」 고 보는 토큰.
     *
     * @var array<int, string>
     */
    public const SCOPE_AWARE_TOKENS = [
        'checkScopeAccess',
        'applyPermissionScope',
        'filterByScope',
        'assertWithinScope',
        'assertAllWithinScope',
        'checkBoardPermissionFor',
        'hasBoardManagePermission',
        'BoardManageScope',
        'BoardReadScope',
        'ClaimScopeGuard',
        'applyNotificationLogScope',
        // 「전체 범위만 허용」 판정(개인정보 명단) — 범위가 좁으면 거부한다
        'getEffectiveScopeForPermission',
    ];

    /**
     * 비회원 역할 식별자 (App\Models\Role::GUEST_IDENTIFIER 와 같은 값 — 업그레이드 스텝처럼 모델을 쓸 수 없는 곳과 맞춘다).
     */
    private const GUEST_ROLE = 'guest';

    /**
     * @param  PermissionRepositoryInterface  $permissionRepository  권한 조회
     */
    public function __construct(
        private PermissionRepositoryInterface $permissionRepository
    ) {}

    /**
     * 강제 지점 원장을 반환합니다 (코어 원장 + 확장이 필터 훅으로 더한 항목).
     *
     * 확장 리스너는 `core.permission.scope_enforcement_ledger` 필터를 구독해 받은 배열에 자기 항목을
     * 병합해 돌려준다. 형식이 어긋난 항목(모드 누락·배열 아님)은 감사가 위반으로 드러내도록 그대로 둔다.
     *
     * @return array<string, array{mode: string, routes: array<int, string>, paths: array<int, string>, exempt?: array<string, string>}> 원장
     */
    public static function ledger(): array
    {
        $ledger = HookManager::applyFilters('core.permission.scope_enforcement_ledger', self::LEDGER);

        $normalized = [];
        foreach (is_array($ledger) ? $ledger : self::LEDGER as $identifier => $entry) {
            $entry = is_array($entry) ? $entry : [];
            $normalized[(string) $identifier] = [
                'mode' => (string) ($entry['mode'] ?? ''),
                'routes' => array_values(array_map('strval', (array) ($entry['routes'] ?? []))),
                'paths' => array_values(array_map('strval', (array) ($entry['paths'] ?? []))),
                'exempt' => array_map('strval', (array) ($entry['exempt'] ?? [])),
            ];
        }

        return $normalized;
    }

    /**
     * 권한 식별자에 맞는 원장 항목을 찾습니다.
     *
     * 정확히 같은 키를 먼저 보고, 없으면 자리표시자(`{slug}` → 점 없는 한 구간)·`*`(아무 문자열) 패턴 키를 본다.
     * 라우트 미들웨어에 적힌 템플릿 식별자(`sirsoft-board.{slug}.posts.read`)와 치환된 식별자 모두 받는다.
     *
     * @param  string  $identifier  권한 식별자
     * @param  array<string, array{mode: string, routes: array<int, string>, paths: array<int, string>, exempt?: array<string, string>}>|null  $ledger  원장 (null 이면 {@see self::ledger()})
     * @return array{mode: string, routes: array<int, string>, paths: array<int, string>, exempt?: array<string, string>}|null 원장 항목
     */
    public static function ledgerEntry(string $identifier, ?array $ledger = null): ?array
    {
        $ledger ??= self::ledger();

        if (isset($ledger[$identifier])) {
            return $ledger[$identifier];
        }

        foreach ($ledger as $key => $entry) {
            if (preg_match(self::identifierPattern($key), $identifier) === 1) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * 쿼리 스코프가 정본인 권한 식별자 목록을 반환합니다.
     *
     * 기존 상수 · 기존 필터 `core.permission.query_scoped_permissions` · 원장의 MODE_QUERY 항목의 합집합이다(하위 호환).
     *
     * @return array<int, string> 권한 식별자 목록
     */
    public static function queryScopedPermissions(): array
    {
        $fromLedger = array_keys(array_filter(
            self::ledger(),
            static fn (array $entry) => $entry['mode'] === self::MODE_QUERY
        ));

        return array_values(array_unique(array_merge(
            HookManager::applyFilters('core.permission.query_scoped_permissions', self::QUERY_SCOPED_PERMISSIONS),
            $fromLedger
        )));
    }

    /**
     * 스코프 메타가 있는 권한을 거는데 모델 바인딩도 원장 등록도 없는 라우트를 찾습니다.
     *
     * 라우트의 `permission:` 미들웨어를 파싱해 `$prefix` 로 시작하는 권한마다 판정한다.
     * 스코프 대상 권한 = DB 의 `resource_route_key`·`owner_key` 가 모두 있거나 원장 항목이 있는 권한.
     *
     * - 라우트가 `resource_route_key` 이름의 파라미터를 Model 서브클래스 시그니처(또는 명시 바인딩)로 받으면 통과
     * - 아니면 원장 항목의 `routes` 패턴이 라우트 이름(또는 URI)과 맞아야 통과(`exempt` 패턴도 통과 — 사유는 auditEnforcers 가 본다)
     * - 둘 다 아니면 `unbound_unledgered`, 자리표시자를 치환하지 못하면 `unresolved_placeholder`
     *
     * @param  iterable<Route>  $routes  검사할 라우트 (보통 `Route::getRoutes()`)
     * @param  string  $prefix  검사할 권한 식별자 접두사 (예: `core.`, `sirsoft-board.`)
     * @param  array<string, string|array<int, string>>  $placeholders  권한 식별자 자리표시자 치환값 (예: `['slug' => 'notice']`,
     *                                                                  `['type' => ['module', 'plugin']]`) — 메타 조회용, 배열이면 값마다 검사
     * @return array<int, array{route: string, uri: string, permission: string, reason: string}> 위반 목록
     */
    public static function auditRoutes(iterable $routes, string $prefix, array $placeholders = []): array
    {
        $ledger = self::ledger();
        $router = RouteFacade::getFacadeRoot();
        $violations = [];

        foreach ($routes as $route) {
            foreach (self::routePermissions($route) as $template) {
                if (! str_starts_with($template, $prefix)) {
                    continue;
                }

                $templateEntry = self::ledgerEntry($template, $ledger);

                foreach (self::resolvePlaceholders($template, $placeholders) as $resolved) {
                    if (str_contains($resolved, '{')) {
                        // 원장에 템플릿 그대로 등록돼 있고 라우트도 원장에 있으면 메타 조회 없이 통과
                        if ($templateEntry !== null && (self::routeIsLedgered($route, $templateEntry) || self::routeIsExempt($route, $templateEntry))) {
                            continue;
                        }
                        $violations[] = self::violation($route, $template, 'unresolved_placeholder');

                        continue;
                    }

                    $entry = $templateEntry ?? self::ledgerEntry($resolved, $ledger);
                    $resourceRouteKey = PermissionHelper::getResourceRouteKey($resolved);
                    $hasOwnerMetadata = $resourceRouteKey !== null && PermissionHelper::getOwnerKey($resolved) !== null;

                    if (! $hasOwnerMetadata && $entry === null) {
                        continue; // 스코프 대상 아님
                    }

                    if ($hasOwnerMetadata && self::routeBindsModel($route, $resourceRouteKey, $router)) {
                        continue;
                    }

                    if ($entry !== null && (self::routeIsLedgered($route, $entry) || self::routeIsExempt($route, $entry))) {
                        continue;
                    }

                    $violations[] = self::violation($route, $template, 'unbound_unledgered');
                }
            }
        }

        // 자리표시자 값이 여럿이면 같은 위반이 반복될 수 있다
        return array_values(array_unique($violations, SORT_REGULAR));
    }

    /**
     * 원장 항목의 강제 메서드가 존재하고 실제로 스코프를 판정하는지 확인합니다.
     *
     * `$prefix` 로 시작하는 원장 키만 본다. 메서드 본문에 {@see self::SCOPE_AWARE_TOKENS} 중 하나가
     * 있거나, 원장에 적힌 다른 메서드를 호출하면 통과한다(위임). 이름이 바뀌어 원장이 낡으면
     * `missing_enforcer`, 판정 흔적이 없으면 `enforcer_without_scope_check`, 모드가 잘못되면 `invalid_mode`,
     * 서비스·쿼리 모드인데 메서드가 하나도 없으면 `no_enforcer_path`, 사유 없는 면제는 `exempt_without_reason` 이다.
     *
     * @param  string  $prefix  검사할 원장 키 접두사 (예: `core.`, `sirsoft-board.`)
     * @param  array<int, string>  $extraTokens  확장 고유의 판정 토큰 (예: 자체 가드 클래스 이름)
     * @return array<int, array{permission: string, path: string|null, reason: string}> 위반 목록
     */
    public static function auditEnforcers(string $prefix, array $extraTokens = []): array
    {
        $ledger = self::ledger();
        $tokens = array_merge(self::SCOPE_AWARE_TOKENS, $extraTokens);
        $ledgerMethods = [];
        foreach ($ledger as $entry) {
            foreach ($entry['paths'] as $path) {
                if (str_contains($path, '::')) {
                    $ledgerMethods[] = Str::after($path, '::');
                }
            }
        }
        $ledgerMethods = array_values(array_unique($ledgerMethods));

        $violations = [];
        foreach ($ledger as $identifier => $entry) {
            if (! str_starts_with($identifier, $prefix)) {
                continue;
            }

            foreach ($entry['exempt'] ?? [] as $pattern => $reason) {
                if (trim($reason) === '') {
                    $violations[] = ['permission' => $identifier, 'path' => (string) $pattern, 'reason' => 'exempt_without_reason'];
                }
            }

            if (! in_array($entry['mode'], self::MODES, true)) {
                $violations[] = ['permission' => $identifier, 'path' => null, 'reason' => 'invalid_mode'];

                continue;
            }

            if ($entry['mode'] !== self::MODE_ROUTE && $entry['paths'] === []) {
                $violations[] = ['permission' => $identifier, 'path' => null, 'reason' => 'no_enforcer_path'];

                continue;
            }

            foreach ($entry['paths'] as $path) {
                $body = self::methodBody($path);

                if ($body === null) {
                    $violations[] = ['permission' => $identifier, 'path' => $path, 'reason' => 'missing_enforcer'];

                    continue;
                }

                if (! self::bodyChecksScope($body, $tokens, $ledgerMethods, Str::after($path, '::'))) {
                    $violations[] = ['permission' => $identifier, 'path' => $path, 'reason' => 'enforcer_without_scope_check'];
                }
            }
        }

        return $violations;
    }

    /**
     * 역할 정의(config)가 스코프를 거는 권한 중 강제 수단이 없는 것을 찾습니다.
     *
     * 비회원(guest) 역할의 스코프는 메타가 있어도 위반이다 — 비회원은 스코프를 가질 수 없다.
     *
     * @param  array  $permissionsConfig  `config('core.permissions')` 형태의 권한 정의
     * @param  array  $rolesConfig  `config('core.roles')` 형태의 역할 정의
     * @return array<int, array{role: string, permission: string, scope_type: string}> 위반 목록
     */
    public static function findUnenforcedInConfig(array $permissionsConfig, array $rolesConfig): array
    {
        $hasOwnerMetadata = [];
        foreach ($permissionsConfig['categories'] ?? [] as $category) {
            foreach ($category['permissions'] ?? [] as $permission) {
                $hasOwnerMetadata[$permission['identifier']] = ! empty($permission['resource_route_key'])
                    && ! empty($permission['owner_key']);
            }
        }

        $exempt = self::queryScopedPermissions();
        $violations = [];

        foreach ($rolesConfig as $role) {
            $isGuest = ($role['identifier'] ?? null) === self::GUEST_ROLE;

            foreach ($role['permission_scopes'] ?? [] as $identifier => $scope) {
                if ($scope === null) {
                    continue;
                }

                if (! $isGuest && (($hasOwnerMetadata[$identifier] ?? false) || in_array($identifier, $exempt, true))) {
                    continue;
                }

                $violations[] = [
                    'role' => $role['identifier'],
                    'permission' => $identifier,
                    'scope_type' => (string) $scope,
                ];
            }
        }

        return $violations;
    }

    /**
     * 저장된 역할-권한 부여 중 스코프가 붙었지만 강제 수단이 없는 것을 찾습니다.
     *
     * @return array<int, array{role: string, permission: string, scope_type: string}> 위반 목록
     */
    public function findUnenforcedScopedGrants(): array
    {
        return $this->permissionRepository->getScopedGrantsWithoutOwnerMetadata(self::queryScopedPermissions());
    }

    /**
     * 비회원(guest) 역할에 스코프(self·role)가 붙은 부여를 찾습니다.
     *
     * 비회원은 스코프를 가질 수 없다(7.1.1) — 업그레이드 스텝이 회수하지만, 그 뒤 직접 DB 를 고치거나
     * 확장 동기화가 되살린 행을 코어 업데이트·시드 때 경고로 드러낸다.
     *
     * @return array<int, array{role: string, permission: string, scope_type: string}> 부여 목록
     */
    public function findGuestScopedGrants(): array
    {
        return $this->permissionRepository->getScopedGrantsForRole(self::GUEST_ROLE);
    }

    /**
     * 라우트의 `permission:` 미들웨어에서 권한 식별자(템플릿 그대로)를 뽑습니다.
     *
     * @param  Route  $route  라우트
     * @return array<int, string> 권한 식별자 목록
     */
    private static function routePermissions(Route $route): array
    {
        $identifiers = [];
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware) || ! str_starts_with($middleware, 'permission:')) {
                continue;
            }

            // permission:{type},{perm1|perm2}[,{requireAll}]
            $parts = explode(',', Str::after($middleware, 'permission:'));
            foreach (explode('|', $parts[1] ?? '') as $identifier) {
                $identifier = trim($identifier);
                if ($identifier !== '') {
                    $identifiers[] = $identifier;
                }
            }
        }

        return array_values(array_unique($identifiers));
    }

    /**
     * 라우트가 주어진 이름의 파라미터를 Model 로 바인딩하는지 판정합니다.
     *
     * @param  Route  $route  라우트
     * @param  string  $parameter  `resource_route_key`
     * @param  mixed  $router  라우터 (명시 바인딩 조회)
     * @return bool 바인딩 여부
     */
    private static function routeBindsModel(Route $route, string $parameter, mixed $router): bool
    {
        if (! in_array($parameter, $route->parameterNames(), true)) {
            return false;
        }

        if ($router !== null && method_exists($router, 'getBindingCallback') && $router->getBindingCallback($parameter) !== null) {
            return true;
        }

        foreach ($route->signatureParameters(['subClass' => Model::class]) as $reflectionParameter) {
            if ($reflectionParameter->getName() === $parameter) {
                return true;
            }
        }

        return false;
    }

    /**
     * 라우트가 원장 항목의 `routes` 패턴에 들어 있는지 판정합니다 (라우트 이름 우선, 이름이 없으면 URI).
     *
     * @param  Route  $route  라우트
     * @param  array{mode: string, routes: array<int, string>, paths: array<int, string>, exempt?: array<string, string>}  $entry  원장 항목
     * @return bool 등록 여부
     */
    private static function routeIsLedgered(Route $route, array $entry): bool
    {
        $name = $route->getName();

        foreach ($entry['routes'] as $pattern) {
            if (($name !== null && Str::is($pattern, $name)) || Str::is($pattern, $route->uri())) {
                return true;
            }
        }

        return false;
    }

    /**
     * 라우트가 원장 항목의 `exempt` 패턴에 들어 있는지 판정합니다 (라우트 이름 우선, 이름이 없으면 URI).
     *
     * @param  Route  $route  라우트
     * @param  array{mode: string, routes: array<int, string>, paths: array<int, string>, exempt?: array<string, string>}  $entry  원장 항목
     * @return bool 면제 여부
     */
    private static function routeIsExempt(Route $route, array $entry): bool
    {
        $name = $route->getName();

        foreach (array_keys($entry['exempt'] ?? []) as $pattern) {
            $pattern = (string) $pattern;
            if (($name !== null && Str::is($pattern, $name)) || Str::is($pattern, $route->uri())) {
                return true;
            }
        }

        return false;
    }

    /**
     * 권한 식별자 자리표시자를 치환합니다 (값이 배열이면 값마다 하나씩 펼친다).
     *
     * @param  string  $identifier  템플릿 식별자
     * @param  array<string, string|array<int, string>>  $placeholders  치환값
     * @return array<int, string> 치환된 식별자들 (치환값이 없는 자리표시자는 남는다)
     */
    private static function resolvePlaceholders(string $identifier, array $placeholders): array
    {
        $resolved = [$identifier];

        foreach ($placeholders as $name => $values) {
            $next = [];
            foreach ($resolved as $candidate) {
                if (! str_contains($candidate, '{'.$name.'}')) {
                    $next[] = $candidate;

                    continue;
                }
                foreach ((array) $values as $value) {
                    $next[] = str_replace('{'.$name.'}', (string) $value, $candidate);
                }
            }
            $resolved = $next;
        }

        return array_values(array_unique($resolved));
    }

    /**
     * 원장 키를 식별자 정규식으로 바꿉니다 (`{name}` → 점 없는 한 구간, `*` → 아무 문자열).
     *
     * @param  string  $key  원장 키
     * @return string 정규식
     */
    private static function identifierPattern(string $key): string
    {
        $pattern = preg_quote($key, '/');
        $pattern = preg_replace('/\\\\\{\w+\\\\\}/', '[^.]+', $pattern);
        $pattern = str_replace('\*', '.*', $pattern);

        return '/^'.$pattern.'$/';
    }

    /**
     * `FQCN::method` 의 소스 본문을 읽습니다.
     *
     * @param  string  $path  `FQCN::method`
     * @return string|null 본문 (클래스·메서드가 없으면 null)
     */
    private static function methodBody(string $path): ?string
    {
        if (! str_contains($path, '::')) {
            return null;
        }

        [$class, $method] = explode('::', $path, 2);

        if (! class_exists($class) && ! trait_exists($class)) {
            return null;
        }

        if (! method_exists($class, $method)) {
            return null;
        }

        $reflection = new ReflectionMethod($class, $method);
        $file = $reflection->getFileName();

        if ($file === false || ! is_readable($file)) {
            return null;
        }

        $lines = file($file);

        return implode('', array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
    }

    /**
     * 메서드 본문이 스코프를 판정하는지(토큰 또는 다른 원장 메서드 호출) 확인합니다.
     *
     * @param  string  $body  메서드 본문
     * @param  array<int, string>  $tokens  판정 토큰
     * @param  array<int, string>  $ledgerMethods  원장에 적힌 메서드 이름
     * @param  string  $self  검사 중인 메서드 이름 (자기 선언은 호출로 세지 않는다)
     * @return bool 판정 여부
     */
    private static function bodyChecksScope(string $body, array $tokens, array $ledgerMethods, string $self): bool
    {
        foreach ($tokens as $token) {
            if (str_contains($body, $token)) {
                return true;
            }
        }

        foreach ($ledgerMethods as $method) {
            if ($method !== $self && preg_match('/(->|::)'.preg_quote($method, '/').'\s*\(/', $body) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * 라우트 위반 항목을 만듭니다.
     *
     * @param  Route  $route  라우트
     * @param  string  $permission  권한 식별자(템플릿)
     * @param  string  $reason  사유
     * @return array{route: string, uri: string, permission: string, reason: string} 위반 항목
     */
    private static function violation(Route $route, string $permission, string $reason): array
    {
        return [
            'route' => (string) ($route->getName() ?? implode('|', $route->methods()).' '.$route->uri()),
            'uri' => $route->uri(),
            'permission' => $permission,
            'reason' => $reason,
        ];
    }
}
