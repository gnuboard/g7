<?php

namespace App\Thumbnails;

use App\Thumbnails\DTO\ThumbnailSpec;
use Illuminate\Support\Facades\Lang;

/**
 * 썸네일 규격 설정의 저장·표시 변환
 *
 * 설정 저장소(`JsonConfigRepository::readCategory`)는 카테고리 1단계만 병합해 목록은 통째로 교체된다.
 * 그래서 화면이 보낸 표를 그대로 저장하면 고정 슬롯 행이 빠진 저장본이 생길 수 있다 — 저장 때
 * `mergeForSave()` 가 고정 슬롯을 늘 보충하고, 읽을 때도 레지스트리가 빠진 고정 행을 기본값으로 채운다.
 * 키가 아예 없는 기존 설치는 기본 표를 그대로 쓰므로 업그레이드 스텝이 필요 없다.
 */
class ThumbnailPresetSettings
{
    /** @var list<string> 운영자가 고칠 수 있는 값 키 (이름·출처는 잠긴 행에서 바꿀 수 없다) */
    public const VALUE_KEYS = [
        'width', 'height', 'fit', 'anchor', 'quality', 'format',
        'upscale', 'pregenerate', 'numeric', 'enabled',
    ];

    /**
     * ThumbnailPresetSettings 생성자
     *
     * @param  ThumbnailPresetRegistry  $registry  규격 레지스트리
     */
    public function __construct(
        private readonly ThumbnailPresetRegistry $registry
    ) {}

