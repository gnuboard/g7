<?php

namespace Plugins\Sirsoft\HeroSection\Services;

use App\Extension\HookManager;
use App\Helpers\TimezoneHelper;
use Plugins\Sirsoft\HeroSection\Enums\HeroPosition;
use Plugins\Sirsoft\HeroSection\Models\Banner;
use Plugins\Sirsoft\HeroSection\Models\PositionSetting;
use Plugins\Sirsoft\HeroSection\Repositories\Contracts\PositionSettingRepositoryInterface;
use Plugins\Sirsoft\HeroSection\Support\UploadLimit;

/**
 * 위치별 슬라이더 설정 서비스
 *
 * 위치 목록은 `HeroPosition`(고정 목록, 위치 키 = 확장점 이름)이고, 저장된 행이 없으면 기본값을 쓴다.
 */
class PositionSettingService
{
    /**
     * PositionSettingService 생성자
     *
     * @param  PositionSettingRepositoryInterface  $settings  위치 설정 Repository
     * @param  PreviewFrameResolver  $frames  관리자 미리보기 구좌 틀 해석기
     */
    public function __construct(
        private PositionSettingRepositoryInterface $settings,
        private PreviewFrameResolver $frames,
    ) {}

    /**
     * 위치 목록과 위치별 설정, 업로드 한도를 반환합니다(관리자 화면용).
     *
     * @return array{positions: array<int, array<string, mixed>>, upload: array<string, int|float>} 위치·설정·업로드 한도
     */
    public function overview(): array
    {
        $saved = $this->settings->allKeyed();

        $positions = array_map(fn (HeroPosition $position) => [
            'key' => $position->value,
            'extension_point' => $position->value,
            'label_key' => $position->labelKey(),
            'settings' => isset($saved[$position->value]) ? $saved[$position->value]->toSettings() : PositionSetting::DEFAULTS,
        ], HeroPosition::cases());

        return [
            'positions' => $positions,
            'upload' => UploadLimit::describe(),
            'editor' => [
                'timezone' => TimezoneHelper::getSiteTimezone(),
                'server_now' => now()->toIso8601String(),
                'default_locale' => config('app.site_locale', config('app.locale')),
                'locales' => array_values(config('app.supported_locales', [])),
                'focus_limits' => ['min' => Banner::FOCUS_MIN, 'max' => Banner::FOCUS_MAX],
                'preview_frame' => $this->frames->resolve(),
            ],
        ];
    }

    /**
     * 위치별 표시 폭을 반환합니다(행이 없으면 content) — 사용자 화면이 첫 로드 때 받는 플러그인 설정 힌트(`slot_widths`)의 원본.
     *
     * @return array<string, string> 위치 키 => content|full
     */
    public function slotWidths(): array
    {
        $saved = $this->settings->allKeyed();
        $widths = [];
        foreach (HeroPosition::cases() as $position) {
            $widths[$position->value] = isset($saved[$position->value]) ? $saved[$position->value]->toSettings()['width'] : PositionSetting::DEFAULTS['width'];
        }

        return $widths;
    }

    /**
     * 한 위치의 설정 값을 반환합니다(행이 없으면 기본값).
     *
     * @param  HeroPosition  $position  위치
     * @return array{autoplay: bool, interval_seconds: int, effect: string, width: string} 설정 값
     */
    public function settingsFor(HeroPosition $position): array
    {
        return $this->settings->findByPosition($position->value)?->toSettings() ?? PositionSetting::DEFAULTS;
    }

    /**
     * 위치 설정을 저장합니다.
     *
     * @param  HeroPosition  $position  위치
     * @param  array{autoplay: bool, interval_seconds: int, effect: string, width?: string}  $data  검증된 설정 값 (width 를 빼면 지금 값 유지)
     * @param  int|null  $actorId  바꾼 회원 ID
     * @return PositionSetting 저장된 설정 행
     */
    public function update(HeroPosition $position, array $data, ?int $actorId): PositionSetting
    {
        $before = $this->settings->findByPosition($position->value);
        $snapshot = $before?->toArray() ?? (PositionSetting::DEFAULTS + ['position' => $position->value]);

        $setting = $this->settings->upsert($position->value, $data + ['updated_by' => $actorId]);

        HookManager::doAction('sirsoft-hero_section.position_setting.after_update', $setting, $snapshot);

        return $setting;
    }
}
