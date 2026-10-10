<?php

namespace App\Console\Commands;

use App\Enums\ExtensionOwnerType;
use App\Enums\ScopeType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

/**
 * Playwright E2E 용 Sanctum 토큰 발급 커맨드.
 *
 * DB 에 있는 권한 식별자(코어/모듈/플러그인·게시판별) 를 받아 권한 보유 관리자 유저를 즉시 생성하고
 * (없는 식별자가 하나라도 있으면 아무것도 만들지 않고 실패 코드로 끝난다 — 권한 행을 지어내지 않는다)
 * Sanctum 개인 액세스 토큰을 발급한다. 발급된 토큰은 stdout 마지막 줄에 출력되어
 * Playwright fixture 가 stdin 캡처로 사용한다.
 *
 * 보안 가드 (3중):
 *   ① CLI 한정 — `php_sapi_name() === 'cli'` 확인. production 웹 요청에서 절대 도달 불가
 *   ② 명시 옵트인 — `G7_PLAYWRIGHT_BYPASS=1` 환경변수 부여 필수.
 *      `.env` 영구 수정 없이 인라인 환경변수로만 활성화 가능 → 무심코 production 으로 새지 않음
 *   ③ APP_DEBUG 강제 — bypass flag 인지 후 `config('app.debug')` 를 true 로 inline override.
 *      SettingsServiceProvider 의 testing/bypass 분기가 이미 settings JSON 덮어쓰기를 건너뛰므로
 *      production + debug=false 환경에서도 토큰 발급이 가능하다
 *
 * 환경 매트릭스:
 * - local   + bypass=1 : 로컬 개발자 PC 에서 직접 spec 작성/실행
 * - testing + bypass=1 : CI / PHPUnit 환경에서 .env.testing 로 동작하는 E2E 통합 (testing 환경은 이미 testing 가드로 통과)
 * - production + bypass=1 : production DB 가 활성 호스트를 가리키는 환경(예: g7.dev) 에서 E2E
 *
 * 호출 예시 (PowerShell):
 *   $env:G7_PLAYWRIGHT_BYPASS='1'; php artisan playwright:issue-token --permissions=core.templates.layouts.edit
 *
 * 로직 출처: tests/Feature/Api/Admin/LayoutAccessCheckEndpointTest::makeAdminUser (44~87행)
 */
class PlaywrightIssueToken extends Command
{
    protected $signature = 'playwright:issue-token
        {--permissions=* : 부여할 권한 식별자 (예: core.templates.layouts.edit). 다중 지정 가능. 뒤에 :self·:role 을 붙이면 그 범위로 부여 (예: core.users.read:self)}
        {--no-admin-role : admin 역할을 부여하지 않고 지정한 권한만 가진 계정을 만든다 (권한 분기 검증용)}
        {--gc-hours=6 : 이 시간(시)보다 오래된 playwright 테스트 유저/역할을 발급 전 정리. 0 이면 정리 안 함}';

    protected $description = 'Playwright E2E 용 Sanctum 토큰 발급 (CLI + G7_PLAYWRIGHT_BYPASS 3중 가드)';

    /** 테스트 전용 역할 식별자 접두사 */
    private const TEST_ROLE_PREFIX = 'playwright_test_';

