<?php

namespace Plugins\Sirsoft\HeroSection\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\HeroSection\Models\PositionSetting;
use Plugins\Sirsoft\HeroSection\Services\PositionSettingService;
use Throwable;

/**
 * 위치별 표시 폭 힌트(`slot_widths`)를 플러그인 설정에 맞춰 둔다 — 사용자 화면은 첫 로드 때 플러그인 설정(`_global.plugins`)을 받으므로,
 * 배너 응답이 오기 전 스켈레톤을 실제와 같은 폭(본문 폭·화면 전체)으로 그릴 수 있다.
 *
 * 원본은 위치 설정 테이블 하나다. 플러그인 설정 저장 직전 필터에서 그 값으로 매번 다시 채우므로, 환경설정 화면이 옛 값을 되돌려
 * 보내도 덮이지 않는다. 표시 폭이 바뀌거나 설정을 초기화했거나 플러그인을 켜고 올렸을 때 다시 저장해 맞춘다.
 */
class SlotWidthHintListener implements HookListenerInterface
{
    /**
     * 플러그인 식별자
     */
    private const PLUGIN = 'sirsoft-hero_section';

    /**
     * 구독할 훅과 메서드 매핑 반환
     *
     * @return array<string, array<string, mixed>> 훅 이름 => 옵션
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.plugin_settings.filter_save_data' => ['method' => 'fillHint', 'type' => 'filter', 'priority' => 20],
            'sirsoft-hero_section.position_setting.after_update' => ['method' => 'onPositionSetting', 'priority' => 20, 'sync' => true],
            'core.plugin_settings.after_reset' => ['method' => 'onPluginEvent', 'priority' => 20, 'sync' => true],
            'core.plugins.after_activate' => ['method' => 'onPluginEvent', 'priority' => 20, 'sync' => true],
            'core.plugins.after_update' => ['method' => 'onPluginEvent', 'priority' => 20, 'sync' => true],
        ];
    }

    /**
     * SlotWidthHintListener 생성자
     *
     * @param  PositionSettingService  $positions  위치 설정 서비스
     */
    public function __construct(private PositionSettingService $positions) {}

    /**
     * 인터페이스 기본 핸들러 (개별 메서드에서 처리)
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 이 플러그인 설정을 저장하기 직전에 표시 폭 힌트를 위치 설정 값으로 채웁니다.
     *
     * @param  array<string, mixed>  $settings  저장할 설정
     * @param  string|null  $identifier  플러그인 식별자
     * @return array<string, mixed> 힌트를 채운 설정
     */
    public function fillHint(array $settings, ?string $identifier = null): array
    {
        if ($identifier !== self::PLUGIN) {
            return $settings;
        }

        $settings['slot_widths'] = $this->positions->slotWidths();

        return $settings;
    }

    /**
     * 표시 폭이 바뀐 저장이면 힌트를 다시 맞춥니다(다른 값만 바뀌었으면 플러그인 설정을 건드리지 않는다).
     *
     * @param  PositionSetting  $setting  저장된 설정 행
     * @param  array<string, mixed>  $snapshot  저장 전 값
     * @return void 반환값 없음
     */
    public function onPositionSetting(PositionSetting $setting, array $snapshot = []): void
    {
        $before = $snapshot['width'] ?? PositionSetting::DEFAULTS['width'];
        if ($before !== $setting->toSettings()['width']) {
            $this->sync();
        }
    }

    /**
     * 이 플러그인의 설정 초기화·활성화·업데이트 뒤 힌트를 다시 맞춥니다.
     *
     * @param  mixed  ...$args  코어 훅 인자 (첫 인자: 플러그인 식별자)
     * @return void 반환값 없음
     */
    public function onPluginEvent(...$args): void
    {
        if (($args[0] ?? null) === self::PLUGIN) {
            $this->sync();
        }
    }

    /**
     * 힌트만 다시 저장합니다 — 값은 저장 직전 필터({@see fillHint})가 채운다. 실패해도 위치 설정 저장은 막지 않는다
     * (스켈레톤 폭만 기본값으로 보인다).
     *
     * @return void 반환값 없음
     */
    private function sync(): void
    {
        try {
            app(PluginSettingsService::class)->save(self::PLUGIN, []);
        } catch (Throwable $e) {
            Log::error('히어로섹션 표시 폭 힌트 저장 실패', ['error' => $e->getMessage()]);
        }
    }
}
