<?php

namespace App\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Services\PasswordExpiryService;
use App\Services\SettingsService;

/**
 * 비밀번호 만료 기준 시작일 기록 리스너
 *
 * 만료 기준일은 `max(마지막 변경일 ?? 시작일, 시작일)` 이다 — 기능을 켠 날부터 계산해야
 * 켜자마자 기존 회원 전원이 만료되지 않는다(#736 확정 결정). 그 "켠 날" 을 서버가 직접 기록한다.
 * 저장 요청은 이 값을 바꿀 수 없다(탭 저장은 규칙이 없어 버려지고, 단건 저장은 거부된다).
 *
 * 두 저장 경로를 모두 덮는다.
 * - 탭 저장(`saveSettings`): `core.settings.filter_save_data` 필터에서 꺼짐→켜짐이면 시작일을 실어
 *   반환한다. 저장은 기존 병합(`array_merge($existing, $tab)`)이 처리하므로, 켜짐 유지 저장은
 *   시작일을 싣지 않아 기존 값이 그대로 남는다.
 * - 단건 저장(`setSetting`): `before_set` 에서 저장 전 값을 잡아 두고, `after_set` 에서 꺼짐→켜짐이면
 *   `SettingsService::setSetting()` 으로 시작일을 기록한다(캐시 무효화 포함 경로).
 *
 * 리스너 인스턴스는 훅마다 새로 만들어지므로 before→after 사이 값은 정적 속성에 둔다.
 *
 * @since 7.1.0
 */
class RecordPasswordExpiryStartListener implements HookListenerInterface
{
    /**
     * 만료 토글 키
     */
    private const ENABLED_KEY = 'security.password_expiry_enabled';

    /**
     * 시작일 키
     */
    private const STARTED_AT_KEY = PasswordExpiryService::STARTED_AT_KEY;

    /**
     * 단건 저장 직전의 만료 토글 값 (before_set → after_set 전달)
     */
    private static ?bool $enabledBeforeSet = null;

    /**
     * 구독할 훅 목록 반환
     *
     * @return array 훅 이름 → 메서드/타입/우선순위 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.settings.filter_save_data' => ['method' => 'filterSaveData', 'type' => 'filter', 'priority' => 10],
            'core.settings.before_set' => ['method' => 'beforeSet', 'sync' => true, 'priority' => 10],
            'core.settings.after_set' => ['method' => 'afterSet', 'sync' => true, 'priority' => 10],
        ];
    }

    /**
     * 기본 훅 핸들러 (HookListenerInterface 필수 메서드)
     *
     * @param  mixed  ...$args  훅 인자
     * @return void
     */
    public function handle(...$args): void
    {
        // 개별 메서드에서 처리
    }

    /**
     * 보안 탭 저장에서 만료가 꺼짐→켜짐이면 시작일을 실어 반환합니다.
     *
     * @param  mixed  $settings  저장할 설정 데이터 (`_tab` 포함)
     * @return mixed 시작일을 더한(또는 그대로인) 설정 데이터
     */
    public function filterSaveData(mixed $settings): mixed
    {
        if (! is_array($settings) || ($settings['_tab'] ?? null) !== 'security') {
            return $settings;
        }

        $security = $settings['security'] ?? null;

        if (! is_array($security) || ! array_key_exists('password_expiry_enabled', $security)) {
            return $settings;
        }

        if (! $this->truthy($security['password_expiry_enabled']) || $this->storedEnabled()) {
            return $settings;
        }

        $settings['security']['password_expiry_started_at'] = now()->toIso8601String();

        return $settings;
    }

    /**
     * 단건 저장 직전의 만료 토글 값을 잡아 둡니다.
     *
     * @param  mixed  $key  설정 키
     * @param  mixed  $value  저장할 값
     * @return void
     */
    public function beforeSet(mixed $key = null, mixed $value = null): void
    {
        if ($key !== self::ENABLED_KEY) {
            return;
        }

        self::$enabledBeforeSet = $this->storedEnabled();
    }

    /**
     * 단건 저장으로 만료가 꺼짐→켜짐이 되면 시작일을 기록합니다.
     *
     * @param  mixed  $key  설정 키
     * @param  mixed  $value  저장한 값
     * @param  mixed  $result  저장 성공 여부
     * @return void
     */
    public function afterSet(mixed $key = null, mixed $value = null, mixed $result = null): void
    {
        if ($key !== self::ENABLED_KEY) {
            return;
        }

        $before = self::$enabledBeforeSet;
        self::$enabledBeforeSet = null;

        if ($result !== true || $before !== false || ! $this->truthy($value)) {
            return;
        }

        // 다른 키 저장이라 이 리스너가 다시 들어와도 위의 키 검사에서 곧바로 빠진다(재진입 안전).
        app(SettingsService::class)->setSetting(self::STARTED_AT_KEY, now()->toIso8601String());
    }

    /**
     * 저장소에 저장된 현재 만료 토글 값을 반환합니다.
     *
     * @return bool 켜져 있으면 true
     */
    private function storedEnabled(): bool
    {
        $stored = app(ConfigRepositoryInterface::class)->getCategory('security');

        return $this->truthy(is_array($stored) ? ($stored['password_expiry_enabled'] ?? false) : false);
    }

    /**
     * 느슨한 참 판정 (true·"1"·"true"·1).
     *
     * @param  mixed  $value  판정할 값
     * @return bool 참이면 true
     */
    private function truthy(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
