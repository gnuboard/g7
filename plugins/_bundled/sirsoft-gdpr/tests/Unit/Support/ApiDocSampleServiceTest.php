<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Gdpr\Tests\Unit\Support;

use App\Contracts\ApiDoc\ApiDocSampleSeeder;
use App\Models\User;
use Plugins\Sirsoft\Gdpr\Models\GdprPolicyVersion;
use Plugins\Sirsoft\Gdpr\Support\ApiDoc\ApiDocSampleService;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;

/**
 * sirsoft-gdpr API 문서 실측용 완전 샘플 시더 테스트.
 *
 * 정책 버전 상세 GET(`admin/policy-versions/{version}`) 실측을 위한 발행 정책 버전
 * 대표 샘플이 생성되고, `policy-versions` 도메인의 path_params 맵이 반환되며,
 * 재실행 시 멱등한지 검증한다.
 */
class ApiDocSampleServiceTest extends PluginTestCase
{
    /**
     * 실제 docgen 실행에서는 코어 ApiDocSampleService 가 먼저 실측 사용자를 시드한다.
     * 정책 버전의 created_by 소유자를 만족시키기 위해 사용자를 먼저 만든다.
     */
    protected function setUp(): void
    {
        parent::setUp();

        User::factory()->create([
            'email' => 'apidoc-sample-user@example.com',
            'name' => 'API 문서 샘플 사용자',
        ]);
    }

    /**
     * 시더가 계약을 구현한다.
     */
    public function test_seeder_implements_contract(): void
    {
        $this->assertInstanceOf(ApiDocSampleSeeder::class, new ApiDocSampleService);
    }

    /**
     * 시더가 발행된 정책 버전 대표 샘플을 생성한다.
     */
    public function test_seed_creates_policy_version(): void
    {
        (new ApiDocSampleService)->seed();

        $version = GdprPolicyVersion::query()->where('version', 1)->first();

        $this->assertNotNull($version);
        $this->assertSame(1, $version->version);
        $this->assertNotNull($version->change_type);
    }

    /**
     * 시더가 policy-versions 도메인의 path_params 맵을 반환한다.
     */
    public function test_seed_returns_policy_versions_path_params_map(): void
    {
        $map = (new ApiDocSampleService)->seed();

        $this->assertArrayHasKey('policy-versions', $map);
        $entry = $map['policy-versions'];
        $this->assertSame(GdprPolicyVersion::class, $entry['model']);
        $this->assertSame('version', $entry['key']);
        $this->assertArrayHasKey('version', $entry['path_params']);
        $this->assertSame('1', $entry['path_params']['version']);
    }

    /**
     * 재실행 시 정책 버전이 중복 생성되지 않는다 (멱등).
     */
    public function test_seed_is_idempotent(): void
    {
        (new ApiDocSampleService)->seed();
        (new ApiDocSampleService)->seed();

        $this->assertSame(1, GdprPolicyVersion::query()->where('version', 1)->count());
    }
}
