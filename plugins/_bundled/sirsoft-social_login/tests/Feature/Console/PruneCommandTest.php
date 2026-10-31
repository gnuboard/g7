<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Feature\Console;

use App\Models\User;
use Plugins\Sirsoft\SocialLogin\Mock\MockGate;
use Plugins\Sirsoft\SocialLogin\Models\SocialAccount;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginAttempt;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginWebhookEvent;
use Plugins\Sirsoft\SocialLogin\Plugin;
use Plugins\Sirsoft\SocialLogin\Services\PruneService;
use Plugins\Sirsoft\SocialLogin\Tests\PluginTestCase;

/**
 * 보존 기간 정리 — 원본 프로필(기한 지남 → 비움) · 시도 기록 · 웹훅 이력(보존 밖 → 삭제) · 만료 모의 표지, `--dry-run` 은 세기만,
 * 매일 예약 선언.
 *
 * @since 1.0.0
 */
class PruneCommandTest extends PluginTestCase
{
    /**
     * 보존 30일: 오래된 시도·웹훅만 지우고 최근 것은 남긴다. 기한 지난 원본 프로필은 비우고 기한 안은 남긴다. dry-run 은 수만 세고
     * 아무것도 바꾸지 않는다.
     *
     * @scenario mode=dry_run|run
     *
     * @effects dry_run_counts_only, prune_removes_old_attempts, prune_removes_old_webhooks, prune_clears_expired_profile_raw, prune_keeps_recent
     *
     * @return void 반환값 없음
     */
    public function test_prune_targets(): void
    {
        $this->setPluginSettings(['attempt_log_retention_days' => 30, 'webhook_event_retention_days' => 30]);
        $tag = 'pr'.substr(uniqid(), -6);

        $old = SocialLoginAttempt::query()->create(['provider_key' => $tag, 'intent' => 'login', 'outcome' => 'failed', 'created_at' => now()->subDays(40)]);
        $recent = SocialLoginAttempt::query()->create(['provider_key' => $tag, 'intent' => 'login', 'outcome' => 'failed', 'created_at' => now()->subDays(5)]);
        $oldEvent = SocialLoginWebhookEvent::query()->create(['provider_key' => $tag, 'event_key' => $tag.'o', 'event_type' => 'unlink', 'payload' => [], 'status' => 'processed', 'attempts' => 1, 'received_at' => now()->subDays(40)]);
        $newEvent = SocialLoginWebhookEvent::query()->create(['provider_key' => $tag, 'event_key' => $tag.'n', 'event_type' => 'unlink', 'payload' => [], 'status' => 'processed', 'attempts' => 1, 'received_at' => now()]);
        $user = User::factory()->create(['email' => $this->uniqueEmail('pr')]);
        $expired = SocialAccount::query()->create(['user_id' => $user->id, 'provider_key' => 'kakao', 'provider_user_id' => $tag.'e', 'linked_at' => now(), 'profile_raw' => '{"a":1}', 'profile_raw_expires_at' => now()->subDay()]);
        $user2 = User::factory()->create(['email' => $this->uniqueEmail('pr2')]);
        $kept = SocialAccount::query()->create(['user_id' => $user2->id, 'provider_key' => 'kakao', 'provider_user_id' => $tag.'k', 'linked_at' => now(), 'profile_raw' => '{"a":2}', 'profile_raw_expires_at' => now()->addDays(3)]);

        $dry = app(PruneService::class)->run(true);
        $this->assertGreaterThanOrEqual(1, $dry['attempts']);
        $this->assertNotNull(SocialLoginAttempt::query()->find($old->id));
        $this->assertNotNull($expired->refresh()->profile_raw);

        $this->artisan('sirsoft-social_login:prune')->assertSuccessful();

        $this->assertNull(SocialLoginAttempt::query()->find($old->id));
        $this->assertNotNull(SocialLoginAttempt::query()->find($recent->id));
        $this->assertNull(SocialLoginWebhookEvent::query()->find($oldEvent->id));
        $this->assertNotNull(SocialLoginWebhookEvent::query()->find($newEvent->id));
        $this->assertNull($expired->refresh()->profile_raw);
        $this->assertNotNull($kept->refresh()->profile_raw);
    }

    /**
     * 만료됐는데 캐시에 남은 모의 제공자 표지는 정리 대상이고(미리 보기는 세기만), 정리하면 사라진다. 켜져 있는 표지는 건드리지 않는다.
     *
     * @scenario mode=dry_run|run
     *
     * @effects prune_clears_expired_mock_flag
     *
     * @return void 반환값 없음
     */
    public function test_prune_clears_expired_mock_flag(): void
    {
        $gate = app(MockGate::class);
        $cache = (new \ReflectionProperty(MockGate::class, 'cache'))->getValue($gate);

        $gate->enable(30);
        $this->assertSame(0, app(PruneService::class)->run(false)['mock_flag']);
        $this->assertTrue($gate->enabled());

        // 저장소가 만료를 늦게 지우는 경우 — 지난 시각이 남아 있다.
        $cache->put('mock_enabled_until', time() - 60, 3600);
        $this->assertFalse($gate->enabled());
        $this->assertSame(1, app(PruneService::class)->run(true)['mock_flag']);
        $this->assertNotNull($cache->get('mock_enabled_until'));

        $this->assertSame(1, app(PruneService::class)->run(false)['mock_flag']);
        $this->assertNull($cache->get('mock_enabled_until'));
    }

    /**
     * 정리 명령은 매일 예약 작업으로 선언된다.
     *
     * @scenario schedule=declared
     *
     * @effects prune_scheduled_daily
     *
     * @return void 반환값 없음
     */
    public function test_schedule_declared(): void
    {
        $schedules = (new Plugin)->getSchedules();

        $this->assertSame(
            [['sirsoft-social_login:prune', 'daily', '0 0 * * *']],
            array_map(fn ($s) => [$s['command'], $s['frequency'], $s['expression']], $schedules),
        );
    }
}
