<?php

namespace App\Support;

use App\Services\DriverRegistryService;
use App\Thumbnails\ThumbnailStore;
use Illuminate\Support\Facades\Lang;

/**
 * 업로드 탭 「저장 위치」 select 의 썸네일 디스크 선택지
 *
 * 저장 검증(`SaveSettingsRequest`)과 화면 메타(`SettingsController::buildSettingsMeta` 의 `thumbnail_disk_options`)가
 * 같은 목록을 써야 화면에 보이는 값은 저장되고 보이지 않는 값은 422 가 된다. 한쪽만 바뀌면 고를 수 있는데
 * 저장이 막히거나, 고를 수 없는 값이 직접 호출로 저장된다.
 *
 *  - 공개 이미지 썸네일: 비공개 `thumbnails` + 공개 자산 디스크 카탈로그(`none` 제외 — 공개 자산 디스크를 고르면 302 로 직접 URL 을 낸다)
 *  - 권한이 걸린 이미지 썸네일: `ThumbnailStore::GATED_ALLOWED_DISKS`(스트림으로만 낸다). 이 서버에서 쓸 수 없는 s3 는 뺀다.
 *
 * 라벨은 드라이버 카탈로그(`available_drivers`)와 같은 로케일 맵이다 — 화면이 `$localized(label)` 로 같은 방식으로 그린다.
 */
class ThumbnailDiskOptions
{
    /**
     * ThumbnailDiskOptions 생성자
     *
     * @param  DriverRegistryService  $drivers  드라이버 카탈로그
     */
    public function __construct(
        private readonly DriverRegistryService $drivers
    ) {}

    /**
     * 화면 메타용 선택지 두 묶음
     *
     * @return array{public: list<array{id: string, label: array<string, string>}>, gated: list<array{id: string, label: array<string, string>}>} 종류별 선택지
     */
    public function all(): array
    {
        return [
            ThumbnailStore::KIND_PUBLIC => $this->forPublic(),
            ThumbnailStore::KIND_GATED => $this->forGated(),
        ];
    }

    /**
     * 공개 원본 썸네일 디스크 선택지
     *
     * @return list<array{id: string, label: array<string, string>}> 선택지
     */
    public function forPublic(): array
    {
        $options = [$this->option(ThumbnailStore::PRIVATE_DISK)];
        $taken = [ThumbnailStore::PRIVATE_DISK];

        foreach ($this->drivers->getAvailableDrivers('public_asset') as $driver) {
            $id = is_array($driver) && is_string($driver['id'] ?? null) ? $driver['id'] : '';

            if ($id === '' || $id === 'none' || in_array($id, $taken, true)) {
                continue;
            }

            $options[] = [
                'id' => $id,
                'label' => is_array($driver['label'] ?? null) ? $driver['label'] : $this->labels("settings.thumbnail_disks.{$id}", $id),
            ];
            $taken[] = $id;
        }

        return $options;
    }

    /**
     * 권한 원본 썸네일 디스크 선택지
     *
     * @return list<array{id: string, label: array<string, string>}> 선택지
     */
    public function forGated(): array
    {
        $options = [];

        foreach (ThumbnailStore::GATED_ALLOWED_DISKS as $id) {
            if ($id !== ThumbnailStore::PRIVATE_DISK && ! $this->drivers->isDriverUsable('storage', $id)) {
                continue;
            }

            $options[] = $this->option($id);
        }

        return $options;
    }

    /**
     * 종류별 허용 디스크 id
     *
     * @param  string  $kind  `public` 또는 `gated`
     * @return list<string> 디스크 id
     */
    public function ids(string $kind): array
    {
        $options = $kind === ThumbnailStore::KIND_GATED ? $this->forGated() : $this->forPublic();

        return array_map(fn (array $option) => $option['id'], $options);
    }

    /**
     * 코어 라벨 키(`settings.thumbnail_disks.{id}`)로 선택지 하나를 만듭니다.
     *
     * @param  string  $id  디스크 id
     * @return array{id: string, label: array<string, string>} 선택지
     */
    private function option(string $id): array
    {
        return ['id' => $id, 'label' => $this->labels("settings.thumbnail_disks.{$id}", $id)];
    }

    /**
     * 활성 로케일 전부의 라벨 (키가 없으면 id)
     *
     * @param  string  $key  다국어 키
     * @param  string  $fallback  키가 없을 때 쓸 값
     * @return array<string, string> 로케일 → 라벨
     */
    private function labels(string $key, string $fallback): array
    {
        $labels = [];

        foreach (config('app.translatable_locales', ['ko', 'en']) as $locale) {
            $text = Lang::get($key, [], $locale);
            $labels[$locale] = is_string($text) && $text !== $key ? $text : $fallback;
        }

        return $labels;
    }
}
