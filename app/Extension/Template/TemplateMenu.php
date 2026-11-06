<?php

namespace App\Extension\Template;

use App\Models\Role;
use App\Rules\SafeLinkRule;
use App\Rules\TranslatableField;
use Illuminate\Validation\Rule;

/**
 * 템플릿 설정 `menu` 칸 — 사용자 화면 메뉴(2단까지)의 선언·구조·정규화 규칙.
 *
 * 선언 예:
 * ```json
 * "menu": {
 *   "type": "menu",
 *   "max_items": 30,
 *   "max_depth": 2,
 *   "auto_items": {
 *     "boards": { "label": "$t:settings.header.menu.auto.boards", "hint": "..." }
 *   }
 * }
 * ```
 *
 * 값은 항목 배열이다. 항목 하나:
 * `{id, kind, label, url, new_tab, is_active, visibility, roles, children}`.
 * `kind` 는 `link`(운영자가 적은 주소) 또는 선언한 자동 항목 키(템플릿이 그 자리에 무엇을 그릴지 안다 —
 * 예: 게시판 전체). 자동 항목은 메뉴 전체에 한 번만 두고 하위 메뉴를 갖지 못한다.
 *
 * 보이기(`visibility`)는 화면의 안내 장치이지 접근 제어가 아니다 — 메뉴 값은 화면 설정에 실려 모두에게 내려간다.
 */
final class TemplateMenu
{
    /**
     * 운영자가 주소를 적는 항목 종류 (자동 항목 키로 쓸 수 없다)
     */
    public const KIND_LINK = 'link';

    /**
     * 보이기 선택지 — 모두 · 회원만 · 비회원만 · 특정 역할
     *
     * @var array<int, string>
     */
    public const VISIBILITIES = ['all', 'members', 'guests', 'roles'];

    /**
     * 하위 메뉴 깊이 상한 (상위 + 하위 1단)
     */
    public const MAX_DEPTH = 2;

    /**
     * 전체 항목 수 기본값과 상한
     */
    public const DEFAULT_MAX_ITEMS = 30;

    public const MAX_ITEMS_CAP = 100;

    /**
     * 항목 이름 최대 길이
     */
    public const LABEL_MAX_LENGTH = 60;

    /**
     * 항목 id 형식 — 화면이 만든 임의 문자열
     */
    public const ID_PATTERN = '/^[A-Za-z0-9_-]{1,40}$/';

    /**
     * 항목에 저장하는 키
     *
     * @var array<int, string>
     */
    private const ITEM_KEYS = ['id', 'kind', 'label', 'url', 'new_tab', 'is_active', 'visibility', 'roles', 'children'];

    /**
     * 선언한 자동 항목 키 목록
     *
     * @param  array<string, mixed>  $field  칸 정의
     * @return array<int, string> 키
     */
    public static function autoKinds(array $field): array
    {
        return array_values(array_filter(
            array_keys(is_array($field['auto_items'] ?? null) ? $field['auto_items'] : []),
            fn ($key) => is_string($key)
        ));
    }

    /**
     * 허용 깊이 (1 또는 2)
     *
     * @param  array<string, mixed>  $field  칸 정의
     * @return int 깊이
     */
    public static function maxDepth(array $field): int
    {
        $depth = is_int($field['max_depth'] ?? null) ? $field['max_depth'] : self::MAX_DEPTH;

        return max(1, min(self::MAX_DEPTH, $depth));
    }

    /**
     * 전체 항목 수 상한
     *
     * @param  array<string, mixed>  $field  칸 정의
     * @return int 상한
     */
    public static function maxItems(array $field): int
    {
        $max = is_int($field['max_items'] ?? null) ? $field['max_items'] : self::DEFAULT_MAX_ITEMS;

        return max(1, min(self::MAX_ITEMS_CAP, $max));
    }

