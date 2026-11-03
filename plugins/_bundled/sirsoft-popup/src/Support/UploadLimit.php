<?php

namespace Plugins\Sirsoft\Popup\Support;

use App\Support\ExecutionEnvironment;

/**
 * 팝업 이미지 업로드 한도 — 실제로 받을 수 있는 크기는 플러그인 설정과 서버 PHP 두 값 중 가장 작은 값이다.
 *
 * PHP 한도(`upload_max_filesize`·`post_max_size`)를 넘는 파일은 라라벨 검증 전에 잘려 빈 파일로 들어오므로,
 * 화면 안내와 업로더 한도는 `effectiveMb()` 를 쓰고 검증은 PHP 한도 초과를 따로 알린다.
 */
class UploadLimit
{
    /**
     * 플러그인 식별자
     */
    private const PLUGIN = 'sirsoft-popup';

    /**
     * 플러그인 설정의 최대 용량(MB)을 반환합니다(1~10 로 고정).
     *
     * @return int 설정 최대 용량(MB)
     */
    public static function settingMb(): int
    {
        $value = (int) (plugin_setting(self::PLUGIN, 'max_upload_mb', 5) ?? 5);

        return max(1, min(10, $value));
    }

    /**
     * 서버 PHP 업로드 한도(MB)를 반환합니다 — upload_max_filesize 와 post_max_size 중 작은 값.
     *
     * @return float 서버 한도(MB, 0 이면 제한 없음)
     */
    public static function serverLimitMb(): float
    {
        $limits = array_filter([
            self::iniSettingBytes('upload_max_filesize'),
            self::iniSettingBytes('post_max_size'),
        ], fn (int $bytes) => $bytes > 0);

        return $limits === [] ? 0.0 : round(min($limits) / 1048576, 2);
    }

    /**
     * 실제 업로드 한도(MB)를 반환합니다.
     *
     * @return float 실제 한도(MB)
     */
    public static function effectiveMb(): float
    {
        $server = self::serverLimitMb();
        $setting = (float) self::settingMb();

        return $server > 0 ? min($setting, $server) : $setting;
    }

    /**
     * 화면 안내용 세 값을 반환합니다.
     *
     * @return array{max_mb: int, server_limit_mb: float, effective_mb: float} 업로드 한도
     */
    public static function describe(): array
    {
        return [
            'max_mb' => self::settingMb(),
            'server_limit_mb' => self::serverLimitMb(),
            'effective_mb' => self::effectiveMb(),
        ];
    }

    /**
     * PHP 설정 값을 바이트로 읽습니다 — 호스팅이 `ini_get` 을 막았으면 읽을 수 없으므로 0(제한 없음과 같게 다룬다)이다.
     *
     * PHP 8 은 막힌 함수를 함수 표에서 지워 호출 자체가 `Error` 다. 막힌 서버에서는 플러그인 설정 한도만 남고,
     * PHP 한도를 넘는 파일은 종전처럼 업로드 검증에서 빈 파일로 걸러진다.
     *
     * @param  string  $key  php.ini 키(upload_max_filesize·post_max_size)
     * @return int 바이트(0 이면 제한 없음 또는 읽을 수 없음)
     */
    public static function iniSettingBytes(string $key): int
    {
        if (! ExecutionEnvironment::hasFunction('ini_get')) {
            return 0;
        }

        return self::iniBytes((string) ini_get($key));
    }

    /**
     * php.ini 크기 표기(예: 8M, 2G)를 바이트로 바꿉니다.
     *
     * @param  string  $value  ini 값
     * @return int 바이트 (0 이하면 제한 없음)
     */
    public static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return 0;
        }

        $number = (float) $value;
        $unit = strtolower(substr($value, -1));

        return (int) match ($unit) {
            'g' => $number * 1073741824,
            'm' => $number * 1048576,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
