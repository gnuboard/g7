<?php

namespace App\Support;

use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\Support\LifecycleWarnings;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;

/**
 * 최근 검색어 범위(`search_scopes`) 집계
 *
 * 최근 검색어는 **검색 범위**마다 따로 쌓인다. 같은 범위를 적은 검색창끼리 목록을 공유하고, 범위가 다르면
 * 목록도 다르다(쇼핑몰 상품 검색어가 게시판 검색창에 뜨지 않는다). 범위는 코어 예약(`core.site` — 통합검색)과
 * 활성 모듈·플러그인이 manifest 로 선언한 것뿐이다 — 코어는 확장 범위를 하드코딩하지 않고, 선언하지 않았거나
 * 비활성인 확장의 범위는 저장을 거부한다(엔진은 조용히 건너뛴다).
 *
 * 선언 모양(module.json · plugin.json):
 * ```json
 * "search_scopes": {
 *   "products": { "label": "sirsoft-ecommerce::messages.search_history.scope_products", "max_count": 10, "retention_days": 0 }
 * }
 * ```
 *
 * - 키는 확장 안의 이름이다. 코어가 `{식별자}.` 를 붙여 범위 id 를 만든다 — 확장이 남의 범위를 주장할 수 없다.
 *   이름 규칙 `/^[a-z][a-z0-9_-]{0,49}$/`.
 * - `label` 은 다국어 맵(`{"ko": "…", "en": "…"}`) 또는 번역 키 문자열이다. 없으면 범위 이름을 그대로 보인다.
 * - `max_count`(1~상한)·`retention_days`(0 이상, 0 = 기한 없음)는 선택이다. 범위를 벗어나거나 정수가 아닌 값은 버리고
 *   경고한다 — 경고는 무엇을 왜 버렸는지 이유별 문장이고, 확장 개발자가 고칠 선언(`cause: declaration`)으로 표시된다.
 *
 * 운영자는 환경설정 > 개인정보 「최근 검색어」 카드에서 범위마다 보관 개수·보관 기한을 고친다. 저장 모양은
 * **목록**(`privacy.search_history_scopes = [{scope, max_count, retention_days}]`)이다 — 범위 id 에 점이 있어
 * 범위를 키로 쓰는 맵은 Laravel 점 표기 검증과 엔진 폼 경로를 깨뜨린다.
 *
 * @since 7.1.5
 */
class SearchScopes
{
    /** 코어 소유자 키 */
    public const CORE_OWNER = 'core';

    /** 코어 통합검색 범위 */
    public const CORE_SITE = 'core.site';

    /** 보관 개수 기본값 (선언·설정이 없을 때) */
    public const DEFAULT_MAX_COUNT = 10;

    /** 보관 기한(일) 기본값 — 0 은 기한 없음 */
    public const DEFAULT_RETENTION_DAYS = 0;

    /** 범위 설정 목록의 설정 키 (privacy 카테고리 안) */
    public const SETTINGS_ROWS_KEY = 'search_history_scopes';

    /** 기능 사용 여부 설정 키 (privacy 카테고리 안) */
    public const SETTINGS_ENABLED_KEY = 'search_history_enabled';

    /**
     * 범위 이름 규칙 — 확장 안의 이름(접두어 앞).
     */
    public const NAME_PATTERN = '/^[a-z][a-z0-9_-]{0,49}$/';

    /**
     * 코어 예약 범위.
     *
     * @var array<string, array{label: string, max_count: int|null, retention_days: int|null}>
     */
    public const CORE_SCOPES = [
        self::CORE_SITE => [
            'label' => 'search.history.scopes.core_site',
            'max_count' => null,
            'retention_days' => null,
        ],
    ];

    /**
     * 보관 개수 상한을 반환합니다 (`core.settings_limits.search_history_max_count_max`).
     *
     * @return int 보관 개수 상한
     */
    public static function maxCountLimit(): int
    {
        return max(1, (int) config('core.settings_limits.search_history_max_count_max', 50));
    }

    /**
     * 보관 기한(일) 상한을 반환합니다 (`core.settings_limits.privacy_retention_days_max`).
     *
     * @return int 보관 기한 상한(일)
     */
    public static function retentionDaysLimit(): int
    {
        return max(1, (int) config('core.settings_limits.privacy_retention_days_max', 3650));
    }

