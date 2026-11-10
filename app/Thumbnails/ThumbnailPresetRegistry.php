<?php

namespace App\Thumbnails;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Extension\HookManager;
use App\Thumbnails\DTO\ThumbnailSpec;
use Illuminate\Http\Request;

/**
 * 썸네일 규격 레지스트리 — 출처 세 갈래를 한 목록으로 합칩니다.
 *
 *  - 코어 고정 슬롯 xs·sm·md·lg·xl: 기본 표는 `config/settings/defaults.json` 의 `upload.thumbnail_presets`
 *    에서만 읽는다(하드코딩 금지). 운영자가 고친 값은 저장 목록의 같은 이름 행이 덮는다.
 *  - 코어 등록 `og`: 크기는 SEO 탭 `og_image_default_width/height`, 자르기·가운데. 업로드 탭에서 못 고친다.
 *  - 확장 규격: 필터 `core.thumbnail.filter_presets`(값: 행 목록). 이름은 `{확장 식별자}.{키}` 만 받는다.
 *    비활성 확장은 필터를 걸지 않으므로 빠지고, 운영자가 고친 값은 저장 목록에 남아 재활성 때 되살아난다.
 *    행의 `label`(사람이 읽는 이름, 다국어 키)은 `extensionDefaults()` 가 그대로 넘기고 관리자 표만 쓴다(규격 값 아님).
 *  - 운영자 규격: 저장 목록의 `source=custom` 행. 이름 `^[a-z][a-z0-9_]{1,31}$`, 예약어와 파이프 리터럴 낱말(`LITERAL_NAMES`) 금지.
 */
class ThumbnailPresetRegistry
{
    /** @var list<string> 코어 고정 슬롯 이름 */
    public const FIXED_SLOTS = ['xs', 'sm', 'md', 'lg', 'xl'];

    /** 코어 등록 og 규격 이름 */
    public const OG = 'og';

    /** @var list<string> 운영자 규격에 쓸 수 없는 이름 */
    public const RESERVED = ['xs', 'sm', 'md', 'lg', 'xl', 'og'];

    /**
     * @var list<string> 규격 이름으로 받지 않는 낱말 — 레이아웃 파이프 `thumb('true')` 의 인자는 따옴표를 벗긴 뒤
     *                   불리언, null, undefined 로 바뀌어(사람 화면 `PipeRegistry.ts` parseArgValue, 봇 화면 `App\Seo\PipeRegistry`)
     *                   규격이 붙지 않는다(원본 또는 기본 sm). 형식 정규식은 이 네 낱말을 통과시키므로 따로 막는다.
     */
    public const LITERAL_NAMES = ['true', 'false', 'null', 'undefined'];

    /** 운영자 규격 이름 형식 */
    public const CUSTOM_NAME_PATTERN = '/^[a-z][a-z0-9_]{1,31}$/';

    /** 확장 규격 이름 형식 — `{vendor-name}.{key}` */
    public const EXTENSION_NAME_PATTERN = '/^([a-z0-9]+-[a-z0-9_]+)\.([a-z0-9_]{1,32})$/';

    /** `?w=` 허용 범위 상한 */
    private const MAX_NUMERIC_WIDTH = 10000;

    /** @var list<ThumbnailSpec>|null 요청 안 캐시 */
    private ?array $cache = null;

    /** @var string|null 캐시 기준 (설정이 바뀌면 다시 만든다) */
    private ?string $cacheKey = null;

    /**
     * ThumbnailPresetRegistry 생성자
     *
     * @param  ConfigRepositoryInterface  $configRepository  설정 저장소 (기본값 표 읽기)
     */
    public function __construct(
        private readonly ConfigRepositoryInterface $configRepository
    ) {}

