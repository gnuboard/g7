<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Feature\Listeners;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Plugins\Sirsoft\SocialLogin\Models\SocialAccount;
use Plugins\Sirsoft\SocialLogin\Tests\PluginTestCase;

/**
 * 관리자 회원 목록 확장 — 「가입경로」 필터(`ext_filters[]=sirsoft-social_login.provider=…`)와 「소셜 연결」 열(페이지당 연결 조회
 * 1회). 회원 연결 열람 권한이 없으면 둘 다 적용하지 않는다.
 *
 * @since 1.0.0
 */
class UserListSocialTest extends PluginTestCase
{
    /** 목록 주소 */
    private const URL = '/api/admin/users';

    /**
     * 연결 회원 2명(카카오·네이버)과 연결 없는 회원 1명을 만듭니다.
     *
     * @param  string  $tag  이름 표지
     * @return array<string, User> 회원
     */
    private function seedMembers(string $tag): array
    {
        $members = [];
        foreach (['kakao', 'naver', 'none'] as $provider) {
            $members[$provider] = User::factory()->create(['name' => $tag.$provider, 'email' => $this->uniqueEmail($provider)]);
            if ($provider !== 'none') {
                SocialAccount::query()->create(['user_id' => $members[$provider]->id, 'provider_key' => $provider, 'provider_user_id' => $tag.$provider, 'linked_at' => now()]);
            }
        }

        return $members;
    }

    /**
     * 가입경로=카카오는 카카오 연결 회원만 남기고, 행에는 `social_providers` 가 붙는다(연결 없는 회원은 빈 목록). 연결 조회는 목록
     * 요청 1회당 한 번이다. 모르는 키는 빈 결과(200), 너무 긴 키는 422.
     *
     * @scenario actor=accounts_viewer, filter=kakao|unknown|too_long
     *
     * @effects provider_filter_narrows, column_rows_have_providers, column_single_query, unknown_provider_empty, invalid_provider_422
     *
     * @return void 반환값 없음
     */
    public function test_filter_and_column(): void
    {
        $this->enableKakao();
        $tag = 'ul'.substr(uniqid(), -6);
        $members = $this->seedMembers($tag);
        Sanctum::actingAs($this->makeAdminUser(['email' => $this->uniqueEmail('ulv')], ['core.users.read', 'sirsoft-social_login.accounts.view']));

        $filtered = $this->getJson(self::URL.'?filters[0][field]=name&filters[0][value]='.$tag.'&ext_filters[]=sirsoft-social_login.provider=kakao')->assertOk();
        $this->assertSame([$members['kakao']->uuid], array_column($filtered->json('data.data'), 'uuid'));

        $queries = 0;
        DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'social_accounts')) {
                $queries++;
            }
        });
        $all = collect($this->getJson(self::URL.'?filters[0][field]=name&filters[0][value]='.$tag.'&per_page=50')->assertOk()->json('data.data'))->keyBy('uuid');
        $this->assertSame(1, $queries);
        $this->assertSame('kakao', $all[$members['kakao']->uuid]['social_providers'][0]['key']);
        $this->assertSame('kakao', $all[$members['kakao']->uuid]['social_providers'][0]['brand']);
        $this->assertSame([], $all[$members['none']->uuid]['social_providers']);

        $this->getJson(self::URL.'?filters[0][field]=name&filters[0][value]='.$tag.'&ext_filters[]=sirsoft-social_login.provider=zzz')->assertOk()->assertJsonCount(0, 'data.data');
        $this->getJson(self::URL.'?ext_filters[]=sirsoft-social_login.provider='.str_repeat('a', 60))->assertStatus(422);
    }

    /**
     * 회원 연결 열람 권한이 없는 관리자에게는 필터를 적용하지 않고 열도 붙이지 않는다.
     *
     * @scenario actor=users_reader_only
     *
     * @effects no_permission_no_filter, no_permission_no_column
     *
     * @return void 반환값 없음
     */
    public function test_requires_accounts_view(): void
    {
        $tag = 'un'.substr(uniqid(), -6);
        $this->seedMembers($tag);
        Sanctum::actingAs($this->makeAdminUser(['email' => $this->uniqueEmail('uln')], ['core.users.read']));

        $rows = $this->getJson(self::URL.'?filters[0][field]=name&filters[0][value]='.$tag.'&ext_filters[]=sirsoft-social_login.provider=kakao')->assertOk()->json('data.data');
        $this->assertCount(3, $rows);
        $this->assertArrayNotHasKey('social_providers', $rows[0]);
    }
}
