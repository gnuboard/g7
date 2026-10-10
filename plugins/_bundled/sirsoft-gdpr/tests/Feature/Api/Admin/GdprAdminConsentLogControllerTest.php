<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Feature\Api\Admin;

use App\Models\User;
use Plugins\Sirsoft\Gdpr\Models\GdprUserConsentHistory;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;

/**
 * 관리자 동의 로그 API 테스트
 *
 * GET /api/plugins/sirsoft-gdpr/admin/consent-log
 *
 * @scenario-manifest tests/scenarios/consent-log-index.yaml
 */
class GdprAdminConsentLogControllerTest extends PluginTestCase
{
    /**
     * 인증 없이 동의 로그를 조회하면 401 로 거절된다.
     *
     * @scenario check=auth_required
     *
     * @effects consent_log_requires_authentication
     *
     * @return void 반환값 없음
     */
    public function test_index_requires_auth(): void
    {
        $this->getJson('/api/plugins/sirsoft-gdpr/admin/consent-log')
            ->assertUnauthorized();
    }

    /**
     * 개인정보 담당자는 동의 로그를 페이지 단위로 받는다(IP·회원 uuid 포함).
     *
     * @scenario check=paginated_list
     *
     * @effects consent_log_returns_paginated_rows
     *
     * @return void 반환값 없음
     */
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

    /**
     * 이메일 필터는 부분 일치로 회원을 고른다.
     *
     * @scenario check=email_filter
     *
     * @effects consent_log_filters_by_email_partial_match
     *
     * @return void 반환값 없음
     */
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

    /**
     * 액션 필터는 하나 또는 여러 값(actions[])으로 고른다.
     *
     * @scenario check=actions_filter
     *
     * @effects consent_log_filters_by_actions
     *
     * @return void 반환값 없음
     */
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

    /**
     * 출처 필터는 sources[] 값으로 고른다.
     *
     * @scenario check=sources_filter
     *
     * @effects consent_log_filters_by_sources
     *
     * @return void 반환값 없음
     */
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

    /**
     * 동의 항목 필터는 consent_keys[] 값으로 고른다.
     *
     * @scenario check=consent_keys_filter
     *
     * @effects consent_log_filters_by_consent_keys
     *
     * @return void 반환값 없음
     */
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

    /**
     * 응답 행에 대상 회원의 uuid 와 이메일이 실린다.
     *
     * @scenario check=user_identity
     *
     * @effects consent_log_rows_include_user_uuid
     *
     * @return void 반환값 없음
     */
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
     * 운영자가 본 prefix 로 검색 가능하도록 일관성 확보 (email 필터와 동일).
     *
     * @scenario check=session_id_prefix
     *
     * @effects consent_log_filters_by_session_id_substring
     *
     * @return void 반환값 없음
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
     * @scenario check=session_id_substring
     *
     * @effects consent_log_filters_by_session_id_substring
     *
     * @return void 반환값 없음
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
     * @scenario check=session_id_empty
     *
     * @effects consent_log_ignores_empty_session_id_filter
     *
     * @return void 반환값 없음
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

    /**
     * 카테고리 스냅샷이 화면이 순회할 수 있는 배열(키·라벨 키·동의 여부)로 실린다.
     *
     * @scenario check=categories_snapshot
     *
     * @effects consent_log_rows_include_categories_snapshot
     *
     * @return void 반환값 없음
     */
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

    /**
     * 카테고리 기록이 없는 행은 스냅샷이 null 이다.
     *
     * @scenario check=categories_snapshot_missing
     *
     * @effects consent_log_snapshot_null_without_categories
     *
     * @return void 반환값 없음
     */
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

    /**
     * 허용 밖 필터 값의 오류 문구가 항목 위치(actions.0)가 아니라 화면 필터 이름을 쓴다(ko).
     *
     * IndexConsentLogRequest::attributes() 가 배열 키와 와일드카드(`actions.*` 등)를 함께 덮는다.
     *
     * @scenario check=invalid_filter_label
     *
     * @effects consent_log_invalid_filter_errors_name_the_filter
     *
     * @return void 반환값 없음
     */
    public function test_invalid_filter_errors_name_the_filter_in_korean(): void
    {
        $this->assertFilterErrorsNameTheFilter('ko', [
            'consent_keys.0' => '동의 항목',
            'actions.0' => '액션',
            'sources.0' => '출처',
        ]);
    }

    /**
     * 같은 오류 문구가 영어 화면에서는 영어 필터 이름을 쓴다.
     *
     * @scenario check=invalid_filter_label
     *
     * @effects consent_log_invalid_filter_errors_name_the_filter
     *
     * @return void 반환값 없음
     */
    public function test_invalid_filter_errors_name_the_filter_in_english(): void
    {
        $this->assertFilterErrorsNameTheFilter('en', [
            'consent_keys.0' => 'Consent Key',
            'actions.0' => 'Action',
            'sources.0' => 'Source',
        ]);
    }

    /**
     * 세 필터 모두 허용 밖 값으로 조회해 422 문구의 항목 이름을 확인한다.
     *
     * @param  string  $locale  요청 언어
     * @param  array<string, string>  $expected  오류 키 => 문구에 들어갈 필터 이름
     * @return void 반환값 없음
     */
    private function assertFilterErrorsNameTheFilter(string $locale, array $expected): void
    {
        $operator = $this->createPrivacyOperatorUser();
        // 코어 SetLocale 미들웨어의 1순위는 인증 사용자의 언어다
        $operator->forceFill(['language' => $locale])->save();

        $query = http_build_query([
            'consent_keys' => [str_repeat('k', 101)],
            'actions' => ['not-an-action'],
            'sources' => ['not-a-source'],
        ]);

        $response = $this->actingAs($operator->fresh())
            ->withHeaders(['Accept-Language' => $locale])
            ->getJson('/api/plugins/sirsoft-gdpr/admin/consent-log?'.$query);

        $response->assertStatus(422);
        $errors = $response->json('errors');

        foreach ($expected as $key => $label) {
            $this->assertArrayHasKey($key, $errors, "{$locale}: {$key} 오류가 있어야 한다");
            $message = $errors[$key][0];
            $this->assertStringContainsString($label, $message, "{$locale}: {$key} 문구의 필터 이름");
            // 항목 위치가 그대로 실리면 안 된다(Laravel 기본 풀이: 「actions.0」 → 「actions.0」/「actions 0」)
            $field = strtok($key, '.');
            $this->assertStringNotContainsString($key, $message, "{$locale}: {$key} 위치가 문구에 남으면 안 된다");
            $this->assertStringNotContainsString($field.' 0', $message, "{$locale}: {$key} 위치가 문구에 남으면 안 된다");
            $this->assertStringNotContainsString('이(가)', $message, "{$locale}: 괄호 조사가 남으면 안 된다");
        }
    }
}
