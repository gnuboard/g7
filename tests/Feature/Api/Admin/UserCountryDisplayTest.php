<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * 국가 표시 복원 회귀 테스트 (#705 D12)
 *
 * 2026-03-31 에 숨겼던 국가 항목을 회원 상세·수정 폼·마이페이지에서 되살렸다. 화면은
 * `country_name ?? country` 를 읽는데 목록 응답만 국가 이름을 싣고 상세·프로필 응답은 코드만
 * 실어, 되살린 화면에 「US」 같은 코드가 그대로 보였다(브라우저 실측). 세 응답이 모두 현지화된
 * 국가 이름을 싣는지 본다.
 */
#[Group('core')]
#[Group('user')]
class UserCountryDisplayTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 관리자 회원 상세 응답이 국가 이름을 싣는다.
     *
     * @scenario filter_axis=country, outcome=narrows
     *
     * @effects user_country_name_exposed_on_detail_and_profile
     *
     * @return void 반환값 없음
     */
    public function test_admin_detail_includes_country_name(): void
    {
        app()->setLocale('ko');
        $member = User::factory()->create(['country' => 'US', 'email' => Str::random(8).'@cd.test']);

        $this->actingAs($this->operator())
            ->getJson('/api/admin/users/'.$member->uuid)
            ->assertOk()
            ->assertJsonPath('data.country', 'US')
            ->assertJsonPath('data.country_name', __('countries.US') !== 'countries.US' ? __('countries.US') : '미국');
    }

    /**
     * 본인 프로필(/api/me) 응답이 국가 이름을 싣고, 국가가 없으면 null 이다.
     *
     * @scenario filter_axis=country, outcome=narrows
     *
     * @effects user_country_name_exposed_on_detail_and_profile
     *
     * @return void 반환값 없음
     */
    public function test_profile_includes_country_name_or_null(): void
    {
        $member = User::factory()->create(['country' => 'JP', 'email' => Str::random(8).'@cd.test']);
        $none = User::factory()->create(['country' => null, 'email' => Str::random(8).'@cd.test']);

        $withCountry = $this->actingAs($member)->getJson('/api/me')->assertOk()->json('data');
        $this->assertSame('JP', $withCountry['country']);
        $this->assertNotEmpty($withCountry['country_name']);
        $this->assertNotSame('JP', $withCountry['country_name']);

        $this->actingAs($none)->getJson('/api/me')->assertOk()->assertJsonPath('data.country_name', null);
    }

    /**
     * 본인 프로필(/api/me) 응답이 언어 이름을 싣는다 — 마이페이지 「내 정보」 가 `language_label ?? language`
     * 를 읽는데 프로필 응답만 이름이 없어 코드(`ko`·`en`)가 그대로 보였다(7.1.0 N O-N7). 언어 칸은 필수라 비어 있는 경우는 없다.
     *
     * @scenario filter_axis=language, outcome=narrows
     *
     * @effects user_language_label_exposed_on_profile
     *
     * @return void 반환값 없음
     */
    public function test_profile_includes_language_label(): void
    {
        app()->setLocale('ko');
        $en = User::factory()->create(['language' => 'en', 'email' => Str::random(8).'@cd.test']);
        $ja = User::factory()->create(['language' => 'ja', 'email' => Str::random(8).'@cd.test']);
        $this->actingAs($en)->getJson('/api/me')->assertOk()
            ->assertJsonPath('data.language', 'en')
            ->assertJsonPath('data.language_label', 'English');
        $this->actingAs($ja)->getJson('/api/me')->assertOk()
            ->assertJsonPath('data.language_label', '日本語');
    }

    /**
     * 회원 조회 권한을 가진 운영자를 만듭니다.
     *
     * @return User 운영자
     */
    private function operator(): User
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => ['ko' => 'cd', 'en' => 'cd'], 'identifier' => 'cd-'.$user->id, 'is_active' => true]);
        $permission = Permission::firstOrCreate(
            ['identifier' => 'core.users.read'],
            ['name' => ['ko' => 'core.users.read', 'en' => 'core.users.read'], 'type' => 'admin', 'order' => 1]
        );
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }
}
