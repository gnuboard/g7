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
 *  - 권한이 걸린 이미지 썸네일: `thumbnails`·`s3`·비공개 첨부 저장소 카탈로그 디스크(스트림으로만 낸다). 공개 자산 디스크는
 *    `s3` 를 빼고 금지, 이 서버에서 쓸 수 없는 디스크는 뺀다. `ThumbnailStore::diskFor(true)` 도 같은 판정을 쓴다.
 *
 * 라벨은 드라이버 카탈로그(`available_drivers`)와 같은 로케일 맵이다 — 화면이 `$localized(label)` 로 같은 방식으로 그린다.
 */
class ThumbnailDiskOptions
{
    /** 공개 자산 카탈로그에도 있지만 권한 원본 썸네일 디스크로 허용하는 코어 디스크 (같은 버킷 경고 대상) */
    public const SHARED_BUCKET_DISK = 's3';

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
     * 허용 = `thumbnails` ∪ `s3` ∪ 비공개 첨부 저장소 카탈로그(`default` 제외) − (공개 자산 카탈로그 − `s3`).
     *  - 공개 자산 디스크(`public`, CDN 플러그인 디스크 등)는 직접 URL 로 열리는 트리라 권한 원본의 파생본을 두지 않는다.
     *  - 코어 `s3` 는 공개 자산 카탈로그에도 있지만 권한 썸네일은 늘 서버를 거쳐 스트림으로만 내므로 계속 허용한다
     *    (같은 버킷을 공개 자산 디스크로도 쓰면 저장 응답과 업로드 탭이 경고한다 — `SettingsService::storageWarnings`).
     *  - 이 서버에서 쓸 수 없는 디스크(어댑터가 없는 s3, `filesystems.disks` 에 정의되지 않은 플러그인 디스크)는 뺀다.
     *
     * 코어 디스크(`thumbnails`, `s3`)는 썸네일 전용 라벨(`settings.thumbnail_disks.{id}`), 플러그인 디스크는 카탈로그 라벨을 쓴다.
     *
     * @return list<array{id: string, label: array<string, string>}> 선택지
     */
    public function forGated(): array
    {
        $options = [];

        foreach ($this->gatedCandidates() as $id => $label) {
            $options[] = $label === null ? $this->option($id) : ['id' => $id, 'label' => $label];
        }

        return $options;
    }

    /**
     * 종류별 허용 디스크 id
     *
     * 권한 쪽은 라벨을 만들지 않고 판정만 한다 — 썸네일 서빙마다 `ThumbnailStore::diskFor(true)` 가 부른다.
     *
     * @param  string  $kind  `public` 또는 `gated`
     * @return list<string> 디스크 id
     */
    public function ids(string $kind): array
    {
        if ($kind === ThumbnailStore::KIND_GATED) {
            return array_keys($this->gatedCandidates());
        }

        return array_map(fn (array $option) => $option['id'], $this->forPublic());
    }

    /**
     * 권한 원본 썸네일 디스크로 허용하는 디스크와 카탈로그 라벨
     *
     * @return array<string, array<string, string>|null> 디스크 id → 카탈로그 라벨(코어 디스크는 null — 썸네일 전용 라벨을 쓴다)
     */
    private function gatedCandidates(): array
    {
        $candidates = array_fill_keys(ThumbnailStore::GATED_ALLOWED_DISKS, null);

        foreach ($this->drivers->getAvailableDrivers('private_attachment') as $driver) {
            $id = is_array($driver) && is_string($driver['id'] ?? null) ? $driver['id'] : '';

            if ($id === '' || $id === PrivateAttachmentDisk::DEFAULT || array_key_exists($id, $candidates)) {
                continue;
            }

            $candidates[$id] = is_array($driver['label'] ?? null) ? $driver['label'] : null;
        }

        // 공개 자산 카탈로그 − s3 (`public` 은 카탈로그를 필터로 덜어 내도 늘 금지)
        $forbidden = ['public'];
        foreach ($this->drivers->getAvailableDrivers('public_asset') as $driver) {
            if (is_array($driver) && is_string($driver['id'] ?? null) && $driver['id'] !== '') {
                $forbidden[] = $driver['id'];
            }
        }
        $forbidden = array_diff($forbidden, [self::SHARED_BUCKET_DISK]);

        $allowed = [];

        foreach ($candidates as $id => $label) {
            if (in_array($id, $forbidden, true)) {
                continue;
            }

            if ($id !== ThumbnailStore::PRIVATE_DISK
                && (! $this->drivers->isDriverUsable('storage', $id) || config("filesystems.disks.{$id}") === null)) {
                continue;
            }

            $allowed[$id] = $label;
        }

        return $allowed;
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
