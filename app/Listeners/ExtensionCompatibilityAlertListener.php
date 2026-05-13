<?php

namespace App\Listeners;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Extension\HookListenerInterface;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Extension\Cache\CoreCacheDriver;
use App\Extension\CoreVersionChecker;
use App\Helpers\TimezoneHelper;
use App\Services\ExtensionCompatibilityAlertService;
use Carbon\Carbon;

/**
 * 확장 호환성 알림 리스너
 *
 * (1) 자동 비활성화된 확장 — `deactivated_reason='incompatible_core'` DB 쿼리 기반 (영속).
 * (2) 코어 업그레이드 후 재호환된 확장 — `ext.recovery_check.*` 캐시 기반.
 *
 * 두 종류의 알림을 관리자 대시보드에 표시합니다. 자동 비활성화 알림은 DB 컬럼이 살아있는
 * 동안 영속적으로 노출되며, 재호환 알림은 사용자가 "다시 활성화" 버튼으로 복구할 때까지
 * 표시됩니다.
 *
 * @since 7.0.0-beta.4
 */
class ExtensionCompatibilityAlertListener implements HookListenerInterface
{
    /**
     * 재호환 감지 결과 캐시 키 prefix (CoreServiceProvider::detectRecoveredExtensions 와 공유).
     */
    public const RECOVERY_CACHE_PREFIX = 'ext.recovery_check.';

    /**
     * @param  ExtensionCompatibilityAlertService|null  $alertService  dismiss 상태 관리 서비스 (DI 주입)
     */
    public function __construct(
        private ?ExtensionCompatibilityAlertService $alertService = null,
    ) {
        $this->alertService = $alertService ?? ExtensionCompatibilityAlertService::fallback();
    }

    /**
     * 구독할 훅과 메서드 매핑 반환.
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
     * 훅 이벤트 처리 (기본 핸들러).
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
        $dismissedIds = $this->alertService->getDismissedAlertIds(auth()->id());

        // 1) 자동 비활성화된 확장 → 경고 알림 (DB 기반, 영속)
        foreach ($this->resolveAutoDeactivated() as $type => $records) {
            foreach ($records as $record) {
                $alertId = "compat_{$type}_{$record['identifier']}";
                if (in_array($alertId, $dismissedIds, true)) {
                    continue;
                }

                $alerts[] = [
                    'id' => $alertId,
                    'type' => 'warning',
                    'subtype' => 'incompatible_core',
                    'icon' => 'exclamation-triangle',
                    'title' => __('extensions.alerts.incompatible_deactivated', [
                        'type' => __('extensions.types.'.rtrim($type, 's')),
                        'name' => $record['identifier'],
                    ]),
                    'message' => __('extensions.alerts.incompatible_message', [
                        'required' => $record['incompatible_required_version'] ?? '?',
                        'installed' => CoreVersionChecker::getCoreVersion(),
                    ]),
                    'extension_type' => rtrim($type, 's'),
                    'identifier' => $record['identifier'],
                    'time' => $record['deactivated_at']
                        ? TimezoneHelper::toUserCarbon(Carbon::parse($record['deactivated_at']))?->diffForHumans()
                        : null,
                    'read' => false,
                ];
            }
        }

        // 2) 재호환된 확장 → 복구 가능 알림 (캐시 기반)
        foreach ($this->resolveRecovered() as $type => $entries) {
            foreach ($entries as $entry) {
                $alertId = "recover_{$type}_{$entry['identifier']}";
                if (in_array($alertId, $dismissedIds, true)) {
                    continue;
                }

                $alerts[] = [
                    'id' => $alertId,
                    'type' => 'info',
                    'subtype' => 'recovery_available',
                    'icon' => 'check-circle',
                    'title' => __('extensions.alerts.recovered_title', [
                        'type' => __('extensions.types.'.rtrim($type, 's')),
                        'name' => $entry['identifier'],
                    ]),
                    'message' => __('extensions.alerts.recovered_body', [
                        'previously_required' => $entry['previously_required'] ?? '?',
                    ]),
                    'extension_type' => rtrim($type, 's'),
                    'identifier' => $entry['identifier'],
                    'recover_endpoint' => "/api/admin/extensions/".rtrim($type, 's')."/{$entry['identifier']}/recover",
                    'time' => $entry['deactivated_at']
                        ? TimezoneHelper::toUserCarbon(Carbon::parse($entry['deactivated_at']))?->diffForHumans()
                        : null,
                    'read' => false,
                ];
            }
        }

        return $alerts;
    }

    /**
     * 자동 비활성화된 확장 목록을 DB 에서 조회 + hidden 확장 제외.
     *
     * @return array<string, array<int, array>> ['plugins' => [...], 'modules' => [...], 'templates' => [...]]
     */
    protected function resolveAutoDeactivated(): array
    {
        $result = [];

        $repos = [
            'plugins' => app(PluginRepositoryInterface::class),
            'modules' => app(ModuleRepositoryInterface::class),
            'templates' => app(TemplateRepositoryInterface::class),
        ];

        foreach ($repos as $type => $repo) {
            $records = $repo->findAutoDeactivated();
            $items = [];

            foreach ($records as $record) {
                if ($this->isHiddenExtension(rtrim($type, 's'), $record->identifier)) {
                    continue;
                }

                $items[] = [
                    'identifier' => $record->identifier,
                    'incompatible_required_version' => $record->incompatible_required_version,
                    'deactivated_at' => $record->deactivated_at,
                ];
            }

            if ($items !== []) {
                $result[$type] = $items;
            }
        }

        return $result;
    }

