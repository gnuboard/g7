<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Feature\Api\Admin;

use Plugins\Sirsoft\Gdpr\Enums\GdprPolicyChangeType;
use Plugins\Sirsoft\Gdpr\Models\GdprPolicyVersion;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;

/**
 * 관리자 GDPR 정책 버전 API 테스트
 *
 * - GET /api/plugins/sirsoft-gdpr/admin/policy-versions
 * - GET /api/plugins/sirsoft-gdpr/admin/policy-versions/current
 */
class GdprAdminPolicyVersionControllerTest extends PluginTestCase
{
    public function test_index_requires_auth(): void
    {
        $this->getJson('/api/plugins/sirsoft-gdpr/admin/policy-versions')
            ->assertUnauthorized();
    }

    public function test_current_requires_auth(): void
    {
        $this->getJson('/api/plugins/sirsoft-gdpr/admin/policy-versions/current')
            ->assertUnauthorized();
    }

    public function test_index_returns_only_initial_seed_after_install(): void
    {
        // 마이그레이션 시 initial 행 (v1) 이 자동 시드됨
        $user = $this->createPrivacyOperatorUser();

        $response = $this->actingAs($user)->getJson('/api/plugins/sirsoft-gdpr/admin/policy-versions');

        $response->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.data.0.version', 1)
            ->assertJsonPath('data.data.0.change_type', GdprPolicyChangeType::Initial->value);
    }

    public function test_index_returns_versions_in_desc_order(): void
    {
        // initial (v1) 위에 추가 발행 row 2개 (v2, v3)
        GdprPolicyVersion::create([
            'version' => 2,
            'change_type' => GdprPolicyChangeType::Material->value,
            'memo' => 'analytics 신설',
            'snapshot' => ['cookie_categories' => [['key' => 'analytics']]],
        ]);
        GdprPolicyVersion::create([
            'version' => 3,
            'change_type' => GdprPolicyChangeType::Material->value,
            'memo' => 'marketing 추가',
            'snapshot' => ['cookie_categories' => [['key' => 'analytics'], ['key' => 'marketing']]],
        ]);

        $user = $this->createPrivacyOperatorUser();

        $response = $this->actingAs($user)->getJson('/api/plugins/sirsoft-gdpr/admin/policy-versions');

        $response->assertOk()
            ->assertJsonPath('data.pagination.total', 3)
            ->assertJsonPath('data.data.0.version', 3)
            ->assertJsonPath('data.data.0.memo', 'marketing 추가')
            ->assertJsonPath('data.data.1.version', 2)
            ->assertJsonPath('data.data.2.version', 1)
            ->assertJsonPath('data.data.2.change_type', GdprPolicyChangeType::Initial->value);
    }

    public function test_current_returns_initial_seed_after_fresh_install(): void
    {
        // 마이그레이션 시 initial (v1) 자동 시드 — current 는 그 행 반환
        $user = $this->createPrivacyOperatorUser();

        $response = $this->actingAs($user)->getJson('/api/plugins/sirsoft-gdpr/admin/policy-versions/current');

        $response->assertOk()
            ->assertJsonPath('data.data.version', 1)
            ->assertJsonPath('data.data.change_type', GdprPolicyChangeType::Initial->value);
    }

    public function test_current_returns_latest_version_with_publisher(): void
    {
        $publisher = $this->createPrivacyOperatorUser();

        // initial 시드 (v1) 위에 v5 추가 — 발행자 정보 포함
        GdprPolicyVersion::create([
            'version' => 5,
            'change_type' => GdprPolicyChangeType::Material->value,
            'memo' => '카테고리 의미 확장',
            'snapshot' => ['cookie_categories' => [['key' => 'analytics']]],
            'created_by' => $publisher->id,
        ]);

        $response = $this->actingAs($publisher)->getJson('/api/plugins/sirsoft-gdpr/admin/policy-versions/current');

        $response->assertOk()
            ->assertJsonPath('data.data.version', 5)
            ->assertJsonPath('data.data.memo', '카테고리 의미 확장')
            ->assertJsonPath('data.data.change_type', GdprPolicyChangeType::Material->value)
            ->assertJsonPath('data.data.publisher.email', $publisher->email);
    }

    public function test_store_requires_auth(): void
    {
        $this->postJson('/api/plugins/sirsoft-gdpr/admin/policy-versions', ['memo' => 'test'])
            ->assertUnauthorized();
    }

    public function test_store_requires_memo(): void
    {
        $user = $this->createPrivacyOperatorUser();

        $this->actingAs($user)
            ->postJson('/api/plugins/sirsoft-gdpr/admin/policy-versions', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['memo']);
    }

