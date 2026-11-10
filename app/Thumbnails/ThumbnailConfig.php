<?php

namespace App\Thumbnails;

/**
 * 업로드 탭 썸네일 설정 → `core.thumbnail.*` config 대응 (부팅 주입·저장 직후 반영 공용)
 *
 * 부팅 때는 `SettingsServiceProvider::applyUploadConfig()` 가, 탭 저장 직후에는 `SettingsService` 가 같은 대응으로
 * config 를 채운다. 대응표를 두 곳에 두면 한쪽만 고쳤을 때 저장 직후 화면과 다음 부팅의 실효값이 갈린다 — 이 한 곳에 둔다.
 */
final class ThumbnailConfig
{
    /** @var array<string, string> 설정 키 → config 키(`core.thumbnail.` 뒤) */
    public const SETTING_KEYS = [
        'thumbnail_enabled' => 'enabled',
        'thumbnail_presets' => 'presets',
        'thumbnail_content_preset' => 'content_preset',
        'thumbnail_disk_public' => 'disk_public',
        'thumbnail_disk_gated' => 'disk_gated',
        'thumbnail_prune_enabled' => 'prune_enabled',
        'thumbnail_animated_original' => 'animated_original',
        'thumbnail_generate_per_minute' => 'generate_per_minute',
        'thumbnail_lock_wait_seconds' => 'lock_wait_seconds',
        'thumbnail_max_source_pixels' => 'max_source_pixels',
        'thumbnail_pregenerate_batch' => 'pregenerate_batch',
        'thumbnail_pregenerate_time_budget' => 'pregenerate_time_budget',
        'thumbnail_purge_time_budget' => 'purge_time_budget',
    ];

    /**
     * 서버 보호 값 (업로드 탭 「고급」 칸, `.env` G7_THUMBNAIL_*) — config 키(`core.thumbnail.` 뒤)
     *
     * 칸을 비우면 config 파일이 읽은 값(`core.thumbnail_server_defaults.*` — `.env` 가 있으면 그 값, 없으면 기본값)으로
     * 되돌린다. 건너뛰기만 하면 저장 직후 같은 요청 안에서 지우기 전 저장값이 남는다.
     *
     * @var list<string>
     */
    public const SERVER_LIMIT_KEYS = [
        'generate_per_minute',
        'lock_wait_seconds',
        'max_source_pixels',
        'pregenerate_batch',
        'pregenerate_time_budget',
        'purge_time_budget',
    ];

    /**
     * 업로드 탭 설정에서 config 값을 뽑습니다 (값이 없거나 빈 키는 뺀다 — config 기본값을 쓴다).
     *
     * 서버 보호 값은 빈 값이면 서버 기본값(`core.thumbnail_server_defaults`)을 넣는다. 키 자체가 없으면(`.env` 로 잠겨
     * 주입에서 빠진 키 포함) 건드리지 않는다.
     *
     * @param  array<string, mixed>  $uploadSettings  업로드 탭 설정
     * @return array<string, mixed> `core.thumbnail.{키}` → 값
     */
    public static function fromUploadSettings(array $uploadSettings): array
    {
        $result = [];

        foreach (self::SETTING_KEYS as $settingKey => $configKey) {
            if (! array_key_exists($settingKey, $uploadSettings)) {
                continue;
            }

            $value = $uploadSettings[$settingKey];
            $isServerLimit = in_array($configKey, self::SERVER_LIMIT_KEYS, true);

            if ($isServerLimit) {
                $result["core.thumbnail.{$configKey}"] = is_numeric($value)
                    ? (int) $value
                    : (int) config("core.thumbnail_server_defaults.{$configKey}");

                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            $result["core.thumbnail.{$configKey}"] = match ($configKey) {
                'enabled', 'prune_enabled', 'animated_original' => (bool) $value,
                'presets' => is_array($value) ? array_values($value) : null,
                default => (string) $value,
            };
        }

        return $result;
    }
}
