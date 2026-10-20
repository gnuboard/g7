<?php

namespace App\Services\Core;

use App\Contracts\Repositories\LanguagePackRepositoryInterface;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Extension\Helpers\ExtensionPendingHelper;
use App\Extension\Helpers\FilePermissionHelper;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * 공식 번들 소스 배치 판정 서비스 (공개 #146).
 *
 * 코어 업데이트가 새 배포본의 `_bundled` 를 통째로 깔던 동작을 「설치됐거나 디스크에 있는 번들과 그
 * 의존 번들만 갱신하고, 나머지는 운영자가 고른 것만 배치」로 바꾸기 위한 판정을 맡는다.
 * 릴리스 인벤토리 읽기(디렉토리·zip), 의존 폐포, 배치/미배치 집합 산출, 건너뛸 경로, 기록 파일
 * 읽기·쓰기를 담당한다. 순수 판정(`plan`)과 파일 I/O 를 나눠 단위 테스트가 쉽게 했다.
 *
 * 설치 여부는 Repository 로 조회하고, DB 에 접근할 수 없으면(테이블 부재 등) 「활성 디렉토리가
 * 있으면 설치됨」으로 폴백하며 경고 로그를 남긴다.
 */
class BundledSourcePlanner
{
    /** 기록 파일 스키마 버전 */
    public const RECORD_SCHEMA = 1;

    /** 확장 유형 → [도메인 디렉토리, manifest 파일명] */
    public const EXTENSION_DOMAINS = [
        'module' => ['modules', 'module.json'],
        'plugin' => ['plugins', 'plugin.json'],
        'template' => ['templates', 'template.json'],
    ];

    /** 언어팩 [도메인 디렉토리, manifest 파일명] */
    public const LANGUAGE_PACK_DOMAIN = ['lang-packs', 'language-pack.json'];

    /** 모든 유형 */
    public const TYPES = ['module', 'plugin', 'template', 'language_pack'];

    /**
     * 판정 중 한 번만 조회하도록 담아 두는 설치 키 집합.
     *
     * @var array<string, bool>|null
     */
    private ?array $installedCache = null;

    /**
     * @param  ModuleRepositoryInterface  $modules  모듈 저장소
     * @param  PluginRepositoryInterface  $plugins  플러그인 저장소
     * @param  TemplateRepositoryInterface  $templates  템플릿 저장소
     * @param  LanguagePackRepositoryInterface  $languagePacks  언어팩 저장소
     */
    public function __construct(
        private readonly ModuleRepositoryInterface $modules,
        private readonly PluginRepositoryInterface $plugins,
        private readonly TemplateRepositoryInterface $templates,
        private readonly LanguagePackRepositoryInterface $languagePacks,
    ) {}

    // ────────────────────────────────────────────────────────────────
    // 릴리스 인벤토리
    // ────────────────────────────────────────────────────────────────

    /**
     * 배포본 디렉토리에서 릴리스 인벤토리를 읽습니다.
     *
     * 디렉토리명이 manifest identifier 와 같은 것만 인정한다(`ExtensionPendingHelper::loadExtensions` 규칙).
     *
     * @param  string  $root  배포본 루트 디렉토리 (config/app.php 가 있는 곳)
     * @return BundledInventory 릴리스 인벤토리
     */
    public function readInventoryFromDirectory(string $root): BundledInventory
    {
        $entries = [];

        foreach (self::EXTENSION_DOMAINS as $type => [$domain, $manifestName]) {
            $found = ExtensionPendingHelper::loadExtensions($root.DIRECTORY_SEPARATOR.$domain, '_bundled', $manifestName);
            foreach ($found as $identifier => $manifest) {
                $entries[BundledInventory::key($type, (string) $identifier)] = $this->makeEntry($type, (string) $identifier, $manifest);
            }
        }

        [$domain, $manifestName] = self::LANGUAGE_PACK_DOMAIN;
        $found = ExtensionPendingHelper::loadExtensions($root.DIRECTORY_SEPARATOR.$domain, '_bundled', $manifestName);
        foreach ($found as $identifier => $manifest) {
            $entries[BundledInventory::key('language_pack', (string) $identifier)] = $this->makeEntry('language_pack', (string) $identifier, $manifest);
        }

        $entries = self::inheritHiddenFromTargets($entries);
        ksort($entries, SORT_STRING);

        $configPath = $root.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'app.php';
        $coreVersion = is_file($configPath) ? self::parseCoreVersion((string) file_get_contents($configPath)) : null;

        return new BundledInventory($coreVersion, $entries, $root);
    }

    /**
     * 배포본 zip 을 풀지 않고 항목명만 읽어 릴리스 인벤토리를 만듭니다.
     *
     * 최상위 래퍼 폴더(`g7-7.1.1/…`)는 `config/app.php` 위치로 접두사를 판정한다.
     * `ZipArchive` 가 없거나 zip 을 열 수 없으면 null 을 돌려준다 — 호출자는 추출 뒤로 미룬다.
     *
     * @param  string  $zipPath  배포본 zip 경로
     * @return BundledInventory|null 릴리스 인벤토리 또는 null
     */
    public function readInventoryFromZip(string $zipPath): ?BundledInventory
    {
        if (! $this->zipArchiveAvailable() || ! is_file($zipPath)) {
            return null;
        }

        $zip = new \ZipArchive;
        if ($zip->open($zipPath) !== true) {
            return null;
        }

        try {
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                $names[] = str_replace('\\', '/', $name);
            }

            $prefix = $this->detectZipPrefix($names);
            if ($prefix === null) {
                return null;
            }

            $entries = [];
            $manifestPattern = '#^'.preg_quote($prefix, '#').'(modules|plugins|templates|lang-packs)/_bundled/([^/]+)/(module|plugin|template|language-pack)\.json$#';
            $typeByDomain = ['modules' => 'module', 'plugins' => 'plugin', 'templates' => 'template', 'lang-packs' => 'language_pack'];
            $manifestByDomain = ['modules' => 'module', 'plugins' => 'plugin', 'templates' => 'template', 'lang-packs' => 'language-pack'];

            foreach ($names as $name) {
                if (! preg_match($manifestPattern, $name, $m)) {
                    continue;
                }
                if ($manifestByDomain[$m[1]] !== $m[3]) {
                    continue;
                }

                $data = json_decode((string) $zip->getFromName($name), true);
                if (! is_array($data) || $data === []) {
                    continue;
                }

                $identifier = $data['identifier'] ?? $m[2];
                if ($identifier !== $m[2]) {
                    continue;
                }

                $type = $typeByDomain[$m[1]];
                $entries[BundledInventory::key($type, $m[2])] = $this->makeEntry($type, $m[2], $data);
            }

            $entries = self::inheritHiddenFromTargets($entries);
            ksort($entries, SORT_STRING);

            $config = $zip->getFromName($prefix.'config/app.php');
            $coreVersion = is_string($config) ? self::parseCoreVersion($config) : null;

            return new BundledInventory($coreVersion, $entries);
        } finally {
            $zip->close();
        }
    }

    /**
     * 이 환경에서 ZipArchive 를 쓸 수 있는지 판정합니다.
     *
     * @return bool 쓸 수 있으면 true
     */
    protected function zipArchiveAvailable(): bool
    {
        return class_exists(\ZipArchive::class);
    }

    /**
     * zip 항목명 목록에서 배포본 루트 접두사를 찾습니다.
     *
     * @param  array<int, string>  $names  슬래시 정규화된 zip 항목명 목록
     * @return string|null 접두사(`''` 또는 `wrapper/`), 배포본이 아니면 null
     */
    private function detectZipPrefix(array $names): ?string
    {
        $candidates = [];
        foreach ($names as $name) {
            if (preg_match('#^((?:[^/]+/)?)config/app\.php$#', $name, $m)) {
                $candidates[] = $m[1];
            }
        }

        if ($candidates === []) {
            return null;
        }

        return in_array('', $candidates, true) ? '' : $candidates[0];
    }

    /**
     * config/app.php 내용에서 코어 기본 버전 문자열을 읽습니다.
     *
     * @param  string  $content  config/app.php 내용
     * @return string|null 버전 또는 null
     */
    public static function parseCoreVersion(string $content): ?string
    {
        if (preg_match("/['\"]version['\"]\s*=>\s*env\s*\(\s*['\"]APP_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $content, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * manifest 로 인벤토리 항목을 만듭니다.
     *
     * @param  string  $type  module|plugin|template|language_pack
     * @param  string  $identifier  식별자
     * @param  array<string, mixed>  $manifest  manifest 내용
     * @return array<string, mixed> 인벤토리 항목
     */
    private function makeEntry(string $type, string $identifier, array $manifest): array
    {
        $domain = $type === 'language_pack' ? self::LANGUAGE_PACK_DOMAIN[0] : self::EXTENSION_DOMAINS[$type][0];

        $entry = [
            'type' => $type,
            'identifier' => $identifier,
            'version' => (string) ($manifest['version'] ?? ''),
            'name' => $manifest['name'] ?? $identifier,
            'hidden' => ! empty($manifest['hidden']),
            'path' => $domain.'/_bundled/'.$identifier,
        ];

        if ($type === 'language_pack') {
            $entry['scope'] = (string) ($manifest['scope'] ?? '');
            $entry['target_identifier'] = isset($manifest['target_identifier']) ? (string) $manifest['target_identifier'] : null;
        } else {
            $entry['dependencies'] = [
                'modules' => self::dependencyIds($manifest['dependencies']['modules'] ?? []),
                'plugins' => self::dependencyIds($manifest['dependencies']['plugins'] ?? []),
            ];
        }

        return $entry;
    }

    /**
     * 숨김 확장(샘플 등)을 대상으로 하는 확장 언어팩도 숨김으로 표시합니다.
     *
     * 언어팩 manifest 에는 `hidden` 이 없어 본체(hello 샘플)는 숨기면서 그 언어팩만 미배치 안내에 나왔다(7.1.1 출시 전).
     * 대상이 같은 배포본에 있고 숨김일 때만 따른다 — 코어 팩·대상이 배포본에 없는 팩은 그대로 둔다.
     *
     * @param  array<string, array<string, mixed>>  $entries  인벤토리 항목
     * @return array<string, array<string, mixed>> 언어팩 숨김을 보정한 항목
     */
    private static function inheritHiddenFromTargets(array $entries): array
    {
        foreach ($entries as $key => $entry) {
            $targetKey = self::languagePackTargetKey($entry);
            if ($targetKey !== null && ! $entry['hidden'] && ! empty($entries[$targetKey]['hidden'])) {
                $entries[$key]['hidden'] = true;
            }
        }

        return $entries;
    }

    /**
     * 확장 언어팩 항목의 대상 확장 키를 돌려줍니다.
     *
     * @param  array<string, mixed>  $entry  인벤토리 항목
     * @return string|null `{type}:{identifier}` (확장 언어팩이 아니거나 대상이 없으면 null — 코어 팩 포함)
     */
    private static function languagePackTargetKey(array $entry): ?string
    {
        if (($entry['type'] ?? null) !== 'language_pack' || ($entry['target_identifier'] ?? null) === null) {
            return null;
        }

        $targetType = self::normalizeType((string) ($entry['scope'] ?? ''));
        if ($targetType === null || $targetType === 'language_pack') {
            return null;
        }

        return BundledInventory::key($targetType, (string) $entry['target_identifier']);
    }

    /**
     * manifest dependencies 의 한 유형 값(`{id: 제약}` 맵 또는 id 목록)을 식별자 목록으로 바꿉니다.
     *
     * @param  mixed  $value  dependencies.modules 또는 dependencies.plugins 값
     * @return array<int, string> 식별자 목록
     */
    private static function dependencyIds(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map('strval', array_is_list($value) ? $value : array_keys($value)));
    }

    // ────────────────────────────────────────────────────────────────
    // 운영자 선택
    // ────────────────────────────────────────────────────────────────

    /**
     * `--bundled-sources` 값을 해석합니다.
     *
     * - 빈 값 → null (호출자가 묻거나 기본값 none 을 쓴다)
     * - `none` → 선택 없음, `all` → 미배치 전부
     * - 쉼표 구분 식별자, `type:id` 허용. 식별자가 여러 유형에 걸리면 `type:id` 를 요구한다.
     *
     * @param  BundledInventory|null  $inventory  릴리스 인벤토리 (null 이면 문법만 검사)
     * @param  string|null  $raw  옵션 값
     * @return array{mode: string, keys: array<int, string>, tokens: array<int, string>}|null 해석 결과
     *
     * @throws \InvalidArgumentException 알 수 없는 유형·식별자이거나 모호한 식별자일 때
     */
    public function resolveSelection(?BundledInventory $inventory, ?string $raw): ?array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        $lower = strtolower($raw);
        if ($lower === 'none' || $lower === 'all') {
            return ['mode' => $lower, 'keys' => [], 'tokens' => []];
        }

        $tokens = array_values(array_filter(array_map('trim', explode(',', $raw)), fn ($t) => $t !== ''));
        $keys = [];

        foreach ($tokens as $token) {
            $type = null;
            $identifier = $token;
            if (str_contains($token, ':')) {
                [$typeRaw, $identifier] = array_map('trim', explode(':', $token, 2));
                $type = self::normalizeType($typeRaw);
                if ($type === null) {
                    throw new \InvalidArgumentException(__('settings.bundled_sources.errors.unknown_type', ['type' => $typeRaw]));
                }
            }

            if ($inventory === null) {
                continue;
            }

            if ($type !== null) {
                $key = BundledInventory::key($type, $identifier);
                if (! $inventory->has($key)) {
                    throw new \InvalidArgumentException(__('settings.bundled_sources.errors.not_in_release', ['identifier' => $token]));
                }
                $keys[] = $key;

                continue;
            }

            $matches = $inventory->keysForIdentifier($identifier);
            if ($matches === []) {
                throw new \InvalidArgumentException(__('settings.bundled_sources.errors.not_in_release', ['identifier' => $token]));
            }
            if (count($matches) > 1) {
                throw new \InvalidArgumentException(__('settings.bundled_sources.errors.ambiguous', [
                    'identifier' => $token,
                    'candidates' => implode(', ', $matches),
                ]));
            }
            $keys[] = $matches[0];
        }

        return ['mode' => 'ids', 'keys' => array_values(array_unique($keys)), 'tokens' => $tokens];
    }

    /**
     * 유형 별칭을 표준 유형명으로 바꿉니다.
     *
     * @param  string  $type  사용자가 적은 유형 (module, plugin, template, language_pack, lang-pack 등)
     * @return string|null 표준 유형명 또는 null
     */
    public static function normalizeType(string $type): ?string
    {
        $type = strtolower(trim($type));

        return match ($type) {
            'module', 'modules' => 'module',
            'plugin', 'plugins' => 'plugin',
            'template', 'templates' => 'template',
            'language_pack', 'language-pack', 'lang_pack', 'lang-pack', 'langpack', 'language_packs', 'lang-packs' => 'language_pack',
            default => null,
        };
    }

    // ────────────────────────────────────────────────────────────────
    // 판정
    // ────────────────────────────────────────────────────────────────

    /**
     * 배치 집합 P 와 미배치 집합 U 를 산출합니다.
     *
     * P = 인벤토리 ∩ (설치됨 ∪ 디스크에 있음) ∪ 그 의존 폐포 ∪ 운영자 선택과 그 의존 폐포,
     * 언어팩은 core 팩 항상, 확장 팩은 대상 확장이 P 에 있거나 설치됐거나 팩 자신이 설치·디스크에 있을 때.
     * U = 인벤토리 − P.
     *
     * @param  BundledInventory  $inventory  릴리스 인벤토리
     * @param  array<int, string>|string  $selection  운영자 선택 — `'all'` 또는 항목 키 목록
     * @return BundledPlacementPlan 판정 결과
     */
    public function plan(BundledInventory $inventory, array|string $selection = []): BundledPlacementPlan
    {
        $placed = [];
        $add = function (string $key, string $reason, ?string $requiredBy = null) use (&$placed, $inventory): bool {
            if (isset($placed[$key])) {
                if ($requiredBy !== null && ! in_array($requiredBy, $placed[$key]['required_by'], true)) {
                    $placed[$key]['required_by'][] = $requiredBy;
                }

                return false;
            }
            $placed[$key] = [
                'entry' => (array) $inventory->get($key),
                'reason' => $reason,
                'required_by' => $requiredBy !== null ? [$requiredBy] : [],
            ];

            return true;
        };

        $installed = $this->installedKeys();
        $entries = $inventory->all();

        // 1) 설치됐거나 디스크에 있는 확장
        foreach ($entries as $key => $entry) {
            if ($entry['type'] === 'language_pack') {
                continue;
            }
            if (isset($installed[$key])) {
                $add($key, 'installed');
            } elseif ($this->isOnDisk($entry['type'], $entry['identifier'])) {
                $add($key, 'on_disk');
            }
        }

        // 2) 그 의존 폐포
        $this->closeDependencies($inventory, array_keys($placed), $add);

        // 3) 운영자 선택과 그 의존 폐포
        $selectedKeys = [];
        if ($selection === 'all') {
            foreach ($entries as $key => $entry) {
                if (! isset($placed[$key]) && $entry['type'] !== 'language_pack') {
                    $selectedKeys[] = $key;
                }
            }
        } elseif (is_array($selection)) {
            $selectedKeys = array_values(array_filter($selection, fn ($k) => $inventory->has($k)));
        }
        foreach ($selectedKeys as $key) {
            $add($key, 'selected');
        }
        // 고른 확장 언어팩의 대상 확장(본체)이 배치·설치되지 않았으면 함께 배치한다(7.1.1 출시 전 — place 와 같은 규칙)
        foreach ($selectedKeys as $key) {
            $targetKey = self::languagePackTargetKey((array) $inventory->get($key));
            if ($targetKey !== null && $inventory->has($targetKey) && ! isset($installed[$targetKey]) && $add($targetKey, 'pack_target', $key)) {
                $selectedKeys[] = $targetKey;
            }
        }
        $this->closeDependencies($inventory, array_values(array_filter($selectedKeys, fn ($k) => ! str_starts_with($k, 'language_pack:'))), $add);

        // 4) 언어팩 규칙
        foreach ($entries as $key => $entry) {
            if ($entry['type'] !== 'language_pack' || isset($placed[$key])) {
                continue;
            }
            $scope = (string) ($entry['scope'] ?? '');
            if ($scope === 'core') {
                $add($key, 'core');

                continue;
            }
            if (isset($installed[$key])) {
                $add($key, 'installed');

                continue;
            }
            if ($this->isOnDisk('language_pack', $entry['identifier'])) {
                $add($key, 'on_disk');

                continue;
            }
            $targetType = self::normalizeType($scope);
            $target = $entry['target_identifier'] ?? null;
            if ($targetType !== null && $targetType !== 'language_pack' && $target !== null) {
                $targetKey = BundledInventory::key($targetType, (string) $target);
                if (isset($placed[$targetKey]) || isset($installed[$targetKey])) {
                    $add($key, 'target_placed', $targetKey);
                }
            }
        }

        $unplaced = array_diff_key($entries, $placed);
        ksort($placed, SORT_STRING);
        ksort($unplaced, SORT_STRING);

        return new BundledPlacementPlan($placed, $unplaced);
    }

    /**
     * 시작 키들의 새 판 manifest 의존성을 추이적으로 따라가 인벤토리에 있는 것을 배치 집합에 더합니다.
     *
     * @param  BundledInventory  $inventory  릴리스 인벤토리
     * @param  array<int, string>  $startKeys  시작 항목 키 목록
     * @param  \Closure  $add  배치 집합 추가 콜백 (key, reason, requiredBy): bool
     * @return void 반환값 없음
     */
    private function closeDependencies(BundledInventory $inventory, array $startKeys, \Closure $add): void
    {
        $queue = $startKeys;
        $visited = [];

        while ($queue !== []) {
            $key = array_shift($queue);
            if (isset($visited[$key])) {
                continue;
            }
            $visited[$key] = true;

            $entry = $inventory->get($key);
            if ($entry === null || ! isset($entry['dependencies'])) {
                continue;
            }

            foreach (['modules' => 'module', 'plugins' => 'plugin'] as $group => $type) {
                foreach ($entry['dependencies'][$group] as $depId) {
                    $depKey = BundledInventory::key($type, $depId);
                    if (! $inventory->has($depKey)) {
                        continue;
                    }
                    $add($depKey, 'dependency', $key);
                    $queue[] = $depKey;
                }
            }
        }
    }

    /**
     * 설치된 확장·언어팩의 항목 키 집합을 돌려줍니다(status 무관).
     *
     * DB 에 접근할 수 없으면 「활성 디렉토리 존재」로 폴백하고 경고 로그를 남긴다.
     *
     * @return array<string, bool> `{type}:{identifier}` => true
     */
    public function installedKeys(): array
    {
        if ($this->installedCache !== null) {
            return $this->installedCache;
        }

        $keys = [];
        try {
            foreach ($this->modules->getInstalledIdentifiers() as $id) {
                $keys[BundledInventory::key('module', (string) $id)] = true;
            }
            foreach ($this->plugins->getInstalledIdentifiers() as $id) {
                $keys[BundledInventory::key('plugin', (string) $id)] = true;
            }
            foreach ($this->templates->getInstalledIdentifiers() as $id) {
                $keys[BundledInventory::key('template', (string) $id)] = true;
            }
            foreach ($this->languagePacks->allForUpdateCheck() as $pack) {
                $keys[BundledInventory::key('language_pack', (string) $pack->identifier)] = true;
            }
        } catch (\Throwable $e) {
            Log::warning('번들 배치 판정: 설치 정보를 DB 에서 읽지 못해 활성 디렉토리 존재로 판정합니다', [
                'error' => $e->getMessage(),
            ]);
            $keys = $this->installedKeysFromActiveDirectories();
        }

        return $this->installedCache = $keys;
    }

    /**
     * 활성 디렉토리 존재로 설치 키 집합을 만듭니다(DB 접근 불가 시 폴백).
     *
     * @return array<string, bool> `{type}:{identifier}` => true
     */
    private function installedKeysFromActiveDirectories(): array
    {
        $keys = [];
        $domains = self::EXTENSION_DOMAINS + ['language_pack' => self::LANGUAGE_PACK_DOMAIN];
        foreach ($domains as $type => [$domain, $manifestName]) {
            $dir = base_path($domain);
            if (! is_dir($dir)) {
                continue;
            }
            foreach (File::directories($dir) as $path) {
                $name = basename($path);
                if (str_starts_with($name, '_')) {
                    continue;
                }
                if (is_file($path.DIRECTORY_SEPARATOR.$manifestName)) {
                    $keys[BundledInventory::key($type, $name)] = true;
                }
            }
        }

        return $keys;
    }

    /**
     * 설치 정보 캐시를 비웁니다(배치·정리 뒤 다시 판정할 때).
     *
     * @return void 반환값 없음
     */
    public function forgetInstalledCache(): void
    {
        $this->installedCache = null;
    }

    /**
     * 번들 소스 디렉토리(`{domain}/_bundled/{id}`)의 절대경로를 돌려줍니다.
     *
     * @param  string  $type  module|plugin|template|language_pack
     * @param  string  $identifier  식별자
     * @return string 절대경로
     */
    public function bundledPath(string $type, string $identifier): string
    {
        $domain = $type === 'language_pack' ? self::LANGUAGE_PACK_DOMAIN[0] : self::EXTENSION_DOMAINS[$type][0];

        return base_path($domain.DIRECTORY_SEPARATOR.'_bundled'.DIRECTORY_SEPARATOR.$identifier);
    }

    /**
     * 번들 소스 디렉토리가 디스크에 있는지 판정합니다.
     *
     * @param  string  $type  module|plugin|template|language_pack
     * @param  string  $identifier  식별자
     * @return bool 있으면 true
     */
    public function isOnDisk(string $type, string $identifier): bool
    {
        return is_dir($this->bundledPath($type, $identifier));
    }

    /**
     * 설치된 확장이 의존하는 확장의 키 집합을 돌려줍니다.
     *
     * 설치된 확장의 활성 manifest 와 인벤토리 manifest 를 모두 본다(번들 밖 설치 확장의 의존도 포함).
     *
     * @param  BundledInventory|null  $inventory  릴리스 인벤토리 (null 이면 활성 manifest 만)
     * @return array<string, bool> `{type}:{identifier}` => true
     */
    public function installedDependencyKeys(?BundledInventory $inventory): array
    {
        $out = [];
        foreach (array_keys($this->installedKeys()) as $key) {
            [$type, $identifier] = explode(':', $key, 2);
            if (! isset(self::EXTENSION_DOMAINS[$type])) {
                continue;
            }

            $deps = [];
            [$domain, $manifestName] = self::EXTENSION_DOMAINS[$type];
            $manifestPath = base_path($domain.DIRECTORY_SEPARATOR.$identifier.DIRECTORY_SEPARATOR.$manifestName);
            if (is_file($manifestPath)) {
                $manifest = json_decode((string) file_get_contents($manifestPath), true);
                if (is_array($manifest)) {
                    $deps[] = ['modules' => self::dependencyIds($manifest['dependencies']['modules'] ?? []), 'plugins' => self::dependencyIds($manifest['dependencies']['plugins'] ?? [])];
                }
            }
            $entry = $inventory?->get($key);
            if ($entry !== null && isset($entry['dependencies'])) {
                $deps[] = $entry['dependencies'];
            }

            foreach ($deps as $dep) {
                foreach ($dep['modules'] as $id) {
                    $out[BundledInventory::key('module', $id)] = true;
                }
                foreach ($dep['plugins'] as $id) {
                    $out[BundledInventory::key('plugin', $id)] = true;
                }
            }
        }

        return $out;
    }

    // ────────────────────────────────────────────────────────────────
    // core:bundled-sources place / prune
    // ────────────────────────────────────────────────────────────────

    /**
     * 배치할 항목 키에 의존 폐포와 대상 언어팩을 더한 목록을 돌려줍니다(place).
     *
     * @param  BundledInventory  $inventory  릴리스 인벤토리
     * @param  array<int, string>  $keys  운영자가 고른 항목 키
     * @return array<string, array{entry: array<string, mixed>, reason: string, required_by: array<int, string>}> 배치할 항목
     */
    public function placementClosure(BundledInventory $inventory, array $keys): array
    {
        $out = [];
        $add = function (string $key, string $reason, ?string $requiredBy = null) use (&$out, $inventory): bool {
            if (isset($out[$key])) {
                if ($requiredBy !== null && ! in_array($requiredBy, $out[$key]['required_by'], true)) {
                    $out[$key]['required_by'][] = $requiredBy;
                }

                return false;
            }
            $out[$key] = ['entry' => (array) $inventory->get($key), 'reason' => $reason, 'required_by' => $requiredBy !== null ? [$requiredBy] : []];

            return true;
        };

        foreach ($keys as $key) {
            if ($inventory->has($key)) {
                $add($key, 'selected');
            }
        }

        // 확장 언어팩만 골랐으면 디스크에 없는 대상 확장(본체)도 함께 배치한다 — 종전에는 본체 없이 언어팩만 남아
        // 설치할 수 없는 팩이 생겼다(7.1.1 출시 전). 본체의 의존 번들은 아래 의존 폐포가 이어 붙인다.
        foreach (array_keys($out) as $key) {
            $targetKey = self::languagePackTargetKey((array) $inventory->get($key));
            if ($targetKey === null || ! $inventory->has($targetKey)) {
                continue;
            }
            $target = (array) $inventory->get($targetKey);
            if (! $this->isOnDisk((string) $target['type'], (string) $target['identifier'])) {
                $add($targetKey, 'pack_target', $key);
            }
        }

        $this->closeDependencies($inventory, array_values(array_filter(array_keys($out), fn ($k) => ! str_starts_with($k, 'language_pack:'))), $add);

        foreach ($inventory->all() as $key => $entry) {
            if ($entry['type'] !== 'language_pack' || isset($out[$key]) || ($entry['scope'] ?? '') === 'core') {
                continue;
            }
            $targetType = self::normalizeType((string) ($entry['scope'] ?? ''));
            if ($targetType === null || $targetType === 'language_pack' || ($entry['target_identifier'] ?? null) === null) {
                continue;
            }
            $targetKey = BundledInventory::key($targetType, (string) $entry['target_identifier']);
            if (isset($out[$targetKey])) {
                $add($key, 'target_placed', $targetKey);
            }
        }

        return $out;
    }

    /**
     * 정리(prune) 대상을 판정합니다.
     *
     * 대상 = 인벤토리 ∩ 디스크 − 설치됨 − 설치된 확장의 의존 대상 − 남는 번들의 의존 대상(반복 축소).
     * 식별자를 주면 그 안에서만 고른다. 확장 언어팩은 팩이 미설치이고 대상 확장 소스가 정리되거나
     * 이미 없을 때 함께 정리한다(core 팩 제외). 인벤토리에 없는 사용자 자체 `_bundled/{id}` 는 대상이 아니다.
     *
     * 배포본과 다른 파일이 있는 폴더는 `$includeModified` 가 아니면 「수정됨, 보존」으로 빼고, 남는 번들로
     * 취급해 그 의존 대상도 보존한다. 비교할 배포본 디렉토리가 없으면 `unverifiable` 을 켠다.
     *
     * @param  BundledInventory  $inventory  릴리스 인벤토리
     * @param  array<int, string>|null  $keys  운영자가 고른 항목 키 (null 이면 전체)
     * @param  bool  $includeModified  수정된 폴더도 정리 대상에 둘지
     * @return array{candidates: array<string, array<string, mixed>>, protected: array<string, array<int, string>>, modified: array<string, array<int, string>>, not_target: array<int, string>, unverifiable: bool}
     *                                                                                                                                                                                                               candidates = 정리 대상, protected = 보존 사유(의존하는 쪽 키 또는 installed), modified = 수정돼 보존한 폴더의 다른 파일, not_target = 대상이 아닌 지정 키
     */
    public function planPrune(BundledInventory $inventory, ?array $keys = null, bool $includeModified = false): array
    {
        $installed = $this->installedKeys();
        $installedDeps = $this->installedDependencyKeys($inventory);
        $requested = $keys === null ? null : array_flip($keys);

        $candidates = [];
        $protected = [];
        $modified = [];
        $unverifiable = false;
        $keepModified = function (string $key, array $entry) use ($inventory, $includeModified, &$modified, &$unverifiable): bool {
            if ($includeModified) {
                return false;
            }
            $files = $this->modifiedFiles($inventory, $entry);
            if ($files === null) {
                $unverifiable = true;

                return true;
            }
            if ($files !== []) {
                $modified[$key] = $files;

                return true;
            }

            return false;
        };

        foreach ($inventory->all() as $key => $entry) {
            if ($entry['type'] === 'language_pack' || ! $this->isOnDisk($entry['type'], $entry['identifier'])) {
                continue;
            }
            if ($requested !== null && ! isset($requested[$key])) {
                continue;
            }
            if (isset($installed[$key])) {
                continue;
            }
            if (isset($installedDeps[$key])) {
                $protected[$key] = ['installed'];

                continue;
            }
            if ($keepModified($key, $entry)) {
                continue;
            }
            $candidates[$key] = $entry;
        }

        // 남는 번들(정리 대상이 아닌 디스크의 _bundled 확장 전부 — 사용자 자체 포함)의 의존 대상은 보존
        $diskDeps = $this->onDiskBundledDependencies();
        do {
            $changed = false;
            foreach (array_keys($candidates) as $key) {
                $requiredBy = [];
                foreach ($diskDeps as $owner => $deps) {
                    if (! isset($candidates[$owner]) && in_array($key, $deps, true)) {
                        $requiredBy[] = $owner;
                    }
                }
                if ($requiredBy !== []) {
                    unset($candidates[$key]);
                    $protected[$key] = $requiredBy;
                    $changed = true;
                }
            }
        } while ($changed);

        // 확장 언어팩
        foreach ($inventory->all() as $key => $entry) {
            if ($entry['type'] !== 'language_pack' || ($entry['scope'] ?? '') === 'core') {
                continue;
            }
            if (! $this->isOnDisk('language_pack', $entry['identifier']) || isset($installed[$key])) {
                continue;
            }
            $targetType = self::normalizeType((string) ($entry['scope'] ?? ''));
            $target = $entry['target_identifier'] ?? null;
            if ($targetType === null || $targetType === 'language_pack' || $target === null) {
                continue;
            }
            $targetKey = BundledInventory::key($targetType, (string) $target);
            $targetPruned = isset($candidates[$targetKey]);
            $targetGone = ! $this->isOnDisk($targetType, (string) $target) && ! isset($installed[$targetKey]);
            if (! $targetPruned && ! $targetGone) {
                continue;
            }
            if ($requested !== null && ! isset($requested[$key]) && ! $targetPruned) {
                continue;
            }
            if ($keepModified($key, $entry)) {
                continue;
            }
            $candidates[$key] = $entry;
        }

        $notTarget = [];
        foreach ((array) $keys as $key) {
            if (! isset($candidates[$key]) && ! isset($protected[$key]) && ! isset($modified[$key])) {
                $notTarget[] = $key;
            }
        }

        ksort($candidates, SORT_STRING);
        ksort($protected, SORT_STRING);
        ksort($modified, SORT_STRING);

        return [
            'candidates' => $candidates,
            'protected' => $protected,
            'modified' => $modified,
            'not_target' => $notTarget,
            'unverifiable' => $unverifiable,
        ];
    }

    /**
     * 디스크의 `{domain}/_bundled/*` 확장마다 manifest 가 요구하는 의존 키 목록을 돌려줍니다.
     *
     * @return array<string, array<int, string>> `{type}:{identifier}` => 의존 키 목록
     */
    private function onDiskBundledDependencies(): array
    {
        $out = [];
        foreach (self::EXTENSION_DOMAINS as $type => [$domain, $manifestName]) {
            $dir = base_path($domain.DIRECTORY_SEPARATOR.'_bundled');
            if (! is_dir($dir)) {
                continue;
            }
            foreach (File::directories($dir) as $path) {
                $manifest = json_decode((string) @file_get_contents($path.DIRECTORY_SEPARATOR.$manifestName), true);
                if (! is_array($manifest)) {
                    continue;
                }
                $deps = [];
                foreach (self::dependencyIds($manifest['dependencies']['modules'] ?? []) as $id) {
                    $deps[] = BundledInventory::key('module', $id);
                }
                foreach (self::dependencyIds($manifest['dependencies']['plugins'] ?? []) as $id) {
                    $deps[] = BundledInventory::key('plugin', $id);
                }
                $out[BundledInventory::key($type, basename($path))] = $deps;
            }
        }

        return $out;
    }

    /**
     * 디스크의 번들 폴더에서 배포본과 다른 파일(내용이 다르거나 배포본에 없는 파일)을 찾습니다.
     *
     * @param  BundledInventory  $inventory  릴리스 인벤토리 (root 가 있어야 비교 가능)
     * @param  array<string, mixed>  $entry  인벤토리 항목
     * @return array<int, string>|null 다른 파일의 번들 폴더 기준 상대경로 목록 (비교할 배포본 디렉토리가 없으면 null)
     */
    public function modifiedFiles(BundledInventory $inventory, array $entry): ?array
    {
        if ($inventory->root === null) {
            return null;
        }

        $releaseDir = $inventory->root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, (string) $entry['path']);
        $diskDir = $this->bundledPath((string) $entry['type'], (string) $entry['identifier']);
        if (! is_dir($diskDir)) {
            return [];
        }

        $diff = [];
        foreach (File::allFiles($diskDir, true) as $file) {
            $relative = str_replace('\\', '/', $file->getRelativePathname());
            $releaseFile = $releaseDir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (! is_file($releaseFile) || @md5_file($releaseFile) !== @md5_file($file->getPathname())) {
                $diff[] = $relative;
            }
        }
        sort($diff, SORT_STRING);

        return $diff;
    }

    /**
     * 다른 파일 목록 중 배포본에 아예 없는 파일만 골라 돌려줍니다.
     *
     * 기본(비-prune) 업데이트는 새 판에서 지워진 파일을 지우지 않는다. 그래서 운영자가 손대지 않은 번들도 이전 판의 남은
     * 파일 때문에 「수정됨」으로 보인다 — prune 안내가 「내용이 다른 파일」과 「배포본에 없는 파일만」을 가르는 데 쓴다
     * (7.1.1 출시 전). 보존 판정은 바꾸지 않는다.
     *
     * @param  BundledInventory  $inventory  릴리스 인벤토리 (root 가 있어야 비교 가능)
     * @param  array<string, mixed>  $entry  인벤토리 항목
     * @param  array<int, string>  $files  {@see modifiedFiles()} 가 돌려준 번들 폴더 기준 상대경로
     * @return array<int, string> 배포본에 없는 파일 (비교할 배포본이 없으면 빈 배열)
     */
    public function filesMissingFromRelease(BundledInventory $inventory, array $entry, array $files): array
    {
        if ($inventory->root === null) {
            return [];
        }

        $releaseDir = $inventory->root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, (string) $entry['path']);

        return array_values(array_filter(
            $files,
            fn (string $relative): bool => ! is_file($releaseDir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative)),
        ));
    }

    // ────────────────────────────────────────────────────────────────
    // 기록 파일
    // ────────────────────────────────────────────────────────────────

    /**
     * 기록 파일 경로를 돌려줍니다.
     *
     * @return string `storage/app/core_update/bundled_sources.json` 절대경로
     */
    public function recordPath(): string
    {
        return storage_path('app'.DIRECTORY_SEPARATOR.'core_update'.DIRECTORY_SEPARATOR.'bundled_sources.json');
    }

    /**
     * 판정 결과를 기록 파일에 씁니다.
     *
     * @param  BundledPlacementPlan  $plan  판정 결과
     * @param  string  $coreVersion  기록할 코어 버전 (배포본 버전)
     * @param  string  $source  기록 주체 (core:update|core:bundled-sources)
     * @return array<string, mixed> 기록한 내용
     */
    public function writeRecord(BundledPlacementPlan $plan, string $coreVersion, string $source): array
    {
        $unplaced = [];
        foreach ($plan->unplaced as $entry) {
            $unplaced[] = self::recordItem($entry);
        }

        $byDependency = [];
        foreach ($plan->placedBy('dependency') as $row) {
            $byDependency[] = [
                'type' => $row['entry']['type'],
                'identifier' => $row['entry']['identifier'],
                'required_by' => array_values($row['required_by']),
            ];
        }

        $bySelection = [];
        foreach ($plan->placedBy('selected') as $row) {
            $bySelection[] = ['type' => $row['entry']['type'], 'identifier' => $row['entry']['identifier']];
        }

        return $this->saveRecord([
            'schema' => self::RECORD_SCHEMA,
            'core_version' => $coreVersion,
            'recorded_at' => now()->toIso8601String(),
            'source' => $source,
            'unplaced' => $unplaced,
            'placed_by_dependency' => $byDependency,
            'placed_by_selection' => $bySelection,
        ]);
    }

    /**
     * 기록 파일의 미배치 목록에서 항목들을 빼고 다시 씁니다(place·prune 뒤).
     *
     * prune 한 항목은 운영자가 의도해 지운 것이므로 미배치 안내에 다시 넣지 않는다.
     *
     * @param  array<int, string>  $keys  뺄 항목 키 목록 (`{type}:{identifier}`)
     * @param  string  $source  기록 주체
     * @return array<string, mixed>|null 갱신한 기록 (기록 파일이 없거나 손상됐으면 null)
     */
    public function removeFromRecord(array $keys, string $source): ?array
    {
        $record = $this->readRecord();
        if ($record === null) {
            return null;
        }

        $drop = array_flip($keys);
        $record['unplaced'] = array_values(array_filter(
            $record['unplaced'],
            fn (array $item) => ! isset($drop[BundledInventory::key((string) $item['type'], (string) $item['identifier'])]),
        ));
        $record['recorded_at'] = now()->toIso8601String();
        $record['source'] = $source;

        return $this->saveRecord($record);
    }

    /**
     * 기록 파일을 읽습니다.
     *
     * @return array<string, mixed>|null 기록 내용 (파일이 없거나 손상됐으면 null — 손상은 경고 로그)
     */
    public function readRecord(): ?array
    {
        $path = $this->recordPath();
        if (! is_file($path)) {
            return null;
        }

        $data = json_decode((string) @file_get_contents($path), true);
        if (! is_array($data) || ! isset($data['unplaced']) || ! is_array($data['unplaced'])) {
            Log::warning('번들 배치 기록 파일이 손상돼 무시합니다', ['path' => $path]);

            return null;
        }

        $data['unplaced'] = array_values(array_filter($data['unplaced'], fn ($item) => is_array($item)
            && isset($item['type'], $item['identifier'])
            && in_array($item['type'], self::TYPES, true)));

        return $data;
    }

    /**
     * 미배치 목록의 정렬 직렬화 해시를 계산합니다(관리자 닫기 키).
     *
     * @param  array<int, array<string, mixed>>  $unplaced  기록의 미배치 항목 목록
     * @return string sha256 해시
     */
    public static function hashUnplaced(array $unplaced): string
    {
        $parts = array_map(fn (array $item) => $item['type'].':'.$item['identifier'].'@'.($item['version'] ?? ''), $unplaced);
        sort($parts, SORT_STRING);

        return hash('sha256', implode("\n", $parts));
    }

    /**
     * 인벤토리 항목을 기록 항목 모양으로 줄입니다.
     *
     * @param  array<string, mixed>  $entry  인벤토리 항목
     * @return array<string, mixed> 기록 항목
     */
    public static function recordItem(array $entry): array
    {
        return [
            'type' => $entry['type'],
            'identifier' => $entry['identifier'],
            'name' => $entry['name'],
            'version' => $entry['version'],
            'hidden' => (bool) $entry['hidden'],
        ];
    }

    /**
     * 해시를 다시 계산해 기록 파일을 씁니다.
     *
     * @param  array<string, mixed>  $record  기록 내용
     * @return array<string, mixed> 쓴 내용
     */
    private function saveRecord(array $record): array
    {
        $record['hash'] = self::hashUnplaced($record['unplaced']);

        $path = $this->recordPath();
        $dir = dirname($path);
        $created = ! is_dir($dir);
        File::ensureDirectoryExists($dir);
        File::put($path, (string) json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // sudo 로 돈 업데이트·명령이 root 소유로 남기면 웹 계정이 안내를 읽지 못한다 — 부모 소유권을 상속한다
        if ($created) {
            FilePermissionHelper::inheritOwnershipFromParent($dir);
        }
        FilePermissionHelper::inheritOwnershipFromParent($path);

        return $record;
    }
}
