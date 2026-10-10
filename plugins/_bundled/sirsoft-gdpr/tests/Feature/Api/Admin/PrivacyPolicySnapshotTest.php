<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Feature\Api\Admin;

use App\Services\PluginSettingsService;
use Plugins\Sirsoft\Gdpr\Enums\GdprPolicyChangeType;
use Plugins\Sirsoft\Gdpr\Models\GdprPolicyVersion;
use Plugins\Sirsoft\Gdpr\Services\GdprPolicyVersionService;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;

/**
 * 정책 버전 스냅샷의 처리방침 주소 기록·비교·표시 검증
 *
 * 1.1.0 부터 스냅샷은 운영자가 적은 처리방침 주소(`privacy_policy_url`)를 기록한다. 1.0.x 가 발행한
 * 스냅샷은 슬러그(`privacy_policy_slug`)만 담고 있으므로, 비교와 표시 모두 그 슬러그를 그 시절 링크
 * 경로(`/page/{slug}`)로 풀어 읽어야 한다. 그러지 않으면 키 이름만 바뀐 같은 주소가 "정책 본문 위치
 * 변경" 으로 읽혀 전 회원 재동의가 걸리거나, 옛 버전 창에서 처리방침 링크가 사라진다.
 */
class PrivacyPolicySnapshotTest extends PluginTestCase
{
    /**
     * 수동 발행 스냅샷은 처리방침 주소를 기록하고 옛 슬러그 키는 싣지 않습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=policy_snapshot
     *
     * @effects policy_snapshot_records_privacy_policy_url
     */
    public function test_manual_publish_snapshot_records_privacy_policy_url(): void
    {
        $values = [
            'privacy_policy_url' => 'https://example.com/privacy',
            'privacy_policy_slug' => 'privacy',
            'legal_entity_name' => '(주)예시',
        ];
        $mock = $this->createMock(PluginSettingsService::class);
        $mock->method('get')->willReturnCallback(
            fn (string $id, ?string $key = null, mixed $default = null) => $key === null ? $values : ($values[$key] ?? $default)
        );
        $this->app->instance(PluginSettingsService::class, $mock);

        $user = $this->createPrivacyOperatorUser();

        $this->actingAs($user)
            ->postJson('/api/plugins/sirsoft-gdpr/admin/policy-versions', ['memo' => '처리방침 주소 변경'])
            ->assertOk();

        $snapshot = GdprPolicyVersion::query()->orderByDesc('version')->firstOrFail()->snapshot;
        $this->assertSame('https://example.com/privacy', $snapshot['privacy_policy_url']);
        $this->assertArrayNotHasKey('privacy_policy_slug', $snapshot);
    }

    /**
     * 처리방침 주소가 바뀌면 Material, 옛 슬러그 스냅샷과 그 슬러그를 옮긴 주소는 같은 주소로 봅니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=policy_snapshot
     *
     * @effects policy_diff_reads_legacy_slug_snapshot_as_same_address
     */
    public function test_detect_change_type_compares_addresses_across_snapshot_formats(): void
    {
        $service = app(GdprPolicyVersionService::class);

        $this->assertSame(
            GdprPolicyChangeType::Material,
            $service->detectChangeType(['privacy_policy_url' => '/privacy'], ['privacy_policy_url' => 'https://example.com/privacy']),
        );
        // 1.0.x 스냅샷(슬러그) → 업그레이드가 옮긴 주소: 같은 링크이므로 재동의 대상이 아니다
        $this->assertSame(
            GdprPolicyChangeType::NonMaterial,
            $service->detectChangeType(['privacy_policy_slug' => 'privacy'], ['privacy_policy_url' => '/page/privacy']),
        );
        // 옛 슬러그와 다른 주소로 바꾸면 위치 변경이다
        $this->assertSame(
            GdprPolicyChangeType::Material,
            $service->detectChangeType(['privacy_policy_slug' => 'privacy'], ['privacy_policy_url' => '/privacy']),
        );
        $this->assertSame(
            GdprPolicyChangeType::NonMaterial,
            $service->detectChangeType(['privacy_policy_slug' => ''], ['privacy_policy_url' => '']),
        );
    }

    /**
     * 상세 API 는 새 스냅샷의 주소를 그대로, 옛 스냅샷의 슬러그는 풀어 옛 슬러그와 함께 내보냅니다.
     *
     * 원본 snapshot 은 입증 자료이므로 고치지 않고 그대로 싣는다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=policy_snapshot
     *
     * @effects snapshot_modal_links_resolved_privacy_policy_url, snapshot_modal_labels_legacy_slug_snapshot
     */
    public function test_detail_resolves_privacy_policy_for_new_and_legacy_snapshots(): void
    {
        GdprPolicyVersion::create([
            'version' => 2,
            'change_type' => GdprPolicyChangeType::Material->value,
            'memo' => '1.0.x 발행분',
            'snapshot' => ['privacy_policy_slug' => 'privacy-policy'],
        ]);
        GdprPolicyVersion::create([
            'version' => 3,
            'change_type' => GdprPolicyChangeType::Material->value,
            'memo' => '1.1.0 발행분',
            'snapshot' => ['privacy_policy_url' => 'https://example.com/privacy'],
        ]);
        GdprPolicyVersion::create([
            'version' => 4,
            'change_type' => GdprPolicyChangeType::Material->value,
            'memo' => '주소 없음',
            'snapshot' => ['privacy_policy_url' => ''],
        ]);

        $user = $this->createPrivacyOperatorUser();

        $this->actingAs($user)->getJson('/api/plugins/sirsoft-gdpr/admin/policy-versions/2')
            ->assertOk()
            ->assertJsonPath('data.data.privacy_policy.url', '/page/privacy-policy')
            ->assertJsonPath('data.data.privacy_policy.legacy_slug', 'privacy-policy')
            ->assertJsonPath('data.data.snapshot.privacy_policy_slug', 'privacy-policy');

        $this->actingAs($user)->getJson('/api/plugins/sirsoft-gdpr/admin/policy-versions/3')
            ->assertOk()
            ->assertJsonPath('data.data.privacy_policy.url', 'https://example.com/privacy')
            ->assertJsonPath('data.data.privacy_policy.legacy_slug', null);

        $this->actingAs($user)->getJson('/api/plugins/sirsoft-gdpr/admin/policy-versions/4')
            ->assertOk()
            ->assertJsonPath('data.data.privacy_policy.url', null)
            ->assertJsonPath('data.data.privacy_policy.legacy_slug', null);
    }
}
