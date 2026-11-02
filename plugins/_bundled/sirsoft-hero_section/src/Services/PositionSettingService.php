<?php

namespace Plugins\Sirsoft\HeroSection\Services;

use App\Extension\HookManager;
use App\Helpers\TimezoneHelper;
use Illuminate\Support\Facades\DB;
use Plugins\Sirsoft\HeroSection\Enums\HeroPosition;
use Plugins\Sirsoft\HeroSection\Enums\SlideWidth;
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
                // 문구 상자 폭·등장 지연 입력 범위 — 저장 규칙(BannerFieldRules)과 같은 상수라 화면이 허용한 값이 저장에서 422 가 되지 않는다
                'text_width_limits' => ['min' => Banner::TEXT_WIDTH_MIN, 'max' => Banner::TEXT_WIDTH_MAX],
                'animation_delay_limits' => ['min' => 0, 'max' => Banner::ANIMATION_DELAY_MAX],
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
     * 위치별로 사이트 상단 메뉴를 배너 위에 겹치는지 반환합니다 — 메뉴 겹치기를 켰고 화면 전체 폭일 때만 true. 사용자 화면이
     * 첫 로드 때 받는 플러그인 설정 힌트(`slot_overlays`)의 원본이다(받는 동안의 스켈레톤도 메뉴 밑으로 들어가 배너가 와도 밀리지 않게).
     *
     * @return array<string, bool> 위치 키 => 겹침 여부
     */
    public function slotOverlays(): array
    {
        $saved = $this->settings->allKeyed();
        $overlays = [];
        foreach (HeroPosition::cases() as $position) {
            $settings = isset($saved[$position->value]) ? $saved[$position->value]->toSettings() : PositionSetting::DEFAULTS;
            $overlays[$position->value] = $settings['header_overlay'] && $settings['width'] === SlideWidth::Full->value;
        }

        return $overlays;
    }

    /**
     * 한 위치의 설정 값을 반환합니다(행이 없으면 기본값).
     *
     * @param  HeroPosition  $position  위치
     * @return array{autoplay: bool, interval_seconds: int, effect: string, width: string, header_overlay: bool, pagination: string} 설정 값
     */
    public function settingsFor(HeroPosition $position): array
    {
        return $this->settings->findByPosition($position->value)?->toSettings() ?? PositionSetting::DEFAULTS;
    }

    /**
     * 위치 설정을 저장합니다.
     *
     * @param  HeroPosition  $position  위치
     * @param  array{autoplay: bool, interval_seconds: int, effect: string, width?: string, header_overlay?: bool, pagination?: string}  $data  검증된 설정 값 (width·header_overlay·pagination 을 빼면 지금 값 유지)
     * @param  int|null  $actorId  바꾼 회원 ID
     * @return PositionSetting 저장된 설정 행
     */
    public function update(HeroPosition $position, array $data, ?int $actorId): PositionSetting
    {
        // 행을 먼저 보장하고 잠가 고친다 — 같은 위치를 동시에 처음 저장하면 둘 다 「행 없음」으로 보고 만들다가 유일 키 충돌(500)이 났다.
        // 처음 저장의 「바뀌기 전」은 기본값 행이고, 겹친 저장은 앞 저장이 끝난 값을 「바뀌기 전」으로 본다
        $this->settings->ensureExists($position->value);
        [$setting, $snapshot] = DB::transaction(function () use ($position, $data, $actorId) {
            $before = $this->settings->findForUpdate($position->value);
            $snapshot = $before->toArray();

            return [$this->settings->update($before, $data + ['updated_by' => $actorId]), $snapshot];
        });

        HookManager::doAction('sirsoft-hero_section.position_setting.after_update', $setting, $snapshot);

        return $setting;
    }
}
