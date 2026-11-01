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
    // 선택 카테고리 — 종류 기본 순서(관리자 템플릿 → 모듈 → 플러그인 → 사용자 템플릿 → 언어팩). 의존이 있으면 앞당긴다(installer_install_order).
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
     * @return array{selection: array<string, array<int, string>>, auto_added: array<int, array{category: string, identifier: string, required_by: string}>, excluded_language_packs: array<int, string>, errors: array<int, array{key: string, params: array<string, string>}>, install_order: array<int, string>, pulled_by: array<string, array<int, string>>}
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

                        $targetCat = installer_dependency_category($dep, $idToCategory);

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
        //   의존 카테고리는 선언된 종류를 먼저 본다 — 같은 식별자가 모듈·플러그인 양쪽에 있어도 섞지 않는다.
        foreach ($categories as $cat) {
            foreach ($selected[$cat] as $ownerId) {
                foreach ($items[$cat][$ownerId]['dependencies_detailed'] ?? [] as $dep) {
                    $depId = (string) ($dep['identifier'] ?? '');
                    $constraint = (string) ($dep['version'] ?? '*');
                    $depCat = installer_dependency_category($dep, $idToCategory);
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

        // 설치 순서 — 종류를 넘는 의존까지 한 그래프로 정렬한다(기본 순서는 종류 → 스캔, 의존만 앞당김).
        $ordered = installer_install_order($selected, $items, $idToCategory, $errors);
        foreach ($categories as $cat) {
            $selected[$cat] = [];
        }
        foreach ($ordered['order'] as $key) {
            [$cat, $id] = explode(':', $key, 2);
            $selected[$cat][] = $id;
        }

        return [
            'selection' => $selected,
            'auto_added' => $autoAdded,
            'excluded_language_packs' => $excludedLanguagePacks,
            'errors' => $errors,
            'install_order' => $ordered['order'],
            'pulled_by' => $ordered['pulled_by'],
        ];
    }
}

if (! function_exists('installer_dependency_category')) {
    /**
     * 의존 항목이 가리키는 카테고리 — 선언된 종류(`modules`/`plugins`)를 먼저 쓰고, 옛 평면 배열(종류 없음)만 식별자 역인덱스로 찾는다.
     *
     * @param  array<string, mixed>  $dep  `dependencies_detailed` 항목
     * @param  array<string, string>  $idToCategory  식별자 → 처음 나온 카테고리
     * @return string|null 카테고리 (찾지 못하면 null)
     */
    function installer_dependency_category(array $dep, array $idToCategory): ?string
    {
        $type = (string) ($dep['type'] ?? '');
        if (in_array($type, ['modules', 'plugins'], true)) {
            return $type;
        }

        return $idToCategory[(string) ($dep['identifier'] ?? '')] ?? null;
    }
}

if (! function_exists('installer_install_order')) {
    /**
     * 설치 순서 — 관리자 템플릿·모듈·플러그인·사용자 템플릿을 한 그래프로 놓고 종류를 넘는 의존까지 반영한 안정 위상 정렬.
     *
     * 기본 순서는 종류 순서(관리자 템플릿 → 모듈 → 플러그인 → 사용자 템플릿) → 그 안의 스캔 순서이고, 기본 순서대로
     * 깊이 우선으로 돌며 선택 집합 안의 의존을 먼저 놓는다 — 의존이 없는 구성은 순서가 바뀌지 않는다.
     * 자기 자신 의존은 건너뛰고, 순환이면 `종류:식별자` 경로로 오류를 남기고 기본 순서를 돌려준다.
     * 언어팩은 그래프 밖에서 코어 범위 팩을 먼저, 나머지는 스캔 순서로 맨 뒤에 붙인다(확장 팩은 코어 locale 팩을 요구한다).
     *
     * `pulled_by` 는 종류 경계를 넘어 앞당겨진 노드 → 그 노드를 요구한 선택 노드 목록이다(설치 화면 「…에 필요」 표시).
     * 같은 종류 안의 정렬은 기록하지 않는다.
     *
     * @param  array<string, array<int, string>>  $selected  카테고리별 선택 식별자
     * @param  array<string, array<string, array<string, mixed>>>  $items  카테고리별 스캔 항목 (식별자 → 항목, 스캔 순서)
     * @param  array<string, string>  $idToCategory  식별자 → 처음 나온 카테고리
     * @param  array<int, array{key: string, params: array<string, string>}>  &$errors  오류 목록 (참조)
     * @return array{order: array<int, string>, pulled_by: array<string, array<int, string>>} `카테고리:식별자` 설치 순서와 앞당김 기록
     */
    function installer_install_order(array $selected, array $items, array $idToCategory, array &$errors): array
    {
        $graphCategories = ['admin_templates', 'modules', 'plugins', 'user_templates'];
        $rank = array_flip($graphCategories);

        // 기본 순서 — 종류 순서 → 스캔 순서
        $base = [];
        foreach ($graphCategories as $cat) {
            $scanOrder = array_flip(array_keys($items[$cat] ?? []));
            $ids = $selected[$cat] ?? [];
            usort($ids, fn (string $a, string $b): int => ($scanOrder[$a] ?? PHP_INT_MAX) <=> ($scanOrder[$b] ?? PHP_INT_MAX));
            foreach ($ids as $id) {
                $base[] = $cat.':'.$id;
            }
        }
        $set = array_flip($base);

        $dependencyKeys = function (string $key) use ($items, $idToCategory, $set): array {
            [$cat, $id] = explode(':', $key, 2);
            $keys = [];
            foreach ($items[$cat][$id]['dependencies_detailed'] ?? [] as $dep) {
                $depCat = installer_dependency_category($dep, $idToCategory);
                $depKey = $depCat.':'.(string) ($dep['identifier'] ?? '');
                // 자기 자신 의존은 순환이 아니라 잘못 적은 선언이다 — 건너뛴다. 선택 밖 의존은 설치 여부 검사가 따로 본다.
                if ($depCat !== null && $depKey !== $key && isset($set[$depKey])) {
                    $keys[] = $depKey;
                }
            }

            return $keys;
        };

        $order = [];
        $state = [];
        $visit = function (string $key, array $path) use (&$visit, &$order, &$state, &$errors, $dependencyKeys): bool {
            if (($state[$key] ?? 0) === 2) {
                return true;
            }
            if (($state[$key] ?? 0) === 1) {
                $errors[] = ['key' => 'error_extension_dependency_cycle', 'params' => ['group' => 'extensions', 'cycle' => implode(' → ', [...$path, $key])]];

                return false;
            }

            $state[$key] = 1;
            foreach ($dependencyKeys($key) as $depKey) {
                if (! $visit($depKey, [...$path, $key])) {
                    return false;
                }
            }
            $state[$key] = 2;
            $order[] = $key;

            return true;
        };

        $pulledBy = [];
        $sorted = true;
        foreach ($base as $key) {
            if (! $visit($key, [])) {
                $sorted = false;
                break;
            }
        }

        if ($sorted) {
            foreach ($base as $key) {
                $ownerRank = $rank[explode(':', $key, 2)[0]];
                foreach ($dependencyKeys($key) as $depKey) {
                    if ($ownerRank < $rank[explode(':', $depKey, 2)[0]]) {
                        $pulledBy[$depKey][] = $key;
                    }
                }
            }
        } else {
            $order = $base;
        }

        // 언어팩 — 코어 범위 팩 먼저, 그 안과 나머지는 스캔 순서
        $packs = $selected['language_packs'] ?? [];
        $packScan = array_flip(array_keys($items['language_packs'] ?? []));
        $isCore = fn (string $id): int => ((string) ($items['language_packs'][$id]['scope'] ?? 'core')) === 'core' ? 0 : 1;
        usort($packs, fn (string $a, string $b): int => [$isCore($a), $packScan[$a] ?? PHP_INT_MAX] <=> [$isCore($b), $packScan[$b] ?? PHP_INT_MAX]);
        foreach ($packs as $id) {
            $order[] = 'language_packs:'.$id;
        }

        return ['order' => $order, 'pulled_by' => $pulledBy];
    }
}

if (! function_exists('installer_extension_install_tasks')) {
    /**
     * 설치 순서(`카테고리:식별자`)를 확장 설치 작업 목록으로 바꾼다 — 웹·터미널 설치(runInstallationTasks)가 그대로 실행한다.
     *
     * 작업 id·target·함수 이름은 종류별로 고정이다 — 중단 뒤 이어 하기의 `completed_tasks` 키(`{id}:{target}`)가 순서와 무관하게 맞는다.
     *
     * @param  array<int, string>  $installOrder  설치 순서
     * @return array<int, array{id: string, target: string, function: string, args: array<int, string>, best_effort?: bool}> 작업 목록
     */
    function installer_extension_install_tasks(array $installOrder): array
    {
        $pairs = [
            'admin_templates' => [['template_install', 'installAdminTemplateSSE'], ['template_activate', 'activateAdminTemplateSSE']],
            'modules' => [['module_install', 'installModuleSSE'], ['module_activate', 'activateModuleSSE']],
            'plugins' => [['plugin_install', 'installPluginSSE'], ['plugin_activate', 'activatePluginSSE']],
            'user_templates' => [['user_template_install', 'installUserTemplateSSE'], ['user_template_activate', 'activateUserTemplateSSE']],
            // 번들 언어팩은 best-effort — 1건 실패로 전체를 멈추지 않는다
            'language_packs' => [['language_pack_install', 'installLanguagePackSSE']],
        ];

        $tasks = [];
        foreach ($installOrder as $entry) {
            [$category, $id] = explode(':', $entry, 2);
            foreach ($pairs[$category] ?? [] as [$taskId, $function]) {
                $task = ['id' => $taskId, 'target' => $id, 'function' => $function, 'args' => [$id]];
                if ($category === 'language_packs') {
                    $task['best_effort'] = true;
                }
                $tasks[] = $task;
            }
        }

        return $tasks;
    }
}

if (! function_exists('installer_category_install_order')) {
    /**
     * 저장된 선택을 정렬 없이 종류 순서대로 펼친 설치 순서 — 해석에 실패해 사전 검증이 멈출 때 작업 목록을 종전처럼 만든다.
     *
     * @param  array<string, mixed>  $selection  카테고리별 선택 식별자
     * @return array<int, string> `카테고리:식별자` 목록
     */
    function installer_category_install_order(array $selection): array
    {
        $order = [];
        foreach (INSTALLER_EXTENSION_CATEGORIES as $category) {
            foreach ((array) ($selection[$category] ?? []) as $id) {
                $order[] = $category.':'.$id;
            }
        }

        return $order;
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
