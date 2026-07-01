<?php

namespace App\Console\Commands;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

/**
 * Playwright E2E 용 Sanctum 토큰 발급 커맨드.
 *
 * 임의 권한 식별자(코어/모듈/플러그인) 를 받아 권한 보유 관리자 유저를 즉시 생성하고
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
        {--permissions=* : 부여할 권한 식별자 (예: core.templates.layouts.edit). 다중 지정 가능}';

    protected $description = 'Playwright E2E 용 Sanctum 토큰 발급 (CLI + G7_PLAYWRIGHT_BYPASS 3중 가드)';

    public function handle(): int
    {
        // ① CLI 한정 — production 웹 요청에서 절대 도달 불가
        if (php_sapi_name() !== 'cli') {
            $this->error('CLI 전용 커맨드입니다. (현재 SAPI: '.php_sapi_name().')');

            return self::FAILURE;
        }

        // ② 명시 옵트인 — 환경변수 없이는 production 호출 실수 차단
        if (env('G7_PLAYWRIGHT_BYPASS') !== '1') {
            $this->error('G7_PLAYWRIGHT_BYPASS=1 환경변수가 필요합니다. (예: PowerShell — $env:G7_PLAYWRIGHT_BYPASS=\'1\')');

            return self::FAILURE;
        }

        // ③ APP_DEBUG 강제 — production + debug=false 환경에서도 sanctum 토큰 발급 + 디버그 정보 누락 방지.
        // SettingsServiceProvider::applyDebugConfig 는 bypass flag 가 있으면 settings JSON 덮어쓰기를 이미 건너뛴 상태.
        Config::set('app.debug', true);

        $permissions = $this->option('permissions') ?: [];

        $user = $this->makeAdminUser($permissions);
        $token = $user->createToken('playwright-'.uniqid())->plainTextToken;

        $this->line($token);

        return self::SUCCESS;
    }

    /**
     * 권한 식별자 배열로 관리자 유저를 생성하고 권한을 부여한다.
     *
     * 절차:
     * 1. User factory 로 신규 유저 생성
     * 2. 권한 식별자별로 Permission 행 보장 (firstOrCreate)
     * 3. uniqid 접미사로 격리된 test role 생성 + 권한 sync
     * 4. admin role 보장 (firstOrCreate) + 유저-역할 부여
     */
    private function makeAdminUser(array $permissions): User
    {
        $user = User::factory()->create();

        $permissionIds = [];
        foreach ($permissions as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'description' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ]
            );
            $permissionIds[] = $permission->id;
        }

        $testRole = Role::create([
            'identifier' => 'playwright_test_'.uniqid(),
            'name' => json_encode(['ko' => 'Playwright 테스트 관리자', 'en' => 'Playwright Test Admin']),
            'description' => json_encode(['ko' => 'E2E 자동화 전용', 'en' => 'E2E automation only']),
            'is_active' => true,
        ]);

        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Admin']),
                'description' => json_encode(['ko' => '시스템 관리자', 'en' => 'System Admin']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'type' => 'admin',
                'is_active' => true,
            ]
        );

        if (! empty($permissionIds)) {
            $testRole->permissions()->sync($permissionIds);
        }

        $user->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $user->roles()->attach($testRole->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }
}
