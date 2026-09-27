<?php

namespace App\Http\Controllers\Api\Admin\Analytics;

use App\Analytics\AnalyticsClock;
use App\Analytics\AnalyticsConfig;
use App\Contracts\Repositories\Analytics\ActionRepositoryInterface;
use App\Contracts\Repositories\Analytics\VisitRepositoryInterface;
use App\Extension\HookManager;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Admin\Analytics\UpdateAnalyticsSettingsRequest;
use App\Services\Analytics\PrivacyService;
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
                'retro_anonymize_available' => PrivacyService::tightensPrivacy($before, $after),
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
        $settings = (array) g7_core_settings('analytics', []);

        // 클라우드 목록 갱신 시각만 저장 형식이 ISO8601 이다(저장값은 어느 시간대인지 스스로
        // 말해야 하므로 오프셋을 유지한다). 화면으로 나갈 때는 다른 통계 시각과 같은
        // **오프셋 없는 사이트 표준시**로 바꾼다 — 오프셋을 실으면 `| datetime` 파이프가
        // 브라우저 시간대로 다시 옮겨 사이트 기준이 깨진다(#682 S3 라운드 7, 설계 결정 「응답
        // 경계에서 변환」).
        $settings['cloud_ip_ranges_updated_at'] = AnalyticsClock::storedToSiteDateTimeString(
            $settings['cloud_ip_ranges_updated_at'] ?? null,
        );

        return $settings;
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
}
