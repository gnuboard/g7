<?php

namespace Tests\Feature\Role;

use App\Enums\ExtensionOwnerType;
use App\Enums\PasswordExpiryPolicy;
use App\Extension\Helpers\ExtensionRoleSyncHelper;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 확장 역할 동기화가 운영자의 비밀번호 만료 설정을 덮지 않는지 테스트 (#736 §4.1)
 *
 * `password_expiry_policy` 는 운영자가 역할 수정 화면에서 고르는 값이고 확장 선언에는 없다. 코어·확장
 * 업데이트의 `syncRole()` 이 이름·설명을 갱신할 때 이 값을 기본값으로 되돌리면 운영자 설정이 사라진다.
 */
class RoleSyncPreservesExpiryPolicyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 동기화 뒤에도 역할의 만료 설정이 그대로다.
     *
     * @scenario surface=role_sync
     *
     * @effects role_sync_keeps_expiry_policy
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function extension_role_sync_keeps_the_expiry_policy(): void
    {
        $role = Role::create([
            'identifier' => 'pw_sync_role',
            'name' => ['ko' => '동기화 역할', 'en' => 'Sync role'],
            'description' => ['ko' => '설명', 'en' => 'Description'],
            'extension_type' => ExtensionOwnerType::Module,
            'extension_identifier' => 'sirsoft-sample',
            'is_active' => true,
            'password_expiry_policy' => 'apply',
        ]);

        app(ExtensionRoleSyncHelper::class)->syncRole(
            'pw_sync_role',
            ['ko' => '새 설명', 'en' => 'New description'],
            ['ko' => '새 이름', 'en' => 'New name'],
            ExtensionOwnerType::Module,
            'sirsoft-sample',
        );

        $this->assertSame(PasswordExpiryPolicy::Apply, $role->fresh()->password_expiry_policy);
    }
}