    /**
     * 재호환 감지 결과를 캐시에서 조회 + hidden 확장 제외.
     *
     * @return array<string, array<int, array>>
     */
    protected function resolveRecovered(): array
    {
        $cache = self::resolveCache();
        $coreVersion = CoreVersionChecker::getCoreVersion();
        $result = [];

        foreach (['plugins', 'modules', 'templates'] as $type) {
            $key = self::RECOVERY_CACHE_PREFIX.$type.'.'.$coreVersion;
            $entries = $cache->get($key, []);

            $filtered = [];
            foreach ($entries as $entry) {
                if (! is_array($entry) || ! isset($entry['identifier'])) {
                    continue;
                }
                if ($this->isHiddenExtension(rtrim($type, 's'), $entry['identifier'])) {
                    continue;
                }
                $filtered[] = $entry;
            }

            if ($filtered !== []) {
                $result[$type] = $filtered;
            }
        }

        return $result;
    }

    /**
     * 확장이 hidden 플래그(학습용 샘플 등) 인지 판정.
     *
     * @param  string  $singularType  module|plugin|template
     * @param  string  $identifier  확장 식별자
     */
    protected function isHiddenExtension(string $singularType, string $identifier): bool
    {
        try {
            $manager = match ($singularType) {
                'plugin' => app(\App\Contracts\Extension\PluginManagerInterface::class),
                'module' => app(\App\Contracts\Extension\ModuleManagerInterface::class),
                'template' => app(\App\Contracts\Extension\TemplateManagerInterface::class),
                default => null,
            };

            if ($manager === null) {
                return false;
            }

            $extension = match ($singularType) {
                'plugin' => $manager->getPlugin($identifier),
                'module' => $manager->getModule($identifier),
                'template' => $manager->getTemplate($identifier),
            };

            // ModuleInterface/PluginInterface 는 isHidden() 제공, Template 는 array
            if (is_array($extension)) {
                return ! empty($extension['hidden']);
            }
            if (is_object($extension) && method_exists($extension, 'isHidden')) {
                return (bool) $extension->isHidden();
            }
        } catch (\Throwable $e) {
            // 알림 표시 실패가 페이지 전체 장애로 이어지지 않도록 fallback
        }

        return false;
    }

    /**
     * CacheInterface 인스턴스를 lazy 조회합니다 (재호환 캐시 읽기 전용).
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