    /**
     * manifest 의 `search_scopes` 선언을 정규화합니다. 형식이 틀린 항목은 버립니다.
     *
     * @param  mixed  $raw  manifest 값
     * @param  string  $identifier  선언한 확장 식별자 (범위 id 접두어)
     * @param  bool  $reportWarnings  설치·활성화·업데이트처럼 운영자에게 알릴 작업이면 true — 생명주기 경고 목록에도 남긴다
     * @return array<string, array{label: string|array<string, string>|null, max_count: int|null, retention_days: int|null}> 범위 id → 선언
     */
    public static function normalize(mixed $raw, string $identifier, bool $reportWarnings = false): array
    {
        if ($raw === null || $raw === []) {
            return [];
        }

        $normalized = [];
        // 버린 이유별로 모은다 — 경고 문구가 「무엇을 왜 버렸는지」를 이유마다 한 덩어리로 말한다
        $invalid = ['not_object' => false, 'invalid_name' => [], 'invalid_definition' => [], 'max_count' => [], 'retention_days' => []];

        if (! is_array($raw)) {
            $invalid['not_object'] = true;
        } else {
            foreach ($raw as $name => $definition) {
                if (! is_string($name) || preg_match(self::NAME_PATTERN, $name) !== 1) {
                    $invalid['invalid_name'][] = is_scalar($name) ? (string) $name : gettype($name);

                    continue;
                }

                if (! (is_array($definition) || $definition === null)) {
                    $invalid['invalid_definition'][] = $name;

                    continue;
                }

                $definition ??= [];

                $maxCount = self::intOrNull($definition['max_count'] ?? null);
                if (($definition['max_count'] ?? null) !== null && ($maxCount === null || $maxCount < 1 || $maxCount > self::maxCountLimit())) {
                    $invalid['max_count'][] = $name.'('.self::describeValue($definition['max_count']).')';
                    $maxCount = null;
                }

                $retentionDays = self::intOrNull($definition['retention_days'] ?? null);
                if (($definition['retention_days'] ?? null) !== null && ($retentionDays === null || $retentionDays < 0 || $retentionDays > self::retentionDaysLimit())) {
                    $invalid['retention_days'][] = $name.'('.self::describeValue($definition['retention_days']).')';
                    $retentionDays = null;
                }

                $normalized[$identifier.'.'.$name] = [
                    'label' => self::normalizeLabel($definition['label'] ?? null),
                    'max_count' => $maxCount,
                    'retention_days' => $retentionDays,
                ];
            }
        }

        $reasons = self::invalidDeclarationReasons($invalid);
        if ($reasons !== []) {
            Log::warning('search_scopes 선언의 형식이 틀린 항목을 무시합니다', [
                'extension' => $identifier,
                'invalid' => $invalid,
            ]);
            if ($reportWarnings) {
                // 확장이 manifest 에 잘못 적은 값이라 다시 실행해도 같다 — 확장 개발자가 고칠 선언으로 표시한다
                LifecycleWarnings::current()->declaration('search_scopes', $identifier, implode(' / ', $reasons));
            }
        }

        return $normalized;
    }

    /**
     * 활성 확장이 선언한 범위를 소유자 키별로 모읍니다 (모듈·플러그인만 — 템플릿은 범위를 선언하지 않는다).
     *
     * @return array<string, array<string, array{label: string|array<string, string>|null, max_count: int|null, retention_days: int|null}>> 소유자 키 → 범위 id → 선언
     */
    public static function declaredByActiveExtensions(): array
    {
        $declared = [];

        try {
            foreach (app(ModuleManager::class)->getActiveModules() as $module) {
                if (method_exists($module, 'getSearchScopes')) {
                    $declared['module:'.$module->getIdentifier()] = $module->getSearchScopes();
                }
            }
        } catch (\Throwable $e) {
            Log::warning('SearchScopes: 모듈 집계 실패 - '.$e->getMessage());
        }

        try {
            foreach (app(PluginManager::class)->getActivePlugins() as $plugin) {
                if (method_exists($plugin, 'getSearchScopes')) {
                    $declared['plugin:'.$plugin->getIdentifier()] = $plugin->getSearchScopes();
                }
            }
        } catch (\Throwable $e) {
            Log::warning('SearchScopes: 플러그인 집계 실패 - '.$e->getMessage());
        }

        return array_filter($declared);
    }

