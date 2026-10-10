<?php

namespace Tests\Feature\Settings;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\FakesConsentProviders;
use Tests\TestCase;

/**
 * 환경설정 개인정보 탭 — 동의 관리 제공자 선택 (#639, 결정 P4·P5·D9)
 *
 * 선택값은 `privacy.consent_provider` 하나다. 빈 문자열은 「자동」이고, 전역 `ConvertEmptyStringsToNull`
 * 이 null 로 바꾸므로 요청 정규화가 되돌린다. 고른 제공자가 비활성화된(missing) 상태에서 다른 칸만 고쳐
 * 저장해도 막히지 않아야 한다 — 화면은 옛 id 를 그대로 다시 보낸다.
 *
 * DB 는 관리자 계정·역할만 쓰므로 트랜잭션으로 격리한다.
 */
class ConsentProviderSettingTest extends TestCase
{
    use DatabaseTransactions;
    use FakesConsentProviders;

    /** 관리자 토큰 */
    private string $token;

    /**
     * 제공자 등록을 비우고 설정 권한 관리자 토큰을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->resetConsentProviders();
        $this->token = $this->createAdminUser()->createToken('consent-provider-test')->plainTextToken;
    }

    /**
     * 설정 읽기·저장 권한을 가진 관리자를 만듭니다.
     *
     * @return User 생성된 관리자
     */
    private function createAdminUser(): User
    {
        $user = User::factory()->create(['email' => 'consent-provider-'.Str::lower(Str::random(10)).'@example.com']);

        $permissionIds = [];
        foreach (['core.settings.read', 'core.settings.update'] as $identifier) {
            $permissionIds[] = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'description' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ]
            )->id;
        }

        $role = Role::create([
            'identifier' => 'consent_provider_test_'.Str::lower(Str::random(8)),
            'name' => json_encode(['ko' => '동의 제공자 테스트', 'en' => 'Consent provider test']),
            'description' => json_encode(['ko' => '테스트 전용', 'en' => 'Test only']),
            'is_active' => true,
        ]);
        $role->permissions()->sync($permissionIds);

        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'description' => json_encode(['ko' => '시스템 관리자', 'en' => 'System Administrator']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );

        $user->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }

    /**
     * 개인정보 탭 저장 요청을 보냅니다 (필수 기간 칸은 기본값으로 채운다).
     *
     * @param  mixed  $consentProvider  제출할 선택값
     * @return TestResponse 응답
     */
    private function savePrivacy(mixed $consentProvider): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/admin/settings', [
                '_tab' => 'privacy',
                'privacy' => [
                    'consent_provider' => $consentProvider,
                    'withdrawn_retention_enabled' => false,
                    'withdrawn_retention_days' => 365,
                    'dormant_enabled' => false,
                    'dormant_days' => 730,
                    'dormant_notice_days' => 30,
                    'dormant_withdraw_enabled' => false,
                    'dormant_withdraw_days' => 365,
                ],
            ]);
    }

    /**
     * 저장소에 실린 선택값을 읽습니다.
     *
     * @return mixed 저장된 값
     */
    private function storedSelection(): mixed
    {
        return app(ConfigRepositoryInterface::class)->getCategory('privacy')['consent_provider'] ?? 'absent';
    }

    /**
     * 「자동」(빈 값)은 null 이 아니라 빈 문자열로 저장된다.
     *
     * @scenario selection=auto, visitor=guest, status=ready
     *
     * @effects empty_selection_is_stored_as_empty_string
     *
     * @return void 반환값 없음
     */
    public function test_empty_selection_is_stored_as_empty_string(): void
    {
        $this->savePrivacy('')->assertOk();

        $this->assertSame('', $this->storedSelection());
    }

    /**
     * 등록된 제공자 id 는 저장되고, 수집을 꺼 둔 제공자도 고를 수 있다.
     *
     * @scenario selection=selected, visitor=guest, status=ready
     *
     * @effects registered_provider_can_be_selected
     *
     * @return void 반환값 없음
     */
    public function test_registered_provider_can_be_selected(): void
    {
        $this->fakeConsentProvider('alpha', available: false);

        $this->savePrivacy('alpha')->assertOk();

        $this->assertSame('alpha', $this->storedSelection());
    }

    /**
     * 등록되지 않은 id 는 422 와 필드 오류로 막는다.
     *
     * @scenario selection=missing, visitor=guest, status=unknown
     *
     * @effects unknown_provider_selection_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_unknown_provider_is_rejected(): void
    {
        $this->savePrivacy('nobody')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['privacy.consent_provider']);
    }

    /**
     * 고른 제공자가 사라진(missing) 상태에서 옛 id 를 그대로 다시 보내도 저장된다.
     *
     * @scenario selection=missing, visitor=guest, status=unknown
     *
     * @effects missing_selection_resend_does_not_block_save
     *
     * @return void 반환값 없음
     */
    public function test_resending_the_stored_missing_id_is_allowed(): void
    {
        $this->fakeConsentProvider('alpha');
        $this->savePrivacy('alpha')->assertOk();

        $this->resetConsentProviders();
        config(['g7_settings.core.privacy.consent_provider' => 'alpha']);

        $this->savePrivacy('alpha')->assertOk();
    }

    /**
     * 설정 조회 응답은 등록된 제공자 목록과 선택 상태를 함께 싣는다.
     *
     * @scenario selection=ambiguous, visitor=guest, status=unknown
     *
     * @effects settings_meta_lists_providers_and_selection
     *
     * @return void 반환값 없음
     */
    public function test_settings_meta_lists_providers_and_selection(): void
    {
        $this->fakeConsentProvider('alpha');
        $this->fakeConsentProvider('beta');

        $meta = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/admin/settings')
            ->assertOk()
            ->json('data._meta');

        $this->assertSame([['id' => 'alpha', 'label' => 'Fake alpha'], ['id' => 'beta', 'label' => 'Fake beta']], $meta['consent_providers']);
        $this->assertSame('ambiguous', $meta['consent_selection']['state']);
    }
}