    /**
     * 모든 규격(꺼진 것 포함)을 돌려줍니다 — 고정 → og → 확장 → 운영자 순.
     *
     * @return list<ThumbnailSpec> 규격 목록
     */
    public function all(): array
    {
        $saved = $this->savedRows();
        $extensionRows = $this->extensionDefaults();
        $key = md5(serialize([$saved, $extensionRows, $this->ogSize(), config('attachment.image_quality')]));

        if ($this->cache !== null && $this->cacheKey === $key) {
            return $this->cache;
        }

        $savedByName = [];
        foreach ($saved as $row) {
            if (is_array($row) && is_string($row['name'] ?? null)) {
                $savedByName[$row['name']] ??= $row;
            }
        }

        $specs = [];

        foreach ($this->fixedDefaults() as $default) {
            $merged = $this->overlay($default, $savedByName[$default['name']] ?? null);
            // 저장 행이 망가져(가로 0 등) 규격을 만들 수 없으면 기본 행으로 — 고정 슬롯이 목록에서 사라지면
            // `?size=sm` 이 조용히 원본을 내고 관리 화면의 표에서도 그 행이 빠진다(ThumbnailPresetSettings 와 같은 폴백).
            $spec = ThumbnailSpec::fromArray($merged, ThumbnailSpec::SOURCE_CORE, true)
                ?? ThumbnailSpec::fromArray($default, ThumbnailSpec::SOURCE_CORE, true);

            if ($spec !== null) {
                $specs[] = $spec;
            }
        }

        $specs[] = $this->ogSpec();

        foreach ($extensionRows as $name => $default) {
            $merged = $this->overlay($default, $savedByName[$name] ?? null);
            $spec = ThumbnailSpec::fromArray($merged, $default['source'], true)
                ?? ThumbnailSpec::fromArray($default, $default['source'], true);

            if ($spec !== null) {
                $specs[] = $spec;
            }
        }

        $taken = array_map(fn (ThumbnailSpec $spec) => $spec->name, $specs);

        foreach ($saved as $row) {
            if (! is_array($row) || ($row['source'] ?? ThumbnailSpec::SOURCE_CUSTOM) !== ThumbnailSpec::SOURCE_CUSTOM) {
                continue;
            }

            $name = (string) ($row['name'] ?? '');

            if (! self::isValidCustomName($name) || in_array($name, $taken, true)) {
                continue;
            }

            $spec = ThumbnailSpec::fromArray($row, ThumbnailSpec::SOURCE_CUSTOM, false);

            if ($spec !== null) {
                $specs[] = $spec;
                $taken[] = $name;
            }
        }

        $this->cache = $specs;
        $this->cacheKey = $key;

        return $specs;
    }

    /**
     * 사용 중인 규격만 돌려줍니다.
     *
     * @return list<ThumbnailSpec> 켜진 규격
     */
    public function enabled(): array
    {
        return array_values(array_filter($this->all(), fn (ThumbnailSpec $spec) => $spec->enabled));
    }

    /**
     * 이름으로 규격을 찾습니다(꺼진 규격 포함).
     *
     * @param  string  $name  규격 이름
     * @return ThumbnailSpec|null 규격
     */
    public function find(string $name): ?ThumbnailSpec
    {
        foreach ($this->all() as $spec) {
            if ($spec->name === $name) {
                return $spec;
            }
        }

        return null;
    }

    /**
     * 요청의 `size`·`w` 를 규격으로 해석합니다.
     *
     * `size`(문자열)가 우선이고, 없으면 `w`(정수 1~10000)를 숫자 요청 대상 규격 중 「가로 ≥ w 인 최소」로,
     * 그런 규격이 없으면 가로가 가장 큰 규격으로 올려 맞춘다. 배열 파라미터·같은 키 두 번·비정수·미정의·꺼진 규격은 null.
     *
     * @param  Request  $request  요청
     * @return ThumbnailSpec|null 규격 (해석 실패면 null)
     */
    public function resolve(Request $request): ?ThumbnailSpec
    {
        $occurrences = ThumbnailQuery::occurrences($request);

        if (($occurrences['size'] ?? 0) > 1 || ($occurrences['w'] ?? 0) > 1) {
            return null;
        }

        if (($occurrences['size'] ?? 0) === 1) {
            $size = $request->query('size');

            if (! is_string($size) || $size === '') {
                return null;
            }

            $spec = $this->find($size);

            return $spec !== null && $spec->enabled ? $spec : null;
        }

        if (($occurrences['w'] ?? 0) === 1) {
            $width = $request->query('w');

            if (! is_string($width) || ! ctype_digit($width)) {
                return null;
            }

            return $this->resolveNumeric((int) $width);
        }

        return null;
    }

    /**
     * 숫자 요청을 규격으로 올려 맞춥니다.
     *
     * @param  int  $width  요청 가로
     * @return ThumbnailSpec|null 규격 (범위 밖·후보 없음이면 null)
     */
    public function resolveNumeric(int $width): ?ThumbnailSpec
    {
        if ($width < 1 || $width > self::MAX_NUMERIC_WIDTH) {
            return null;
        }

        $candidates = array_values(array_filter($this->enabled(), fn (ThumbnailSpec $spec) => $spec->numeric));

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn (ThumbnailSpec $a, ThumbnailSpec $b) => $a->width <=> $b->width);

        foreach ($candidates as $spec) {
            if ($spec->width >= $width) {
                return $spec;
            }
        }

