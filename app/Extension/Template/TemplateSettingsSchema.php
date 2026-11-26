<?php

namespace App\Extension\Template;

use App\Support\Favicon\FaviconPolicy;
use App\Support\Template\TemplateSettingCollections;
use Illuminate\Support\Facades\File;

/**
 * 템플릿 환경설정 선언 파일(`templates/{id}/config/settings/defaults.json`) 해석기
 *
 * 모듈 설정과 같은 모양이다 — 섹션(`_meta.categories`)마다 기본값(`defaults.{섹션}.{키}`)·노출
 * (`frontend_schema.{섹션}`)을 두고, 저장도 섹션별 파일이다. 모듈·플러그인은 PHP 클래스가 검증 스키마를 돌려주지만
 * 템플릿에는 PHP 클래스가 없으므로 칸 선언(`schema.{섹션}.{키}`)과 섹션 라벨(`sections.{섹션}`)까지 이 파일에 둔다.
 *
 * ```json
 * {
 *   "_meta": { "version": "1.0.0", "categories": ["branding", "header"] },
 *   "sections": { "branding": { "label": "$t:settings.sections.branding" } },
 *   "defaults": { "branding": { "favicon": [], "accent": "blue" }, "header": { "max_boards": 5 } },
 *   "schema": {
 *     "branding": {
 *       "favicon": { "type": "attachments", "max_files": 1, "accept": ["ico","png","svg","gif","jpg","jpeg","webp"] },
 *       "accent": { "type": "enum", "options": ["blue", "green"] }
 *     },
 *     "header": { "max_boards": { "type": "integer", "min": 1, "max": 10 } }
 *   },
 *   "frontend_schema": { "branding": { "expose": true, "fields": { "favicon_url": { "expose": true }, "accent": { "expose": true } } } }
 * }
 * ```
 *
 * 칸 경로는 `섹션.키` 다. 표준 키({@see self::STANDARD_KEYS})는 코어가 의미를 아는 칸이라 규격이 고정이다.
 * 계산 키는 저장하지 않고 읽을 때 만든다 — 파비콘 칸의 `branding.favicon_url`, 이미지 칸의 `{섹션}.{키}_url`.
 */
final class TemplateSettingsSchema
{
    /**
     * 칸 종류
     *
     * @var array<int, string>
     */
    public const TYPES = ['string', 'text', 'integer', 'boolean', 'enum', 'url', 'link', 'email', 'color', 'multilingual', 'list', 'attachments', 'image', 'menu'];

    /**
     * 목록(list) 행 안에 둘 수 있는 칸 종류 — 첨부·중첩 목록은 행 안에 둘 수 없다
     *
     * @var array<int, string>
     */
    public const LIST_ITEM_TYPES = ['string', 'text', 'integer', 'boolean', 'enum', 'url', 'link', 'email', 'color', 'multilingual'];

    /**
     * 첨부 파일을 담는 칸 종류
     *
     * @var array<int, string>
     */
    public const FILE_TYPES = ['attachments', 'image'];