    /**
     * 코어 예약 + 활성 확장 선언 전체를 선언 순서(코어 → 모듈 → 플러그인)로 집계합니다.
     *
     * @return array<string, array{owner: string, label: string|array<string, string>|null, max_count: int|null, retention_days: int|null}> 범위 id → 선언
     */
    public static function all(): array
    {
        $scopes = [];
        foreach (self::CORE_SCOPES as $scope => $definition) {
            $scopes[$scope] = ['owner' => self::CORE_OWNER] + $definition;
        }

        foreach (self::declaredByActiveExtensions() as $owner => $declared) {
            foreach ($declared as $scope => $definition) {
                // 접두어가 식별자라 겹칠 수 없지만, 코어 예약과 같은 id 는 코어가 이긴다.
                if (isset($scopes[$scope])) {
                    continue;
                }

                $scopes[$scope] = ['owner' => $owner] + $definition;
            }
        }

        return $scopes;
    }

    /**
     * 지금 선언된 범위 id 목록을 반환합니다.
     *
     * @return array<int, string> 범위 id 목록
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * 범위가 지금 선언되어 있는지 판정합니다 (비활성 확장의 범위는 false).
     *
     * @param  string  $scope  범위 id
     * @return bool 선언되어 있으면 true
     */
    public static function isDeclared(string $scope): bool
    {
        return array_key_exists($scope, self::all());
    }

    /**
     * 최근 검색어 기능을 쓰는지 반환합니다 (`privacy.search_history_enabled`, 기본 켬).
     *
     * @return bool 사용 여부
     */
    public static function enabled(): bool
    {
        $value = g7_core_settings('privacy.'.self::SETTINGS_ENABLED_KEY, true);

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    }

