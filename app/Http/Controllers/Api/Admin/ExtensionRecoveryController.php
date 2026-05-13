<?php

namespace App\Http\Controllers\Api\Admin;

use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Contracts\Extension\TemplateManagerInterface;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Enums\DeactivationReason;
use App\Extension\CoreVersionChecker;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Extension\AutoDeactivatedListRequest;
use App\Http\Requests\Extension\DismissAlertRequest;
use App\Http\Requests\Extension\RecoverRequest;
use App\Services\ExtensionCompatibilityAlertService;
use Illuminate\Http\JsonResponse;

/**
 * 확장 호환성 복구/조회/dismiss 엔드포인트.
 *
 * 코어 버전 비호환으로 자동 비활성화된 확장의 (1) 영속 알림 데이터 소스, (2) 사용자별
 * dismiss, (3) 재호환 시 원클릭 복구 (재활성화) 를 담당합니다.
 *
 * @since 7.0.0-beta.4
 */
class ExtensionRecoveryController extends AdminBaseController
{
    /**
     * @param  ExtensionCompatibilityAlertService  $alertService  dismiss 상태 관리 서비스
     */
    public function __construct(
        private readonly ExtensionCompatibilityAlertService $alertService,
    ) {
        parent::__construct();
    }

    /**
     * 자동 비활성화된 확장 목록 조회 (상단 배너 + 대시보드 카드 데이터 소스).
     *
     * @param  AutoDeactivatedListRequest  $request  요청
     * @return JsonResponse 자동 비활성화된 확장 배열 ['plugins' => [...], 'modules' => [...], 'templates' => [...]]
     */
    public function autoDeactivated(AutoDeactivatedListRequest $request): JsonResponse
    {
        $repos = [
            'plugins' => app(PluginRepositoryInterface::class),
            'modules' => app(ModuleRepositoryInterface::class),
            'templates' => app(TemplateRepositoryInterface::class),
        ];

        // 사용자별 dismiss 이력 (Listener 와 동일 SSoT — Service 가 단일 진입점)
        $dismissedIds = $this->alertService->getDismissedAlertIds($request->user()?->id);

        $result = [];
        foreach ($repos as $type => $repo) {
            $singular = rtrim($type, 's');
            $result[$type] = $repo->findAutoDeactivated()
                ->reject(fn ($record) => $this->isHiddenExtension($singular, $record->identifier))
                ->reject(fn ($record) => in_array("compat_{$type}_{$record->identifier}", $dismissedIds, true))
                ->map(function ($record) {
                    return [
                        'identifier' => $record->identifier,
                        'incompatible_required_version' => $record->incompatible_required_version,
                        'deactivated_at' => $record->deactivated_at,
                    ];
                })->values();
        }

        return ResponseHelper::success('extensions.alerts.auto_deactivated_listed', [
            'items' => $result,
            'current_core_version' => CoreVersionChecker::getCoreVersion(),
        ]);
    }

    /**
     * 비호환으로 자동 비활성화된 확장의 원클릭 복구 (재활성화).
     *
     * @param  RecoverRequest  $request  요청
     * @param  string  $type  확장 타입 (module|plugin|template)
     * @param  string  $identifier  확장 식별자
     * @return JsonResponse 복구 결과
     */
    public function recover(RecoverRequest $request, string $type, string $identifier): JsonResponse
    {
        $repo = $this->resolveRepository($type);
        if (! $repo) {
            return ResponseHelper::error('extensions.errors.invalid_type', 422);
        }

        $record = $repo->findByIdentifier($identifier);
        if (! $record) {
            return ResponseHelper::error('extensions.errors.not_found', 404);
        }

        // hidden 확장은 사용자 노출 대상 아님 — 직접 endpoint 호출로 우회 차단
        if ($this->isHiddenExtension($type, $identifier)) {
            return ResponseHelper::error('extensions.errors.hidden_extension', 422, [
                'error_code' => 'hidden_extension',
            ]);
        }

        $reasonValue = $record->deactivated_reason instanceof \BackedEnum
            ? $record->deactivated_reason->value
            : $record->deactivated_reason;

        if ($reasonValue !== DeactivationReason::IncompatibleCore->value) {
            return ResponseHelper::error('extensions.errors.not_auto_deactivated', 422, [
                'error_code' => 'not_auto_deactivated',
            ]);
        }

        // 재호환 재검증 (글로벌 핸들러가 비호환 시 422 + core_version_mismatch 자동 변환)
        CoreVersionChecker::validateExtension(
            $record->incompatible_required_version,
            $identifier,
            $type,
        );

        // 활성화 호출 (force=false — 검증 이미 통과했으므로 재검증 의도)
        $manager = $this->resolveManager($type);
        match ($type) {
            'plugin' => $manager->activatePlugin($identifier),
            'module' => $manager->activateModule($identifier),
            'template' => $manager->activateTemplate($identifier),
        };

        return ResponseHelper::success('extensions.alerts.recovered_success', [
            'extension_type' => $type,
            'identifier' => $identifier,
        ]);
    }

