<?php

namespace App\Console\Commands;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Playwright E2E 가 남긴 격리용 계정·역할을 회수하는 커맨드.
 *
 * `playwright:issue-token` 은 병렬 실행이 서로의 권한 집합을 밟지 않도록 호출마다
 * `playwright_test_{uniqid}` 역할과 신규 유저를 **새로** 만든다. 격리는 의도된 설계지만
 * 회수 수단이 없어 발급분이 영구 누적됐다 — g7.dev 실측에서 활성 역할 2,264개 중 2,242개가
 * 이 잔여물이었고, `/api/admin/roles/active` 가 1.7MB · 8초를 쓰게 되어 그 목록을 당기는
 * 관리자 화면(게시판 폼 등) 진입이 함께 느려졌다.
 *
 * 회수 대상은 **이 커맨드가 만든 것만**이다:
 *   - `playwright_test_` 로 시작하는 역할
 *   - 그 역할이 붙어 있고, 그것 말고는 `admin` 역할밖에 없는 유저 (+ 그 유저의 토큰)
 *   - 예전 발급 커맨드가 DB 에 없던 권한 식별자로 토큰을 받을 때 `firstOrCreate` 로 만들어 남긴 권한 행
 *     (지금 발급 커맨드는 없는 식별자를 거절해 새로 만들지 않는다) —
 *     이름·설명이 식별자 그대로(ko·en), 코어 소유, 트리 부모·자식 없음, 코어 정의 권한이 아님,
 *     회수하는 테스트 역할 말고는 붙은 역할이 없음. 게시판이 생기기 전(또는 지워진 뒤) 그 게시판
 *     권한으로 토큰을 받으면 이런 행이 남아 관리자 권한 트리에 식별자 그대로 보였다(7.1.0 O-D2).
 *     사람이 읽는 이름을 가진 정당한 권한, 사람 역할이 쓰는 권한은 남긴다.
 * 사람이 쓰는 계정에 `playwright_test_*` 가 붙어 있으면 그 유저는 건너뛴다.
 *
 * 보안 가드는 발급 커맨드와 같다 (CLI 한정 + `G7_PLAYWRIGHT_BYPASS=1` 옵트인).
 *
 * 호출 예시 (PowerShell):
 *   $env:G7_PLAYWRIGHT_BYPASS='1'; php artisan playwright:cleanup --dry-run
 *   $env:G7_PLAYWRIGHT_BYPASS='1'; php artisan playwright:cleanup --keep-hours=2
 */
class PlaywrightCleanup extends Command
{
    protected $signature = 'playwright:cleanup
        {--keep-hours=0 : 최근 N시간 안에 만들어진 발급분은 남긴다 (진행 중인 실행 보호)}
        {--dry-run : 삭제하지 않고 대상만 센다}';

    protected $description = 'Playwright E2E 가 남긴 격리 역할·계정·권한 행 회수 (CLI + G7_PLAYWRIGHT_BYPASS 가드)';

    /** 발급 커맨드가 붙이는 역할 식별자 접두어 */
    private const ROLE_PREFIX = 'playwright_test_';

