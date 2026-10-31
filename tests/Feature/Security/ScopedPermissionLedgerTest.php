<?php

namespace Tests\Feature\Security;

use App\Extension\HookManager;
use App\Helpers\PermissionHelper;
use App\Models\Permission;
use App\Models\Role;
use App\Support\ScopedPermissionEnforcement;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * 강제 지점 원장 + 테스트 감사 (7.1.1 — D11).
 *
 * 스코프 메타(resource_route_key·owner_key)가 있는 권한을 걸면서 라우트가 그 모델을 바인딩하지 않으면
 * PermissionMiddleware Step 4 가 조용히 건너뛴다. 그런 라우트는 서비스·저장소가 다시 판정해야 하고, 그 위치를
 * 원장에 적어야 한다. 이 테스트는 (1) 코어 라우트 중 바인딩도 원장도 없는 것 (2) 원장에 적혔지만 사라졌거나 판정하지
 * 않는 메서드 (3) 번들 확장 선언 중 매니저가 읽지 않는 권한 단위 스코프 키를 찾아낸다.
 */
class ScopedPermissionLedgerTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<int, callable> 테스트가 등록한 원장 필터 */
    private array $filters = [];

    /**
     * 코어 역할·권한을 심고 권한 메타 캐시를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        PermissionHelper::clearPermissionScopeCache();
    }

    /**
     * 테스트가 등록한 필터를 걷어 냅니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ($this->filters as $filter) {
            HookManager::removeFilter('core.permission.scope_enforcement_ledger', $filter);
        }
        PermissionHelper::clearPermissionScopeCache();

        parent::tearDown();
    }

    /**
     * 원장 필터를 등록합니다 (tearDown 에서 제거).
     *
     * @param  array<string, array>  $entries  더할 원장 항목
     * @return void 반환값 없음
     */
    private function addLedger(array $entries): void
    {
        $filter = static fn (array $ledger): array => array_merge($ledger, $entries);
        $this->filters[] = $filter;
        HookManager::addFilter('core.permission.scope_enforcement_ledger', $filter);
    }

    /**
     * 스코프 메타가 있는 테스트 권한을 만듭니다.
     *
     * @param  string  $identifier  권한 식별자
     * @param  string|null  $routeKey  리소스 라우트 키
     * @param  string|null  $ownerKey  소유자 컬럼
     * @return void 반환값 없음
     */
    private function permission(string $identifier, ?string $routeKey = 'role', ?string $ownerKey = 'id'): void
    {
        Permission::create([
            'identifier' => $identifier,
            'name' => ['ko' => $identifier, 'en' => $identifier],
            'description' => ['ko' => $identifier, 'en' => $identifier],
            'type' => 'admin',
            'resource_route_key' => $routeKey,
            'owner_key' => $ownerKey,
        ]);
    }

    /**
     * 감사용 가짜 라우트를 만듭니다.
     *
     * @param  string  $uri  라우트 주소
     * @param  string  $permission  권한 미들웨어의 식별자 부분
     * @param  string  $name  라우트 이름
     * @param  callable|null  $action  액션 (기본: 인자 없는 클로저)
     * @return Route 라우트
     */
    private function route(string $uri, string $permission, string $name, ?callable $action = null): Route
    {
        return (new Route(['GET'], $uri, ['uses' => $action ?? static fn () => null]))
            ->middleware("permission:admin,{$permission}")
            ->name($name);
    }

    /**
     * 실패 메시지용으로 위반 목록을 읽기 좋게 만듭니다.
     *
     * @param  array  $violations  위반 목록
     * @return string 메시지
     */
    private function describe(array $violations): string
    {
        return '원장 감사 위반 — 라우트가 모델을 바인딩하지 않으면 강제 지점을 ScopedPermissionEnforcement::LEDGER(코어) 또는 '
            ."확장의 core.permission.scope_enforcement_ledger 리스너에 등록하세요:\n"
            .json_encode($violations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 코어 권한을 거는 모든 라우트는 모델을 바인딩하거나 원장에 등록돼 있다.
     *
     * @scenario case=core_route_audit
     *
     * @effects core_routes_have_no_unledgered_scope_gap
     *
     * @return void 반환값 없음
     */
    public function test_core_routes_have_no_unbound_unledgered_scoped_permission(): void
    {
        $violations = ScopedPermissionEnforcement::auditRoutes(
            RouteFacade::getRoutes(),
            'core.',
            ['type' => ['module', 'plugin', 'template']],
        );

        $this->assertSame([], $violations, $this->describe($violations));
    }

    /**
     * 코어 원장의 강제 메서드는 전부 존재하고 스코프를 판정한다.
     *
     * @scenario case=core_enforcer_audit
     *
     * @effects core_ledger_enforcers_exist_and_check_scope
     *
     * @return void 반환값 없음
     */
    public function test_core_ledger_enforcers_exist_and_check_scope(): void
    {
        $this->assertSame([], ScopedPermissionEnforcement::auditEnforcers('core.'));
    }

    /**
     * 번들 확장 선언(module.php·plugin.php)에 권한 단위 resource_route_key·owner_key 가 없다 — 매니저는 카테고리 단위만
     * 읽으므로 권한 단위 선언은 「강제된다」 는 착시만 준다.
     *
     * @scenario case=bundled_manifest_audit
     *
     * @effects permission_level_scope_keys_absent
     *
     * @return void 반환값 없음
     */
    public function test_bundled_manifests_have_no_permission_level_scope_keys(): void
    {
        $files = array_merge(
            glob(base_path('modules/_bundled/*/module.php')) ?: [],
            glob(base_path('plugins/_bundled/*/plugin.php')) ?: [],
        );
        $this->assertNotEmpty($files);

        $offenders = [];
        foreach ($files as $file) {
            foreach ($this->permissionLevelScopeKeys((string) file_get_contents($file)) as $action) {
                $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file).' → action '.$action;
            }
        }

        $this->assertSame([], $offenders, '권한 단위 스코프 키는 무시됩니다 — 카테고리 단위로 옮기거나 지우세요');
    }

    /**
     * 모델을 바인딩하지 않는 라우트는 위반, 원장에 올리면 통과, 모델을 바인딩하면 원장 없이 통과.
     *
     * @scenario case=mechanism_binding_vs_ledger
     *
     * @effects unbound_route_reported, ledgered_route_passes, bound_route_passes
     *
     * @return void 반환값 없음
     */
    public function test_audit_routes_flags_unbound_and_accepts_ledger_or_binding(): void
    {
        $this->permission('zz-test.items.read');
        $unbound = $this->route('zz-test/items/{id}', 'zz-test.items.read', 'zz-test.items.show-by-id');
        $bound = $this->route('zz-test/items/{role}', 'zz-test.items.read', 'zz-test.items.show', static fn (Role $role) => null);

        $this->assertSame(
            [['route' => 'zz-test.items.show-by-id', 'uri' => 'zz-test/items/{id}', 'permission' => 'zz-test.items.read', 'reason' => 'unbound_unledgered']],
            ScopedPermissionEnforcement::auditRoutes([$unbound, $bound], 'zz-test.')
        );

        $this->addLedger(['zz-test.items.read' => [
            'mode' => ScopedPermissionEnforcement::MODE_SERVICE,
            'routes' => ['zz-test.items.show-*'],
            'paths' => [ScopedPermissionEnforcement::class.'::auditRoutes'],
        ]]);

        $this->assertSame([], ScopedPermissionEnforcement::auditRoutes([$unbound, $bound], 'zz-test.'));
    }

    /**
     * 메타가 없고 원장에도 없는 권한은 스코프 대상이 아니다. 자리표시자 치환값이 없으면 위반으로 드러낸다.
     *
     * @scenario case=mechanism_placeholders
     *
     * @effects non_scoped_permission_ignored, unresolved_placeholder_reported, template_ledger_key_matches
     *
     * @return void 반환값 없음
     */
    public function test_audit_routes_handles_placeholders_and_non_scoped_permissions(): void
    {
        $this->permission('zz-test.plain.read', null, null);
        $this->permission('zz-test.alpha.posts.read');
        $plain = $this->route('zz-test/plain', 'zz-test.plain.read', 'zz-test.plain');
        $templated = $this->route('zz-test/{slug}/posts/{id}', 'zz-test.{slug}.posts.read', 'zz-test.posts.show');

        $this->assertSame([], ScopedPermissionEnforcement::auditRoutes([$plain], 'zz-test.'));
        $this->assertSame('unresolved_placeholder', ScopedPermissionEnforcement::auditRoutes([$templated], 'zz-test.')[0]['reason']);
        $this->assertSame('unbound_unledgered', ScopedPermissionEnforcement::auditRoutes([$templated], 'zz-test.', ['slug' => ['alpha']])[0]['reason']);

        // 원장 키는 템플릿 그대로 써도 치환된 식별자와 맞는다
        $this->addLedger(['zz-test.{slug}.posts.read' => [
            'mode' => ScopedPermissionEnforcement::MODE_SERVICE,
            'routes' => ['zz-test.posts.show'],
            'paths' => [ScopedPermissionEnforcement::class.'::auditRoutes'],
        ]]);
        $this->assertSame([], ScopedPermissionEnforcement::auditRoutes([$templated], 'zz-test.', ['slug' => ['alpha']]));
        $this->assertNotNull(ScopedPermissionEnforcement::ledgerEntry('zz-test.alpha.posts.read'));
        $this->assertNull(ScopedPermissionEnforcement::ledgerEntry('zz-test.alpha.beta.posts.read'));
    }

    /**
     * 강제 메서드 감사 — 사라진 메서드, 판정 흔적 없는 메서드, 잘못된 모드, 메서드 없는 서비스 모드를 드러낸다.
     * 다른 원장 메서드를 부르는 메서드(위임)는 통과한다.
     *
     * @scenario case=mechanism_enforcers
     *
     * @effects missing_enforcer_reported, enforcer_without_scope_check_reported, invalid_mode_reported, delegation_accepted
     *
     * @return void 반환값 없음
     */
    public function test_audit_enforcers_reports_stale_and_non_checking_paths(): void
    {
        $this->addLedger([
            'zz-test.gone' => ['mode' => ScopedPermissionEnforcement::MODE_SERVICE, 'routes' => [], 'paths' => [self::class.'::methodThatDoesNotExist']],
            'zz-test.blind' => ['mode' => ScopedPermissionEnforcement::MODE_QUERY, 'routes' => [], 'paths' => [self::class.'::enforcerWithoutCheck']],
            'zz-test.delegating' => ['mode' => ScopedPermissionEnforcement::MODE_SERVICE, 'routes' => [], 'paths' => [self::class.'::enforcerDelegating', self::class.'::enforcerChecking']],
            'zz-test.bad-mode' => ['mode' => 'magic', 'routes' => [], 'paths' => []],
            'zz-test.no-path' => ['mode' => ScopedPermissionEnforcement::MODE_SERVICE, 'routes' => [], 'paths' => []],
        ]);

        $reasons = [];
        foreach (ScopedPermissionEnforcement::auditEnforcers('zz-test.') as $violation) {
            $reasons[$violation['permission']] = $violation['reason'];
        }

        $this->assertSame([
            'zz-test.gone' => 'missing_enforcer',
            'zz-test.blind' => 'enforcer_without_scope_check',
            'zz-test.bad-mode' => 'invalid_mode',
            'zz-test.no-path' => 'no_enforcer_path',
        ], $reasons);
    }

    /**
     * 면제 칸 — 사유가 있는 면제 라우트는 라우트 감사를 통과하고, 사유가 빈 면제는 강제 메서드 감사가 드러낸다.
     *
     * @scenario case=mechanism_exempt
     *
     * @effects exempt_route_passes, exempt_without_reason_reported
     *
     * @return void 반환값 없음
     */
    public function test_exempt_routes_pass_and_require_reason(): void
    {
        $this->permission('zz-test.exempt.read');
        $route = $this->route('zz-test/exempt/check', 'zz-test.exempt.read', 'zz-test.exempt.check');

        $this->assertSame('unbound_unledgered', ScopedPermissionEnforcement::auditRoutes([$route], 'zz-test.')[0]['reason']);

        $this->addLedger([
            'zz-test.exempt.read' => [
                'mode' => ScopedPermissionEnforcement::MODE_QUERY,
                'routes' => [],
                'paths' => [self::class.'::enforcerChecking'],
                'exempt' => ['zz-test.exempt.check' => '존재 확인 — 범위로 좁힐 소유 대상 없음'],
            ],
            'zz-test.exempt-blank' => [
                'mode' => ScopedPermissionEnforcement::MODE_QUERY,
                'routes' => [],
                'paths' => [self::class.'::enforcerChecking'],
                'exempt' => ['zz-test.blank.*' => '  '],
            ],
        ]);

        $this->assertSame([], ScopedPermissionEnforcement::auditRoutes([$route], 'zz-test.'));
        $this->assertSame(
            [['permission' => 'zz-test.exempt-blank', 'path' => 'zz-test.blank.*', 'reason' => 'exempt_without_reason']],
            ScopedPermissionEnforcement::auditEnforcers('zz-test.exempt')
        );
    }

    /**
     * 원장의 query 항목은 기존 쿼리 스코프 목록에 합쳐진다(하위 호환).
     *
     * @scenario case=mechanism_query_compat
     *
     * @effects ledger_query_entries_join_query_scoped_list
     *
     * @return void 반환값 없음
     */
    public function test_query_scoped_permissions_include_ledger_query_entries(): void
    {
        $this->addLedger(['zz-test.counted' => ['mode' => ScopedPermissionEnforcement::MODE_QUERY, 'routes' => [], 'paths' => []]]);

        $list = ScopedPermissionEnforcement::queryScopedPermissions();

        $this->assertContains('core.notification-logs.read', $list);
        $this->assertContains('zz-test.counted', $list);
    }

    /**
     * 판정 흔적이 없는 감사용 메서드.
     *
     * @return void 반환값 없음
     */
    public static function enforcerWithoutCheck(): void {}

    /**
     * 스코프를 판정하는 감사용 메서드.
     *
     * @return bool 판정 결과
     */
    public static function enforcerChecking(): bool
    {
        return PermissionHelper::checkScopeAccess(new Role, 'zz-test.delegating');
    }

    /**
     * 다른 원장 메서드에 위임하는 감사용 메서드.
     *
     * @return bool 판정 결과
     */
    public static function enforcerDelegating(): bool
    {
        return self::enforcerChecking();
    }

    /**
     * 선언 소스에서 권한 단위(`'action' => …` 가 있는 배열)에 스코프 키가 있는 액션을 찾습니다.
     *
     * 주석은 토큰 단계에서 지운다 — 「선언하지 않는다」 는 안내 주석이 걸리지 않게.
     *
     * @param  string  $source  PHP 소스
     * @return array<int, string> 위반 액션 이름
     */
    private function permissionLevelScopeKeys(string $source): array
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        $found = [];
        preg_match_all("/'action'\s*=>\s*'([^']+)'/", $code, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as $index => [, $offset]) {
            // 이 항목을 감싸는 배열의 여는 괄호를 찾는다
            $depth = 0;
            $start = null;
            for ($i = $offset; $i >= 0; $i--) {
                $char = $code[$i];
                if ($char === ']') {
                    $depth++;
                } elseif ($char === '[') {
                    if ($depth === 0) {
                        $start = $i;
                        break;
                    }
                    $depth--;
                }
            }
            if ($start === null) {
                continue;
            }

            // 같은 깊이의 내용만 모은다(name·description 같은 안쪽 배열은 건너뛴다)
            $depth = 0;
            $own = '';
            for ($i = $start + 1, $length = strlen($code); $i < $length; $i++) {
                $char = $code[$i];
                if ($char === '[') {
                    $depth++;
                } elseif ($char === ']') {
                    if ($depth === 0) {
                        break;
                    }
                    $depth--;
                } elseif ($depth === 0) {
                    $own .= $char;
                }
            }

            if (preg_match("/'(resource_route_key|owner_key)'\s*=>/", $own) === 1) {
                $found[] = $matches[1][$index][0];
            }
        }

        return $found;
    }
}
