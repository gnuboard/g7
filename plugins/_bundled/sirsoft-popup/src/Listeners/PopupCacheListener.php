<?php

namespace Plugins\Sirsoft\Popup\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Plugins\Sirsoft\Popup\Services\PublicPopupService;

/**
 * 사용자 화면 공개 캐시 비우기 — 팝업·이미지·순서·표시 방식이 바뀌면 모든 대상·언어 캐시를 무효로 한다.
 *
 * 저장 직후 사용자 화면에 바로 반영돼야 하므로 모두 `sync` 로 돈다(큐로 미루면 그사이 옛 팝업이 보인다).
 * 플러그인 비활성화 때는 Plugin::deactivate() 가 비운다. 코어 환경설정의 드라이버 탭(공개 자산 디스크)을 저장해도
 * 비운다 — 캐시에 담긴 팝업 이미지의 `direct_url` 이 옛 디스크 기준으로 묵지 않게.
 */
class PopupCacheListener implements HookListenerInterface
{
    /**
     * 구독할 훅과 메서드 매핑 반환
     *
     * @return array<string, array<string, mixed>> 훅 이름 => 옵션
     */
    public static function getSubscribedHooks(): array
    {
        $sync = ['method' => 'invalidate', 'priority' => 5, 'sync' => true];

        return [
            'sirsoft-popup.popup.after_create' => $sync,
            'sirsoft-popup.popup.after_update' => $sync,
            'sirsoft-popup.popup.after_delete' => $sync,
            'sirsoft-popup.popup.after_bulk_status' => $sync,
            'sirsoft-popup.popup.after_bulk_copy' => $sync,
            'sirsoft-popup.popup.after_bulk_delete' => $sync,
            'sirsoft-popup.popup.after_reorder' => $sync,
            'sirsoft-popup.popup_image.after_change' => $sync,
            'core.plugin_settings.after_save' => ['method' => 'onPluginSettingsSaved', 'priority' => 5, 'sync' => true],
            'core.plugin_settings.after_reset' => ['method' => 'onPluginSettingsSaved', 'priority' => 5, 'sync' => true],
            'core.settings.after_save' => ['method' => 'onCoreSettingsSaved', 'priority' => 5, 'sync' => true],
        ];
    }

    /**
     * PopupCacheListener 생성자
     *
     * @param  PublicPopupService  $popups  공개 노출 서비스
     */
    public function __construct(private PublicPopupService $popups) {}

    /**
     * 인터페이스 기본 핸들러 (개별 메서드에서 처리)
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 공개 캐시를 비웁니다.
     *
     * @param  mixed  ...$args  훅 인자 (쓰지 않음)
     * @return void 반환값 없음
     */
    public function invalidate(...$args): void
    {
        $this->popups->invalidate();
    }

    /**
     * 이 플러그인의 설정(표시 방식·캐시 시간)이 바뀌면 공개 캐시를 비웁니다.
     *
     * @param  mixed  ...$args  코어 훅 인자 (첫 인자: 플러그인 식별자)
     * @return void 반환값 없음
     */
    public function onPluginSettingsSaved(...$args): void
    {
        if (($args[0] ?? null) === 'sirsoft-popup') {
            $this->popups->invalidate();
        }
    }

    /**
     * 코어 환경설정의 드라이버 탭이 저장되면 공개 캐시를 비웁니다 — 공개 자산 디스크가 바뀌면 새 업로드의 주소 모양이
     * 달라진다. 탭 이름을 알 수 없는 호출(인자 모양 변경)도 비운다(비우는 쪽이 안전).
     *
     * @param  mixed  ...$args  코어 훅 인자 (string $tab, array $settings, mixed $result)
     * @return void 반환값 없음
     */
    public function onCoreSettingsSaved(...$args): void
    {
        $tab = $args[0] ?? null;
        if (! is_string($tab) || $tab === 'drivers') {
            $this->popups->invalidate();
        }
    }
}
