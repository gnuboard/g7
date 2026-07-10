<?php

namespace Plugins\Sirsoft\Gdpr\Support\ApiDoc;

use App\Contracts\ApiDoc\ApiDocSampleSeeder;
use App\Models\User;
use Plugins\Sirsoft\Gdpr\Enums\GdprPolicyChangeType;
use Plugins\Sirsoft\Gdpr\Models\GdprPolicyVersion;

/**
 * sirsoft-gdpr API 문서 실측용 완전 샘플 시더
 *
 * 정책 버전 상세 GET(`admin/policy-versions/{version}`)은 route-model binding 없이
 * `show(int $version)` 로 `version` 컬럼 값을 조회한다. 실측 시 `{version}` 를 실제
 * 발행된 정책 버전 번호로 치환하지 못하면 상세 GET 이 실측 제외(unresolved-path-param)로
 * 남으므로, 이 시더는 발행된 정책 버전 1건을 멱등 생성하고 `policy-versions` 도메인의
 * `path_params` 맵으로 `{version}` 를 실제 버전 번호로 정확 일치 치환한다.
 *
 * `api:docgen --scope=plugin:sirsoft-gdpr --seed` 실행 시 커맨드가 규약 위치
 * (`Plugins\Sirsoft\Gdpr\Support\ApiDoc\ApiDocSampleService`)로 자동 발견한다.
 */
class ApiDocSampleService implements ApiDocSampleSeeder
{
    /**
     * @var int 샘플 정책 버전 번호
     */
    private const SAMPLE_VERSION = 1;

    /**
     * GDPR 정책 버전 완전 샘플을 멱등 생성하고 `policy-versions` 도메인의 path_params 맵을 반환합니다.
     *
     * @return array<string, array{model: class-string, key: string, value: string, path_params?: array<string, string>}> 도메인 => 대표 레코드 정보
     */
    public function seed(): array
    {
        if (! class_exists(GdprPolicyVersion::class)) {
            return [];
        }

        $version = $this->seedPolicyVersion();

        $entry = [
            'model' => GdprPolicyVersion::class,
            'key' => 'version',
            'value' => (string) $version->version,
            'path_params' => [
                'version' => (string) $version->version,
            ],
        ];

        return [
            'policy-versions' => $entry,
        ];
    }

    /**
     * 발행된 GDPR 정책 버전 1건을 멱등 생성합니다.
     *
     * @return GdprPolicyVersion 샘플 정책 버전
     */
    private function seedPolicyVersion(): GdprPolicyVersion
    {
        $actor = User::query()->orderBy('id')->first();

        return GdprPolicyVersion::query()->firstOrCreate(
            ['version' => self::SAMPLE_VERSION],
            [
                'change_type' => GdprPolicyChangeType::Initial,
                'memo' => 'API 문서 실측용 샘플 정책 버전',
                'snapshot' => [
                    'privacy_policy_slug' => 'privacy',
                    'banner_enabled' => true,
                    'banner_position' => 'bottom_bar',
                ],
                'created_by' => $actor?->getKey(),
            ]
        );
    }
}
