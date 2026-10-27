<?php

/**
 * 그누보드7 인스톨러 - 확장 선택 해석기 (웹·터미널 공유)
 *
 * 웹 Step 4 화면(installer.js)의 의존성 자동 선택·버전 제약·언어팩 대상 연동 규칙을 서버로 옮긴 것이다.
 * 확장 저장 API(`save-extensions.php`), 설치 직전 의존성 사전 검증(`dependencyPrecheckSSE()`),
 * 터미널 설치(`php g7-install`)가 **같은 함수**를 부른다. 같은 규칙의 JS 사본은
 * `public/install/assets/js/installer-extension-resolver.js` 이며, 두 구현은 공용 픽스처 테스트로 같은 결과를 내도록 잠근다.
 *
 * 순수 함수다 — 파일·상태·세션을 읽거나 쓰지 않는다. 입력 스캔 결과는 `scan-extensions.php` 의 data 형식.
 */

if (! defined('INSTALLER_EXTENSION_CATEGORIES')) {
    // 선택 카테고리 — 설치 순서(관리자 템플릿 → 모듈 → 플러그인 → 사용자 템플릿 → 언어팩)이기도 하다.
    define('INSTALLER_EXTENSION_CATEGORIES', ['admin_templates', 'modules', 'plugins', 'user_templates', 'language_packs']);
}

if (! function_exists('installer_satisfies_version_constraint')) {
    /**
     * 버전 제약 충족 여부 — installer.js `satisfiesVersionConstraint()` 와 같은 판정식.
     *
     * 지원 연산자: >=, >, <=, <, =, ==, !=, ^(메이저 고정), ~(마이너 고정). 연산자 없으면 >=.
     *
     * @param  string  $available  사용 가능한 버전
     * @param  string|null  $constraint  제약 (빈 값·* 은 항상 충족)
     * @return bool 충족 여부
     */
    function installer_satisfies_version_constraint(string $available, ?string $constraint): bool
    {
        if ($constraint === null || $constraint === '' || $constraint === '*') {
            return true;
        }

        $parse = static function (string $version): array {
            $parts = array_map('intval', explode('.', (string) preg_replace('/^[v=]/', '', $version)));

            return [$parts[0] ?? 0, $parts[1] ?? 0, $parts[2] ?? 0];
        };

        if (! preg_match('/^([><=^~!]*)\s*(.+)$/', $constraint, $m)) {
            return true;
        }

        $op = $m[1] !== '' ? $m[1] : '>=';
        $avail = $parse($available);
        $req = $parse($m[2]);
        $cmp = $avail <=> $req;

        return match ($op) {
            '>=' => $cmp >= 0,
            '>' => $cmp > 0,
            '<=' => $cmp <= 0,
            '<' => $cmp < 0,
            '=', '==' => $cmp === 0,
            '!=' => $cmp !== 0,
            '^' => $cmp >= 0 && $avail[0] === $req[0],
            '~' => $cmp >= 0 && $avail[0] === $req[0] && $avail[1] === $req[1],
            default => $cmp >= 0,
        };
    }
}

if (! function_exists('installer_extension_display_name')) {
    /**
     * 확장 표시 이름 — 다국어 객체면 현재 설치 언어 → ko → en → 식별자 (installer.js getLocalizedText 와 같은 우선순위).
     *
     * 현재 언어는 웹·터미널 설치가 함께 쓰는 세션 값(g7_locale)이다.
     *
     * @param  array<string, mixed>  $item  스캔 항목
     * @return string 표시 이름
     */
    function installer_extension_display_name(array $item): string
    {
        $name = $item['name'] ?? null;
        if (is_array($name)) {
            $locale = (string) ($_SESSION['g7_locale'] ?? 'ko');

            return (string) ($name[$locale] ?? $name['ko'] ?? $name['en'] ?? $item['identifier'] ?? '');
        }

        return is_string($name) && $name !== '' ? $name : (string) ($item['identifier'] ?? '');
    }
}

