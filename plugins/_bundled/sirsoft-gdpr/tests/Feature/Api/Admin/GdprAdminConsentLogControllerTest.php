<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Feature\Api\Admin;

use App\Models\User;
use Plugins\Sirsoft\Gdpr\Models\GdprUserConsentHistory;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;

/**
 * 관리자 동의 로그 API 테스트
 *
 * GET /api/plugins/sirsoft-gdpr/admin/consent-log
 */
class GdprAdminConsentLogControllerTest extends PluginTestCase
{
    public function test_index_requires_auth(): void
    {
        $this->getJson('/api/plugins/sirsoft-gdpr/admin/consent-log')
            ->assertUnauthorized();
    }

    public function test_index_returns_paginated_log_for_privacy_operator(): void
    {
        $operator = $this->createPrivacyOperatorUser();
        $subject = User::factory()->create();

        GdprUserConsentHistory::create([
            'user_id' => $subject->id,
            'consent_key' => 'cookie_analytics',
            'action' => 'granted',
            'source' => 'banner',
            'policy_version' => '1.0',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
        ]);

        $response = $this->actingAs($operator)->getJson('/api/plugins/sirsoft-gdpr/admin/consent-log');

        $response->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.consent_key', 'cookie_analytics')
            ->assertJsonPath('data.data.0.ip_address', '127.0.0.1')
            ->assertJsonPath('data.data.0.user.uuid', $subject->uuid)
            ->assertJsonPath('data.pagination.total', 1);
    }

    public function test_index_filters_by_email_partial_match(): void
    {
        $operator = $this->createPrivacyOperatorUser();
        $alice = User::factory()->create(['email' => 'alice@example.com']);
        $bob = User::factory()->create(['email' => 'bob@example.com']);

        GdprUserConsentHistory::create([
            'user_id' => $alice->id,
            'consent_key' => 'cookie_analytics',
            'action' => 'granted',
            'source' => 'banner',
        ]);

        GdprUserConsentHistory::create([
            'user_id' => $bob->id,
            'consent_key' => 'cookie_marketing',
            'action' => 'granted',
            'source' => 'banner',
        ]);

        $response = $this->actingAs($operator)
            ->getJson('/api/plugins/sirsoft-gdpr/admin/consent-log?email=alice');

        $response->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.user_id', $alice->id);
    }

    public function test_index_filters_by_actions_array(): void
    {
        $operator = $this->createPrivacyOperatorUser();
        $subject = User::factory()->create();

        GdprUserConsentHistory::create([
            'user_id' => $subject->id,
            'consent_key' => 'cookie_analytics',
            'action' => 'granted',
            'source' => 'banner',
        ]);

        GdprUserConsentHistory::create([
            'user_id' => $subject->id,
            'consent_key' => 'cookie_analytics',
            'action' => 'revoked',
            'source' => 'mypage',
        ]);

        // 단일 액션 — actions[]=revoked
        $response = $this->actingAs($operator)
            ->getJson('/api/plugins/sirsoft-gdpr/admin/consent-log?actions[]=revoked');

        $response->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.action', 'revoked');

        // 다중 액션
        $response = $this->actingAs($operator)
            ->getJson('/api/plugins/sirsoft-gdpr/admin/consent-log?actions[]=granted&actions[]=revoked');

        $response->assertOk()
            ->assertJsonCount(2, 'data.data');
    }

    public function test_index_filters_by_sources_array(): void
    {
        $operator = $this->createPrivacyOperatorUser();
        $subject = User::factory()->create();

        GdprUserConsentHistory::create([
            'user_id' => $subject->id,
            'consent_key' => 'cookie_analytics',
            'action' => 'granted',
            'source' => 'banner',
        ]);

        GdprUserConsentHistory::create([
            'user_id' => $subject->id,
            'consent_key' => 'cookie_analytics',
            'action' => 'revoked',
            'source' => 'mypage',
        ]);

        $response = $this->actingAs($operator)
            ->getJson('/api/plugins/sirsoft-gdpr/admin/consent-log?sources[]=mypage');

        $response->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.source', 'mypage');
    }

    public function test_index_filters_by_consent_keys_array(): void
    {
        $operator = $this->createPrivacyOperatorUser();
        $subject = User::factory()->create();

        GdprUserConsentHistory::create([
            'user_id' => $subject->id,
            'consent_key' => 'cookie_necessary',
            'action' => 'granted',
            'source' => 'banner',
        ]);

        GdprUserConsentHistory::create([
            'user_id' => $subject->id,
            'consent_key' => 'cookie_marketing',
            'action' => 'granted',
            'source' => 'banner',
        ]);

        $response = $this->actingAs($operator)
            ->getJson('/api/plugins/sirsoft-gdpr/admin/consent-log?consent_keys[]=cookie_marketing');

        $response->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.consent_key', 'cookie_marketing');
    }

    public function test_index_response_includes_user_uuid(): void
    {
        $operator = $this->createPrivacyOperatorUser();
        $subject = User::factory()->create();

        GdprUserConsentHistory::create([
            'user_id' => $subject->id,
            'consent_key' => 'cookie_analytics',
            'action' => 'granted',
            'source' => 'banner',
        ]);

        $this->actingAs($operator)->getJson('/api/plugins/sirsoft-gdpr/admin/consent-log')
            ->assertOk()
            ->assertJsonPath('data.data.0.user.uuid', $subject->uuid)
            ->assertJsonPath('data.data.0.user.email', $subject->email);
    }

