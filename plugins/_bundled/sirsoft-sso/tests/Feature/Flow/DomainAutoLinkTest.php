<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Flow;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;

/**
 * 소유 도메인 자동 연결(D6 ①) — IdP 마다 기본 꺼짐, 켜면 소유 도메인 + OIDC 이메일 검증일 때만.
 *
 * @since 1.0.0
 */
class DomainAutoLinkTest extends PluginTestCase
{
    /**
     * 소유 도메인 자동 연결은 기본 꺼짐이라 연결 확인으로 가는지 확인한다.
     *
     * @scenario domain_auto=off(default)
     *
     * @effects goes_to_link_verification
     *
     * @return void 반환값 없음
     */
    public function test_default_off_requires_verification(): void
    {
        $idp = $this->mockOidcIdp();
        $user = User::factory()->create(['email' => $this->uniqueEmail('corp798.test')]);

        $response = $this->roundTrip($idp, 'full', ['sub' => 'da-1', 'email' => $user->email]);

        $this->assertArrayHasKey('sso_link', $this->redirectQuery($response));
        $this->assertDatabaseMissing('sso_accounts', ['subject' => 'da-1']);
    }

    /**
     * 자동 연결을 켜면 소유 도메인 회원을 연결하고 인계하는지 확인한다.
     *
     * @scenario domain_auto=on, email_verified=true
     *
     * @effects linked_via_domain, handoff
     *
     * @return void 반환값 없음
     */
    public function test_on_links_owned_domain_member(): void
    {
        $idp = $this->mockOidcIdp(['link_methods' => ['domain_auto' => true, 'verify' => true, 'admin' => true]]);
        $user = User::factory()->create(['email' => $this->uniqueEmail('corp798.test')]);

        $response = $this->roundTrip($idp, 'full', ['sub' => 'da-2', 'email' => $user->email]);

        $this->assertDatabaseHas('sso_accounts', ['subject' => 'da-2', 'user_id' => $user->id, 'linked_via' => 'domain']);
        $this->assertSame($user->id, $this->redeemHandoff($response)['user']->id);
    }

    /**
     * 회원 이메일을 대소문자 섞어 저장했어도(드라이버는 IdP 이메일을 소문자로 바꾼다) 같은 회원으로 찾는다 — 플러그인의 회원 이메일 조회는
     * 모두 `LOWER(email) = ?` 로 해서 운영 DB 가 대소문자를 구분하는 collation 이어도 다른 회원으로 갈리지 않는다(§7 G-1).
     *
     * @scenario domain_auto=on, member_email=mixed_case
     *
     * @effects linked_via_domain, email_lookup_case_insensitive
     *
     * @return void 반환값 없음
     */
    public function test_mixed_case_member_email_is_matched_case_insensitively(): void
    {
        $idp = $this->mockOidcIdp(['link_methods' => ['domain_auto' => true, 'verify' => true, 'admin' => true]]);
        $user = User::factory()->create(['email' => 'Hong.Mixed'.random_int(1000, 9999).'@Corp798.test']);

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $this->roundTrip($idp, 'full', ['sub' => 'da-case', 'email' => mb_strtolower($user->email)]);

        $this->assertDatabaseHas('sso_accounts', ['subject' => 'da-case', 'user_id' => $user->id, 'linked_via' => 'domain']);
        $userEmailLookups = array_values(array_filter($queries, static fn (string $sql) => preg_match('/from [`"]?(\w+_)?users[`"]?\s/i', $sql) === 1 && stripos($sql, 'email') !== false));
        $this->assertNotSame([], $userEmailLookups, '회원 이메일 조회가 한 번은 있어야 한다');
        foreach ($userEmailLookups as $sql) {
            $this->assertStringContainsStringIgnoringCase('lower(email)', $sql, '회원 이메일 조회는 대소문자 무시: '.$sql);
        }
    }

    /**
     * 이메일 미검증이나 소유 도메인 밖 신원은 자동 연결하지 않는지 확인한다.
     *
     * @scenario domain_auto=on, email_verified=false|foreign_domain
     *
     * @effects not_auto_linked
     *
     * @return void 반환값 없음
     */
    public function test_on_but_unverified_or_foreign_is_not_auto_linked(): void
    {
        $idp = $this->mockOidcIdp(['link_methods' => ['domain_auto' => true, 'verify' => true, 'admin' => true]]);

        $unverified = User::factory()->create(['email' => $this->uniqueEmail('corp798.test')]);
        $response = $this->roundTrip($idp, 'email_unverified', ['sub' => 'da-3', 'email' => $unverified->email]);
        $this->assertArrayHasKey('sso_link', $this->redirectQuery($response), 'OIDC 는 email_verified=true 일 때만 자동');

        $foreign = User::factory()->create(['email' => $this->uniqueEmail('partner.example')]);
        $this->switchBrowser();
        $response = $this->roundTrip($idp, 'full', ['sub' => 'da-4', 'email' => $foreign->email]);
        $this->assertArrayHasKey('sso_link', $this->redirectQuery($response), '소유 도메인 밖은 자동 연결하지 않는다');

        $this->assertDatabaseMissing('sso_accounts', ['subject' => 'da-3']);
        $this->assertDatabaseMissing('sso_accounts', ['subject' => 'da-4']);
    }
}