    /**
     * 발급 커맨드가 남긴 격리 역할·계정·권한 행을 회수합니다.
     *
     * @return int 종료 코드 (가드 거부 시 FAILURE)
     */
    public function handle(): int
    {
        // 발급 커맨드와 동일한 가드 — 회수도 개발/CI 환경에서만 돈다
        if (php_sapi_name() !== 'cli') {
            $this->error('CLI 전용 커맨드입니다. (현재 SAPI: '.php_sapi_name().')');

            return self::FAILURE;
        }

        if (env('G7_PLAYWRIGHT_BYPASS') !== '1') {
            $this->error('G7_PLAYWRIGHT_BYPASS=1 환경변수가 필요합니다. (예: PowerShell — $env:G7_PLAYWRIGHT_BYPASS=\'1\')');

            return self::FAILURE;
        }

        Config::set('app.debug', true);

        $keepHours = (int) $this->option('keep-hours');
        $dryRun = (bool) $this->option('dry-run');

        $roles = Role::query()
            ->where('identifier', 'like', self::ROLE_PREFIX.'%')
            ->when($keepHours > 0, fn ($q) => $q->where('created_at', '<', now()->subHours($keepHours)))
            ->get();

        $roleIds = $roles->pluck('id')->all();
        $permissionIds = $this->reclaimablePermissionIds($roleIds, $keepHours);

        if ($roles->isEmpty() && $permissionIds === []) {
            $this->info('회수할 Playwright 잔여물이 없습니다.');

            return self::SUCCESS;
        }

        $userIds = $this->reclaimableUserIds($roleIds);

        $this->line('역할 '.$roles->count().'개 / 계정 '.count($userIds).'개 / 권한 '.count($permissionIds).'개가 회수 대상입니다.');

        if ($dryRun) {
            $this->comment('--dry-run 이므로 삭제하지 않았습니다.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($roleIds, $userIds, $permissionIds) {
            if ($userIds !== []) {
                // 토큰을 먼저 지운다 — 유저가 사라진 뒤 남으면 고아 행이 된다
                PersonalAccessToken::where('tokenable_type', User::class)
                    ->whereIn('tokenable_id', $userIds)
                    ->delete();

                DB::table('user_roles')->whereIn('user_id', $userIds)->delete();
                // User·Role 모두 SoftDeletes 를 쓰지 않으므로 delete() 가 곧 물리 삭제다
                User::whereIn('id', $userIds)->delete();
            }

            if ($roleIds !== []) {
                DB::table('user_roles')->whereIn('role_id', $roleIds)->delete();
                DB::table('role_permissions')->whereIn('role_id', $roleIds)->delete();
                DB::table('role_menus')->whereIn('role_id', $roleIds)->delete();
                Role::whereIn('id', $roleIds)->delete();
            }

            if ($permissionIds !== []) {
                // 외래키 CASCADE 에 기대지 않고 참조 행을 먼저 지운다(테스트 역할의 연결은 위에서 이미 끊겼다)
                DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
                DB::table('permission_hooks')->whereIn('permission_id', $permissionIds)->delete();
                Permission::whereIn('id', $permissionIds)->delete();
            }
        });

        $this->info('회수 완료 — 역할 '.count($roleIds).'개, 계정 '.count($userIds).'개, 권한 '.count($permissionIds).'개.');

        return self::SUCCESS;
    }

    /**
     * 회수해도 되는 계정 id 를 고릅니다.
     *
     * 대상 역할이 붙어 있으면서, 그 밖에는 발급 커맨드가 함께 부여하는 `admin` 역할만 가진
     * 계정만 고릅니다. 사람이 쓰는 계정에 테스트 역할이 섞여 있으면 건너뜁니다.
     *
     * @param  array<int>  $roleIds  회수 대상 역할 id 배열
     * @return array<int> 회수 가능한 계정 id 배열
     */
    private function reclaimableUserIds(array $roleIds): array
    {
        if ($roleIds === []) {
            return [];
        }

        $userRoles = (new UserRole)->getTable();
        $roles = (new Role)->getTable();

        $candidateIds = DB::table($userRoles)
            ->whereIn('role_id', $roleIds)
            ->distinct()
            ->pluck('user_id')
            ->all();

        if ($candidateIds === []) {
            return [];
        }

        // 후보별 보유 역할 식별자를 모아, 테스트 역할과 admin 외의 것이 섞였는지 본다
        $held = DB::table($userRoles)
            ->join($roles, "{$roles}.id", '=', "{$userRoles}.role_id")
            ->whereIn("{$userRoles}.user_id", $candidateIds)
            ->select("{$userRoles}.user_id", "{$roles}.identifier")
            ->get()
            ->groupBy('user_id');

        $reclaimable = [];

        foreach ($held as $userId => $rows) {
            $others = $rows
                ->pluck('identifier')
                ->reject(fn ($identifier) => str_starts_with((string) $identifier, self::ROLE_PREFIX)
                    || $identifier === 'admin');

            if ($others->isEmpty()) {
                $reclaimable[] = (int) $userId;
            }
        }

        return $reclaimable;
    }

