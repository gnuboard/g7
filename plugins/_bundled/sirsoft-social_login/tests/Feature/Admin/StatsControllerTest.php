<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Support\Carbon;
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

    /**
     * 기간 시작은 사이트 시간대 자정이다 — 서울 사이트에서 「7일」은 7일 전 서울 00:00 부터 센다(UTC 자정이 아니다).
     * 기준 시각은 그 경계를 사이트 시간대 문자열로 보인다(보는 관리자의 시간대가 달라도 같다 — 여기서는 로스앤젤레스).
     *
     * @scenario days=7, clock=site_evening|site_after_midnight
     *
     * @effects stats_period_starts_at_site_midnight, stats_since_shown_in_site_timezone
     *
     * @return void 반환값 없음
     */
    public function test_period_starts_at_site_midnight(): void
    {
        config(['app.default_user_timezone' => 'Asia/Seoul']);
        $this->enableKakao();
        Sanctum::actingAs($this->makeAdminUser(['email' => $this->uniqueEmail('stz'), 'timezone' => 'America/Los_Angeles']));

        try {
            // 서울 2026-10-28 23:00:56 (UTC 14:00:56) — 7일 전 서울 자정은 2026-10-21 00:00 = UTC 2026-10-20 15:00.
            Carbon::setTestNow(Carbon::parse('2026-10-28 14:00:56', 'UTC'));
            $key = 'stz'.substr(uniqid(), -6);
            foreach ([
                '2026-10-20 14:59:59', // 서울 10-20 23:59:59 — 기간 밖
                '2026-10-20 15:00:00', // 서울 10-21 00:00:00 — 기간 첫 순간
                '2026-10-20 23:30:00', // 서울 10-21 08:30:00 — 기간 안(UTC 자정 경계라면 빠진다)
            ] as $utc) {
                SocialLoginAttempt::query()->create(['provider_key' => $key, 'intent' => 'login', 'outcome' => 'handoff', 'created_at' => Carbon::parse($utc, 'UTC')]);
            }

            $data = $this->getJson(self::URL.'?days=7')->assertOk()->json('data');
            $this->assertSame('2026-10-21 00:00:00', $data['since']);
            $this->assertSame(2, collect($data['rows'])->firstWhere('provider_key', $key)['logins'] ?? 0);

            // 서울 자정을 막 넘긴 2026-10-29 00:30 (UTC 10-28 15:30) — 시작은 서울 10-22 00:00 = UTC 10-21 15:00.
            Carbon::setTestNow(Carbon::parse('2026-10-28 15:30:00', 'UTC'));
            $late = 'stz'.substr(uniqid(), -6);
            foreach ([
                '2026-10-21 10:00:00', // 서울 10-21 19:00 — 기간 밖(UTC 날짜로는 시작일과 같은 날이라 섞이던 값)
                '2026-10-21 14:59:59', // 서울 10-21 23:59:59 — 기간 밖
                '2026-10-21 15:00:00', // 서울 10-22 00:00:00 — 기간 안
            ] as $utc) {
                SocialLoginAttempt::query()->create(['provider_key' => $late, 'intent' => 'login', 'outcome' => 'failed', 'created_at' => Carbon::parse($utc, 'UTC')]);
            }

            $data = $this->getJson(self::URL.'?days=7')->assertOk()->json('data');
            $this->assertSame('2026-10-22 00:00:00', $data['since']);
            $this->assertSame(1, collect($data['rows'])->firstWhere('provider_key', $late)['failures'] ?? 0);
        } finally {
            Carbon::setTestNow();
        }
    }
}