        return $candidates[array_key_last($candidates)];
    }

    /**
     * 등록 직후 미리 만들 규격들
     *
     * @return list<ThumbnailSpec> 켜진 규격 중 `pregenerate` 가 켜진 것
     */
    public function pregenerated(): array
    {
        return array_values(array_filter($this->enabled(), fn (ThumbnailSpec $spec) => $spec->pregenerate));
    }

    /**
     * 화면(편집기·프론트 URL 유틸)에 내보낼 규격 목록
     *
     * @return list<array{name: string, width: int, height: ?int, fit: string, numeric: bool, format: string}> 켜진 규격
     */
    public function forFrontend(): array
    {
        return array_map(fn (ThumbnailSpec $spec) => [
            'name' => $spec->name,
            'width' => $spec->width,
            'height' => $spec->height,
            'fit' => $spec->fit,
            'numeric' => $spec->numeric,
            'format' => $spec->format,
        ], $this->enabled());
    }

    /**
     * 확장이 등록한 규격의 기본값 (이름 → 행). 「기본값으로」 되돌리기의 근거.
     *
     * @return array<string, array<string, mixed>> 이름 → 기본 행 (`source` 는 확장 식별자)
     */
    public function extensionDefaults(): array
    {
        $rows = HookManager::applyFilters('core.thumbnail.filter_presets', []);

        if (! is_array($rows)) {
            return [];
        }

        $result = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['name'] ?? null)) {
                continue;
            }

            if (! preg_match(self::EXTENSION_NAME_PATTERN, $row['name'], $matches)) {
                continue;
            }

            // 출처는 이름 앞부분(확장 식별자)이 정한다 — 행이 다른 출처를 적어도 따르지 않는다.
            $row['source'] = $matches[1];

            if (ThumbnailSpec::fromArray($row) === null || isset($result[$row['name']])) {
                continue;
            }

            $result[$row['name']] = $row;
        }

        return $result;
    }

    /**
     * 코어 고정 슬롯의 기본 표 (defaults.json)
     *
     * @return list<array<string, mixed>> 고정 슬롯 행
     */
    public function fixedDefaults(): array
    {
        $rows = $this->configRepository->getDefaults()['upload']['thumbnail_presets'] ?? [];
        $result = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && in_array($row['name'] ?? null, self::FIXED_SLOTS, true)) {
                $result[] = $row + ['source' => ThumbnailSpec::SOURCE_CORE];
            }
        }

        return $result;
    }

    /**
     * 운영자 규격 이름이 형식·예약어를 지키는지 (파이프 리터럴 낱말 `LITERAL_NAMES` 도 예약어로 본다)
     *
     * @param  string  $name  이름
     * @return bool 쓸 수 있으면 true
     */
    public static function isValidCustomName(string $name): bool
    {
        return preg_match(self::CUSTOM_NAME_PATTERN, $name) === 1
            && ! in_array($name, self::RESERVED, true)
            && ! in_array($name, self::LITERAL_NAMES, true);
    }

    /**
     * 요청 안 캐시를 비웁니다 (설정 저장 직후·테스트).
     *
     * @return void 반환값 없음
     */
    public function flush(): void
    {
        $this->cache = null;
        $this->cacheKey = null;
    }

    /**
     * 저장된 규격 행 목록 (설정 주입값, 없으면 기본 표)
     *
     * @return list<array<string, mixed>> 저장 행
     */
    private function savedRows(): array
    {
        $rows = config('core.thumbnail.presets');

        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * og 규격 — SEO 탭의 기본 og 이미지 크기
     *
     * @return ThumbnailSpec og 규격
     */
    private function ogSpec(): ThumbnailSpec
    {
        [$width, $height] = $this->ogSize();

        return new ThumbnailSpec(
            name: self::OG,
            source: ThumbnailSpec::SOURCE_CORE,
            width: $width,
            height: $height,
            fit: ThumbnailSpec::FIT_COVER,
            anchor: 'center',
            locked: true,
        );
    }

    /**
     * SEO 탭의 og 이미지 크기
     *
     * @return array{0: int, 1: int} [가로, 세로]
     */
    private function ogSize(): array
    {
        $width = (int) g7_core_settings('seo.og_image_default_width', 1200);
        $height = (int) g7_core_settings('seo.og_image_default_height', 630);

        return [$width > 0 ? $width : 1200, $height > 0 ? $height : 630];
    }

    /**
     * 기본 행 위에 저장 행의 「값」만 덮습니다 — 이름·출처는 기본 행 것을 지킨다.
     *
     * @param  array<string, mixed>  $default  기본 행
     * @param  array<string, mixed>|null  $saved  저장 행
     * @return array<string, mixed> 병합 행
     */
    private function overlay(array $default, ?array $saved): array
    {
        if ($saved === null) {
            return $default;
        }

        $values = array_intersect_key($saved, array_flip(ThumbnailPresetSettings::VALUE_KEYS));

        return array_merge($default, $values, ['name' => $default['name'], 'source' => $default['source']]);
    }
}