    /**
     * 관리자 업로드 탭 「썸네일 규격」 표 데이터.
     *
     * 행마다 `kind`(fixed·og·extension·custom)를 붙인다. 확장 행은 「기본값으로」에 쓸 `defaults` 를,
     * og 행은 크기를 SEO 탭에서 고친다는 표시(`size_readonly`)를 싣는다.
     *
     * 모든 행에 사람이 읽는 이름 `label`(로케일 → 문구 맵, 없으면 null)을 싣는다. 이름은 확장 등록 행의 `label` 에서만
     * 온다 — 고정·og 는 이름 자체(xs~xl, og)가 뜻이고, 운영자 규격은 운영자가 정한 이름이 곧 표시 이름이라 null 이다.
     * 저장 행에 끼어든 `label` 은 따르지 않는다. 화면은 `$localized()` 로 현재 언어를 고르고 null 이면 규격 이름을 보인다.
     *
     * @return list<array<string, mixed>> 표 행
     */
    public function presentRows(): array
    {
        $extensionDefaults = $this->registry->extensionDefaults();
        $rows = [];

        foreach ($this->registry->all() as $spec) {
            $row = $spec->toArray();
            $row['label'] = null;

            if ($spec->name === ThumbnailPresetRegistry::OG) {
                $row['kind'] = 'og';
                $row['size_readonly'] = true;
            } elseif (in_array($spec->name, ThumbnailPresetRegistry::FIXED_SLOTS, true)) {
                $row['kind'] = 'fixed';
            } elseif (isset($extensionDefaults[$spec->name])) {
                $row['kind'] = 'extension';
                $row['label'] = $this->localizedLabel($extensionDefaults[$spec->name]['label'] ?? null);
                $row['defaults'] = ThumbnailSpec::fromArray($extensionDefaults[$spec->name], $spec->source, true)?->toArray();
            } else {
                $row['kind'] = 'custom';
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * 화면이 보낸 표를 저장 목록으로 바꿉니다.
     *
     *  - 고정 슬롯은 늘 5행 — 보낸 행이 없으면 기존 저장값(없으면 기본 표)을 쓴다. 값만 받고 이름은 잠근다.
     *  - og 는 저장하지 않는다(크기는 SEO 탭이 소유한다).
     *  - 확장 행은 지금 켜진 확장의 행이면 값만 받고, 지금 꺼진 확장의 기존 저장 행은 그대로 남긴다(재활성 때 되살린다).
     *  - 나머지는 운영자 규격 — 형식·예약어·중복은 FormRequest 가 먼저 거르고, 여기서는 남은 것을 정규화만 한다.
     *
     * @param  array<int, mixed>  $input  화면이 보낸 행 목록
     * @param  array<int, mixed>  $previous  기존 저장 행 목록
     * @return list<array<string, mixed>> 저장할 행 목록
     */
    public function mergeForSave(array $input, array $previous = []): array
    {
        $inputByName = $this->indexByName($input);
        $previousByName = $this->indexByName($previous);
        $extensionDefaults = $this->registry->extensionDefaults();
        $result = [];

        foreach ($this->registry->fixedDefaults() as $default) {
            $source = $inputByName[$default['name']] ?? $previousByName[$default['name']] ?? $default;
            $result[] = $this->normalized($default, $source, ThumbnailSpec::SOURCE_CORE);
        }

        foreach ($extensionDefaults as $name => $default) {
            if (isset($inputByName[$name])) {
                $result[] = $this->normalized($default, $inputByName[$name], $default['source']);
            } elseif (isset($previousByName[$name])) {
                $result[] = $this->normalized($default, $previousByName[$name], $default['source']);
            }
        }

        // 지금 꺼진 확장의 저장 행 보존
        foreach ($previousByName as $name => $row) {
            $source = (string) ($row['source'] ?? '');

            if (preg_match(ThumbnailPresetRegistry::EXTENSION_NAME_PATTERN, $name) === 1
                && ! isset($extensionDefaults[$name])
                && $source !== ThumbnailSpec::SOURCE_CUSTOM) {
                $result[] = $row;
            }
        }

        $taken = array_map(fn (array $row) => $row['name'], $result);

        foreach ($input as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = (string) ($row['name'] ?? '');

            if ($name === ThumbnailPresetRegistry::OG || in_array($name, $taken, true)
                || ! ThumbnailPresetRegistry::isValidCustomName($name)) {
                continue;
            }

            $spec = ThumbnailSpec::fromArray($row, ThumbnailSpec::SOURCE_CUSTOM, false);

            if ($spec === null) {
                continue;
            }

            $result[] = $this->storedRow($spec);
            $taken[] = $name;
        }

        return $result;
    }

    /**
     * 확장이 등록한 규격 이름을 로케일 맵으로 바꿉니다.
     *
     * 썸네일 원본 제공자 이름(`ThumbnailSourceProvider::label()`)과 같은 관례로 다국어 키(`{확장 식별자}::messages.…`)를 받아
     * 활성 로케일(`app.translatable_locales`)마다 풀고, 드라이버 카탈로그 라벨처럼 로케일 맵으로 낸다. 언어팩이 확장의
     * 백엔드 번역 파일에 그 로케일 문구를 더하면 그대로 실린다. 로케일 맵(`['ko' => …, 'en' => …]`)을 직접 주어도 받는다.
     * 번역이 없는 로케일(키가 그대로 돌아옴)·빈 값은 맵에서 빼고, 남는 것이 없으면 null 이다.
     *
     * @param  mixed  $label  등록 행의 `label` (다국어 키 또는 로케일 맵)
     * @return array<string, string>|null 로케일 → 문구 (없으면 null)
     */
    private function localizedLabel(mixed $label): ?array
    {
        $result = [];

        if (is_string($label) && trim($label) !== '') {
            $key = trim($label);
            $locales = config('app.translatable_locales', ['ko', 'en']);

            foreach (is_array($locales) ? $locales : [] as $locale) {
                if (! is_string($locale) || ! Lang::hasForLocale($key, $locale)) {
                    continue;
                }

                $text = Lang::get($key, [], $locale, false);

                if (is_string($text) && trim($text) !== '') {
                    $result[$locale] = trim($text);
                }
            }
        } elseif (is_array($label)) {
            foreach ($label as $locale => $text) {
                if (is_string($locale) && is_string($text) && trim($text) !== '') {
                    $result[$locale] = trim($text);
                }
            }
        }

        return $result === [] ? null : $result;
    }

    /**
     * 이름 → 행 색인 (앞의 행이 이긴다)
     *
     * @param  array<int, mixed>  $rows  행 목록
     * @return array<string, array<string, mixed>> 색인
     */
    private function indexByName(array $rows): array
    {
        $index = [];

        foreach ($rows as $row) {
            if (is_array($row) && is_string($row['name'] ?? null) && $row['name'] !== '') {
                $index[$row['name']] ??= $row;
            }
        }

        return $index;
    }

    /**
     * 잠긴 행의 이름·출처는 기본 행 것을, 값은 보낸 행 것을 써서 정규화합니다.
     *
     * @param  array<string, mixed>  $default  기본 행
     * @param  array<string, mixed>  $source  값을 가져올 행
     * @param  string  $origin  출처
     * @return array<string, mixed> 저장 행
     */
    private function normalized(array $default, array $source, string $origin): array
    {
        $merged = array_merge($default, array_intersect_key($source, array_flip(self::VALUE_KEYS)));
        $merged['name'] = $default['name'];
        $spec = ThumbnailSpec::fromArray($merged, $origin, true) ?? ThumbnailSpec::fromArray($default, $origin, true);

        return $spec !== null ? $this->storedRow($spec) : $default;
    }

    /**
     * 저장 행 — 잠금 표시는 저장하지 않는다(출처로 다시 정해진다).
     *
     * @param  ThumbnailSpec  $spec  규격
     * @return array<string, mixed> 저장 행
     */
    private function storedRow(ThumbnailSpec $spec): array
    {
        $row = $spec->toArray();
        unset($row['locked']);

        return $row;
    }
}
