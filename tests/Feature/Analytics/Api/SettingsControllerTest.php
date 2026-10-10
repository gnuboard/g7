<?php

namespace Tests\Feature\Analytics\Api;

use App\Extension\HookManager;
use App\Http\Requests\Admin\Analytics\UpdateAnalyticsSettingsRequest;
use Illuminate\Support\Facades\Queue;

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
     * 정수 설정은 키마다 하한 아래·상한 위를 거절하고, 경계값은 받는다.
     *
     * 모집단은 저장 요청의 검증 규칙에서 파생한다 — 손으로 고른 몇 키만 재면 새 정수 설정의
     * 범위가 빠져도 아무도 모른다.
     *
     * @return void 없음
     *
     * @scenario endpoint=settings, role=update
     *
     * @effects settings_reject_values_outside_their_range
     */
    public function test_every_integer_setting_enforces_its_range(): void
    {
        $admin = $this->updateAdmin();
        $ranges = [];

        foreach ((new UpdateAnalyticsSettingsRequest)->rules() as $key => $rules) {
            if (! is_array($rules) || ! in_array('integer', $rules, true)) {
                continue;
            }

            foreach ($rules as $rule) {
                if (is_string($rule) && preg_match('/^(min|max):(\d+)$/', $rule, $m) === 1) {
                    $ranges[$key][$m[1]] = (int) $m[2];
                }
            }
        }

        $this->assertGreaterThanOrEqual(15, count($ranges), '정수 설정 모집단이 줄었다 — 규칙 파생이 깨졌는지 본다');

        foreach ($ranges as $key => $range) {
            $this->assertArrayHasKey('min', $range, $key.' 에 하한이 없다');

            $this->actingAs($admin)->putJson('/api/admin/analytics/settings', [$key => $range['min'] - 1])
                ->assertStatus(422)->assertJsonValidationErrors($key);
            $this->actingAs($admin)->putJson('/api/admin/analytics/settings', [$key => $range['min']])
                ->assertOk();

            if (isset($range['max'])) {
                $this->actingAs($admin)->putJson('/api/admin/analytics/settings', [$key => $range['max'] + 1])
                    ->assertStatus(422)->assertJsonValidationErrors($key);
            }
        }
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
     * 동의 연동으로 올렸어도 가릴 폭이 0 이면 소급을 제안하지 않는지 확인합니다.
     *
     * 브라우저 실측(#703): 제안된 소급 창이 보내는 값(가릴 폭 0·해시 아님)을 소급 요청 검증이 422 로 거부했다.
     *
     * @return void 없음
     *
     * @scenario endpoint=settings, role=update
     *
     * @effects tightening_without_retro_target_offers_nothing
     */
    public function test_consent_mode_without_mask_does_not_offer_retro(): void
    {
        config([
            'g7_settings.core.analytics.privacy_mode' => 'full',
            'g7_settings.core.analytics.ip_mask_octets' => 0,
        ]);
        $this->visit();

        $response = $this->actingAs($this->updateAdmin())
            ->putJson('/api/admin/analytics/settings', ['privacy_mode' => 'consent']);

        $response->assertOk();
        $this->assertFalse(
            $response->json('data.meta.retro_anonymize_available'),
            '소급 창이 보낼 값(가릴 폭 0·해시 아님)은 소급 요청 검증이 거부한다 — 띄우지 않는다',
        );
    }

    /**
     * 동의 연동으로 올리며 가릴 폭이 있으면 소급을 제안하고, 그 창이 보내는 값을 소급 요청이 받는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=settings, role=update
     *
     * @effects stricter_privacy_offers_retroactive_anonymization
     */
    public function test_consent_mode_with_mask_offers_retro_and_request_accepts_it(): void
    {
        config([
            'g7_settings.core.analytics.privacy_mode' => 'full',
            'g7_settings.core.analytics.ip_mask_octets' => 0,
        ]);
        $this->visit();
        Queue::fake();

        $response = $this->actingAs($this->updateAdmin())
            ->putJson('/api/admin/analytics/settings', ['privacy_mode' => 'consent', 'ip_mask_octets' => 1]);

        $response->assertOk();
        $this->assertTrue($response->json('data.meta.retro_anonymize_available'));

        // 소급 창이 보내는 값 그대로(가릴 폭 = 저장한 설정, 익명 모드가 아니면 해시 없음)
        $this->actingAs($this->updateAdmin())
            ->postJson('/api/admin/analytics/privacy/anonymize', ['confirm' => true, 'mask_octets' => 1])
            ->assertStatus(202);
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
    /**
     * 화면이 보낸 숫자·참거짓 문자열이 선언한 형으로 저장되는지 확인합니다.
     *
     * 폼 입력은 무엇이든 문자열로 온다. 그대로 저장하면 설정 파일과 응답에 "45" 가 남아
     * 설정을 읽는 쪽이 숫자로 알고 쓰던 값을 문자열로 받는다(브라우저 실측 M6-T4).
     *
     * @return void 없음
     *
     * @scenario endpoint=settings, role=update
     *
     * @effects settings_store_declared_types_not_form_strings
     */
    public function test_form_strings_are_stored_as_the_declared_types(): void
    {
        $response = $this->actingAs($this->updateAdmin())
            ->putJson('/api/admin/analytics/settings', [
                'retention_days' => '45',
                'visit_timeout_minutes' => '20',
                'enabled' => '1',
                'respect_dnt' => '0',
            ]);

        $response->assertOk();
        $this->assertSame(45, $response->json('data.settings.retention_days'));
        $this->assertSame(20, $response->json('data.settings.visit_timeout_minutes'));
        $this->assertTrue($response->json('data.settings.enabled'));
        $this->assertFalse($response->json('data.settings.respect_dnt'));

        $stored = (array) g7_core_settings('analytics', []);
        $this->assertSame(45, $stored['retention_days'], '저장된 값도 숫자여야 한다');
        $this->assertFalse($stored['respect_dnt']);
    }

    /**
     * 코어 설정 길로 온 값도 같은 형으로 저장되는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=settings, role=update
     *
     * @effects settings_store_declared_types_not_form_strings
     */
    public function test_the_core_settings_route_casts_the_same_way(): void
    {
        $admin = $this->adminWith(['core.settings.update', 'core.settings.read']);

        $this->actingAs($admin)
            ->postJson('/api/admin/settings', [
                '_tab' => 'analytics',
                'analytics' => ['retention_days' => '45', 'enabled' => '0'],
            ])
            ->assertOk();

        $stored = (array) g7_core_settings('analytics', []);
        $this->assertSame(45, $stored['retention_days']);
        $this->assertFalse($stored['enabled']);
    }

    /**
     * 코어 설정 길로 온 값도 같은 규칙으로 검증되는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=settings, role=update
     *
     * @effects settings_reject_values_outside_their_range
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

    /**
     * 설정 응답의 시각이 사이트 표준시로 나가는지 확인합니다.
     *
     * 봇 탭이 「클라우드 대역 갱신」 시각을 `| datetime` 으로 그린다. 그 파이프는 값을
     * `new Date()` 로 파싱한 뒤 **브라우저 로컬 getter** 로 찍으므로, 오프셋이 붙어 나가면
     * 보는 사람의 시간대로 다시 옮겨져 사이트 기준이 깨진다(#682 S3 라운드 7, 설계 결정
     * 「응답 경계에서 변환」). 저장값은 ISO8601 그대로 둔다 — 바꾸는 것은 나가는 길뿐이다.
     *
     * @return void 없음
     *
     * @scenario endpoint=settings, role=read
     *
     * @effects analytics_times_are_rendered_in_the_site_timezone
     */
    public function test_settings_response_carries_site_timezone_times(): void
    {
        config([
            'g7_settings.core.general.timezone' => 'Asia/Seoul',
            'g7_settings.core.analytics.cloud_ip_ranges_updated_at' => '2026-09-22T00:30:00+00:00',
        ]);

        $updatedAt = $this->actingAs($this->adminWith(['core.analytics.read', 'core.analytics.update']))
            ->getJson('/api/admin/analytics/settings')
            ->assertOk()
            ->json('data.settings.cloud_ip_ranges_updated_at');

        // 00:30 UTC = 09:30 KST.
        $this->assertSame('2026-09-22 09:30:00', $updatedAt, '오프셋이 붙으면 브라우저가 다시 옮긴다');
    }

    /**
     * 구간 경계는 순증가·중복 없음·범위 안이어야 저장되는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=settings, role=update, input=bucket_bounds
     *
     * @effects bucket_bounds_must_be_ascending
     */
    public function test_bucket_bounds_must_be_ascending_unique_and_in_range(): void
    {
        $admin = $this->updateAdmin();

        foreach (['30,10', '10,10', '0,5', '10, 30', 'a,b', '1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16', '90000'] as $bad) {
            $this->actingAs($admin)
                ->putJson('/api/admin/analytics/settings', ['duration_bucket_bounds' => $bad])
                ->assertStatus(422)
                ->assertJsonValidationErrors('duration_bucket_bounds');
        }

        $this->actingAs($admin)
            ->putJson('/api/admin/analytics/settings', ['pages_bucket_bounds' => '5,3'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pages_bucket_bounds');

        $this->actingAs($admin)
            ->putJson('/api/admin/analytics/settings', [
                'duration_bucket_bounds' => '5,20',
                'pages_bucket_bounds' => '2,5',
                'archive_on_view_enabled' => false,
                'archive_on_view_max_visits' => 2000,
            ])
            ->assertOk()
            ->assertJsonPath('data.settings.duration_bucket_bounds', '5,20')
            ->assertJsonPath('data.settings.archive_on_view_enabled', false)
            ->assertJsonPath('data.settings.archive_on_view_max_visits', 2000);
    }
}
