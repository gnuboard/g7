<?php

namespace Tests\Concerns;

use App\Enums\ExtensionOwnerType;
use App\Enums\UserStatus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;

/**
 * 비밀번호 정책(#736) 테스트 공용 픽스처
 *
 * 보안 설정은 config(`g7_settings.core.security.*`)로 테스트마다 명시한다 — 사이트 저장값이 새지 않도록
 * `TestCase` 가 기본값(전부 꺼짐)으로 고정해 두었고, 정책을 검증하는 테스트가 필요한 키만 켠다.
 */
trait PasswordPolicyFixtures
{
    /**
     * 보안 설정 값을 덮어씁니다.
     *
     * @param  array<string, mixed>  $values  security 카테고리 키 => 값
     * @return void 반환값 없음
     */
    protected function setSecurity(array $values): void
    {
        foreach ($values as $key => $value) {
            Config::set("g7_settings.core.security.{$key}", $value);
        }
    }

    /**
     * 비밀번호 만료를 켭니다 (기본: 90일·안내 7일·전체·변경해야 로그인·시작일 200일 전).
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 키
     * @return void 반환값 없음
     */
    protected function enableExpiry(array $overrides = []): void
    {
        $this->setSecurity(array_merge([
            'password_expiry_enabled' => true,
            'password_expiry_days' => 90,
            'password_expiry_notice_days' => 7,
            'password_expiry_scope' => 'all',
            'password_expiry_action' => 'force',
            'password_expiry_remind_days' => 30,
            'password_expiry_batch_size' => 1000,
            'password_expiry_started_at' => now()->subDays(200)->toIso8601String(),
        ], $overrides));
    }

    /**
     * 일반 회원을 만듭니다 ('user' 역할 포함).
     *
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @param  string  $password  비밀번호 평문
     * @return User 회원
     */
    protected function makeMember(array $attributes = [], string $password = 'Passw0rd!old'): User
    {
        // 변경일 기본값은 지금이다 — 비워 두면 기준일이 시작일(테스트 기본 200일 전)이 되어 픽스처 관리자·조회자까지
        // 만료 대상에 섞인다. 기능 도입 전 회원(NULL)을 재려면 호출자가 명시한다.
        $user = User::factory()->create(array_merge([
            'password' => Hash::make($password),
            'status' => UserStatus::Active->value,
            'password_changed_at' => now(),
        ], $attributes));

        $role = Role::firstOrCreate(['identifier' => 'user'], [
            'name' => ['ko' => '회원', 'en' => 'User'],
            'description' => ['ko' => '회원', 'en' => 'User'],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'is_active' => true,
        ]);
        $user->roles()->syncWithoutDetaching([$role->id => ['assigned_at' => now(), 'assigned_by' => null]]);

        return $user->fresh();
    }

    /**
     * 기본 관리자 역할(모든 관리자 권한)을 가진 관리자를 만듭니다.
     *
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @param  string  $password  비밀번호 평문
     * @return User 관리자
     */
    protected function makeAdmin(array $attributes = [], string $password = 'Passw0rd!old'): User
    {
        $this->seed(RolePermissionSeeder::class);

        $user = $this->makeMember($attributes, $password);
        $role = Role::where('identifier', 'admin')->firstOrFail();
        $user->roles()->syncWithoutDetaching([$role->id => ['assigned_at' => now(), 'assigned_by' => null]]);
        $user->flushPermissionCaches();

        return $user->fresh();
    }

    /**
     * 지정한 관리자 권한만 가진 관리자를 만듭니다 (권한 분기 검증용).
     *
     * @param  array<int, string>  $permissions  권한 식별자 목록
     * @param  array<string, string>  $scopes  권한 식별자 => scope_type (부분 범위)
     * @return User 관리자
     */
    protected function makeLimitedAdmin(array $permissions, array $scopes = []): User
    {
        $user = $this->makeMember();

        $permissionIds = collect($permissions)->map(fn (string $identifier) => Permission::firstOrCreate(
            ['identifier' => $identifier],
            [
                'name' => ['ko' => $identifier, 'en' => $identifier],
                'description' => ['ko' => $identifier, 'en' => $identifier],
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'type' => 'admin',
            ],
        )->id)->all();

        $role = Role::create([
            'identifier' => 'pw_policy_test_'.uniqid(),
            'name' => ['ko' => '시험 관리자', 'en' => 'Test admin'],
            'description' => ['ko' => '시험 관리자', 'en' => 'Test admin'],
            'is_active' => true,
        ]);
        $role->permissions()->sync($permissionIds);

        foreach ($scopes as $identifier => $scope) {
            $role->permissions()->updateExistingPivot(Permission::where('identifier', $identifier)->value('id'), ['scope_type' => $scope]);
        }

        $user->roles()->syncWithoutDetaching([$role->id => ['assigned_at' => now(), 'assigned_by' => null]]);
        $user->flushPermissionCaches();

        return $user->fresh();
    }

    /**
     * 실제 저장된 로그인 토큰을 발급합니다 (현재 기기 생존 판정용 — `Sanctum::actingAs` 는 id 가 없다).
     *
     * @param  User  $user  회원
     * @param  string  $name  토큰 이름
     * @return string 평문 토큰
     */
    protected function issueToken(User $user, string $name = 'test-device'): string
    {
        return $user->createToken($name)->plainTextToken;
    }
}