    /**
     * 저장된 범위 설정 행을 범위 id 별로 읽습니다 (형식이 틀린 행은 버린다).
     *
     * @param  mixed  $rows  저장값 (null 이면 설정에서 읽음)
     * @return array<string, array{scope: string, max_count: int|null, retention_days: int|null}> 범위 id → 행
     */
    public static function storedRows(mixed $rows = null): array
    {
        $rows ??= g7_core_settings('privacy.'.self::SETTINGS_ROWS_KEY, []);

        if (! is_array($rows)) {
            return [];
        }

        $byScope = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['scope'] ?? null) || $row['scope'] === '') {
                continue;
            }

            $byScope[$row['scope']] = [
                'scope' => $row['scope'],
                'max_count' => self::intOrNull($row['max_count'] ?? null),
                'retention_days' => self::intOrNull($row['retention_days'] ?? null),
            ];
        }

        return $byScope;
    }

    /**
     * 범위의 실제 적용값을 반환합니다 — 설정 행 → 선언값 → 기본값(10개·기한 없음) 순.
     *
     * 선언이 사라진(비활성 확장) 범위도 설정 행이 있으면 그 값을 쓴다 — 보관 기한 파기가 확장을 끈 동안
     * 멈추지 않게 하기 위함이다.
     *
     * @param  string  $scope  범위 id
     * @param  array<string, array<string, mixed>>|null  $declared  선언 목록 (null 이면 all())
     * @param  array<string, array<string, mixed>>|null  $stored  설정 행 (null 이면 storedRows())
     * @return array{max_count: int, retention_days: int} 적용값
     */
    public static function effective(string $scope, ?array $declared = null, ?array $stored = null): array
    {
        $declared ??= self::all();
        $stored ??= self::storedRows();

        $row = $stored[$scope] ?? [];
        $definition = $declared[$scope] ?? [];

        $maxCount = $row['max_count'] ?? $definition['max_count'] ?? self::DEFAULT_MAX_COUNT;
        $retentionDays = $row['retention_days'] ?? $definition['retention_days'] ?? self::DEFAULT_RETENTION_DAYS;

        return [
            'max_count' => min(self::maxCountLimit(), max(1, (int) $maxCount)),
            'retention_days' => min(self::retentionDaysLimit(), max(0, (int) $retentionDays)),
        ];
    }

    /**
     * 보관 기한이 있는 범위의 적용 기한을 반환합니다 (선언 범위 + 설정 행만 있는 범위).
     *
     * @return array<string, int> 범위 id → 보관 기한(일, 1 이상)
     */
    public static function retentionPolicies(): array
    {
        $declared = self::all();
        $stored = self::storedRows();

        $policies = [];
        foreach (array_unique([...array_keys($declared), ...array_keys($stored)]) as $scope) {
            $days = self::effective($scope, $declared, $stored)['retention_days'];
            if ($days > 0) {
                $policies[$scope] = $days;
            }
        }

        return $policies;
    }

    /**
     * 범위 선언 라벨을 지금 언어의 글자로 바꿉니다.
     *
     * @param  string|array<string, string>|null  $label  선언 라벨 (다국어 맵 또는 번역 키)
     * @param  string  $scope  범위 id (라벨이 없을 때 대신 보인다)
     * @return string 보일 글자
     */
    public static function labelText(string|array|null $label, string $scope): string
    {
        if (is_string($label) && $label !== '') {
            return Lang::has($label) ? (string) __($label) : $label;
        }

        if (is_array($label)) {
            $picked = LocalizedValue::pick($label);
            if ($picked !== null && $picked !== '') {
                return $picked;
            }
        }

        return $scope;
    }

    /**
     * 프론트로 내보낼 모양으로 집계합니다 (`G7Config.searchScopes` — 범위 id → `{max_count}`).
     *
     * 기능을 끈 사이트에서는 빈 객체를 낸다 — 엔진은 선언되지 않은 범위로 보고 기록·표시를 하지 않는다.
     *
     * @return array<string, array{max_count: int}> 범위 id → 적용값
     */
    public static function forFrontend(): array
    {
        try {
            if (! self::enabled()) {
                return [];
            }

            $declared = self::all();
            $stored = self::storedRows();

            $result = [];
            foreach (array_keys($declared) as $scope) {
                $result[$scope] = ['max_count' => self::effective($scope, $declared, $stored)['max_count']];
            }

            return $result;
        } catch (\Throwable $e) {
            Log::warning('SearchScopes: 프론트 집계 실패 - '.$e->getMessage());

            return [];
        }
    }

    /**
     * 지금 선언된 범위를 화면 표시용으로 모읍니다 — 범위 id → 소유자 키·지금 언어 이름 (선언 순서).
     *
     * 관리자 회원 상세가 범위별 검색어를 묶어 보일 때 쓴다. 꺼진 확장의 범위는 선언이 없으므로 빠진다.
     *
     * @return array<string, array{owner: string, label: string}> 범위 id → 표시 정보
     */
    public static function labeled(): array
    {
        $labeled = [];
        foreach (self::all() as $scope => $definition) {
            $labeled[$scope] = [
                'owner' => (string) $definition['owner'],
                'label' => self::labelText($definition['label'] ?? null, $scope),
            ];
        }

        return $labeled;
    }

    /**
     * 관리자 환경설정 응답의 `_meta.search_scopes` 를 만듭니다 (선언 순서).
     *
     * 소유 확장의 이름은 설치 기록(DB)에서 읽어야 해 호출자(서비스)가 해석기로 넘긴다 — 이 클래스는 DB 를 읽지 않는다.
     *
     * @param  callable(string): string  $ownerLabel  소유자 키 → 사람이 읽는 이름
     * @return array<int, array{scope: string, owner: string, owner_label: string, label: string, default_max_count: int, default_retention_days: int}> 범위 메타 목록
     */
    public static function forSettingsMeta(callable $ownerLabel): array
    {
        $meta = [];
        foreach (self::all() as $scope => $definition) {
            $meta[] = [
                'scope' => $scope,
                'owner' => $definition['owner'],
                'owner_label' => $ownerLabel($definition['owner']),
                'label' => self::labelText($definition['label'], $scope),
                'default_max_count' => $definition['max_count'] ?? self::DEFAULT_MAX_COUNT,
                'default_retention_days' => $definition['retention_days'] ?? self::DEFAULT_RETENTION_DAYS,
            ];
        }

        return $meta;
    }

    /**
     * 관리자 응답용 설정 목록을 만듭니다 — 선언 범위마다 한 행, 선언 순서, 적용값으로 채운다.
     *
     * 저장된 목록에 빠진 범위(새로 켠 확장)는 선언값으로 채우고, 선언이 사라진 범위의 행은 화면에 내지 않는다
     * (저장 때 {@see self::mergeForSave()} 가 보존한다).
     *
     * @param  mixed  $stored  저장값
     * @return array<int, array{scope: string, max_count: int, retention_days: int}> 화면용 행 목록
     */
    public static function presentRows(mixed $stored): array
    {
        $declared = self::all();
        $storedRows = self::storedRows(is_array($stored) ? $stored : []);

        $rows = [];
        foreach (array_keys($declared) as $scope) {
            $rows[] = ['scope' => $scope] + self::effective($scope, $declared, $storedRows);
        }

        return $rows;
    }

    /**
     * 저장할 목록을 만듭니다 — 제출한 행 + 지금 선언되지 않아 화면에 없던 기존 행(확장을 끈 동안 값 보존).
     *
     * @param  array<int, mixed>  $submitted  제출한 행 목록 (검증 통과)
     * @param  mixed  $existing  지금 저장된 목록
     * @return array<int, array{scope: string, max_count: int, retention_days: int}> 저장할 행 목록
     */
    public static function mergeForSave(array $submitted, mixed $existing): array
    {
        $rows = [];
        foreach (self::storedRows($submitted) as $scope => $row) {
            $rows[$scope] = [
                'scope' => $scope,
                'max_count' => (int) ($row['max_count'] ?? self::DEFAULT_MAX_COUNT),
                'retention_days' => (int) ($row['retention_days'] ?? self::DEFAULT_RETENTION_DAYS),
            ];
        }

        $declared = self::keys();
        foreach (self::storedRows(is_array($existing) ? $existing : []) as $scope => $row) {
            if (isset($rows[$scope]) || in_array($scope, $declared, true)) {
                continue;
            }

            $rows[$scope] = [
                'scope' => $scope,
                'max_count' => (int) ($row['max_count'] ?? self::DEFAULT_MAX_COUNT),
                'retention_days' => (int) ($row['retention_days'] ?? self::DEFAULT_RETENTION_DAYS),
            ];
        }

        return array_values($rows);
    }

    /**
     * 버린 선언 항목을 이유별 사람이 읽는 문장 목록으로 바꿉니다 (관리자 결과 창·CLI 표의 사유 칸).
     *
     * @param  array{not_object: bool, invalid_name: array<int, string>, invalid_definition: array<int, string>, max_count: array<int, string>, retention_days: array<int, string>}  $invalid  이유별로 모은 항목
     * @return array<int, string> 이유마다 한 문장 (버린 것이 없으면 빈 목록)
     */
    private static function invalidDeclarationReasons(array $invalid): array
    {
        $reasons = [];

        if ($invalid['not_object']) {
            $reasons[] = LifecycleWarnings::reason('extensions.warnings.search_scopes_not_object', [], 'search_scopes');
        }
        if ($invalid['invalid_name'] !== []) {
            $names = implode(', ', $invalid['invalid_name']);
            $reasons[] = LifecycleWarnings::reason('extensions.warnings.search_scopes_invalid_name', ['names' => $names], $names);
        }
        if ($invalid['invalid_definition'] !== []) {
            $names = implode(', ', $invalid['invalid_definition']);
            $reasons[] = LifecycleWarnings::reason('extensions.warnings.search_scopes_invalid_definition', ['names' => $names], $names);
        }
        if ($invalid['max_count'] !== []) {
            $items = implode(', ', $invalid['max_count']);
            $reasons[] = LifecycleWarnings::reason('extensions.warnings.search_scopes_max_count_out_of_range', [
                'min' => 1,
                'max' => self::maxCountLimit(),
                'items' => $items,
            ], 'max_count: '.$items);
        }
        if ($invalid['retention_days'] !== []) {
            $items = implode(', ', $invalid['retention_days']);
            $reasons[] = LifecycleWarnings::reason('extensions.warnings.search_scopes_retention_days_out_of_range', [
                'min' => 0,
                'max' => self::retentionDaysLimit(),
                'items' => $items,
            ], 'retention_days: '.$items);
        }

        return $reasons;
    }

    /**
     * 선언값을 경고 문구에 넣을 짧은 글자로 바꿉니다.
     *
     * @param  mixed  $value  선언값
     * @return string 글자 (배열·객체는 JSON, 64자를 넘으면 자름)
     */
    private static function describeValue(mixed $value): string
    {
        $text = match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_UNICODE),
        };

        return mb_strlen($text) > 64 ? mb_substr($text, 0, 64).'…' : $text;
    }

    /**
     * 라벨 선언을 정규화합니다.
     *
     * @param  mixed  $label  선언값
     * @return string|array<string, string>|null 번역 키·글자, 다국어 맵, 또는 null
     */
    private static function normalizeLabel(mixed $label): string|array|null
    {
        if (is_string($label)) {
            return trim($label) !== '' ? $label : null;
        }

        if (is_array($label)) {
            $map = array_filter($label, fn ($text, $locale) => is_string($locale) && is_string($text) && $text !== '', ARRAY_FILTER_USE_BOTH);

            return $map !== [] ? $map : null;
        }

        return null;
    }

    /**
     * 정수 또는 정수 문자열이면 정수로, 아니면 null 로 바꿉니다.
     *
     * @param  mixed  $value  값
     * @return int|null 정수 또는 null
     */
    private static function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }
}
