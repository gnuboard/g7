<?php

namespace App\Listeners;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Extension\HookListenerInterface;
use App\Extension\Cache\CoreCacheDriver;
use App\Helpers\TimezoneHelper;
use Carbon\Carbon;

/**
 * 확장 호환성 알림 리스너
 *
 * 코어 버전 호환성 문제로 자동 비활성화된 확장에 대한
 * 알림을 관리자 대시보드에 표시합니다.
 */
class ExtensionCompatibilityAlertListener implements HookListenerInterface
{
    private const CACHE_KEY = 'ext.compatibility_alerts';

    /**
     * 구독할 훅과 메서드 매핑 반환
     *
     * @return array 훅 매핑 배열
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.dashboard.alerts' => [
                'method' => 'addCompatibilityAlerts',
                'priority' => 10,
                'type' => 'filter',
            ],
        ];
    }

    /**
     * 훅 이벤트 처리 (기본 핸들러)
     *
     * @param  mixed  ...$args  훅에서 전달된 인수들
     */
    public function handle(...$args): void
    {
        // 기본 핸들러는 사용하지 않음
    }

    /**
     * 호환성 알림을 대시보드에 추가합니다.
     *
     * @param  array  $alerts  기존 알림 배열
     * @return array 알림이 추가된 배열
     */
    public function addCompatibilityAlerts(array $alerts): array
    {
        $compatibilityAlerts = self::resolveCache()->get(self::CACHE_KEY, []);

        foreach ($compatibilityAlerts as $type => $data) {
            foreach ($data['deactivated'] as $extension) {
                $alerts[] = [
                    'id' => 'compat_'.$extension['identifier'],
                    'type' => 'warning',
                    'icon' => 'exclamation-triangle',
                    'title' => __('extensions.alerts.incompatible_deactivated', [
                        'type' => __('extensions.types.'.rtrim($type, 's')),
                        'name' => $extension['identifier'],
                    ]),
                    'message' => __('extensions.alerts.incompatible_message', [
                        'required' => $extension['required'],
                        'installed' => $data['core_version'],
                    ]),
                    'time' => TimezoneHelper::toUserCarbon(Carbon::parse($data['timestamp']))?->diffForHumans(),
                    'read' => false,
                ];
            }
        }

        return $alerts;
    }

    /**
     * 특정 확장의 호환성 알림을 제거합니다.
     *
     * @param  string  $type  확장 타입 (modules, plugins, templates)
     * @param  string  $identifier  확장 식별자
     */
    public static function dismissAlert(string $type, string $identifier): void
    {
        $cache = self::resolveCache();
        $alerts = $cache->get(self::CACHE_KEY, []);

        if (isset($alerts[$type]['deactivated'])) {
            $alerts[$type]['deactivated'] = array_filter(
                $alerts[$type]['deactivated'],
                fn ($ext) => $ext['identifier'] !== $identifier
            );

            // 해당 타입에 비활성화된 확장이 없으면 타입 자체를 제거
            if (empty($alerts[$type]['deactivated'])) {
                unset($alerts[$type]);
            }

            if (empty($alerts)) {
                $cache->forget(self::CACHE_KEY);
            } else {
                $cache->put(self::CACHE_KEY, $alerts, 86400);
            }
        }
    }

    /**
     * 모든 호환성 알림을 제거합니다.
     */
    public static function dismissAllAlerts(): void
    {
        self::resolveCache()->forget(self::CACHE_KEY);
    }

    /**
     * CacheInterface 인스턴스를 lazy 조회합니다.
     */
    private static function resolveCache(): CacheInterface
    {
        try {
            return app(CacheInterface::class);
        } catch (\Throwable $e) {
            return new CoreCacheDriver(config('cache.default', 'array'));
        }
    }
}
