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

    /**
     * 테스트 대상 서비스를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(GdprPolicyVersionService::class);
    }

    /**
     * 설치 시드(v1) 다음 발행은 버전 2 부터 시작합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario expected_publish=yes_v_plus_1, current_db_state=fresh_install_v1_only
     *
     * @effects publish_starts_after_initial_seed
     */
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

    /**
     * 연속 발행은 버전 번호를 하나씩 올립니다.
     *
     * @return void 반환값 없음
     *
     * @scenario expected_publish=yes_v_plus_1, current_db_state=fresh_install_v1_only
     *
     * @effects publish_monotonically_increments_version
     */
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

    /**
     * 발행 시 처리방침 주소를 포함한 스냅샷과 메모를 그대로 저장합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario expected_publish=yes_v_plus_1, memo_input=provided
     *
     * @effects publish_persists_snapshot_and_memo_and_user_id
     */
    public function test_publish_persists_snapshot_and_memo(): void
    {
        $snapshot = [
            'cookie_categories' => [
                ['key' => 'necessary', 'required' => true],
                ['key' => 'analytics', 'required' => false],
            ],
            'privacy_policy_url' => '/privacy-policy',
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

    /**
     * 발행 운영자의 회원 번호를 기록합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario expected_publish=yes_v_plus_1, user_role=privacy_operator
     *
     * @effects publish_persists_snapshot_and_memo_and_user_id
     */
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

    /**
     * 쿠키 카테고리가 늘면 Material 로 판정합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario change_type=material_add_category, expected_publish=yes_v_plus_1
     *
     * @effects material_when_category_key_added
     */
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

    /**
     * 쿠키 카테고리가 줄면 Material 로 판정합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario change_type=material_remove_category, expected_publish=yes_v_plus_1
     *
     * @effects material_when_category_key_removed
     */
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

    /**
     * 카테고리 설명(처리 목적)이 바뀌면 Material 로 판정합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario change_type=material_description_change, expected_publish=yes_v_plus_1
     *
     * @effects material_when_category_description_changed
     */
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

    /**
     * 개인정보처리방침 주소가 바뀌면 Material 로 판정합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario change_type=material_privacy_policy_url_change, expected_publish=yes_v_plus_1
     *
     * @effects material_when_privacy_policy_url_changed
     */
    public function test_detect_change_type_returns_material_when_privacy_policy_url_changed(): void
    {
        $oldSnapshot = ['privacy_policy_url' => '/privacy'];
        $newSnapshot = ['privacy_policy_url' => 'https://example.com/privacy'];

        $this->assertSame(GdprPolicyChangeType::Material, $this->service->detectChangeType($oldSnapshot, $newSnapshot));
    }

    /**
     * 1.0.x 스냅샷끼리 슬러그가 다르면 옛 링크 경로로 풀어 비교해 Material 로 판정합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario change_type=material_privacy_policy_url_change, expected_publish=yes_v_plus_1
     *
     * @effects material_when_privacy_policy_url_changed
     */
    public function test_detect_change_type_returns_material_when_legacy_slug_snapshots_differ(): void
    {
        // 1.0.x 발행분끼리 비교할 때도 옛 슬러그를 그 시절 링크 경로로 풀어 같은 판정을 유지한다.
        $oldSnapshot = ['privacy_policy_slug' => 'privacy-policy'];
        $newSnapshot = ['privacy_policy_slug' => 'privacy-policy-v2'];

        $this->assertSame(GdprPolicyChangeType::Material, $this->service->detectChangeType($oldSnapshot, $newSnapshot));
    }

    /**
     * 차단 도메인만 바뀌면 Non-material 로 판정합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario change_type=non_material_domain_add, expected_publish=no
     *
     * @effects non_material_when_only_domains_changed
     */
    public function test_detect_change_type_returns_non_material_when_only_domains_changed(): void
    {
        $base = [
            'cookie_categories' => [['key' => 'necessary'], ['key' => 'analytics']],
            'privacy_policy_url' => '/privacy-policy',
        ];
        $oldSnapshot = array_merge($base, [
            'blocked_domains' => ['analytics' => ['google-analytics.com']],
        ]);
        $newSnapshot = array_merge($base, [
            'blocked_domains' => ['analytics' => ['google-analytics.com', 'hotjar.com']],
        ]);

        $this->assertSame(GdprPolicyChangeType::NonMaterial, $this->service->detectChangeType($oldSnapshot, $newSnapshot));
    }

    /**
     * 카테고리 라벨만 바뀌면 Non-material 로 판정합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario change_type=non_material_label_change, expected_publish=no
     *
     * @effects non_material_when_only_label_changed
     */
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

    /**
     * 스냅샷이 같으면 Non-material 로 판정합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario change_type=no_change, expected_publish=no
     *
     * @effects non_material_when_snapshots_equal
     */
    public function test_detect_change_type_returns_non_material_when_snapshots_equal(): void
    {
        $snapshot = [
            'cookie_categories' => [['key' => 'necessary'], ['key' => 'analytics']],
            'privacy_policy_url' => '/privacy-policy',
        ];

        $this->assertSame(GdprPolicyChangeType::NonMaterial, $this->service->detectChangeType($snapshot, $snapshot));
    }

    /**
     * 카테고리 순서만 바뀌면 Non-material 로 판정합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario change_type=no_change, expected_publish=no
     *
     * @effects non_material_when_category_order_changed_only
     */
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
     * 발행된 버전 번호로 그 행을 조회합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario current_db_state=has_v2_published
     *
     * @effects get_by_version_returns_row_or_null
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
     * 발행되지 않은 버전 번호는 null 을 돌려줍니다.
     *
     * @return void 반환값 없음
     *
     * @scenario current_db_state=fresh_install_v1_only
     *
     * @effects get_by_version_returns_row_or_null
     */
    public function test_get_by_version_returns_null_when_not_found(): void
    {
        $result = $this->service->getByVersion(999);

        $this->assertNull($result);
    }

    /**
     * 설치 시드 버전(v1)도 조회됩니다.
     *
     * @return void 반환값 없음
     *
     * @scenario current_db_state=fresh_install_v1_only
     *
     * @effects get_by_version_returns_row_or_null, migration_up_seeds_initial_row
     */
    public function test_get_by_version_returns_initial_seed(): void
    {
        $result = $this->service->getByVersion(1);

        $this->assertInstanceOf(GdprPolicyVersion::class, $result);
        $this->assertSame(1, $result->version);
        $this->assertSame(GdprPolicyChangeType::Initial, $result->change_type);
    }
}
