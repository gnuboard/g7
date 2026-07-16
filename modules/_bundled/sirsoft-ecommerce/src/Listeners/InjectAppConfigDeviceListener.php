<?php

declare(strict_types=1);

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Modules\Sirsoft\Ecommerce\Http\Middleware\DetectDevice;

/**
 * 요청 기기 유형(iOS 여부)을 프론트엔드 appConfig 에 주입하는 리스너.
 *
 * 코어 `core.frontend.filter_app_config` 필터 훅을 구독해, DetectDevice 미들웨어가
 * 컨테이너에 보관한 iOS 판정값을 `appConfig.isIos` 로 주입한다. 프론트는
 * window.G7Config.appConfig → _global.appConfig.isIos 로 받아 체크아웃 레이아웃의
 * 애플페이 iOS 게이팅에 활용한다.
 *
 * 코어는 config/frontend.php 정적 값만 노출하므로, 요청별로 달라지는 값(기기 유형)은
 * 이 훅을 통해 이커머스 모듈이 채운다.
 */
class InjectAppConfigDeviceListener implements HookListenerInterface
{
    /**
     * 구독할 훅 목록 반환
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.frontend.filter_app_config' => [
                'method' => 'injectDeviceFlags',
                'priority' => 20,
                'type' => 'filter',
            ],
        ];
    }

    /**
     * 기본 핸들러 (미사용).
     *
     * @param  mixed  ...$args
     */
    public function handle(...$args): void {}

    /**
     * appConfig 에 요청 기기 iOS 여부를 주입합니다.
     *
     * @param  array<string, mixed>  $appConfig  코어가 조립한 프론트엔드 appConfig
     * @return array<string, mixed> isIos 가 추가된 appConfig
     */
    public function injectDeviceFlags(array $appConfig): array
    {
        $appConfig['isIos'] = DetectDevice::isIos();

        return $appConfig;
    }
}
