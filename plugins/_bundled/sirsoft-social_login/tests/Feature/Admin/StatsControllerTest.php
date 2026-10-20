<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Feature\Admin;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Plugins\Sirsoft\SocialLogin\Models\SocialAccount;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginAttempt;
use Plugins\Sirsoft\SocialLogin\Tests\PluginTestCase;

/**
 * 통계 API — 기간(7·30·90) 안의 제공자별 가입·로그인·연결·실패와 지금의 연결 수·소셜 전용 회원. 관리자 연결 테스트는 세지 않는다.
 *
 * @since 1.0.0
 */
class StatsControllerTest extends PluginTestCase
{
    /** API 주소 */
    private const URL = '/api/plugins/sirsoft-social_login/admin/stats';

    /**
     * 기간 안 시도만 세고 test 의도는 뺀다. 7일과 30일 값이 기간에 맞게 갈린다. 기간 밖 값은 422.
     *
     * @scenario days=7|30|invalid, intent=login|test
     *
     * @effects stats_counts_by_outcome, stats_excludes_test_intent, stats_period_filter, stats_invalid_days_refused, stats_linked_and_social_only
     *
     * @return void 반환값 없음
     */
    public function test_period_counts(): void
    {
        $this->enableKakao();
        Sanctum::actingAs($this->makeAdminUser(['email' => $this->uniqueEmail('stats')]));
        $key = 'stat'.substr(uniqid(), -6);

        $rows = [
            ['signup', 'login', now()->subDays(2)],
            ['handoff', 'login', now()->subDays(2)],
            ['handoff', 'login', now()->subDays(20)],
            ['failed', 'login', now()->subDays(1)],
            ['link', 'link', now()->subDays(1)],
            ['failed', 'test', now()->subDays(1)],
        ];
        foreach ($rows as [$outcome, $intent, $at]) {
            SocialLoginAttempt::query()->create(['provider_key' => $key, 'intent' => $intent, 'outcome' => $outcome, 'created_at' => $at]);
        }
        $only = User::factory()->passwordless()->create(['email' => $this->uniqueEmail('only')]);
        SocialAccount::query()->create(['user_id' => $only->id, 'provider_key' => $key, 'provider_user_id' => 's1', 'linked_at' => now()]);

        $week = collect($this->getJson(self::URL.'?days=7')->assertOk()->json('data.rows'))->firstWhere('provider_key', $key);
        $this->assertSame(['signups' => 1, 'logins' => 1, 'links' => 1, 'failures' => 1, 'linked_accounts' => 1, 'social_only_users' => 1],
            array_intersect_key($week, array_flip(['signups', 'logins', 'links', 'failures', 'linked_accounts', 'social_only_users'])));

        $month = collect($this->getJson(self::URL)->assertOk()->json('data.rows'))->firstWhere('provider_key', $key);
        $this->assertSame(2, $month['logins']);
        $this->assertSame(30, $this->getJson(self::URL)->json('data.days'));

        $this->getJson(self::URL.'?days=14')->assertStatus(422);
    }
}
