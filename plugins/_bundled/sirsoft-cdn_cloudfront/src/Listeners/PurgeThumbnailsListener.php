<?php

namespace Plugins\Sirsoft\CdnCloudfront\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Plugins\Sirsoft\CdnCloudfront\Services\PurgeService;

/**
 * 코어가 썸네일 파생본을 지운 뒤(`core.thumbnail.after_purge`) CloudFront 캐시를 무효화한다.
 *
 * 코어는 요청(또는 큐 작업) 하나에서 지운 접두를 모아 한 번만 발화하므로 무효화도 한 번이다.
 * 액션 훅이라 큐 드라이버가 있으면 큐에서 돈다 — 사용자 응답을 CDN API 호출이 붙잡지 않는다.
 */
class PurgeThumbnailsListener implements HookListenerInterface
{
    /**
     * 무효화 서비스를 주입받습니다.
     *
     * @param  PurgeService  $purges  무효화 서비스
     */
    public function __construct(private readonly PurgeService $purges) {}

    /**
     * 구독할 훅과 메서드 매핑 반환
     *
     * @return array<string, array<string, mixed>> 훅 이름 => 옵션
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.thumbnail.after_purge' => ['method' => 'purge', 'priority' => 10],
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
     * 퍼지 묶음을 무효화 요청으로 보냅니다.
     *
     * @param  mixed  $items  `list<array{source_disk, prefix, thumbnail_disk, public_url_prefix, original_public_url, original_is_directory}>`
     * @return void 반환값 없음
     */
    public function purge(mixed $items = []): void
    {
        if (! is_array($items) || $items === []) {
            return;
        }

        $this->purges->purgeItems(array_values($items));
    }
}