if (! function_exists('installer_resolve_extension_selection')) {
    /**
     * 요청한 확장 선택을 해석한다 — 존재 확인·의존 자동 포함·버전 제약·단일 선택·언어팩 대상 연동·설치 순서.
     *
     * 오류 항목은 `['key' => 번역 키, 'params' => 치환값]`. 오류가 하나라도 있으면 저장·설치하지 않는다.
     *
     * @param  array<string, array<int, array<string, mixed>>>  $scan  스캔 결과 (카테고리별 항목)
     * @param  array<string, mixed>  $requested  카테고리별 요청 식별자 목록
     * @return array{selection: array<string, array<int, string>>, auto_added: array<int, array{category: string, identifier: string, required_by: string}>, excluded_language_packs: array<int, string>, errors: array<int, array{key: string, params: array<string, string>}>, install_order: array<int, string>}
     */
    function installer_resolve_extension_selection(array $scan, array $requested): array
    {
        $categories = INSTALLER_EXTENSION_CATEGORIES;
        $errors = [];
        $autoAdded = [];

        // 카테고리별 식별자 → 항목, 전체 식별자 → 카테고리 역인덱스
        $items = [];
        $idToCategory = [];
        foreach ($categories as $cat) {
            $items[$cat] = [];
            foreach ($scan[$cat] ?? [] as $item) {
                $id = (string) ($item['identifier'] ?? '');
                if ($id === '') {
                    continue;
                }
                $items[$cat][$id] = $item;
                $idToCategory[$id] ??= $cat;
            }
        }

        // 요청 정리 — 문자열·중복 제거, 존재 확인
        $selected = [];
        foreach ($categories as $cat) {
            $selected[$cat] = [];
            foreach ((array) ($requested[$cat] ?? []) as $id) {
                $id = trim((string) $id);
                if ($id === '' || in_array($id, $selected[$cat], true)) {
                    continue;
                }
                if (! isset($items[$cat][$id])) {
                    $errors[] = ['key' => 'error_extension_not_found', 'params' => ['identifier' => $id, 'group' => $cat]];

                    continue;
                }
                $selected[$cat][] = $id;
            }
        }

        // 의존성 자동 포함 — 고정점까지 반복(웹과 같은 10회 상한). 의존 확장은 의존 타입 또는 역인덱스로 카테고리를 정한다.
        $unavailable = [];
        for ($iteration = 0; $iteration < 10; $iteration++) {
            $changed = false;
            foreach ($categories as $cat) {
                foreach ($selected[$cat] as $ownerId) {
                    foreach ($items[$cat][$ownerId]['dependencies_detailed'] ?? [] as $dep) {
                        $depId = (string) ($dep['identifier'] ?? '');
                        if ($depId === '') {
                            continue;
                        }

                        $depType = (string) ($dep['type'] ?? '');
                        $targetCat = in_array($depType, ['modules', 'plugins'], true) ? $depType : ($idToCategory[$depId] ?? null);

                        if ($targetCat === null || ! isset($items[$targetCat][$depId])) {
                            $unavailable[$ownerId.'>'.$depId] = ['owner' => installer_extension_display_name($items[$cat][$ownerId]), 'dependency' => $depId];

                            continue;
                        }

                        if (in_array($depId, $selected[$targetCat], true)) {
                            continue;
                        }

                        // 템플릿은 단일 선택 — 웹처럼 교체한다.
                        if (in_array($targetCat, ['admin_templates', 'user_templates'], true)) {
                            $selected[$targetCat] = [$depId];
                        } else {
                            $selected[$targetCat][] = $depId;
                        }
                        $autoAdded[] = ['category' => $targetCat, 'identifier' => $depId, 'required_by' => $ownerId];
                        $changed = true;
                    }
                }
            }

            if (! $changed) {
                break;
            }
        }

        foreach ($unavailable as $entry) {
            $errors[] = ['key' => 'error_extension_dependency_unavailable', 'params' => $entry];
        }

        // 버전 제약 — 선택된 의존 확장의 번들 버전이 제약을 충족해야 한다.
        foreach ($categories as $cat) {
            foreach ($selected[$cat] as $ownerId) {
                foreach ($items[$cat][$ownerId]['dependencies_detailed'] ?? [] as $dep) {
                    $depId = (string) ($dep['identifier'] ?? '');
                    $constraint = (string) ($dep['version'] ?? '*');
                    $depCat = $idToCategory[$depId] ?? null;
                    if ($depCat === null || ! in_array($depId, $selected[$depCat], true)) {
                        continue;
                    }
                    $available = (string) ($items[$depCat][$depId]['version'] ?? '0.0.0');
                    if (! installer_satisfies_version_constraint($available, $constraint)) {
                        $errors[] = ['key' => 'error_extension_dependency_version', 'params' => [
                            'owner' => installer_extension_display_name($items[$cat][$ownerId]),
                            'dependency' => $depId,
                            'required' => $constraint,
                            'available' => $available,
                        ]];
                    }
                }
            }
        }

        // 단일 선택 — 관리자 템플릿 정확히 1개, 사용자 템플릿 0~1개.
        if (count($selected['admin_templates']) === 0) {
            $errors[] = ['key' => 'error_admin_template_required', 'params' => []];
        } elseif (count($selected['admin_templates']) > 1) {
            $errors[] = ['key' => 'error_admin_template_single', 'params' => []];
        }
        if (count($selected['user_templates']) > 1) {
            $errors[] = ['key' => 'error_user_template_single', 'params' => []];
        }

        // 언어팩 대상 연동 — 대상 확장이 선택되지 않은 언어팩은 제외한다(웹 카드 비활성·자동 해제와 같음).
        $excludedLanguagePacks = [];
        $selected['language_packs'] = array_values(array_filter($selected['language_packs'], function (string $id) use ($items, $selected, &$excludedLanguagePacks): bool {
            $pack = $items['language_packs'][$id];
            $scope = (string) ($pack['scope'] ?? 'core');
            $target = (string) ($pack['target_identifier'] ?? '');
            if ($scope === 'core' || $target === '') {
                return true;
            }

            $matched = match ($scope) {
                'module' => in_array($target, $selected['modules'], true),
                'plugin' => in_array($target, $selected['plugins'], true),
                'template' => in_array($target, $selected['admin_templates'], true) || in_array($target, $selected['user_templates'], true),
                default => false,
            };
            if (! $matched) {
                $excludedLanguagePacks[] = $id;
            }

            return $matched;
        }));

        // 설치 순서 — 카테고리 순서 안에서 같은 카테고리 의존을 위상 정렬(스캔 순서 기준 안정 정렬).
        $installOrder = [];
        foreach ($categories as $cat) {
            $sorted = installer_topological_order($cat, $selected[$cat], $items[$cat], $errors);
            $selected[$cat] = $sorted;
            foreach ($sorted as $id) {
                $installOrder[] = $cat.':'.$id;
            }
        }

        return [
            'selection' => $selected,
            'auto_added' => $autoAdded,
            'excluded_language_packs' => $excludedLanguagePacks,
            'errors' => $errors,
            'install_order' => $installOrder,
        ];
    }
}