    public function test_store_publishes_new_material_version_with_memo(): void
    {
        $user = $this->createPrivacyOperatorUser();

        $response = $this->actingAs($user)
            ->postJson('/api/plugins/sirsoft-gdpr/admin/policy-versions', [
                'memo' => '정책 본문 외부 수정 + 의도적 재동의 트리거',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.data.version', 2)
            ->assertJsonPath('data.data.change_type', GdprPolicyChangeType::Material->value)
            ->assertJsonPath('data.data.memo', '정책 본문 외부 수정 + 의도적 재동의 트리거')
            ->assertJsonPath('data.data.publisher.email', $user->email);

        // DB 에 v2 row 추가됨 (initial 시드 v1 위에)
        $this->assertSame(2, GdprPolicyVersion::count());
    }

    public function test_index_respects_per_page_clamp(): void
    {
        // initial 시드 (v1) 위에 추가 4건 발행 → 총 5건
        for ($i = 2; $i <= 5; $i++) {
            GdprPolicyVersion::create([
                'version' => $i,
                'change_type' => GdprPolicyChangeType::Material->value,
                'snapshot' => [],
            ]);
        }

        $user = $this->createPrivacyOperatorUser();

        $response = $this->actingAs($user)
            ->getJson('/api/plugins/sirsoft-gdpr/admin/policy-versions?per_page=2');

        $response->assertOk()
            ->assertJsonPath('data.pagination.per_page', 2)
            ->assertJsonPath('data.pagination.total', 5)
            ->assertJsonPath('data.pagination.last_page', 3);
    }

    /**
     * 작업 2 (B-3 옵션 X): show — 미인증 401.
     */
    public function test_show_requires_auth(): void
    {
        $this->getJson('/api/plugins/sirsoft-gdpr/admin/policy-versions/1')
            ->assertUnauthorized();
    }

    /**
     * 작업 2 (B-3 옵션 X): show — 인증 + 권한 통과 + 발행된 버전 → 200 + snapshot 포함.
     */
    public function test_show_returns_detail_with_snapshot_for_existing_version(): void
    {
        GdprPolicyVersion::create([
            'version' => 2,
            'change_type' => GdprPolicyChangeType::Material->value,
            'memo' => 'analytics 카테고리 신설',
            'snapshot' => [
                'cookie_categories' => [
                    ['key' => 'necessary', 'label' => '필수', 'required' => true],
                    ['key' => 'analytics', 'label' => '분석', 'required' => false],
                ],
                'privacy_policy_slug' => 'privacy-policy',
                'blocked_domains' => [
                    'analytics' => ['google-analytics.com', '*.hotjar.com'],
                    'marketing' => ['facebook.com'],
                ],
            ],
        ]);

        $user = $this->createPrivacyOperatorUser();

        $response = $this->actingAs($user)
            ->getJson('/api/plugins/sirsoft-gdpr/admin/policy-versions/2');

        $response->assertOk()
            ->assertJsonPath('data.data.version', 2)
            ->assertJsonPath('data.data.change_type', GdprPolicyChangeType::Material->value)
            ->assertJsonPath('data.data.memo', 'analytics 카테고리 신설')
            ->assertJsonPath('data.data.snapshot.cookie_categories.1.key', 'analytics')
            ->assertJsonPath('data.data.snapshot.privacy_policy_slug', 'privacy-policy')
            // blocked_domains 객체가 프론트 iteration 친화 배열로 변환되었는지 확인
            ->assertJsonPath('data.data.blocked_domains_list.0.category', 'analytics')
            ->assertJsonPath('data.data.blocked_domains_list.0.domains.0', 'google-analytics.com')
            ->assertJsonPath('data.data.blocked_domains_list.0.domains.1', '*.hotjar.com')
            ->assertJsonPath('data.data.blocked_domains_list.1.category', 'marketing')
            ->assertJsonPath('data.data.blocked_domains_list.1.domains.0', 'facebook.com');
    }

    /**
     * 작업 2 (B-3 옵션 X): show — snapshot.blocked_domains 가 없으면 blocked_domains_list 가 빈 배열.
     */
    public function test_show_returns_empty_blocked_domains_list_when_snapshot_has_none(): void
    {
        GdprPolicyVersion::create([
            'version' => 2,
            'change_type' => GdprPolicyChangeType::Material->value,
            'snapshot' => ['cookie_categories' => [['key' => 'necessary']]],
        ]);

        $user = $this->createPrivacyOperatorUser();

        $response = $this->actingAs($user)
            ->getJson('/api/plugins/sirsoft-gdpr/admin/policy-versions/2');

        $response->assertOk()
            ->assertJsonPath('data.data.blocked_domains_list', []);
    }

    /**
     * 작업 2 (B-3 옵션 X): show — initial seed (v1) 도 조회 가능.
     */
    public function test_show_returns_initial_seed_version(): void
    {
        $user = $this->createPrivacyOperatorUser();

        $response = $this->actingAs($user)
            ->getJson('/api/plugins/sirsoft-gdpr/admin/policy-versions/1');

        $response->assertOk()
            ->assertJsonPath('data.data.version', 1)
            ->assertJsonPath('data.data.change_type', GdprPolicyChangeType::Initial->value);
    }

    /**
     * 작업 2 (B-3 옵션 X): show — 미발행 버전 → 404.
     */
    public function test_show_returns_404_for_unknown_version(): void
    {
        $user = $this->createPrivacyOperatorUser();

        $response = $this->actingAs($user)
            ->getJson('/api/plugins/sirsoft-gdpr/admin/policy-versions/999');

        $response->assertNotFound();
    }
}