    /**
     * 세션 ID 부분 일치 (LIKE %val%) — DataGrid 가 앞 8자만 노출하므로 prefix 검색 허용.
     * 검토 #28 — 운영자가 본 prefix 로 검색 가능하도록 일관성 확보 (email 필터와 동일).
     *
     * @return void
     */
    public function test_index_filters_by_session_id_prefix(): void
    {
        $operator = $this->createPrivacyOperatorUser();

        GdprUserConsentHistory::create([
            'session_id' => 'abcd1234-5678-9abc-def0-111111111111',
            'consent_key' => 'cookie_analytics',
            'action' => 'granted',
            'source' => 'banner',
        ]);

        GdprUserConsentHistory::create([
            'session_id' => 'zzzz9999-0000-0000-0000-222222222222',
            'consent_key' => 'cookie_marketing',
            'action' => 'granted',
            'source' => 'banner',
        ]);

        // 앞 8자 prefix 만 입력해도 매칭
        $response = $this->actingAs($operator)
            ->getJson('/api/plugins/sirsoft-gdpr/admin/consent-log?session_id=abcd1234');

        $response->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.session_id', 'abcd1234-5678-9abc-def0-111111111111');
    }

    /**
     * 세션 ID 부분 일치 — 중간 부분도 매칭 (`%val%`).
     *
     * @return void
     */
    public function test_index_filters_by_session_id_middle_substring(): void
    {
        $operator = $this->createPrivacyOperatorUser();

        GdprUserConsentHistory::create([
            'session_id' => 'aaaaaaaa-1234-5678-9abc-defdefdefdef',
            'consent_key' => 'cookie_analytics',
            'action' => 'granted',
            'source' => 'banner',
        ]);

        GdprUserConsentHistory::create([
            'session_id' => 'bbbbbbbb-0000-0000-0000-000000000000',
            'consent_key' => 'cookie_marketing',
            'action' => 'granted',
            'source' => 'banner',
        ]);

        // 중간 부분 (1234-5678) 만 입력해도 매칭
        $response = $this->actingAs($operator)
            ->getJson('/api/plugins/sirsoft-gdpr/admin/consent-log?session_id=1234-5678');

        $response->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.session_id', 'aaaaaaaa-1234-5678-9abc-defdefdefdef');
    }

    /**
     * 세션 ID 빈 문자열 — 필터 적용 안 됨 (전체 조회).
     *
     * @return void
     */
    public function test_index_returns_all_when_session_id_filter_is_empty(): void
    {
        $operator = $this->createPrivacyOperatorUser();

        GdprUserConsentHistory::create([
            'session_id' => 'session-a',
            'consent_key' => 'cookie_analytics',
            'action' => 'granted',
            'source' => 'banner',
        ]);

        GdprUserConsentHistory::create([
            'session_id' => 'session-b',
            'consent_key' => 'cookie_marketing',
            'action' => 'granted',
            'source' => 'banner',
        ]);

        $response = $this->actingAs($operator)
            ->getJson('/api/plugins/sirsoft-gdpr/admin/consent-log?session_id=');

        $response->assertOk()
            ->assertJsonCount(2, 'data.data');
    }

    public function test_index_response_includes_categories_snapshot_as_iteration_array(): void
    {
        $operator = $this->createPrivacyOperatorUser();

        GdprUserConsentHistory::create([
            'session_id' => 'session-snapshot',
            'consent_key' => 'cookie_analytics',
            'action' => 'granted',
            'source' => 'banner',
            'categories' => [
                'cookie_necessary' => true,
                'cookie_analytics' => true,
                'cookie_marketing' => false,
            ],
        ]);

        $response = $this->actingAs($operator)
            ->getJson('/api/plugins/sirsoft-gdpr/admin/consent-log');

        $response->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonCount(3, 'data.data.0.categories_snapshot')
            ->assertJsonPath('data.data.0.categories_snapshot.0.key', 'cookie_necessary')
            ->assertJsonPath('data.data.0.categories_snapshot.0.label_key', 'sirsoft-gdpr.consent.category_cookie_necessary')
            ->assertJsonPath('data.data.0.categories_snapshot.0.granted', true)
            ->assertJsonPath('data.data.0.categories_snapshot.2.key', 'cookie_marketing')
            ->assertJsonPath('data.data.0.categories_snapshot.2.granted', false);
    }

    public function test_index_response_categories_snapshot_is_null_when_categories_missing(): void
    {
        $operator = $this->createPrivacyOperatorUser();

        GdprUserConsentHistory::create([
            'session_id' => 'session-no-snapshot',
            'consent_key' => 'cookie_analytics',
            'action' => 'granted',
            'source' => 'banner',
            'categories' => null,
        ]);

        $response = $this->actingAs($operator)
            ->getJson('/api/plugins/sirsoft-gdpr/admin/consent-log');

        $response->assertOk()
            ->assertJsonPath('data.data.0.categories_snapshot', null);
    }
}