    /**
     * 호환성 알림 dismiss (사용자별).
     *
     * @param  DismissAlertRequest  $request  요청
     * @param  string  $type  확장 타입 (module|plugin|template)
     * @param  string  $identifier  확장 식별자
     * @return JsonResponse dismiss 결과
     */
    public function dismiss(DismissAlertRequest $request, string $type, string $identifier): JsonResponse
    {
        $userId = $request->user()?->id;
        $alertId = "compat_{$type}s_{$identifier}";
        $this->alertService->dismissAlert($alertId, $userId);

        // 재호환 알림도 함께 dismiss (해당 alert 라면 즉시 사라지나 캐시 만료/감지 갱신 시 재노출)
        $this->alertService->dismissAlert("recover_{$type}s_{$identifier}", $userId);

        return ResponseHelper::success('extensions.alerts.dismissed', [
            'alert_id' => $alertId,
        ]);
    }

    /**
     * 타입에 해당하는 Repository 를 반환합니다.
     *
     * @param  string  $type  확장 타입
     * @return mixed|null Repository 또는 null
     */
    protected function resolveRepository(string $type): mixed
    {
        return match ($type) {
            'plugin' => app(PluginRepositoryInterface::class),
            'module' => app(ModuleRepositoryInterface::class),
            'template' => app(TemplateRepositoryInterface::class),
            default => null,
        };
    }

    /**
     * 타입에 해당하는 Manager 를 반환합니다.
     *
     * @param  string  $type  확장 타입
     * @return mixed Manager 인스턴스
     */
    protected function resolveManager(string $type): mixed
    {
        return match ($type) {
            'plugin' => app(PluginManagerInterface::class),
            'module' => app(ModuleManagerInterface::class),
            'template' => app(TemplateManagerInterface::class),
        };
    }

    /**
     * 확장이 hidden(학습용 샘플 등) manifest 플래그를 가졌는지 판정합니다.
     *
     * Listener 의 동일 로직과 정합 — 자동 비활성화/재호환 알림을 사용자에게 노출하지 않는 정책.
     *
     * @param  string  $singularType  module|plugin|template
     * @param  string  $identifier  확장 식별자
     * @return bool
     */
    protected function isHiddenExtension(string $singularType, string $identifier): bool
    {
        try {
            $manager = $this->resolveManager($singularType);
            if ($manager === null) {
                return false;
            }

            $extension = match ($singularType) {
                'plugin' => $manager->getPlugin($identifier),
                'module' => $manager->getModule($identifier),
                'template' => $manager->getTemplate($identifier),
            };

            if (is_array($extension)) {
                return ! empty($extension['hidden']);
            }
            if (is_object($extension) && method_exists($extension, 'isHidden')) {
                return (bool) $extension->isHidden();
            }
        } catch (\Throwable $e) {
            // 판정 실패 시 안전 측 — hidden 아닌 것으로 간주 (기존 노출 정책 유지)
        }

        return false;
    }
}
