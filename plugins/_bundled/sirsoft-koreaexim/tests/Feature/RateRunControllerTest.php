<?php

namespace Plugins\Sirsoft\Koreaexim\Tests\Feature;

use App\Extension\HookListenerRegistrar;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Plugins\Sirsoft\Koreaexim\Listeners\ValidatePluginSettingsListener;
use Plugins\Sirsoft\Koreaexim\Tests\PluginTestCase;

class RateRunControllerTest extends PluginTestCase
{
    private const PREFIX = '/api/plugins/sirsoft-koreaexim/admin';

    /**
     * 자동 관리 중 코어 설정 API로 인증키를 지울 수 없는지 검증합니다.
     *
     * @effects empty_key_rejected_after_http_normalization, stored_key_preserved
     *
     * @return void 반환값 없음
     */
    public function test_auto_management_rejects_clearing_key_through_core_settings_api(): void
    {
        HookListenerRegistrar::register(ValidatePluginSettingsListener::class);
        $user = $this->createAdminUser(['core.plugins.read', 'core.plugins.update', 'sirsoft-koreaexim.exchange_rate.manage']);
        $this->actingAs($user);
        foreach (['', null, '   '] as $key) {
            $this->putJson('/api/admin/plugins/sirsoft-koreaexim/settings', ['auto_manage' => true, 'auth_key' => $key])->assertUnprocessable();
            $this->assertSame('fixture-key', plugin_setting('sirsoft-koreaexim', 'auth_key'));
        }
        $this->putJson('/api/admin/plugins/sirsoft-koreaexim/settings', ['auth_key' => ''])->assertUnprocessable();
        $this->putJson('/api/admin/plugins/sirsoft-koreaexim/settings', ['retry_max' => 2])->assertOk();
        $this->putJson('/api/admin/plugins/sirsoft-koreaexim/settings', ['auth_key' => '••••••••'])->assertOk();
        $this->assertSame('fixture-key', plugin_setting('sirsoft-koreaexim', 'auth_key'));
    }

    /**
     * 비회원과 무권한 사용자의 이력 조회를 거부하는지 검증합니다.
     *
     * @effects guest_and_unprivileged_user_cannot_read_runs
     *
     * @return void 반환값 없음
     */
    public function test_guest_and_unprivileged_user_cannot_read_runs(): void
    {
        $this->getJson(self::PREFIX.'/runs')->assertUnauthorized();
        $this->actingAs($this->createAdminUser())->getJson(self::PREFIX.'/runs')->assertForbidden();
        Http::assertNothingSent();
    }

    /**
     * 조회 권한으로 상태와 이력만 읽고 갱신은 실행할 수 없는지 검증합니다.
     *
     * @effects view_permission_reads_status_and_history_but_cannot_run
     *
     * @return void 반환값 없음
     */
    public function test_view_permission_reads_status_and_history_but_cannot_run(): void
    {
        $user = $this->createAdminUser(['sirsoft-koreaexim.exchange_rate.view']);
        $this->actingAs($user)->getJson(self::PREFIX.'/status')->assertOk()->assertJsonPath('data.abilities.can_manage', false);
        $this->getJson(self::PREFIX.'/runs')->assertOk()->assertJsonPath('data.pagination.total', 0);
        $this->postJson(self::PREFIX.'/runs', ['dry_run' => true])->assertForbidden();
        Http::assertNothingSent();
    }

    /**
     * 관리자가 연결 테스트를 실행하고 통화 상세를 조회할 수 있는지 검증합니다.
     *
     * @effects manager_can_test_connection_and_fetch_currency_details
     *
     * @return void 반환값 없음
     */
    public function test_manager_can_test_connection_and_fetch_currency_details(): void
    {
        Http::fake(['*' => Http::response($this->quotes())]);
        $user = $this->createAdminUser(['sirsoft-koreaexim.exchange_rate.view', 'sirsoft-koreaexim.exchange_rate.manage']);
        $response = $this->actingAs($user)->postJson(self::PREFIX.'/runs', ['dry_run' => true])->assertOk()->assertJsonPath('data.status', 'dry_run');
        $this->getJson(self::PREFIX.'/runs/'.$response->json('data.id'))->assertOk()->assertJsonCount(5, 'data.items')->assertJsonMissingPath('data.raw_response');
        $this->postJson(self::PREFIX.'/runs', ['dry_run' => true])->assertStatus(429);
        Http::assertSentCount(1);
        // 연결 테스트는 자기 예산만 쓴다 — 같은 카운터를 공유하면 키를 확인한 직후
        // 「지금 갱신」이 60초 동안 429 로 막힌다(지적 2026-09-14).
        $this->assertTrue(RateLimiter::tooManyAttempts('koreaexim:test:cooldown', 1));
        $this->assertFalse(RateLimiter::tooManyAttempts('koreaexim:manual:cooldown', 1));
    }