    /**
     * 3중 가드를 통과하면 요청한 권한(과 범위)을 가진 테스트 계정을 만들고 토큰을 출력합니다.
     *
     * @return int 종료 코드
     */
    public function handle(): int
    {
        // ① CLI 한정 — production 웹 요청에서 절대 도달 불가
        if (php_sapi_name() !== 'cli') {
            $this->error('CLI 전용 커맨드입니다. (현재 SAPI: '.php_sapi_name().')');

            return self::FAILURE;
        }

        // ② 명시 옵트인 — 환경변수 없이는 production 호출 실수 차단
        // 여기의 `env()` 는 config:cache 의 영향을 받지 않는다 — 이 값은 `.env` 파일이 아니라
        // 호출자가 그 자리에서 넘기는 프로세스 환경변수이고, config 로 캡처할 대상도 아니다.
        // (`.env` 유래 값을 런타임 `env()` 로 읽는 것은 금지다 — config 로 캡처해야 한다.)
        if (env('G7_PLAYWRIGHT_BYPASS') !== '1') {
            $this->error('G7_PLAYWRIGHT_BYPASS=1 환경변수가 필요합니다. (예: PowerShell — $env:G7_PLAYWRIGHT_BYPASS=\'1\')');

            return self::FAILURE;
        }

        // ③ APP_DEBUG 강제 — production + debug=false 환경에서도 sanctum 토큰 발급 + 디버그 정보 누락 방지.
        // SettingsServiceProvider::applyDebugConfig 는 bypass flag 가 있으면 settings JSON 덮어쓰기를 이미 건너뛴 상태.
        Config::set('app.debug', true);

        // 권한 식별자 검사 — 아무것도 만들거나 지우기 전에 한다. 잘못된 범위는 예외, DB 에 없는 식별자는 실패 코드.
        // 예전에는 없는 식별자를 받으면 이름·설명이 식별자 그대로인 가짜 권한 행을 만들어, 관리자 권한 트리에
        // 식별자가 그대로 보이는 행이 쌓였고 spec 은 실제 라우트가 검사하지 않는 권한을 「받았다」고 믿었다
        // (7.1.0 O-D2 · 결정 「발급 때 거절」). 권한은 그 권한을 정의한 확장의 설치·게시판 생성으로만 생긴다.
        $specs = array_map(
            fn (string $spec): array => $this->parsePermissionSpec($spec),
            $this->option('permissions') ?: []
        );
        $unknown = $this->findUnknownPermissions(array_column($specs, 0));
        if ($unknown !== []) {
            $this->error('DB 에 없는 권한 식별자라 토큰을 발급하지 않습니다: '.implode(', ', $unknown));
            $this->line('그 권한을 정의하는 확장이 설치돼 있는지, 게시판별 권한이면 게시판을 먼저 만들었는지, 식별자 철자가 맞는지 확인하세요.');

            return self::FAILURE;
        }

        // 발급 전 오래된 테스트 아티팩트 정리 — 이 커맨드는 호출마다 유저 1명 + 역할 1개를
        // 새로 만들지만 회수 주체가 없어 그대로 누적된다. 정리하지 않으면 회원/역할 관리 화면이
        // 테스트 잔재로 뒤덮인다(실측: 발급 2974회 → 유저 2974명·역할 2974개 잔존).
        // 시간 임계값을 두어 동시 실행 중인 다른 워커의 계정은 건드리지 않는다.
        $gcHours = (int) $this->option('gc-hours');
        if ($gcHours > 0) {
            $this->pruneStaleTestArtifacts($gcHours);
        }

        // --no-admin-role: 지정한 권한만 가진 계정을 만든다.
        // 기본값(플래그 없음)은 종전대로 admin 역할을 함께 부여한다 — 기존 spec 무영향.
        $user = $this->makeAdminUser($specs, ! $this->option('no-admin-role'));
        $token = $user->createToken('playwright-'.uniqid())->plainTextToken;

        $this->line($token);

        return self::SUCCESS;
    }

    /**
     * 임계 시간보다 오래된 playwright 테스트 유저/역할을 정리한다.
     *
     * 대상: `playwright_test_*` 역할과, 그 역할이 유일한 확장 역할인 유저(= 이 커맨드가
     * factory 로 만든 계정). 실제 회원과 구분되도록 반드시 역할 소유 관계로만 판별한다.
     *
     * chunkById(키셋 순회) 필수 — 콜백이 순회 대상 행을 삭제하므로 OFFSET 기반 chunk()/each()
     * 는 다음 페이지가 줄어든 결과 집합을 지나쳐 일부를 건너뛴다.
     *
     * @param  int  $hours  이 시간보다 오래된 아티팩트만 정리
     * @return void
     */
    private function pruneStaleTestArtifacts(int $hours): void
    {
        $threshold = now()->subHours($hours);
        $roles = 0;
        $users = 0;

        Role::where('identifier', 'like', self::TEST_ROLE_PREFIX.'%')
            ->where('created_at', '<', $threshold)
            ->chunkById(100, function ($chunk) use (&$roles, &$users) {
                foreach ($chunk as $role) {
                    // 이 역할을 가진 유저 중, 다른 playwright 역할이 없는 계정만 제거 대상
                    foreach ($role->users()->get() as $user) {
                        $otherTestRoles = $user->roles()
                            ->where('roles.id', '!=', $role->id)
                            ->where('roles.identifier', 'like', self::TEST_ROLE_PREFIX.'%')
                            ->count();
                        if ($otherTestRoles === 0) {
                            $user->tokens()->delete();
                            $user->roles()->detach();
                            $user->forceDelete();
                            $users++;
                        }
                    }

                    $role->permissions()->detach();
                    $role->users()->detach();
                    $role->delete();
                    $roles++;
                }
            });

        if ($roles > 0) {
            $this->info("[gc] playwright 테스트 잔재 정리: 역할 {$roles}건, 유저 {$users}건");
        }
    }