if (! function_exists('installer_topological_order')) {
    /**
     * 같은 카테고리 안 의존을 반영한 설치 순서 — 스캔 순서를 기준으로 하는 안정 위상 정렬.
     *
     * 순환이 있으면 오류를 기록하고 스캔 순서를 그대로 돌려준다.
     *
     * @param  string  $category  카테고리
     * @param  array<int, string>  $ids  선택 식별자
     * @param  array<string, array<string, mixed>>  $items  카테고리의 스캔 항목 (식별자 → 항목, 스캔 순서)
     * @param  array<int, array{key: string, params: array<string, string>}>  &$errors  오류 목록 (참조)
     * @return array<int, string> 설치 순서
     */
    function installer_topological_order(string $category, array $ids, array $items, array &$errors): array
    {
        $scanOrder = array_flip(array_keys($items));
        usort($ids, fn (string $a, string $b): int => ($scanOrder[$a] ?? PHP_INT_MAX) <=> ($scanOrder[$b] ?? PHP_INT_MAX));

        $set = array_flip($ids);
        $sorted = [];
        $state = [];

        $visit = function (string $id, array $path) use (&$visit, &$sorted, &$state, $set, $items, $category, &$errors): bool {
            if (($state[$id] ?? 0) === 2) {
                return true;
            }
            if (($state[$id] ?? 0) === 1) {
                $errors[] = ['key' => 'error_extension_dependency_cycle', 'params' => ['group' => $category, 'cycle' => implode(' → ', [...$path, $id])]];

                return false;
            }

            $state[$id] = 1;
            foreach ($items[$id]['dependencies_detailed'] ?? [] as $dep) {
                $depId = (string) ($dep['identifier'] ?? '');
                if ($depId !== '' && isset($set[$depId]) && ! $visit($depId, [...$path, $id])) {
                    return false;
                }
            }
            $state[$id] = 2;
            $sorted[] = $id;

            return true;
        };

        foreach ($ids as $id) {
            if (! $visit($id, [])) {
                return $ids;
            }
        }

        return $sorted;
    }
}

if (! function_exists('installer_scan_extensions')) {
    /**
     * 번들 확장을 스캔한다 — 웹 Step 4 화면이 받는 `scan-extensions.php` 응답의 data 와 같은 구조.
     *
     * @return array<string, array<int, array<string, mixed>>> 카테고리별 스캔 항목
     */
    function installer_scan_extensions(): array
    {
        if (! function_exists('scanTemplates')) {
            if (! defined('SCAN_EXTENSIONS_LIBRARY')) {
                define('SCAN_EXTENSIONS_LIBRARY', true);
            }
            require_once dirname(__DIR__).'/api/scan-extensions.php';
        }

        $templates = scanTemplates();

        return [
            'admin_templates' => $templates['admin'],
            'user_templates' => $templates['user'],
            'modules' => scanModules(),
            'plugins' => scanPlugins(),
            'language_packs' => scanLanguagePacks(),
        ];
    }
}

if (! function_exists('installer_extension_error_messages')) {
    /**
     * 해석기 오류 목록을 번역 문구로 바꾼다.
     *
     * @param  array<int, array{key: string, params: array<string, string>}>  $errors  오류 목록
     * @return array<int, string> 번역 문구
     */
    function installer_extension_error_messages(array $errors): array
    {
        return array_map(fn (array $e): string => lang($e['key'], $e['params']), $errors);
    }
}