    /**
     * 발급 커맨드가 만든 권한 행 중 회수해도 되는 것의 id 를 고릅니다.
     *
     * 예전 발급 커맨드(`PlaywrightIssueToken::makeAdminUser`)는 DB 에 없는 식별자를 받으면 이름·설명을 식별자
     * 그대로(ko·en) 넣고 코어 소유·부모 없음으로 권한 행을 만들었다(지금은 발급 때 거절한다). 그렇게 남은
     * 모양이면서 아래를 모두 만족하는 것만
     * 고른다 — 하나라도 어긋나면 정당한 권한일 수 있으므로 남긴다.
     *   - 코어가 정의한 권한 식별자가 아니다(`config('core.permissions')`)
     *   - 자식 권한이 없다(트리의 부모 노드가 아니다)
     *   - 이번에 회수하는 테스트 역할 말고는 붙은 역할이 없다
     *   - `--keep-hours` 안에 만들어지지 않았다
     *
     * @param  array<int>  $reclaimRoleIds  이번에 회수하는 테스트 역할 id 배열
     * @param  int  $keepHours  최근 N시간 안에 만든 행은 남긴다 (0 이면 시간 제한 없음)
     * @return array<int> 회수 가능한 권한 id 배열
     */
    private function reclaimablePermissionIds(array $reclaimRoleIds, int $keepHours): array
    {
        $coreDefined = $this->coreDefinedPermissionIdentifiers();

        $candidates = Permission::query()
            ->whereNull('parent_id')
            ->where('extension_identifier', 'core')
            ->when($keepHours > 0, fn ($q) => $q->where('created_at', '<', now()->subHours($keepHours)))
            ->get(['id', 'identifier', 'name', 'description'])
            ->filter(function (Permission $permission) use ($coreDefined): bool {
                $identifier = (string) $permission->identifier;
                $name = (array) $permission->name;
                $description = (array) $permission->description;

                return ! isset($coreDefined[$identifier])
                    && ($name['ko'] ?? null) === $identifier
                    && ($name['en'] ?? null) === $identifier
                    && ($description['ko'] ?? $identifier) === $identifier
                    && ($description['en'] ?? $identifier) === $identifier;
            });

        if ($candidates->isEmpty()) {
            return [];
        }

        $ids = $candidates->pluck('id')->all();

        $withChildren = Permission::query()->whereIn('parent_id', $ids)->distinct()->pluck('parent_id')->all();

        $usedElsewhere = DB::table('role_permissions')
            ->whereIn('permission_id', $ids)
            ->when($reclaimRoleIds !== [], fn ($q) => $q->whereNotIn('role_id', $reclaimRoleIds))
            ->distinct()
            ->pluck('permission_id')
            ->all();

        return array_values(array_map('intval', array_diff($ids, $withChildren, $usedElsewhere)));
    }

    /**
     * 코어 설정이 정의한 권한·카테고리·모듈 식별자 집합을 만듭니다.
     *
     * @return array<string, true> 식별자 집합
     */
    private function coreDefinedPermissionIdentifiers(): array
    {
        $config = (array) config('core.permissions', []);
        $identifiers = [];

        if (isset($config['module']['identifier'])) {
            $identifiers[(string) $config['module']['identifier']] = true;
        }

        foreach ((array) ($config['categories'] ?? []) as $category) {
            if (isset($category['identifier'])) {
                $identifiers[(string) $category['identifier']] = true;
            }

            foreach ((array) ($category['permissions'] ?? []) as $permission) {
                if (isset($permission['identifier'])) {
                    $identifiers[(string) $permission['identifier']] = true;
                }
            }
        }

        return $identifiers;
    }
}