    /**
     * 이미지 칸의 기본 허용 확장자
     *
     * @var array<int, string>
     */
    public const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'gif'];

    /**
     * 설정 화면 부품 묶음 규약 경로(`dist/` 상대) — 템플릿이 자기 설정 화면 부품을 공급하는 자리.
     */
    public const SETTINGS_ASSETS = ['js' => 'settings/settings.iife.js', 'css' => 'settings/settings.css'];

    /**
     * 표준 키 — 코어가 읽는 칸
     */
    public const KEY_FAVICON = FaviconPolicy::TEMPLATE_KEY;

    public const KEY_ACCENT = 'branding.accent';

    public const KEY_COLOR_SCHEME_DEFAULT = 'branding.color_scheme_default';

    /**
     * @var array<int, string>
     */
    public const STANDARD_KEYS = [self::KEY_FAVICON, self::KEY_ACCENT, self::KEY_COLOR_SCHEME_DEFAULT];

    /**
     * 기본 화면 모드 표준 키가 받을 수 있는 값
     *
     * @var array<int, string>
     */
    public const COLOR_SCHEME_VALUES = ['system', 'light', 'dark'];

    /**
     * 다른 주인(코어·모듈)이 가진 값과 이름이 겹쳐 경고하는 키 — 템플릿 설정으로 다시 저장하지 말라는 규칙(문서 §「두지 않는 것」)
     *
     * @var array<int, string>
     */
    public const OWNED_ELSEWHERE_KEYS = [
        'site_name', 'site_title', 'site_description', 'site_logo', 'site_favicon', 'site_url',
        'registration_enabled', 'locale', 'locales', 'language', 'languages', 'currency', 'timezone',
        'company_name', 'business_number', 'ceo_name', 'mail_order_number', 'privacy_officer',
        'meta_title', 'meta_description', 'google_analytics_id',
    ];

    /**
     * @param  string  $identifier  템플릿 식별자
     * @param  array<int, string>  $categories  섹션 순서
     * @param  array<string, array<string, mixed>>  $sections  섹션 라벨·안내
     * @param  array<string, array<string, mixed>>  $defaults  기본값 (섹션 => 키 => 값, 선언된 칸 전부 채움)
     * @param  array<string, array<string, array<string, mixed>>>  $fields  칸 선언 (섹션 => 키 => 정의)
     * @param  array<string, mixed>  $frontendSchema  노출 스키마
     */
    private function __construct(
        public readonly string $identifier,
        public readonly array $categories,
        public readonly array $sections,
        public readonly array $defaults,
        public readonly array $fields,
        public readonly array $frontendSchema,
    ) {}

    /**
     * 선언 파일 경로를 돌려줍니다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @param  string|null  $templateRoot  템플릿 디렉토리 (생략하면 설치 디렉토리 `templates/{id}`)
     * @return string 절대 경로
     */
    public static function path(string $identifier, ?string $templateRoot = null): string
    {
        $root = $templateRoot ?? base_path('templates'.DIRECTORY_SEPARATOR.$identifier);

        return rtrim($root, '/\\').DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'settings'.DIRECTORY_SEPARATOR.'defaults.json';
    }

    /**
     * 템플릿이 공급한 설정 화면 레이아웃 소스 경로를 돌려줍니다 — `.g7.html` 이 있으면 그것, 없으면 짝 `.json`,
     * 둘 다 없으면 null(그 템플릿은 설정 화면이 없어 목록 「설정」이 잠긴다 — 공용 화면은 없다).
     *
     * 템플릿 자기 `layouts/` 밖(`settings/`)에 둔다. `layouts/` 안이면 그 템플릿의 자기 화면으로도 등록돼
     * 사용자 템플릿에 관리자 화면이 섞인다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @param  string|null  $templateRoot  템플릿 디렉토리
     * @return string|null 소스 경로
     */
    public static function layoutPath(string $identifier, ?string $templateRoot = null): ?string
    {
        return self::firstLayoutFile(rtrim($templateRoot ?? base_path('templates'.DIRECTORY_SEPARATOR.$identifier), '/\\').DIRECTORY_SEPARATOR.'settings');
    }

    /**
     * 관리자 템플릿이 다른 템플릿 설정 화면을 덮어쓴 레이아웃 경로를 돌려줍니다 — 없으면 null.
     *
     * 플러그인·모듈 화면 덮어쓰기와 같은 자리(`layouts/overrides/{템플릿id}/`)다. 교차 등록은 이 파일이 있으면
     * 템플릿 원본 대신 이 파일을 쓴다 — 행 소유(`source_identifier`)는 설정 화면을 공급한 템플릿 그대로다.
     *
     * @param  string  $adminTemplate  덮어쓰는 관리자 템플릿 식별자
     * @param  string  $identifier  설정 화면을 공급한 템플릿 식별자
     * @return string|null 덮어쓴 레이아웃 소스 경로
     */
    public static function overrideLayoutPath(string $adminTemplate, string $identifier): ?string
    {
        return self::firstLayoutFile(base_path('templates'.DIRECTORY_SEPARATOR.$adminTemplate.DIRECTORY_SEPARATOR.'layouts'.DIRECTORY_SEPARATOR.'overrides'.DIRECTORY_SEPARATOR.$identifier));
    }

    /**
     * 템플릿이 공급한 설정 화면 부품 묶음 중 실제로 빌드된 파일의 `dist/` 상대 경로를 돌려줍니다.
     *
     * 규약 경로는 고정이다(`templates/{id}/dist/settings/settings.iife.js`·`templates/{id}/dist/settings/settings.css`). 관리자 화면이 설치된 템플릿
     * (꺼진 것 포함) 묶음을 플러그인 묶음처럼 싣는다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @param  string|null  $templateRoot  템플릿 디렉토리
     * @return array{js?: string, css?: string} 종류 => `dist/` 상대 경로
     */
    public static function settingsAssets(string $identifier, ?string $templateRoot = null): array
    {
        $dist = rtrim($templateRoot ?? base_path('templates'.DIRECTORY_SEPARATOR.$identifier), '/\\').DIRECTORY_SEPARATOR.'dist';
        $assets = [];

        foreach (self::SETTINGS_ASSETS as $kind => $relative) {
            if (File::isFile($dist.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative))) {
                $assets[$kind] = $relative;
            }
        }

        return $assets;
    }

    /**
     * `dist/` 상대 경로가 설정 화면 부품 묶음 규약 경로인지 판정합니다 — 꺼진 템플릿 파일 제공을 이 두 파일로 한정한다.
     *
     * @param  string  $relativePath  `dist/` 상대 경로
     * @return bool 규약 경로이면 true
     */
    public static function isSettingsAssetPath(string $relativePath): bool
    {
        return in_array(ltrim(str_replace('\\', '/', $relativePath), '/'), self::SETTINGS_ASSETS, true);
    }

    /**
     * 템플릿이 공급한 설정 화면 부품 매니페스트 경로 — 확장 매니페스트 형식(`identifier`)이라 레이아웃 검사·관리자 템플릿
     * 부품 덮어쓰기(`overrides.target`)가 그 템플릿 부품을 안다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @return string 상대 경로 (`base_path` 기준)
     */
    public static function componentsManifestPath(string $identifier): string
    {
        return "templates/{$identifier}/components.settings.json";
    }

    /**
     * 템플릿 설정 화면 부품이 쓰는 프론트 언어 묶음(최상위 키) 목록 — 부품 매니페스트 `lang`, 없으면 `["settings"]`.
     *
     * 관리자 화면은 이 묶음만 `{템플릿id}.{묶음}` 으로 감싸 싣는다(템플릿 문구 전체를 싣지 않는다). 부품은 문구를
     * `{템플릿id}.{키}` 로 먼저 찾고 없으면 그대로의 키로 찾는다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @return array<int, string> 언어 묶음 키
     */
    public static function settingsLangGroups(string $identifier): array
    {
        $path = base_path(self::componentsManifestPath($identifier));
        $declared = null;

        if (File::isFile($path)) {
            $manifest = json_decode((string) File::get($path), true);
            $declared = is_array($manifest) ? ($manifest['lang'] ?? null) : null;
        }

        $groups = is_array($declared)
            ? array_values(array_unique(array_filter($declared, static fn ($group): bool => is_string($group) && preg_match('/^[a-z0-9_-]{1,64}$/', $group) === 1)))
            : [];

        return $groups === [] ? ['settings'] : $groups;
    }

    /**
     * 디렉토리에서 설정 화면 레이아웃 파일(`.g7.html` 우선, 짝 `.json`)을 찾습니다.
     *
     * @param  string  $dir  찾을 디렉토리
     * @return string|null 파일 경로
     */
    private static function firstLayoutFile(string $dir): ?string
    {
        foreach (['template_settings.g7.html', 'template_settings.json'] as $file) {
            $candidate = $dir.DIRECTORY_SEPARATOR.$file;

            if (File::exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * 템플릿이 선언 파일을 갖고 있는지 판정합니다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @param  string|null  $templateRoot  템플릿 디렉토리
     * @return bool 존재하면 true
     */
    public static function isDeclared(string $identifier, ?string $templateRoot = null): bool
    {
        return File::exists(self::path($identifier, $templateRoot));
    }

    /**
     * 선언 파일을 읽어 해석합니다 — 파일이 없으면 null.
     *
     * @param  string  $identifier  템플릿 식별자
     * @param  string|null  $templateRoot  템플릿 디렉토리
     * @return self|null 해석 결과
     *
     * @throws \InvalidArgumentException 파일이 JSON 이 아니거나 선언이 규약에 어긋나면
     */
    public static function load(string $identifier, ?string $templateRoot = null): ?self
    {
        $path = self::path($identifier, $templateRoot);

        if (! File::exists($path)) {
            return null;
        }

        $data = self::decode($identifier, (string) File::get($path));
        $errors = self::validate($data);

        if ($errors !== []) {
            throw new \InvalidArgumentException(__('templates.settings.errors.invalid_schema', [
                'template' => $identifier,
                'errors' => implode('; ', $errors),
            ]));
        }

        return self::fromArray($identifier, $data);
    }

    /**
     * 선언 파일의 경고(규약 위반은 아니지만 다른 주인과 겹칠 수 있는 키)를 모읍니다 — 설치·업데이트 경고용.
     *
     * @param  string  $identifier  템플릿 식별자
     * @param  string|null  $templateRoot  템플릿 디렉토리
     * @return array<int, string> 경고 (칸 경로)
     */
    public static function warningsFor(string $identifier, ?string $templateRoot = null): array
    {
        $path = self::path($identifier, $templateRoot);

        if (! File::exists($path)) {
            return [];
        }

        $data = json_decode((string) File::get($path), true);

        return is_array($data) ? self::warnings($data) : [];
    }

    /**
     * JSON 을 디코드합니다.
     *
     * @param  string  $identifier  템플릿 식별자 (오류 문맥)
     * @param  string  $json  원문
     * @return array<string, mixed> 디코드 결과
     *
     * @throws \InvalidArgumentException JSON 이 아니면
     */
    private static function decode(string $identifier, string $json): array
    {
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($data)) {
            throw new \InvalidArgumentException(__('templates.settings.errors.invalid_json', [
                'template' => $identifier,
                'error' => json_last_error_msg(),
            ]));
        }

        return $data;
    }

    /**
     * 검증을 통과한 선언 배열로 인스턴스를 만듭니다 — 선언된 칸마다 기본값을 채운다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @param  array<string, mixed>  $data  선언
     * @return self 해석 결과
     */
    private static function fromArray(string $identifier, array $data): self
    {
        $categories = array_values(array_filter((array) ($data['_meta']['categories'] ?? []), 'is_string'));
        $fields = [];
        $defaults = [];

        foreach ($categories as $category) {
            $fields[$category] = is_array($data['schema'][$category] ?? null) ? $data['schema'][$category] : [];
            $declared = is_array($data['defaults'][$category] ?? null) ? $data['defaults'][$category] : [];

            foreach ($fields[$category] as $key => $field) {
                $defaults[$category][$key] = array_key_exists($key, $declared) ? $declared[$key] : self::typeDefault($field);
            }

            $defaults[$category] ??= [];
        }

        return new self(
            $identifier,
            $categories,
            is_array($data['sections'] ?? null) ? $data['sections'] : [],
            $defaults,
            $fields,
            is_array($data['frontend_schema'] ?? null) ? $data['frontend_schema'] : [],
        );
    }

    /**
     * 칸 종류의 빈 기본값을 돌려줍니다.
     *
     * @param  array<string, mixed>  $field  칸 정의
     * @return mixed 기본값
     */
    private static function typeDefault(array $field): mixed
    {
        return match ($field['type'] ?? null) {
            'integer' => null,
            'boolean' => false,
            'multilingual' => [],
            'list', 'attachments', 'image', 'menu' => [],
            default => '',
        };
    }

    /**
     * 선언 내용의 규약 위반을 모읍니다.
     *
     * @param  array<string, mixed>  $data  디코드한 선언
     * @return array<int, string> 위반 설명 (없으면 빈 배열)
     */
    public static function validate(array $data): array
    {
        $errors = [];

        foreach (['defaults', 'schema', 'frontend_schema', 'sections'] as $block) {
            if (array_key_exists($block, $data) && ! is_array($data[$block])) {
                $errors[] = "{$block}: object";
            }
        }

        $categories = $data['_meta']['categories'] ?? null;

        if (! is_array($categories) || $categories === [] || array_values($categories) !== $categories) {
            return array_merge($errors, ['_meta.categories: non-empty list']);
        }

        $schema = is_array($data['schema'] ?? null) ? $data['schema'] : [];
        $defaults = is_array($data['defaults'] ?? null) ? $data['defaults'] : [];

        foreach (array_keys($schema) as $category) {
            if (! in_array($category, $categories, true)) {
                $errors[] = "schema.{$category}: not in _meta.categories";
            }
        }

        foreach ($categories as $category) {
            if (! is_string($category) || preg_match('/^[a-z][a-z0-9_]*$/', $category) !== 1) {
                $errors[] = "_meta.categories.{$category}: name";

                continue;
            }

            $sectionFields = $schema[$category] ?? null;

            if (! is_array($sectionFields) || $sectionFields === []) {
                $errors[] = "schema.{$category}: at least one field";

                continue;
            }

            foreach ($sectionFields as $key => $field) {
                $path = "{$category}.{$key}";

                if (! is_string($key) || preg_match('/^[a-z][a-z0-9_]*$/', $key) !== 1) {
                    $errors[] = "schema.{$path}: key";

                    continue;
                }

                if (! is_array($field)) {
                    $errors[] = "schema.{$path}: object";

                    continue;
                }

                $errors = array_merge($errors, self::validateField($path, $field, false));

                if (in_array($field['type'] ?? null, ['image'], true) && isset($sectionFields[$key.'_url'])) {
                    $errors[] = "schema.{$path}_url: reserved (computed)";
                }

                $declaredDefault = $defaults[$category][$key] ?? null;

                if (array_key_exists($key, (array) ($defaults[$category] ?? [])) && ! self::valueMatchesType($field, $declaredDefault)) {
                    $errors[] = "defaults.{$path}: type";
                }
            }

            if ($category === 'branding' && isset($sectionFields['favicon_url'])) {
                $errors[] = 'schema.branding.favicon_url: reserved (computed)';
            }
        }

        $errors = array_merge($errors, self::validateStandardKeys($schema), self::validateFrontendSchema($data, $categories, $schema));

        return $errors;
    }

    /**
     * 칸 정의 하나를 검사합니다.
     *
     * @param  string  $path  칸 경로 (오류 문맥)
     * @param  array<string, mixed>  $field  칸 정의
     * @param  bool  $inList  목록 행 안 칸이면 true
     * @return array<int, string> 위반
     */
    private static function validateField(string $path, array $field, bool $inList): array
    {
        $errors = [];
        $type = $field['type'] ?? null;
        $allowed = $inList ? self::LIST_ITEM_TYPES : self::TYPES;

        if (! in_array($type, $allowed, true)) {
            return ["schema.{$path}.type"];
        }

        if ($type === 'enum' && self::optionValues($field) === []) {
            $errors[] = "schema.{$path}.options";
        }

        foreach (['min', 'max', 'step', 'max_length', 'max_items', 'max_files', 'max_size_kb'] as $numeric) {
            if (array_key_exists($numeric, $field) && ! is_int($field[$numeric])) {
                $errors[] = "schema.{$path}.{$numeric}: integer";
            }
        }

        if (in_array($type, self::FILE_TYPES, true)) {
            if (isset($field['max_files']) && $field['max_files'] < 1) {
                $errors[] = "schema.{$path}.max_files";
            }

            if (isset($field['accept']) && (! is_array($field['accept']) || $field['accept'] === [])) {
                $errors[] = "schema.{$path}.accept";
            }

            if ($type === 'image' && is_array($field['accept'] ?? null)
                && array_diff(array_map('strtolower', $field['accept']), self::IMAGE_EXTENSIONS) !== []) {
                $errors[] = "schema.{$path}.accept: image extensions only (".implode(',', self::IMAGE_EXTENSIONS).')';
            }
        }

        if ($type === 'list') {
            $items = $field['item_fields'] ?? null;

            if (! is_array($items) || $items === []) {
                $errors[] = "schema.{$path}.item_fields";
            } else {
                foreach ($items as $itemKey => $itemField) {
                    if (! is_string($itemKey) || preg_match('/^[a-z][a-z0-9_]*$/', $itemKey) !== 1 || ! is_array($itemField)) {
                        $errors[] = "schema.{$path}.item_fields.{$itemKey}";

                        continue;
                    }

                    $errors = array_merge($errors, self::validateField("{$path}.item_fields.{$itemKey}", $itemField, true));
                }
            }
        }

        if ($type === 'menu') {
            $errors = array_merge($errors, TemplateMenu::declarationErrors($path, $field));
        }

        if (($field['sensitive'] ?? false) === true && ! in_array($type, ['string', 'text'], true)) {
            $errors[] = "schema.{$path}.sensitive: string/text only";
        }

        if (isset($field['visible_if'])) {
            $condition = $field['visible_if'];

            if (! is_array($condition) || ! is_string($condition['field'] ?? null) || (! array_key_exists('equals', $condition) && ! is_array($condition['in'] ?? null))) {
                $errors[] = "schema.{$path}.visible_if: {field, equals|in}";
            }
        }

        if (isset($field['renamed_from']) && (! is_array($field['renamed_from']) || array_filter($field['renamed_from'], fn ($old) => ! is_string($old) || preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $old) !== 1) !== [])) {
            $errors[] = "schema.{$path}.renamed_from: [\"section.key\"]";
        }

        return $errors;
    }

    /**
     * 표준 키 규격을 검사합니다.
     *
     * @param  array<string, mixed>  $schema  칸 선언
     * @return array<int, string> 위반
     */
    private static function validateStandardKeys(array $schema): array
    {
        $errors = [];
        $favicon = $schema['branding']['favicon'] ?? null;

        if (is_array($favicon)) {
            $accept = is_array($favicon['accept'] ?? null) ? array_map('strtolower', $favicon['accept']) : [];
            sort($accept);
            $expected = FaviconPolicy::EXTENSIONS;
            sort($expected);

            if (($favicon['type'] ?? null) !== 'attachments' || ($favicon['max_files'] ?? null) !== 1 || $accept !== $expected) {
                $errors[] = 'schema.branding.favicon: attachments, max_files=1, accept='.implode(',', FaviconPolicy::EXTENSIONS);
            }
        }

        $accent = $schema['branding']['accent'] ?? null;

        if (is_array($accent)) {
            $values = self::optionValues($accent);

            if (($accent['type'] ?? null) !== 'enum' || $values === [] || array_filter($values, fn ($v) => ! is_string($v) || preg_match('/^[a-z][a-z0-9-]{0,31}$/', $v) !== 1) !== []) {
                $errors[] = 'schema.branding.accent: enum, values ^[a-z][a-z0-9-]{0,31}$';
            }
        }

        $scheme = $schema['branding']['color_scheme_default'] ?? null;

        if (is_array($scheme)) {
            $values = self::optionValues($scheme);

            if (($scheme['type'] ?? null) !== 'enum' || $values === [] || array_diff($values, self::COLOR_SCHEME_VALUES) !== []) {
                $errors[] = 'schema.branding.color_scheme_default: enum ⊆ '.implode('|', self::COLOR_SCHEME_VALUES);
            }
        }

        return $errors;
    }

    /**
     * 노출 스키마를 검사합니다 — 섹션·키가 선언 안에 있어야 하고, 비밀값은 노출할 수 없다.
     *
     * @param  array<string, mixed>  $data  선언
     * @param  array<int, mixed>  $categories  섹션
     * @param  array<string, mixed>  $schema  칸 선언
     * @return array<int, string> 위반
     */
    private static function validateFrontendSchema(array $data, array $categories, array $schema): array
    {
        $errors = [];
        $frontend = is_array($data['frontend_schema'] ?? null) ? $data['frontend_schema'] : [];

        foreach ($frontend as $category => $definition) {
            if (! in_array($category, $categories, true)) {
                $errors[] = "frontend_schema.{$category}: not a section";

                continue;
            }

            $sectionFields = is_array($schema[$category] ?? null) ? $schema[$category] : [];
            $computed = self::computedKeysOf((string) $category, $sectionFields);
            $exposedFields = is_array($definition['fields'] ?? null) ? $definition['fields'] : null;

            if ($exposedFields === null) {
                // 섹션 통째 노출이면 그 섹션에 비밀값이 없어야 한다
                foreach ($sectionFields as $key => $field) {
                    if (($definition['expose'] ?? false) === true && (($field['sensitive'] ?? false) === true)) {
                        $errors[] = "frontend_schema.{$category}: exposes sensitive {$key}";
                    }
                }

                continue;
            }

            foreach ($exposedFields as $key => $fieldDefinition) {
                if (! isset($sectionFields[$key]) && ! in_array($key, $computed, true)) {
                    $errors[] = "frontend_schema.{$category}.fields.{$key}: not declared";

                    continue;
                }

                if (($fieldDefinition['expose'] ?? false) === true && (($sectionFields[$key]['sensitive'] ?? false) === true)) {
                    $errors[] = "frontend_schema.{$category}.fields.{$key}: sensitive cannot be exposed";
                }
            }
        }

        return $errors;
    }

    /**
     * 다른 주인이 가진 값과 이름이 겹치는 칸을 모읍니다(경고).
     *
     * @param  array<string, mixed>  $data  선언
     * @return array<int, string> 칸 경로
     */
    public static function warnings(array $data): array
    {
        $warnings = [];

        foreach ((array) ($data['schema'] ?? []) as $category => $sectionFields) {
            foreach ((array) $sectionFields as $key => $field) {
                if (in_array($key, self::OWNED_ELSEWHERE_KEYS, true)) {
                    $warnings[] = "{$category}.{$key}";
                }
            }
        }

        return $warnings;
    }

    /**
     * 값이 칸 종류에 맞는지 판정합니다 — 기본값 선언 검사용(형태만 본다).
     *
     * @param  array<string, mixed>  $field  칸 정의
     * @param  mixed  $value  값
     * @return bool 맞으면 true
     */
    private static function valueMatchesType(array $field, mixed $value): bool
    {
        return match ($field['type'] ?? null) {
            'integer' => $value === null || is_int($value),
            'boolean' => is_bool($value),
            'enum' => in_array($value, self::optionValues($field), true),
            'multilingual' => is_array($value) && array_filter($value, fn ($v) => ! is_string($v)) === [],
            'list', 'attachments', 'image' => is_array($value) && array_is_list($value),
            'menu' => TemplateMenu::structureErrors($value, $field) === [],
            default => is_string($value),
        };
    }

    /**
     * enum 선택지 값 목록을 돌려줍니다 — `["a","b"]` 와 `[{value,label}]` 를 모두 받는다.
     *
     * @param  array<string, mixed>  $field  칸 정의
     * @return array<int, mixed> 값 목록
     */
    public static function optionValues(array $field): array
    {
        $values = [];

        foreach ((array) ($field['options'] ?? []) as $option) {
            $values[] = is_array($option) ? ($option['value'] ?? null) : $option;
        }

        return array_values(array_filter($values, fn ($v) => $v !== null));
    }

    /**
     * 섹션의 계산 키 이름 목록 — 파비콘 칸은 `favicon_url`, 이미지 칸은 `{키}_url`.
     *
     * @param  string  $category  섹션
     * @param  array<string, mixed>  $sectionFields  그 섹션 칸 선언
     * @return array<int, string> 계산 키
     */
    private static function computedKeysOf(string $category, array $sectionFields): array
    {
        $keys = [];

        foreach ($sectionFields as $key => $field) {
            $isFavicon = "{$category}.{$key}" === self::KEY_FAVICON;

            if ($isFavicon || (($field['type'] ?? null) === 'image')) {
                $keys[] = $key.'_url';
            }
        }

        return $keys;
    }

    /**
     * 칸 정의를 돌려줍니다.
     *
     * @param  string  $path  칸 경로 (`섹션.키`)
     * @return array<string, mixed>|null 정의 (없으면 null)
     */
    public function field(string $path): ?array
    {
        [$category, $key] = array_pad(explode('.', $path, 2), 2, '');

        return $this->fields[$category][$key] ?? null;
    }

    /**
     * 선언된 칸 전부를 경로로 펼쳐 돌려줍니다.
     *
     * @return array<string, array<string, mixed>> `섹션.키` => 정의 (섹션 순서 유지)
     */
    public function flatFields(): array
    {
        $flat = [];

        foreach ($this->categories as $category) {
            foreach ($this->fields[$category] ?? [] as $key => $field) {
                $flat["{$category}.{$key}"] = $field;
            }
        }

        return $flat;
    }

    /**
     * 첨부를 담는 칸 경로 목록(`attachments`·`image`).
     *
     * @return array<int, string> 칸 경로
     */
    public function attachmentPaths(): array
    {
        return array_keys(array_filter($this->flatFields(), fn ($field) => in_array($field['type'] ?? null, self::FILE_TYPES, true)));
    }

    /**
     * 이미지 칸 경로 목록.
     *
     * @return array<int, string> 칸 경로
     */
    public function imagePaths(): array
    {
        return array_keys(array_filter($this->flatFields(), fn ($field) => ($field['type'] ?? null) === 'image'));
    }

    /**
     * 비밀값 칸 경로 목록.
     *
     * @return array<int, string> 칸 경로
     */
    public function sensitivePaths(): array
    {
        return array_keys(array_filter($this->flatFields(), fn ($field) => ($field['sensitive'] ?? false) === true));
    }

    /**
     * 계산 키 경로 목록 — 저장·제출 대상이 아니다.
     *
     * @return array<int, string> 칸 경로
     */
    public function computedPaths(): array
    {
        $paths = [];

        foreach ($this->categories as $category) {
            foreach (self::computedKeysOf($category, $this->fields[$category] ?? []) as $key) {
                $paths[] = "{$category}.{$key}";
            }
        }

        return $paths;
    }

    /**
     * 옛 경로 → 새 경로 이름 바꾸기 목록(`renamed_from`).
     *
     * @return array<string, string> 옛 경로 => 새 경로
     */
    public function renames(): array
    {
        $renames = [];

        foreach ($this->flatFields() as $path => $field) {
            foreach ((array) ($field['renamed_from'] ?? []) as $old) {
                $renames[$old] = $path;
            }
        }

        return $renames;
    }

    /**
     * 첨부 칸의 컬렉션 이름을 돌려줍니다 — 템플릿마다·칸마다 갈린다(다른 템플릿·코어 로고와 섞이지 않음).
     *
     * @param  string  $path  칸 경로
     * @return string 컬렉션 이름
     */
    public function collection(string $path): string
    {
        return TemplateSettingCollections::forField($this->identifier, $path);
    }

    /**
     * 첨부 칸의 최대 파일 수.
     *
     * @param  string  $path  칸 경로
     * @return int 최대 개수 (기본 1)
     */
    public function maxFiles(string $path): int
    {
        return (int) ($this->field($path)['max_files'] ?? 1);
    }

    /**
     * 첨부 칸의 허용 확장자 — 이미지 칸은 선언이 없으면 이미지 기본 목록.
     *
     * @param  string  $path  칸 경로
     * @return array<int, string> 소문자 확장자
     */
    public function acceptedExtensions(string $path): array
    {
        $field = $this->field($path) ?? [];

        if (is_array($field['accept'] ?? null)) {
            return array_values(array_map(fn ($ext) => strtolower(ltrim((string) $ext, '.')), $field['accept']));
        }

        return ($field['type'] ?? null) === 'image' ? self::IMAGE_EXTENSIONS : [];
    }

    /**
     * 파비콘 표준 칸을 선언했는지 판정합니다.
     *
     * @return bool 선언했으면 true
     */
    public function declaresFavicon(): bool
    {
        return $this->field(self::KEY_FAVICON) !== null;
    }

    /**
     * 섹션 이름 목록이 비어 있지 않은지 — 설정 화면을 가질 수 있는지.
     *
     * @return bool 칸이 하나 이상이면 true
     */
    public function hasFields(): bool
    {
        return $this->flatFields() !== [];
    }
}
