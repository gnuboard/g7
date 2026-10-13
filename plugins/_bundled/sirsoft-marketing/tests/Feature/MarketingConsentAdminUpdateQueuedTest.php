<?php

namespace Plugins\Sirsoft\Marketing\Tests\Feature;

use App\Enums\PermissionType;
use App\Extension\HookListenerRegistrar;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Plugins\Sirsoft\Marketing\Listeners\MarketingConsentListener;
use Plugins\Sirsoft\Marketing\Models\MarketingConsent;
use Plugins\Sirsoft\Marketing\Tests\PluginTestCase;

/**
 * 회원 수정 직후 마케팅 동의가 저장돼 있고, 이력 출처가 수정한 경로대로 남는다 — 큐 워커와 무관
 *
 * 동의 저장 리스너가 큐로 가면 저장 응답 뒤 다시 연 화면에 옛 동의값이 보이고, 워커에서는 요청이 없어
 * `routeIs('api.admin.*')` 가 늘 거짓이라 관리자가 바꾼 동의도 「본인 수정(profile)」 으로 기록된다.
 */
class MarketingConsentAdminUpdateQueuedTest extends PluginTestCase
{
    /**
     * 큐를 database 로 바꾸고 동의 리스너를 운영과 같은 등록기로 등록합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database']);
        HookListenerRegistrar::register(MarketingConsentListener::class, 'sirsoft-marketing');
    }

    /**
     * 마케팅 동의를 켠 상태의 일반 회원을 만듭니다.
     *
     * @return User 회원
     */
    private function makeConsentingMember(): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['identifier' => 'user'], ['name' => ['ko' => '회원', 'en' => 'User']]);
        foreach (['core.profile.read', 'core.profile.update'] as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                ['name' => ['ko' => $identifier, 'en' => $identifier], 'type' => PermissionType::User]
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user->roles()->syncWithoutDetaching([$role->id]);

        MarketingConsent::create([
            'user_id' => $user->id,
            'consent_key' => MarketingConsent::MASTER_KEY,
            'is_consented' => true,
            'consented_at' => now()->subDay(),
            'last_source' => 'register',
        ]);

        return $user;
    }

    /**
     * 생성·수정·삭제 전 동의 액션이 sync 다. (실패-먼저)
     *
     * @scenario case=sync_contract
     *
     * @effects consent_saved_in_request
     *
     * @return void 반환값 없음
     */
    public function test_consent_actions_are_synchronous(): void
    {
        $hooks = MarketingConsentListener::getSubscribedHooks();

        foreach (['core.user.after_create', 'core.user.after_update', 'core.user.before_delete'] as $hook) {
            $this->assertTrue(($hooks[$hook]['sync'] ?? false) === true, "{$hook} 가 sync 가 아닙니다.");
        }
    }

    /**
     * 관리자가 회원의 마케팅 동의를 끄면 직후 상세 응답이 꺼짐이고 이력 출처가 관리자다. (실패-먼저)
     *
     * @scenario case=admin_update
     *
     * @effects consent_saved_in_request, source_matches_request_route
     *
     * @return void 반환값 없음
     */
    public function test_admin_update_saves_consent_with_admin_source_without_worker(): void
    {
        $admin = $this->createAdminUser(['core.users.update', 'core.users.read']);
        $member = $this->makeConsentingMember();

        $this->actingAs($admin)->putJson("/api/admin/users/{$member->uuid}", [
            'name' => $member->name,
            'email' => $member->email,
            'role_ids' => $member->roles()->pluck('roles.id')->all(),
            MarketingConsent::MASTER_KEY => false,
        ])->assertOk();

        $detail = $this->actingAs($admin)->getJson("/api/admin/users/{$member->uuid}");
        $detail->assertOk();
        $this->assertFalse($detail->json('data.'.MarketingConsent::MASTER_KEY), '관리자가 동의를 껐는데 직후 상세 응답이 옛 값입니다.');

        $this->assertSame('admin', $this->latestHistorySource($member->id), '관리자 수정이 관리자 출처로 기록되지 않았습니다.');
    }

    /**
     * 본인이 마이페이지에서 동의를 켜면 직후 프로필 응답이 켜짐이고 이력 출처가 profile 이다. (실패-먼저)
     *
     * @scenario case=profile_update
     *
     * @effects consent_saved_in_request, source_matches_request_route
     *
     * @return void 반환값 없음
     */
    public function test_profile_update_saves_consent_with_profile_source_without_worker(): void
    {
        $member = $this->makeConsentingMember();
        MarketingConsent::where('user_id', $member->id)->update(['is_consented' => false]);

        $this->actingAs($member)->putJson('/api/user/profile', [
            'name' => $member->name,
            'email' => $member->email,
            MarketingConsent::MASTER_KEY => true,
        ])->assertOk();

        $profile = $this->actingAs($member)->getJson('/api/user/profile');
        $profile->assertOk();
        $this->assertTrue($profile->json('data.'.MarketingConsent::MASTER_KEY), '본인이 동의를 켰는데 직후 프로필 응답이 옛 값입니다.');

        $this->assertSame('profile', $this->latestHistorySource($member->id));
    }

    /**
     * 회원의 마케팅 전체 동의 이력 최신 행의 출처를 읽습니다.
     *
     * @param  int  $userId  회원 ID
     * @return string|null 출처
     */
    private function latestHistorySource(int $userId): ?string
    {
        return DB::table('user_marketing_consent_histories')
            ->where('user_id', $userId)
            ->where('channel_key', MarketingConsent::MASTER_KEY)
            ->orderByDesc('id')
            ->value('source');
    }
}
