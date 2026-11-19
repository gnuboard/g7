<?php

namespace Plugins\Sirsoft\CdnCloudfront\Support;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

/**
 * 디스크 `cloudfront` 정의 — 코어 S3 디스크 설정을 복제하고 공개 URL 만 CDN 주소로 바꾼다.
 *
 * 파일은 코어 S3 설정(버킷·지역·자격·엔드포인트)이 가리키는 곳에 그대로 저장되고, `url()` 이 CDN 주소로
 * 나간다. 그래서 S3 버킷을 CloudFront 배포의 원본으로 둔 구성에서만 뜻이 있다.
 *
 * 플러그인이 꺼지면 이 정의가 사라진다(서비스 프로바이더가 등록하지 않는다). 디스크를 고른 설정값은
 * 남지만 코어가 없는 디스크를 기본값으로 바꿔 쓴다 — 공개 자산 디스크는 스트리밍으로, 공개 썸네일
 * 디스크는 비공개 썸네일 디스크로. 다시 켜면 저장값이 되살아난다.
 *
 * 코어 config 는 s3 디스크를 항상 정의하므로 드라이버만으로는 S3 설정 여부를 알 수 없다. 버킷이 비어 있으면
 * 디스크를 만들지 않는다 — 저장도 조회도 실패할 디스크를 선택지에 올리지 않는다.
 */
class CloudFrontDisk
{
    /** 디스크 이름 (공개 자산 디스크 카탈로그 id 와 같다) */
    public const NAME = 'cloudfront';

    /**
     * CDN 주소를 읽을 플러그인 설정을 주입받습니다.
     *
     * @param  CloudFrontSettings  $settings  플러그인 설정
     */
    public function __construct(private readonly CloudFrontSettings $settings) {}

    /**
     * 디스크 설정 배열을 만듭니다.
     *
     * CDN 주소가 없으면 `url` 을 null 로 둔다 — `url()` 이 null 이 되어 코어가 서버 스트리밍으로 내보낸다.
     *
     * @return array<string, mixed>|null 디스크 설정 (코어 S3 디스크가 없거나 버킷이 비어 있으면 null)
     */
    public function definition(): ?array
    {
        $s3 = config('filesystems.disks.s3');

        if (! is_array($s3) || ($s3['driver'] ?? null) !== 's3' || trim((string) ($s3['bucket'] ?? '')) === '') {
            return null;
        }

        $s3['url'] = $this->settings->cdnBaseUrl();

        return $s3;
    }

    /**
     * 디스크를 config 에 등록합니다.
     *
     * 같은 요청 안에서 다시 부르면(설정 변경 직후 등) 만들어 둔 디스크 인스턴스를 버려 새 설정을 쓰게 한다.
     * 만들 수 없으면 있던 정의도 지운다.
     *
     * @return bool 등록했으면 true
     */
    public function register(): bool
    {
        $definition = $this->definition();

        if ($definition === null) {
            $this->unregister();

            return false;
        }

        Config::set('filesystems.disks.'.self::NAME, $definition);
        Storage::forgetDisk(self::NAME);

        return true;
    }

    /**
     * 디스크 정의를 지웁니다 (플러그인을 끈 프로세스가 끝날 때까지 옛 디스크를 쓰지 않게 한다).
     *
     * 키째 뺀다 — null 값으로 남기면 프레임워크 파일시스템 프로바이더가 디스크 목록을 돌며 그 null 을
     * 배열로 받다가 TypeError 로 부팅이 깨진다.
     *
     * @return void 반환값 없음
     */
    public function unregister(): void
    {
        $disks = (array) config('filesystems.disks', []);
        unset($disks[self::NAME]);

        Config::set('filesystems.disks', $disks);
        Storage::forgetDisk(self::NAME);
    }

    /**
     * 공개 자산 디스크 카탈로그 항목을 만듭니다.
     *
     * 라벨은 코어 카탈로그와 같은 로케일별 배열이다(선택 칸이 화면 언어로 고른다).
     *
     * @return array{id: string, label: array<string, string>, provider: string} 카탈로그 항목
     */
    public static function catalogEntry(): array
    {
        $label = [];

        foreach ((array) config('app.translatable_locales', ['ko', 'en']) as $locale) {
            $label[$locale] = (string) __(CloudFrontSettings::IDENTIFIER.'::messages.disk_label', [], $locale);
        }

        return [
            'id' => self::NAME,
            'label' => $label,
            'provider' => CloudFrontSettings::IDENTIFIER,
        ];
    }
}
