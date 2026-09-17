<?php

namespace App\Http\Controllers\Api\Admin\Analytics;

use App\Analytics\AnalyticsConfig;
use App\Contracts\Repositories\Analytics\ActionRepositoryInterface;
use App\Contracts\Repositories\Analytics\VisitRepositoryInterface;
use App\Enums\Analytics\PrivacyMode;
use App\Extension\HookManager;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Admin\Analytics\UpdateAnalyticsSettingsRequest;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;

/**
 * 방문자 통계 설정 API
 *
 * 설정 화면의 다섯 탭이 모두 이 한 쌍을 씁니다. 탭 하나만 저장해도 되도록 보낸 키만
 * 바꿉니다.
 *
 * 개인정보 수준을 더 엄격한 쪽으로 바꾸면, 이미 쌓인 기록에도 그 수준을 적용할지
 * 물어볼 수 있다는 사실을 응답에 함께 실어 화면이 모달을 띄웁니다.
 */
class SettingsController extends AdminBaseController
{
    /**
     * 설정 컨트롤러를 생성합니다.
     *
     * @param  SettingsService  $settings  설정 서비스
     * @param  VisitRepositoryInterface  $visits  방문 원장 저장소
     * @param  ActionRepositoryInterface  $actions  액션 원장 저장소
     */
    public function __construct(
        private readonly SettingsService $settings,
        private readonly VisitRepositoryInterface $visits,
        private readonly ActionRepositoryInterface $actions,
    ) {
        parent::__construct();
    }

    /**
     * 현재 설정을 조회합니다.
     *
     * @return JsonResponse 설정 응답
     */
    public function show(): JsonResponse
    {
        return $this->success('analytics.settings.fetch_success', [
            'settings' => $this->currentSettings(),
        ]);
    }

    /**
     * 설정을 저장합니다.
     *
     * @param  UpdateAnalyticsSettingsRequest  $request  설정 저장 요청
     * @return JsonResponse 저장 응답
     */
    public function update(UpdateAnalyticsSettingsRequest $request): JsonResponse
    {
        $before = AnalyticsConfig::fromSettings();
        $changes = $request->validated();

        if ($changes === []) {
            return $this->success('analytics.settings.save_success', [
                'settings' => $this->currentSettings(),
                'meta' => ['retro_anonymize_available' => false, 'ledger_rows' => $this->ledgerRows()],
            ]);
        }

        $this->settings->saveSettings(['_tab' => 'analytics', 'analytics' => $changes]);

        $after = AnalyticsConfig::fromSettings();

        HookManager::doAction('core.analytics.settings_updated', ['changes' => array_keys($changes)]);

        if ($before->privacyMode !== $after->privacyMode) {
            HookManager::doAction('core.analytics.privacy_mode_changed', [
                'from' => $before->privacyMode->value,
                'to' => $after->privacyMode->value,
            ]);
        }

        return $this->success('analytics.settings.save_success', [
            'settings' => $this->currentSettings(),
            'meta' => [
                'retro_anonymize_available' => $this->becameStricter($before, $after),
                'ledger_rows' => $this->ledgerRows(),
            ],
        ]);
    }

    /**
     * 저장된 설정 값을 모두 읽어옵니다.
     *
     * @return array<string, mixed> 설정 값
     */
    private function currentSettings(): array
    {
        return (array) g7_core_settings('analytics', []);
    }

    /**
     * 원장 행 수를 셉니다 (소급 적용 모달이 「몇 건에 적용되는가」를 보인다).
     *
     * @return array{visits: int, actions: int} 행 수
     */
    private function ledgerRows(): array
    {
        return [
            'visits' => $this->visits->countAll(),
            'actions' => $this->actions->countAll(),
        ];
    }

    /**
     * 개인정보 수준이 더 엄격해졌는지 판정합니다.
     *
     * 약해진 쪽으로 바꿀 때는 물어보지 않습니다 — 과거 기록은 이미 그보다 약한 정보를
     * 담고 있어 소급할 것이 없습니다.
     *
     * @param  AnalyticsConfig  $before  바꾸기 전 설정
     * @param  AnalyticsConfig  $after  바꾼 뒤 설정
     * @return bool 엄격해졌으면 true
     */
    private function becameStricter(AnalyticsConfig $before, AnalyticsConfig $after): bool
    {
        $rank = static fn (PrivacyMode $mode): int => match ($mode) {
            PrivacyMode::Full => 0,
            PrivacyMode::Consent => 1,
            PrivacyMode::Anonymous => 2,
        };

        return $rank($after->privacyMode) > $rank($before->privacyMode)
            || $after->ipMaskOctets > $before->ipMaskOctets
            || ($after->pseudonymizeUserId && ! $before->pseudonymizeUserId);
    }
}