    /**
     * 선언(칸 정의) 규약 위반을 모읍니다.
     *
     * @param  string  $path  칸 경로 (오류 문맥)
     * @param  array<string, mixed>  $field  칸 정의
     * @return array<int, string> 위반
     */
    public static function declarationErrors(string $path, array $field): array
    {
        $errors = [];

        if (array_key_exists('max_depth', $field) && (! is_int($field['max_depth']) || $field['max_depth'] < 1 || $field['max_depth'] > self::MAX_DEPTH)) {
            $errors[] = "schema.{$path}.max_depth: 1~".self::MAX_DEPTH;
        }

        if (array_key_exists('max_items', $field) && is_int($field['max_items']) && ($field['max_items'] < 1 || $field['max_items'] > self::MAX_ITEMS_CAP)) {
            $errors[] = "schema.{$path}.max_items: 1~".self::MAX_ITEMS_CAP;
        }

        if (array_key_exists('auto_items', $field)) {
            $auto = $field['auto_items'];

            if (! is_array($auto) || ($auto !== [] && array_is_list($auto))) {
                $errors[] = "schema.{$path}.auto_items: object";
            } else {
                foreach ($auto as $key => $definition) {
                    if (! is_string($key) || preg_match('/^[a-z][a-z0-9_]*$/', $key) !== 1 || $key === self::KIND_LINK || ! is_array($definition)) {
                        $errors[] = "schema.{$path}.auto_items.{$key}";
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * 값의 구조 위반을 모읍니다 — 저장 검증과 기본값 선언 검사가 함께 쓴다.
     *
     * 반환 키는 오류 위치(`''` = 칸 전체, `2` · `2.children.0` = 그 항목), 값은 문구 키(`templates.settings.menu.*`)와 치환값이다.
     *
     * @param  mixed  $items  값
     * @param  array<string, mixed>  $field  칸 정의
     * @return array<int, array{at: string, key: string, replace: array<string, mixed>}> 위반
     */
    public static function structureErrors(mixed $items, array $field): array
    {
        if (! is_array($items) || ! array_is_list($items)) {
            return [['at' => '', 'key' => 'templates.settings.menu.not_list', 'replace' => []]];
        }

        $errors = [];
        $autoKinds = self::autoKinds($field);
        $maxDepth = self::maxDepth($field);
        $seenIds = [];
        $seenAuto = [];
        $count = 0;

        $walk = function (array $list, string $prefix, int $depth) use (&$walk, &$errors, &$seenIds, &$seenAuto, &$count, $autoKinds, $maxDepth): void {
            foreach ($list as $index => $item) {
                $at = $prefix === '' ? (string) $index : "{$prefix}.children.{$index}";
                $count++;

                if (! is_array($item)) {
                    $errors[] = ['at' => $at, 'key' => 'templates.settings.menu.item_invalid', 'replace' => []];

                    continue;
                }

                $id = $item['id'] ?? null;

                if (! is_string($id) || preg_match(self::ID_PATTERN, $id) !== 1) {
                    $errors[] = ['at' => $at, 'key' => 'templates.settings.menu.id_invalid', 'replace' => []];
                } elseif (isset($seenIds[$id])) {
                    $errors[] = ['at' => $at, 'key' => 'templates.settings.menu.id_duplicate', 'replace' => []];
                } else {
                    $seenIds[$id] = true;
                }

                $kind = $item['kind'] ?? null;
                $children = $item['children'] ?? [];
                $hasChildren = is_array($children) && $children !== [];

                if ($kind !== self::KIND_LINK && ! in_array($kind, $autoKinds, true)) {
                    $errors[] = ['at' => $at, 'key' => 'templates.settings.menu.kind_invalid', 'replace' => []];
                } elseif ($kind !== self::KIND_LINK) {
                    if (isset($seenAuto[$kind])) {
                        $errors[] = ['at' => $at, 'key' => 'templates.settings.menu.auto_duplicate', 'replace' => []];
                    }

                    $seenAuto[$kind] = true;

                    if ($hasChildren) {
                        $errors[] = ['at' => $at, 'key' => 'templates.settings.menu.auto_no_children', 'replace' => []];
                    }
                } else {
                    if (! self::hasLabel($item['label'] ?? null)) {
                        $errors[] = ['at' => $at, 'key' => 'templates.settings.menu.label_required', 'replace' => []];
                    }

                    $url = $item['url'] ?? null;

                    if (($url === null || $url === '') && ! $hasChildren) {
                        $errors[] = ['at' => $at, 'key' => 'templates.settings.menu.url_required', 'replace' => []];
                    }
                }

                if (array_key_exists('children', $item) && $item['children'] !== null && (! is_array($item['children']) || ! array_is_list($item['children']))) {
                    $errors[] = ['at' => $at, 'key' => 'templates.settings.menu.item_invalid', 'replace' => []];
                } elseif ($hasChildren && $depth >= $maxDepth) {
                    $errors[] = ['at' => $at, 'key' => 'templates.settings.menu.too_deep', 'replace' => ['depth' => $maxDepth]];
                } elseif ($hasChildren) {
                    $walk($children, $at, $depth + 1);
                }

                $visibility = $item['visibility'] ?? 'all';

                if (! in_array($visibility, self::VISIBILITIES, true)) {
                    $errors[] = ['at' => $at, 'key' => 'templates.settings.menu.visibility_invalid', 'replace' => []];
                } elseif ($visibility === 'roles' && (! is_array($item['roles'] ?? null) || $item['roles'] === [])) {
                    $errors[] = ['at' => $at, 'key' => 'templates.settings.menu.roles_required', 'replace' => []];
                }
            }
        };

        $walk($items, '', 1);

        if ($count > self::maxItems($field)) {
            array_unshift($errors, ['at' => '', 'key' => 'templates.settings.menu.too_many', 'replace' => ['max' => self::maxItems($field)]]);
        }

        return $errors;
    }

    /**
     * 항목 칸 단위 검증 규칙 — 상위·하위 각 단계에 같은 규칙을 건다.
     *
     * 구조(종류별 필수·자동 항목 1회·깊이·전체 수·id 중복)는 {@see self::structureErrors()} 가 본다.
     *
     * @param  string  $path  칸 경로
     * @param  array<string, mixed>  $field  칸 정의
     * @return array<string, array<int, mixed>> 속성 => 규칙
     */
    public static function itemRules(string $path, array $field): array
    {
        $rules = [];
        $levels = [$path.'.*'];

        if (self::maxDepth($field) >= 2) {
            $levels[] = $path.'.*.children.*';
        }

        // 저장값은 규칙이 있는 키만 남는다(FormRequest::validated) — 항목의 모든 키에 규칙을 둔다.
        // 형식·종류·깊이의 판정은 structureErrors 가 항목 위치에 단다.
        foreach ($levels as $level) {
            $rules[$level] = ['array'];
            $rules[$level.'.id'] = ['required', 'string', 'max:40'];
            $rules[$level.'.kind'] = ['required', 'string', 'max:40'];
            $rules[$level.'.visibility'] = ['nullable', 'string', 'max:20'];
            $rules[$level.'.children'] = ['nullable', 'array'];
            $rules[$level.'.label'] = ['nullable', new TranslatableField(self::LABEL_MAX_LENGTH)];
            $rules[$level.'.url'] = ['nullable', 'string', 'max:2048', new SafeLinkRule];
            $rules[$level.'.new_tab'] = ['nullable', 'boolean'];
            $rules[$level.'.is_active'] = ['nullable', 'boolean'];
            $rules[$level.'.roles'] = ['nullable', 'array', 'max:50'];
            $rules[$level.'.roles.*'] = ['string', 'max:100', Rule::exists(Role::class, 'identifier')];
        }

        return $rules;
    }

    /**
     * 값을 저장 형태로 정리합니다 — 알려진 키만 남기고 불리언을 맞추며, 자동 항목·깊이 밖의 하위 메뉴는 버린다.
     *
     * @param  mixed  $items  값
     * @param  array<string, mixed>  $field  칸 정의
     * @return mixed 정리한 값 (목록이 아니면 그대로 — 검증이 거른다)
     */
    public static function normalize(mixed $items, array $field): mixed
    {
        if (! is_array($items) || ! array_is_list($items)) {
            return $items;
        }

        $maxDepth = self::maxDepth($field);

        $clean = function (array $list, int $depth) use (&$clean, $maxDepth): array {
            $out = [];

            foreach ($list as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $row = array_intersect_key($item, array_flip(self::ITEM_KEYS));
                $kind = $row['kind'] ?? self::KIND_LINK;
                $row['label'] = is_string($row['label'] ?? null) ? [config('app.locale') => $row['label']] : (is_array($row['label'] ?? null) ? $row['label'] : []);
                $row['new_tab'] = filter_var($row['new_tab'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $row['is_active'] = filter_var($row['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN);
                $row['visibility'] = $row['visibility'] ?? 'all';
                $row['roles'] = $row['visibility'] === 'roles' ? array_values(array_filter((array) ($row['roles'] ?? []), 'is_string')) : [];

                if ($kind !== self::KIND_LINK) {
                    unset($row['url'], $row['new_tab']);
                } else {
                    $row['url'] = is_string($row['url'] ?? null) ? trim($row['url']) : '';
                }

                $children = is_array($row['children'] ?? null) ? $row['children'] : [];
                $row['children'] = ($kind === self::KIND_LINK && $depth < $maxDepth) ? $clean($children, $depth + 1) : [];

                $out[] = $row;
            }

            return $out;
        };

        return $clean($items, 1);
    }

    /**
     * 다국어 이름에 채운 언어가 하나라도 있는지
     *
     * @param  mixed  $label  이름 값
     * @return bool 있으면 true
     */
    private static function hasLabel(mixed $label): bool
    {
        if (is_string($label)) {
            return trim($label) !== '';
        }

        return is_array($label) && array_filter($label, fn ($v) => is_string($v) && trim($v) !== '') !== [];
    }

    /**
     * 화면 서술 — 편집기가 그리는 데 필요한 선언 값.
     *
     * @param  array<string, mixed>  $field  칸 정의
     * @param  callable(mixed): (string|null)  $translate  라벨 번역기
     * @return array<string, mixed> 서술 조각
     */
    public static function describe(array $field, callable $translate): array
    {
        $auto = [];

        foreach (is_array($field['auto_items'] ?? null) ? $field['auto_items'] : [] as $key => $definition) {
            $auto[] = [
                'key' => (string) $key,
                'label' => $translate($definition['label'] ?? null) ?? (string) $key,
                'hint' => $translate($definition['hint'] ?? null),
            ];
        }

        return [
            'max_items' => self::maxItems($field),
            'max_depth' => self::maxDepth($field),
            'auto_items' => $auto,
        ];
    }

    /**
     * 검증 유틸 — 값과 칸 정의로 구조 위반을 Laravel 오류 형태(`경로` => [문구])로 돌려준다.
     *
     * @param  string  $path  칸 경로
     * @param  mixed  $items  값
     * @param  array<string, mixed>  $field  칸 정의
     * @return array<string, array<int, string>> 오류
     */
    public static function messages(string $path, mixed $items, array $field): array
    {
        $messages = [];

        foreach (self::structureErrors($items, $field) as $error) {
            $attribute = $error['at'] === '' ? $path : "{$path}.{$error['at']}";
            $messages[$attribute][] = __($error['key'], $error['replace']);
        }

        return $messages;
    }
}
