<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Unit\Services;

use App\Models\User;
use Plugins\Sirsoft\Gdpr\Enums\GdprPolicyChangeType;
use Plugins\Sirsoft\Gdpr\Models\GdprPolicyVersion;
use Plugins\Sirsoft\Gdpr\Services\GdprPolicyVersionService;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;

/**
 * GDPR 정책 버전 서비스 Unit 테스트
 *
 * publish() 의 단조 증가 + snapshot/메모 저장 검증.
 * detectChangeType() 의 Material/Non-material 분류 정확성 검증.
 */
class GdprPolicyVersionServiceTest extends PluginTestCase
{
    private GdprPolicyVersionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(GdprPolicyVersionService::class);
    }

    public function test_publish_starts_after_initial_seed(): void
    {
        // 마이그레이션 시 initial 행 (version=1) 이 자동 시드됨
        // → 추가 publish 시 다음 version 은 2 부터 시작
        $version = $this->service->publish(
            GdprPolicyChangeType::Material,
            ['cookie_categories' => []],
            null,
            null,
        );

        $this->assertInstanceOf(GdprPolicyVersion::class, $version);
        $this->assertSame(2, $version->version);
        $this->assertSame(GdprPolicyChangeType::Material, $version->change_type);
    }

    public function test_publish_monotonically_increments_version(): void
    {
        // initial 시드 (v1) 위에 publish 호출 → v2, v3, v4 순차 증가
        $second = $this->service->publish(GdprPolicyChangeType::Material, [], 'reason', null);
        $third = $this->service->publish(GdprPolicyChangeType::Material, [], null, null);
        $fourth = $this->service->publish(GdprPolicyChangeType::NonMaterial, [], null, null);

        $this->assertSame(2, $second->version);
        $this->assertSame(3, $third->version);
        $this->assertSame(4, $fourth->version);
    }

    public function test_publish_persists_snapshot_and_memo(): void
    {
        $snapshot = [
            'cookie_categories' => [
                ['key' => 'necessary', 'required' => true],
                ['key' => 'analytics', 'required' => false],
            ],
            'privacy_policy_slug' => 'privacy-policy',
        ];

        $version = $this->service->publish(
            GdprPolicyChangeType::Material,
            $snapshot,
            'analytics 카테고리 신설',
            null,
        );

        $this->assertSame($snapshot, $version->snapshot);
        $this->assertSame('analytics 카테고리 신설', $version->memo);
    }

    public function test_publish_records_user_id(): void
    {
        $user = User::factory()->create();

        $version = $this->service->publish(
            GdprPolicyChangeType::Material,
            [],
            null,
            $user->id,
        );

        $this->assertSame($user->id, $version->created_by);
        $this->assertSame($user->id, $version->createdBy->id);
    }

    public function test_detect_change_type_returns_material_when_category_added(): void
    {
        $oldSnapshot = [
            'cookie_categories' => [['key' => 'necessary'], ['key' => 'analytics']],
        ];
        $newSnapshot = [
            'cookie_categories' => [['key' => 'necessary'], ['key' => 'analytics'], ['key' => 'marketing']],
        ];

        $changeType = $this->service->detectChangeType($oldSnapshot, $newSnapshot);

        $this->assertSame(GdprPolicyChangeType::Material, $changeType);
    }

    public function test_detect_change_type_returns_material_when_category_removed(): void
    {
        $oldSnapshot = [
            'cookie_categories' => [['key' => 'necessary'], ['key' => 'analytics']],
        ];
        $newSnapshot = [
            'cookie_categories' => [['key' => 'necessary']],
        ];

        $this->assertSame(GdprPolicyChangeType::Material, $this->service->detectChangeType($oldSnapshot, $newSnapshot));
    }

    public function test_detect_change_type_returns_material_when_description_changed(): void
    {
        $oldSnapshot = [
            'cookie_categories' => [
                ['key' => 'analytics', 'description' => ['ko' => '방문자 분석']],
            ],
        ];
        $newSnapshot = [
            'cookie_categories' => [
                ['key' => 'analytics', 'description' => ['ko' => '방문자 분석 + 광고 효율 측정']],
            ],
        ];

        $this->assertSame(GdprPolicyChangeType::Material, $this->service->detectChangeType($oldSnapshot, $newSnapshot));
    }

    public function test_detect_change_type_returns_material_when_slug_changed(): void
    {
        $oldSnapshot = ['privacy_policy_slug' => 'privacy-policy'];
        $newSnapshot = ['privacy_policy_slug' => 'privacy-policy-v2'];

        $this->assertSame(GdprPolicyChangeType::Material, $this->service->detectChangeType($oldSnapshot, $newSnapshot));
    }

    public function test_detect_change_type_returns_non_material_when_only_domains_changed(): void
    {
        $base = [
            'cookie_categories' => [['key' => 'necessary'], ['key' => 'analytics']],
            'privacy_policy_slug' => 'privacy-policy',
        ];
        $oldSnapshot = array_merge($base, [
            'blocked_domains' => ['analytics' => ['google-analytics.com']],
        ]);
        $newSnapshot = array_merge($base, [
            'blocked_domains' => ['analytics' => ['google-analytics.com', 'hotjar.com']],
        ]);

        $this->assertSame(GdprPolicyChangeType::NonMaterial, $this->service->detectChangeType($oldSnapshot, $newSnapshot));
    }

    public function test_detect_change_type_returns_non_material_when_only_label_changed(): void
    {
        $oldSnapshot = [
            'cookie_categories' => [
                ['key' => 'analytics', 'label' => ['ko' => '분석']],
            ],
        ];
        $newSnapshot = [
            'cookie_categories' => [
                ['key' => 'analytics', 'label' => ['ko' => '분석 쿠키']],
            ],
        ];

        $this->assertSame(GdprPolicyChangeType::NonMaterial, $this->service->detectChangeType($oldSnapshot, $newSnapshot));
    }

    public function test_detect_change_type_returns_non_material_when_snapshots_equal(): void
    {
        $snapshot = [
            'cookie_categories' => [['key' => 'necessary'], ['key' => 'analytics']],
            'privacy_policy_slug' => 'privacy-policy',
        ];

        $this->assertSame(GdprPolicyChangeType::NonMaterial, $this->service->detectChangeType($snapshot, $snapshot));
    }

    public function test_detect_change_type_ignores_category_order(): void
    {
        $oldSnapshot = [
            'cookie_categories' => [['key' => 'necessary'], ['key' => 'analytics'], ['key' => 'marketing']],
        ];
        $newSnapshot = [
            'cookie_categories' => [['key' => 'marketing'], ['key' => 'necessary'], ['key' => 'analytics']],
        ];

        // 정렬 후 비교하므로 순서만 다른 경우는 Material 이 아님
        $this->assertSame(GdprPolicyChangeType::NonMaterial, $this->service->detectChangeType($oldSnapshot, $newSnapshot));
    }

    /**
     * 작업 2 (B-3 옵션 X): getByVersion 이 매칭 row 를 반환.
     */
    public function test_get_by_version_returns_matching_row(): void
    {
        GdprPolicyVersion::create([
            'version' => 2,
            'change_type' => GdprPolicyChangeType::Material->value,
            'memo' => 'analytics 신설',
            'snapshot' => ['cookie_categories' => [['key' => 'analytics']]],
        ]);

        $result = $this->service->getByVersion(2);

        $this->assertInstanceOf(GdprPolicyVersion::class, $result);
        $this->assertSame(2, $result->version);
        $this->assertSame('analytics 신설', $result->memo);
        $this->assertSame('analytics', $result->snapshot['cookie_categories'][0]['key']);
    }

    /**
     * 작업 2 (B-3 옵션 X): getByVersion 이 미발행 버전에 null 반환.
     */
    public function test_get_by_version_returns_null_when_not_found(): void
    {
        $result = $this->service->getByVersion(999);

        $this->assertNull($result);
    }

    /**
     * 작업 2 (B-3 옵션 X): getByVersion 이 initial seed (v1) 도 조회 가능.
     */
    public function test_get_by_version_returns_initial_seed(): void
    {
        $result = $this->service->getByVersion(1);

        $this->assertInstanceOf(GdprPolicyVersion::class, $result);
        $this->assertSame(1, $result->version);
        $this->assertSame(GdprPolicyChangeType::Initial, $result->change_type);
    }
}