    /**
     * DB 에 없는 권한 식별자를 돌려줍니다(요청 순서 유지, 중복 제거).
     *
     * @param  array<int, string>  $identifiers  권한 식별자 목록
     * @return array<int, string> 없는 식별자 목록 (모두 있으면 빈 배열)
     */
    private function findUnknownPermissions(array $identifiers): array
    {
        $identifiers = array_values(array_unique($identifiers));
        if ($identifiers === []) {
            return [];
        }

        $existing = Permission::whereIn('identifier', $identifiers)->pluck('identifier')->all();

        return array_values(array_diff($identifiers, $existing));
    }

    /**
     * `--permissions` 값 하나를 식별자와 범위로 나눕니다.
     *
     * @param  string  $spec  `식별자` 또는 `식별자:self` · `식별자:role`
     * @return array{0: string, 1: string|null} [권한 식별자, 범위(null = 전체)]
     *
     * @throws \InvalidArgumentException 범위가 self·role 이 아닐 때
     */
    private function parsePermissionSpec(string $spec): array
    {
        if (! str_contains($spec, ':')) {
            return [$spec, null];
        }

        [$identifier, $scope] = explode(':', $spec, 2);

        if (ScopeType::tryFrom($scope) === null) {
            throw new \InvalidArgumentException("알 수 없는 권한 범위 '{$scope}' — self 또는 role 만 쓸 수 있습니다: {$spec}");
        }

        return [$identifier, $scope];
    }

    /**
     * 권한 식별자 배열로 관리자 유저를 생성하고 권한을 부여한다.
     *
     * 절차:
     * 1. User factory 로 신규 유저 생성
     * 2. 권한 식별자별로 있는 Permission 행을 찾는다 — 없는 식별자는 handle 이 미리 거절했다(행을 만들지 않는다)
     * 3. uniqid 접미사로 격리된 test role 생성 + 권한 sync
     * 4. (withAdminRole 일 때만) admin role 보장 (firstOrCreate) + 유저-역할 부여
     *
     * `withAdminRole = false` 는 **권한 분기(읽기 전용 등) 검증 전용**이다. 기본값 true 는
     * admin 역할을 함께 붙이므로, 요청한 권한만 가진 세션을 만들 수 없다 —
     * admin 역할이 사이트의 전체 권한을 보유하기 때문에 `--permissions` 로 좁혀도
     * 화면은 항상 최대 권한으로 렌더된다(실측: admin 역할 권한 263건).
     *
     * @param  array<int, array{0: string, 1: string|null}>  $specs  부여할 [권한 식별자, 범위] 목록 (parsePermissionSpec 결과, 모두 DB 에 있는 식별자)
     * @param  bool  $withAdminRole  admin 역할 동반 부여 여부
     * @return User 생성된 유저
     */
    private function makeAdminUser(array $specs, bool $withAdminRole = true): User
    {
        // 「식별자:범위」 — 범위 스코프(self·role)로 부여된 위임 관리자 세션을 만든다.
        // 범위 없이 부여하면 전체 접근이라 스코프 우회(보조 경로가 범위를 무시하는 결함)를
        // E2E 로 재현·잠글 수 없다. 범위·식별자 검사는 handle 이 아무것도 만들기 전에 끝냈다 — 유저를 먼저
        // 만들면 테스트 역할이 붙지 않은 계정이 남아 GC 가 회수하지 못한다.
        $user = User::factory()->create();

        $idsByIdentifier = Permission::whereIn('identifier', array_column($specs, 0))->pluck('id', 'identifier');

        $permissionIds = [];
        foreach ($specs as [$identifier, $scope]) {
            $permissionIds[$idsByIdentifier[$identifier]] = ['scope_type' => $scope];
        }

        $testRole = Role::create([
            'identifier' => 'playwright_test_'.uniqid(),
            'name' => ['ko' => 'Playwright 테스트 관리자', 'en' => 'Playwright Test Admin'],
            'description' => ['ko' => 'E2E 자동화 전용', 'en' => 'E2E automation only'],
            'is_active' => true,
        ]);

        if (! empty($permissionIds)) {
            $testRole->permissions()->sync($permissionIds);
        }

        if ($withAdminRole) {
            $adminRole = Role::firstOrCreate(
                ['identifier' => 'admin'],
                [
                    'name' => ['ko' => '관리자', 'en' => 'Admin'],
                    'description' => ['ko' => '시스템 관리자', 'en' => 'System Admin'],
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                    'is_active' => true,
                ]
            );

            $user->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        }

        // test role 은 항상 부여한다 — GC(pruneStaleTestArtifacts)가 이 역할로 테스트 계정을
        // 식별하므로, 빠지면 --no-admin-role 로 만든 계정이 영구 잔존한다.
        $user->roles()->attach($testRole->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }
}
