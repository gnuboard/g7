<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Helpers\PermissionHelper;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 회원 검색 응답이 **자기 키워드를 싣고 오는지** 검증합니다. (T2-D1 · #599 3차 실측)
 *
 * 관리자 마일리지 수동 지급 모달의 회원 자동완성은 글자마다 요청을 낸다. 응답은 도착 순서가
 * 보장되지 않아 **가장 큰 옛 응답이 가장 늦게** 오고, 화면은 그때 입력칸을 다시 읽어 그 목록에
 * 현재 키워드를 도장했다 — 그래서 `MEAS599T-S2` 를 친 화면에 `M` 한 글자가 부른 **전체 회원
 * 83행**이 떴다(2026-09-22 실측: 요청은 `keyword=MEAS599T-S2` 로 나가 200·1건을 받았는데,
 * 드롭다운 1행은 `관리자 | <관리자 이메일>` 이었다).
 *
 * 화면이 요청 시점의 값을 되읽을 방법은 없다 — `<success>` 안의 `$event.target.value` 는
 * **응답이 도착한 순간의 DOM** 을 읽는다. 그래서 응답이 스스로 「이 목록은 이 키워드의 것」을
 * 말하게 한다. 화면은 그 값과 지금 입력값을 견주어, 다른 것이면 그리지 않는다.
 *
 * 엉뚱한 회원에게 돈이 지급되는 것을 막는 자리다.
 */
class UserSearchEchoesKeywordTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $token;

    /**
     * 검색 권한을 가진 운영자와 토큰을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->clearPermissionCache();
        $this->admin = $this->createUserSearchAdmin();
        $this->token = $this->admin->createToken('test-token')->plainTextToken;
    }

    /**
     * `PermissionHelper` 의 정적 캐시를 비웁니다.
     *
     * 테스트마다 권한을 새로 심으므로, 캐시가 남아 있으면 앞 테스트의 판정이 이어진다.
     *
     * @return void 반환값 없음
     */
    private function clearPermissionCache(): void
    {
        $reflection = new \ReflectionClass(PermissionHelper::class);
        $prop = $reflection->getProperty('permissionCache');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    /**
     * 회원 조회 권한(`core.users.read`)을 가진 운영자를 만듭니다.
     *
     * @return User 운영자
     */
    private function createUserSearchAdmin(): User
    {
        $user = User::factory()->create();

        $permission = Permission::firstOrCreate(
            ['identifier' => 'core.users.read'],
            [
                'name' => json_encode(['ko' => 'core.users.read', 'en' => 'core.users.read']),
                'description' => json_encode(['ko' => '회원 조회 권한', 'en' => 'Read users']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'type' => 'admin',
            ],
        );

        $role = Role::factory()->create();
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user->fresh();
    }

    /**
     * 검색 응답이 그 목록을 만든 키워드를 함께 싣는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario lookup=narrow_keyword
     *
     * @effects user_search_response_carries_its_own_keyword
     */
    public function test_search_response_carries_the_keyword_that_produced_it(): void
    {
        User::factory()->create(['name' => 'MEAS599T-S2', 'email' => 'meas599t-s2@example.test']);

        $this->withToken($this->token)
            ->getJson('/api/admin/users/search?keyword=MEAS599T-S2')
            ->assertOk()
            ->assertJsonPath('meta.keyword', 'MEAS599T-S2')
            ->assertJsonCount(1, 'data');
    }

    /**
     * 대조군 — 한 글자 검색의 응답도 **그 한 글자**를 싣는다.
     *
     * 화면이 두 응답을 구분하는 근거가 이 값이다. 두 응답이 같은 값을 실으면 늦게 온 쪽이
     * 이겨도 화면이 알아채지 못한다.
     *
     * @return void 반환값 없음
     *
     * @scenario lookup=broad_keyword
     *
     * @effects user_search_broad_response_carries_its_own_keyword
     */
    public function test_a_broader_search_carries_its_own_keyword(): void
    {
        User::factory()->create(['name' => 'MEAS599T-S2', 'email' => 'meas599t-s2@example.test']);
        User::factory()->create(['name' => 'Minsu', 'email' => 'minsu@example.test']);

        $response = $this->withToken($this->token)
            ->getJson('/api/admin/users/search?keyword=M')
            ->assertOk()
            ->assertJsonPath('meta.keyword', 'M');

        $this->assertGreaterThanOrEqual(
            2,
            count($response->json('data')),
            '한 글자 검색이 여러 건을 내야 이 축이 성립한다 — 결과가 1건이면 넓은 응답을 재는 것이 아니다',
        );
    }

    /**
     * 키워드의 LIKE 와일드카드(`%`·`_`)는 글자 그대로 찾는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario lookup=literal_wildcards
     *
     * @effects user_search_keyword_wildcards_are_literal
     */
    public function test_like_wildcards_in_keyword_match_literally(): void
    {
        $percent = User::factory()->create(['name' => 'qa-pct%1', 'nickname' => null, 'email' => 'qa-pct-literal@example.test']);
        User::factory()->create(['name' => 'qa-pct91', 'nickname' => null, 'email' => 'qa-pct-wild@example.test']);
        $under = User::factory()->create(['name' => 'qa-wild_a', 'nickname' => null, 'email' => 'qa-under-literal@example.test']);
        User::factory()->create(['name' => 'qa-wildXa', 'nickname' => null, 'email' => 'qa-under-wild@example.test']);

        // 회원 목록 검색(core 55)과 같이 자동완성 검색도 `%`·`_` 를 글자 그대로 찾는다 — 그대로 두면 `%` 한 글자가
        // 범위 안 전체 회원을 돌려준다(#736 영역 C 실측: keyword=% → 167명 전부).
        $pct = $this->withToken($this->token)->getJson('/api/admin/users/search?keyword='.urlencode('pct%1'))->assertOk();
        $this->assertSame([$percent->uuid], collect($pct->json('data'))->pluck('uuid')->all());

        $us = $this->withToken($this->token)->getJson('/api/admin/users/search?keyword='.urlencode('wild_a'))->assertOk();
        $this->assertSame([$under->uuid], collect($us->json('data'))->pluck('uuid')->all());
    }

    /**
     * uuid 로 찾은 응답은 키워드가 없으므로 `null` 을 싣는지 검증합니다.
     *
     * 화면은 그 경우 키워드 비교를 하지 않는다 — 빈 문자열로 내려보내면 「검색어가 비었을 때」와
     * 구분되지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario lookup=by_uuid
     *
     * @effects user_search_uuid_lookup_carries_a_null_keyword
     */
    public function test_a_uuid_lookup_carries_a_null_keyword(): void
    {
        $user = User::factory()->create();

        $this->withToken($this->token)
            ->getJson('/api/admin/users/search?uuid='.$user->uuid)
            ->assertOk()
            ->assertJsonPath('meta.keyword', null);
    }
}
