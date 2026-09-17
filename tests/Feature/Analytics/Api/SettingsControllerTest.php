<?php

namespace Tests\Feature\Analytics\Api;

use App\Extension\HookManager;

/**
 * 설정 API — 무엇이 저장되고 무엇이 거절되는가.
 *
 * 설정은 전용 API 와 코어 설정 API 두 길로 저장된다. 규칙을 한쪽에만 두면 다른 길로
 * 들어온 값이 검증 없이 저장되고, 그 값이 수집·정리·집계를 조용히 망가뜨린다.
 *
 * @scenario endpoint=settings, role=update
 */
class SettingsControllerTest extends AnalyticsApiTestCase
{
    /**
     * 보낸 키만 바뀌고 나머지는 남는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=settings, role=update
     *
     * @effects settings_save_only_the_keys_that_were_sent
     */
    public function test_only_the_sent_keys_change(): void
    {
        $response = $this->actingAs($this->updateAdmin())
            ->putJson('/api/admin/analytics/settings', ['retention_days' => 45]);

        $response->assertOk();
        $this->assertSame(45, $response->json('data.settings.retention_days'));
        $this->assertSame(
            90,
            $response->json('data.settings.hourly_retention_days'),
            '설정 화면이 탭 하나만 저장해도 나머지가 지워지면 안 된다',
        );
    }

    /**
     * 범위 밖 값이 거절되는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=settings, role=update
     *
     * @effects settings_reject_values_outside_their_range
     */
    public function test_values_outside_their_range_are_rejected(): void
    {
        $admin = $this->updateAdmin();

        // 보존일 하한 아래 — 허용하면 정리 배치가 오늘 방문까지 지운다.
        $this->actingAs($admin)
            ->putJson('/api/admin/analytics/settings', ['retention_days' => 3])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->putJson('/api/admin/analytics/settings', ['ip_mask_octets' => 9])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->putJson('/api/admin/analytics/settings', ['privacy_mode' => 'whatever'])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->putJson('/api/admin/analytics/settings', ['archive_chunk_size' => 10])
            ->assertStatus(422);
    }

    /**
     * 개인정보 수준이 강해지면 소급 모달을 제안하는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=settings, role=update
     *
     * @effects stricter_privacy_offers_retroactive_anonymization
     */
    public function test_stricter_privacy_offers_retroactive_anonymization(): void
    {
        config(['g7_settings.core.analytics.privacy_mode' => 'full']);
        $this->visit();

        $response = $this->actingAs($this->updateAdmin())
            ->putJson('/api/admin/analytics/settings', ['privacy_mode' => 'anonymous']);

        $response->assertOk();
        $this->assertTrue($response->json('data.meta.retro_anonymize_available'));
        $this->assertSame(1, $response->json('data.meta.ledger_rows.visits'), '소급이 몇 건에 적용되는지 함께 보인다');
    }

    /**
     * 개인정보 수준이 약해지면 소급을 제안하지 않는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=settings, role=update
     *
     * @effects weaker_privacy_does_not_offer_it
     */
    public function test_weaker_privacy_does_not_offer_it(): void
    {
        config(['g7_settings.core.analytics.privacy_mode' => 'anonymous']);

        $response = $this->actingAs($this->updateAdmin())
            ->putJson('/api/admin/analytics/settings', ['privacy_mode' => 'full']);

        $response->assertOk();
        $this->assertFalse(
            $response->json('data.meta.retro_anonymize_available'),
            '과거 기록은 이미 그보다 약한 정보를 담고 있어 소급할 것이 없다',
        );
    }

    /**
     * 저장이 훅을 발화하는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=settings, role=update
     *
     * @effects settings_save_only_the_keys_that_were_sent
     */
    public function test_saving_fires_the_settings_and_mode_hooks(): void
    {
        config(['g7_settings.core.analytics.privacy_mode' => 'full']);

        $updated = [];
        $modeChanged = [];

        HookManager::addAction('core.analytics.settings_updated', function ($payload) use (&$updated): void {
            $updated[] = $payload;
        });
        HookManager::addAction('core.analytics.privacy_mode_changed', function ($payload) use (&$modeChanged): void {
            $modeChanged[] = $payload;
        });

        $this->actingAs($this->updateAdmin())
            ->putJson('/api/admin/analytics/settings', ['privacy_mode' => 'consent'])
            ->assertOk();

        $this->assertCount(1, $updated);
        $this->assertCount(1, $modeChanged);
        $this->assertSame('full', $modeChanged[0]['from']);
        $this->assertSame('consent', $modeChanged[0]['to']);
    }

    /**
     * 코어 설정 경로로 들어와도 같은 규칙으로 검증되는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=settings, role=update
     *
     * @effects the_core_settings_route_validates_analytics_the_same_way
     */
    public function test_the_core_settings_route_validates_analytics_the_same_way(): void
    {
        $admin = $this->adminWith(['core.settings.update', 'core.settings.read']);

        $this->actingAs($admin)
            ->postJson('/api/admin/settings', [
                '_tab' => 'analytics',
                'analytics' => ['retention_days' => 3],
            ])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->postJson('/api/admin/settings', [
                '_tab' => 'analytics',
                'analytics' => ['retention_days' => 45],
            ])
            ->assertOk();
    }
}
