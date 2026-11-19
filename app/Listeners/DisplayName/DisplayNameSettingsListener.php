<?php

namespace App\Listeners\DisplayName;

use App\Contracts\Extension\HookListenerInterface;
use App\Services\DisplayNameSettingsService;

/**
 * 환경설정 > 회원 저장에 표시 이름 처리를 잇는 리스너.
 *
 * - 저장 전(필터): 저장하지 않는 지시 값(「로그인 시 닉네임 입력 요구」)을 떼어 두고, 영역 묶음에 꺼진 확장의 기존 값을 보존해 합친다.
 * - 저장 뒤(액션): 이름이 구워진 캐시를 비우고, 지시가 있었으면 닉네임 항목의 「기존 회원에게도 입력 요구」를 켠다.
 *
 * @since 7.2.0
 */
class DisplayNameSettingsListener implements HookListenerInterface
{
    /**
     * 이번 저장에서 닉네임 입력 요구를 함께 켤지 (저장 한 번에만 쓴다)
     */
    private static bool $requireNickname = false;

    /**
     * 구독할 훅 목록 반환
     *
     * @return array 훅 이름 → 메서드/우선순위 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.settings.filter_save_data' => ['method' => 'filterSaveData', 'type' => 'filter', 'priority' => 20],
            'core.settings.after_save' => ['method' => 'onSettingsSave', 'priority' => 30, 'sync' => true],
        ];
    }

    /**
     * 기본 핸들러 (사용하지 않는다 — 훅마다 메서드를 지정했다)
     *
     * @param  mixed  ...$args  훅 인자
     * @return void
     */
    public function handle(...$args): void {}

    /**
     * 회원 탭 저장 데이터를 저장할 모양으로 맞춥니다.
     *
     * @param  mixed  $settings  저장 데이터 (`_tab` 과 탭 이름 아래의 값)
     * @return mixed 맞춘 저장 데이터
     */
    public function filterSaveData(mixed $settings): mixed
    {
        self::$requireNickname = false;

        if (! is_array($settings) || ($settings['_tab'] ?? null) !== 'member' || ! is_array($settings['member'] ?? null)) {
            return $settings;
        }

        [$settings['member'], self::$requireNickname] = app(DisplayNameSettingsService::class)->prepareForSave($settings['member']);

        return $settings;
    }

    /**
     * 회원 탭 저장이 끝난 뒤의 처리를 합니다.
     *
     * @param  mixed  ...$args  훅 인자 ($tab, $settings, $result)
     * @return void
     */
    public function onSettingsSave(...$args): void
    {
        $requireNickname = self::$requireNickname;
        self::$requireNickname = false;

        if (($args[0] ?? null) !== 'member' || ($args[2] ?? null) === false) {
            return;
        }

        app(DisplayNameSettingsService::class)->afterSave($requireNickname);
    }
}
