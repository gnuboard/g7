<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
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
 * 권한(`permissions`) 행은 `firstOrCreate` 로 **공용**을 재사용하므로 건드리지 않는다.
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

    protected $description = 'Playwright E2E 가 남긴 격리 역할·계정 회수 (CLI + G7_PLAYWRIGHT_BYPASS 가드)';

    /** 발급 커맨드가 붙이는 역할 식별자 접두어 */
    private const ROLE_PREFIX = 'playwright_test_';

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

        if ($roles->isEmpty()) {
            $this->info('회수할 Playwright 잔여물이 없습니다.');

            return self::SUCCESS;
        }

        $userIds = $this->reclaimableUserIds($roles->pluck('id')->all());

        $this->line('역할 '.$roles->count().'개 / 계정 '.count($userIds).'개가 회수 대상입니다.');

        if ($dryRun) {
            $this->comment('--dry-run 이므로 삭제하지 않았습니다.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($roles, $userIds) {
            if ($userIds !== []) {
                // 토큰을 먼저 지운다 — 유저가 사라진 뒤 남으면 고아 행이 된다
                PersonalAccessToken::where('tokenable_type', User::class)
                    ->whereIn('tokenable_id', $userIds)
                    ->delete();

                DB::table('user_roles')->whereIn('user_id', $userIds)->delete();
                // User·Role 모두 SoftDeletes 를 쓰지 않으므로 delete() 가 곧 물리 삭제다
                User::whereIn('id', $userIds)->delete();
            }

            $roleIds = $roles->pluck('id')->all();
            DB::table('user_roles')->whereIn('role_id', $roleIds)->delete();
            DB::table('role_permissions')->whereIn('role_id', $roleIds)->delete();
            DB::table('role_menus')->whereIn('role_id', $roleIds)->delete();
            Role::whereIn('id', $roleIds)->delete();
        });

        $this->info('회수 완료 — 역할 '.$roles->count().'개, 계정 '.count($userIds).'개.');

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

        $candidateIds = DB::table('user_roles')
            ->whereIn('role_id', $roleIds)
            ->distinct()
            ->pluck('user_id')
            ->all();

        if ($candidateIds === []) {
            return [];
        }

        // 후보별 보유 역할 식별자를 모아, 테스트 역할과 admin 외의 것이 섞였는지 본다
        $held = DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->whereIn('user_roles.user_id', $candidateIds)
            ->select('user_roles.user_id', 'roles.identifier')
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
}
