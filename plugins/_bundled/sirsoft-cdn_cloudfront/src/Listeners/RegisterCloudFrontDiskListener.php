<?php

namespace Plugins\Sirsoft\CdnCloudfront\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Plugins\Sirsoft\CdnCloudfront\Support\CloudFrontDisk;

/**
 * 공개 자산 디스크 카탈로그에 `cloudfront` 를 올린다.
 *
 * 코어는 이 카탈로그 하나로 드라이버 탭 「공개 자산 스토리지」와 업로드 탭 「공개 썸네일 디스크」의
 * 선택지를 만든다. 리스너는 활성 플러그인만 등록되므로 플러그인을 끄면 선택지에서도 빠진다.
 */
class RegisterCloudFrontDiskListener implements HookListenerInterface
{
    /**
     * 구독할 훅과 메서드 매핑 반환
     *
     * @return array<string, array<string, mixed>> 훅 이름 => 옵션
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.settings.available_public_asset_drivers' => ['method' => 'register', 'type' => 'filter', 'priority' => 10],
        ];
    }

    /**
     * 인터페이스 기본 핸들러 (개별 메서드에서 처리)
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 카탈로그에 항목을 덧붙입니다 (이미 같은 id 가 있으면 그대로 둔다).
     *
     * 디스크 정의(config)가 없으면 올리지 않는다 — S3 버킷이 비어 디스크를 만들지 못한 경우,
     * 고를 수는 있는데 실제로는 기본 디스크로 바뀌어 쓰이는 선택지가 되기 때문이다.
     *
     * @param  mixed  $drivers  앞선 필터의 카탈로그
     * @return mixed 카탈로그
     */
    public function register(mixed $drivers): mixed
    {
        if (! is_array($drivers) || config('filesystems.disks.'.CloudFrontDisk::NAME) === null) {
            return $drivers;
        }

        if (in_array(CloudFrontDisk::NAME, array_column($drivers, 'id'), true)) {
            return $drivers;
        }

        $drivers[] = CloudFrontDisk::catalogEntry();

        return $drivers;
    }
}
