<?php

namespace Plugins\Sirsoft\CdnCloudfront;

use App\Extension\AbstractPlugin;

/**
 * CloudFront CDN 플러그인
 *
 * 두 가지를 한다.
 *  1. 코어 S3 설정(버킷·자격)을 그대로 복제하고 공개 URL 만 CloudFront 주소로 바꾼 디스크 `cloudfront` 를
 *     정의해 공개 자산 디스크 카탈로그에 올린다. 운영자가 환경설정 > 업로드 탭의 「공개 썸네일 디스크」나
 *     드라이버 탭의 「공개 자산 스토리지」에서 고르면 파일은 S3 에, 주소는 CDN 으로 나간다.
 *  2. 코어가 썸네일 파생본을 지울 때 발화하는 `core.thumbnail.after_purge` 를 받아 CDN 캐시 무효화
 *     (CreateInvalidation)를 요청 단위로 한 번 보낸다. 실패하면 대기열에 넣고 5분마다 다시 보낸다.
 *
 * 무효화 자격 증명은 S3 자격과 따로 받는다(비면 무효화를 보내지 않는다). S3 업로드 권한과 배포 무효화
 * 권한을 한 키에 몰아 주지 않게 하려는 것이다.
 */
class Plugin extends AbstractPlugin
{
    /**
     * 설정 기본값을 반환합니다.
     *
     * @return array<string, mixed> 설정 키 => 설정 스키마의 기본값
     */
    public function getConfigValues(): array
    {
        return array_map(static fn (array $field) => $field['default'], $this->getSettingsSchema());
    }

    /**
     * 플러그인 설정 스키마를 반환합니다.
     *
     * 자격 증명 두 칸은 sensitive — 설정 조회 응답에서 가려지고, 가린 값이 그대로 돌아오면 저장값을 유지한다.
     * 형식 검증(URL·배포 ID 모양)은 설정 저장 검증 규칙 훅(ValidatePluginSettingsListener)이 맡는다.
     *
     * @return array<string, array<string, mixed>> 설정 키 => 형식·기본값·한계값·다국어 라벨
     */
    public function getSettingsSchema(): array
    {
        return [
            'cdn_base_url' => [
                'type' => 'string',
                'default' => '',
                'max' => 255,
                'required' => false,
                'label' => ['ko' => 'CDN 주소', 'en' => 'CDN base URL'],
                'hint' => [
                    'ko' => 'CloudFront 배포의 도메인 주소입니다. 이 주소가 비어 있으면 파일은 S3 에 저장되지만 화면에는 서버를 거친 주소로 나갑니다.',
                    'en' => 'Domain URL of the CloudFront distribution. When empty, files are stored on S3 but served through the server.',
                ],
            ],
            'distribution_id' => [
                'type' => 'string',
                'default' => '',
                'max' => 64,
                'required' => false,
                'label' => ['ko' => '배포 ID', 'en' => 'Distribution ID'],
                'hint' => [
                    'ko' => '캐시 무효화를 보낼 CloudFront 배포의 ID 입니다.',
                    'en' => 'ID of the CloudFront distribution to invalidate.',
                ],
            ],
            'access_key' => [
                'type' => 'string',
                'default' => '',
                'max' => 128,
                'sensitive' => true,
                'required' => false,
                'label' => ['ko' => '무효화용 액세스 키', 'en' => 'Invalidation access key'],
                'hint' => [
                    'ko' => 'CloudFront 무효화 권한만 가진 IAM 키를 따로 넣습니다. 비워 두면 캐시 무효화를 보내지 않습니다.',
                    'en' => 'A separate IAM key that only has CloudFront invalidation permission. Leave empty to skip cache invalidation.',
                ],
            ],
            'secret_key' => [
                'type' => 'string',
                'default' => '',
                'max' => 255,
                'sensitive' => true,
                'required' => false,
                'label' => ['ko' => '무효화용 비밀 키', 'en' => 'Invalidation secret key'],
                'hint' => [
                    'ko' => '위 액세스 키의 비밀 키입니다. 비워 두면 캐시 무효화를 보내지 않습니다.',
                    'en' => 'Secret key of the access key above. Leave empty to skip cache invalidation.',
                ],
            ],
            'purge_enabled' => [
                'type' => 'boolean',
                'default' => true,
                'required' => false,
                'label' => ['ko' => '캐시 자동 무효화', 'en' => 'Automatic cache invalidation'],
                'hint' => [
                    'ko' => '썸네일이 지워지면 CDN 캐시 무효화를 보냅니다. 배포 ID 와 무효화용 키가 모두 있어야 동작합니다.',
                    'en' => 'Sends a CDN cache invalidation when thumbnails are removed. Requires the distribution ID and invalidation keys.',
                ],
            ],
        ];
    }

    /**
     * 훅 리스너 클래스 목록을 반환합니다.
     *
     * @return array<int, class-string> 디스크 카탈로그 등록·디스크 동기화·퍼지·설정 검증 리스너
     */
    public function getHookListeners(): array
    {
        return [
            Listeners\RegisterCloudFrontDiskListener::class,
            Listeners\SyncCloudFrontDiskListener::class,
            Listeners\PurgeThumbnailsListener::class,
            Listeners\ValidatePluginSettingsListener::class,
        ];
    }

    /**
     * 정기 작업 선언 — 실패한 캐시 무효화 재시도.
     *
     * 5분마다 대기열에서 시도 시각이 된 건만 다시 보낸다(건마다 대기 시간이 지수로 늘고 5회에서 멈춘다).
     * 대기열이 비면 아무 호출도 하지 않으므로 잦은 주기라도 실행 기록은 실패만 남긴다.
     *
     * @return array<int, array<string, mixed>> 스케줄 선언 목록 (형식: AbstractPlugin::getSchedules() 참고)
     */
    public function getSchedules(): array
    {
        return [
            [
                'name' => 'retry-purges',
                'type' => 'artisan',
                'label' => ['ko' => 'CDN 캐시 무효화 재시도', 'en' => 'Retry CDN cache invalidations'],
                'description' => [
                    'ko' => '실패한 CloudFront 캐시 무효화를 다시 보냅니다(캐시 자동 무효화를 켠 경우만).',
                    'en' => 'Resends failed CloudFront cache invalidations (only when automatic cache invalidation is on).',
                ],
                'command' => 'sirsoft-cdn_cloudfront:retry-purges',
                'expression' => '*/5 * * * *',
                'without_overlapping' => 10,
                'history_mode' => 'failures_only',
                'enabled_condition' => [
                    ['source' => 'plugin_setting', 'key' => 'purge_enabled', 'operator' => 'truthy', 'default' => true],
                ],
            ],
        ];
    }

    /**
     * 이 플러그인이 만드는 테이블 목록을 반환합니다 (데이터 삭제 제거 때 지운다).
     *
     * @return array<int, string> 접두사 없는 테이블 이름
     */
    public function getDynamicTables(): array
    {
        return ['cloudfront_purges'];
    }
}