    /**
     * 잘못된 필터와 비활성 상태의 실제 갱신을 거부하는지 검증합니다.
     *
     * @effects invalid_filters_and_disabled_real_update_are_rejected
     *
     * @return void 반환값 없음
     */
    public function test_invalid_filters_and_disabled_real_update_are_rejected(): void
    {
        $user = $this->createAdminUser(['sirsoft-koreaexim.exchange_rate.view', 'sirsoft-koreaexim.exchange_rate.manage']);
        $this->actingAs($user)->getJson(self::PREFIX.'/runs?status=invalid')->assertUnprocessable();
        $this->getJson(self::PREFIX.'/runs?date_to=2026-09-14')->assertOk();
        $this->getJson(self::PREFIX.'/runs/999999999')->assertNotFound();
        $this->configure(['auto_manage' => false]);
        $this->postJson(self::PREFIX.'/runs', ['dry_run' => false])->assertUnprocessable();
        Http::assertNothingSent();
    }

    /**
     * 뒤집힌 기간 거절 문구가 필드 원문 이름(date to·date from) 대신 화면의 칸 이름을 쓰는지 검증합니다.
     *
     * 화면이 뒤집힌 기간을 조회하던 때 이 문구가 「date to 필드는 date from 이후…」로 토스트에 떴다(#703 브라우저 실측).
     *
     * @effects inverted_date_range_rejection_names_fields
     *
     * @return void 반환값 없음
     */
    public function test_inverted_date_range_rejection_names_fields(): void
    {
        $user = $this->createAdminUser(['sirsoft-koreaexim.exchange_rate.view']);
        $message = $this->actingAs($user)
            ->getJson(self::PREFIX.'/runs?date_from=2026-09-15&date_to=2026-09-10')
            ->assertUnprocessable()
            ->json('errors.date_to.0');

        $this->assertStringContainsString(__('sirsoft-koreaexim::validation.attributes.date_to'), $message);
        $this->assertStringContainsString(__('sirsoft-koreaexim::validation.attributes.date_from'), $message);
        $this->assertStringNotContainsString('date to', $message);
    }

    /**
     * 코어 설정 경로에서도 플러그인의 관리 권한을 요구하는지 검증합니다.
     *
     * @effects core_plugin_settings_cannot_bypass_own_manage_permission
     *
     * @return void 반환값 없음
     */
    public function test_core_plugin_settings_cannot_bypass_own_manage_permission(): void
    {
        $user = $this->createAdminUser(['core.plugins.read', 'core.plugins.update', 'sirsoft-koreaexim.exchange_rate.view']);
        $this->actingAs($user)->putJson('/api/admin/plugins/sirsoft-koreaexim/settings', ['auto_manage' => false])->assertForbidden();
        $this->assertTrue((bool) plugin_setting('sirsoft-koreaexim', 'auto_manage'));
    }

    /**
     * 상태 응답이 인증키 원문 대신 설정 여부만 노출하는지 검증합니다.
     *
     * @effects stored_key_preserved
     *
     * @return void 반환값 없음
     */
    public function test_status_exposes_key_presence_without_leaking_the_key(): void
    {
        $this->actingAs($this->createAdminUser(['sirsoft-koreaexim.exchange_rate.view']));
        $response = $this->getJson(self::PREFIX.'/status')->assertOk();
        // 마스크 문자열도 truthy 라 화면은 불리언으로 판정해야 합니다.
        $this->assertTrue($response->json('data.auth_key_set'));
        $this->assertSame('fixture-key', plugin_setting('sirsoft-koreaexim', 'auth_key'));
        $this->assertStringNotContainsString('fixture-key', $response->getContent());
        $this->assertSame(1000, $response->json('data.quota_limit'));
    }
}
