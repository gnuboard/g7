<?php

namespace Plugins\Sirsoft\Popup\Support;

use Plugins\Sirsoft\Popup\Enums\DismissMode;
use Plugins\Sirsoft\Popup\Enums\MobileStyle;
use Plugins\Sirsoft\Popup\Enums\PcPosition;

/**
 * 표시 방식 설정 — 모든 팝업에 똑같이 적용되는 전역 값을 범위 안으로 맞춰 돌려준다.
 *
 * 설정 화면 검증(`getSettingsSchema()`)을 거친 값이 기본이지만, 설정 파일을 직접 고쳤거나 옛 값이 남아도
 * 사용자 레이어가 깨지지 않게 읽을 때 한 번 더 범위를 맞춘다.
 */
class DisplaySettings
{
    /**
     * 플러그인 식별자
     */
    private const PLUGIN = 'sirsoft-popup';

    /**
     * 넘김 간격 허용 값(초)
     *
     * @var array<int, int>
     */
    public const INTERVALS = [4, 6, 8];

    /**
     * 사용자 레이어·관리자 미리보기가 쓰는 표시 방식을 반환합니다.
     *
     * @return array<string, mixed> 표시 방식
     */
    public static function current(): array
    {
        $interval = (int) self::value('autoplay_interval', 6);

        return [
            'pc_position' => self::enum('pc_position', PcPosition::values(), PcPosition::Center->value),
            'pc_left' => self::int('pc_left', 80, 0, 2000),
            'pc_top' => self::int('pc_top', 120, 0, 2000),
            'pc_width' => self::int('pc_width', 480, 240, 1200),
            'pc_height' => self::int('pc_height', 520, 200, 1000),
            'mobile_style' => self::enum('mobile_style', MobileStyle::values(), MobileStyle::Sheet->value),
            'dim_background' => self::bool('dim_background', true),
            'close_on_backdrop' => self::bool('close_on_backdrop', false),
            'autoplay' => self::bool('autoplay', true),
            'autoplay_interval' => in_array($interval, self::INTERVALS, true) ? $interval : 6,
            'dismiss_mode' => self::enum('dismiss_mode', DismissMode::values(), DismissMode::Today->value),
            'dismiss_days' => self::int('dismiss_days', 7, 1, 30),
        ];
    }

    /**
     * 설정 값을 읽습니다.
     *
     * @param  string  $key  설정 키
     * @param  mixed  $default  기본값
     * @return mixed 설정 값
     */
    private static function value(string $key, mixed $default): mixed
    {
        return plugin_setting(self::PLUGIN, $key, $default) ?? $default;
    }

    /**
     * 정수 설정을 범위 안으로 맞춰 읽습니다.
     *
     * @param  string  $key  설정 키
     * @param  int  $default  기본값
     * @param  int  $min  최솟값
     * @param  int  $max  최댓값
     * @return int 설정 값
     */
    private static function int(string $key, int $default, int $min, int $max): int
    {
        $value = self::value($key, $default);

        return is_numeric($value) ? max($min, min($max, (int) $value)) : $default;
    }

    /**
     * 참/거짓 설정을 읽습니다.
     *
     * @param  string  $key  설정 키
     * @param  bool  $default  기본값
     * @return bool 설정 값
     */
    private static function bool(string $key, bool $default): bool
    {
        $value = filter_var(self::value($key, $default), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        return $value ?? $default;
    }

    /**
     * 정해진 값 중 하나인 설정을 읽습니다.
     *
     * @param  string  $key  설정 키
     * @param  array<int, string>  $options  허용 값
     * @param  string  $default  기본값
     * @return string 설정 값
     */
    private static function enum(string $key, array $options, string $default): string
    {
        $value = (string) self::value($key, $default);

        return in_array($value, $options, true) ? $value : $default;
    }
}
