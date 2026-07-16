<?php

namespace App\Extension;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Extension\ExtensionMiddlewareRegistryInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 활성 확장의 미들웨어 선언을 수집해 라우트 매칭 인덱스를 캐시로 제공합니다.
 *
 * IDV `getRouteScopeIndex` 와 달리, targets 가 정확한 이름이 아니라 glob/brace/URI
 * 패턴이므로 [정확한이름 => 미들웨어] 해시맵이 아니라 [group.timing => list<{predicates, class}>]
 * 구조로 두고 요청 라우트명·URI 를 술어로 순회 매칭합니다. 확장 미들웨어 총량이 소수(수십
 * 이하)라 O(n) 순회 비용은 무시 가능. 결과는 (routeName|path)+group+timing 단위로 2차 캐시.
 *
 * 술어는 캐시를 위해 클로저 대신 `{op, arg}` 태그 배열로 보관하고 조회 시 평가합니다
 * (클로저는 직렬화 불가). op ∈ {name, name_ext, name_core, uri, always}.
 *
 * @since 7.0.5
 */
class ExtensionMiddlewareRegistry implements ExtensionMiddlewareRegistryInterface
{
    /**
     * 미들웨어 인덱스 캐시 키 (IDV `identity_policies.route_scope_index` 네이밍 컨벤션 미러).
     * 물리 키는 CoreCacheDriver 접두사로 `g7:core:extension.middleware_index`.
     */
    public const CACHE_KEY = 'extension.middleware_index';

    /**
     * 캐시 무효화 태그 (단수 slug). Manager 상태 변경 지점에서 flush().
     */
    public const CACHE_TAG = 'extension_middleware';

    /**
     * 허용 그룹.
     */
    private const ALLOWED_GROUPS = ['web', 'api'];

    /**
     * 확장 라우트 판별 glob (양 그룹). 라우트명이 이 중 하나에 매칭되면 확장 라우트.
     */
    private const EXT_GLOB = ['*.modules.*', '*.plugins.*'];

    /**
     * 요청 단위 2차 캐시 (동일 요청 내 게이트가 4회 조회해도 인덱스 1회 빌드).
     *
     * @var array<string, list<class-string>>
     */
    protected array $resolveCache = [];

    /**
     * @param  CacheInterface  $cache  코어 캐시 드라이버 (CoreCacheDriver 자동 바인딩)
     */
    public function __construct(
        protected CacheInterface $cache,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function resolveForRoute(string $routeName, string $path, string $group, string $timing): array
    {
        $memoKey = $routeName."\0".$path."\0".$group."\0".$timing;
        if (isset($this->resolveCache[$memoKey])) {
            return $this->resolveCache[$memoKey];
        }

        $matched = [];
        foreach ($this->buildIndex() as $entry) {
            if ($entry['group'] !== $group || $entry['timing'] !== $timing) {
                continue;
            }
            foreach ($entry['predicates'] as $predicate) {
                if ($this->predicateMatches($predicate, $routeName, $path)) {
                    $matched[] = $entry;
                    break;
                }
            }
        }

        usort($matched, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        $classes = array_values(array_map(static fn (array $e): string => $e['class'], $matched));

        return $this->resolveCache[$memoKey] = $classes;
    }

    /**
     * 활성 확장의 미들웨어 선언을 그룹별 엔트리로 펼쳐 캐시된 인덱스를 반환합니다.
     *
     * @return list<array{timing: string, group: string, predicates: list<array{op: string, arg?: string}>, class: class-string, order: int}>
     */
    protected function buildIndex(): array
    {
        $ttl = (int) g7_core_settings('cache.extension_middleware_ttl', 3600);

        return $this->cache->remember(
            self::CACHE_KEY,
            fn (): array => $this->collectEntries(),
            $ttl,
            [self::CACHE_TAG],
        );
    }

    /**
     * 활성 모듈·플러그인의 getMiddleware() 를 검증·정규화해 엔트리 배열로 수집합니다.
     *
     * @return list<array{timing: string, group: string, predicates: list<array{op: string, arg?: string}>, class: class-string, order: int}>
     */
    protected function collectEntries(): array
    {
        $entries = [];
        $order = 0;

        foreach ($this->activeExtensions() as [$extension, $identifier, $kind]) {
            if (! method_exists($extension, 'getMiddleware')) {
                continue;
            }

            $declarations = $extension->getMiddleware();
            if (! is_array($declarations)) {
                continue;
            }

            foreach ($declarations as $declaration) {
                $normalized = $this->normalizeDeclaration($declaration, $identifier, $kind);
                if ($normalized === null) {
                    continue;
                }

                [$class, $groups, $timing, $targets] = $normalized;

                foreach ($groups as $group) {
                    $predicates = [];
                    foreach ($targets as $target) {
                        foreach ($this->normalizeTarget($target, $identifier, $kind, $group) as $predicate) {
                            $predicates[] = $predicate;
                        }
                    }

                    $entries[] = [
                        'timing' => $timing,
                        'group' => $group,
                        'predicates' => $predicates,
                        'class' => $class,
                        'order' => $order,
                    ];
                }

                $order++;
            }
        }

        return $entries;
    }

    /**
     * 단일 미들웨어 선언을 검증·정규화합니다. 무효 시 warning 후 null.
     *
     * @param  mixed  $declaration  getMiddleware() 항목
     * @param  string  $identifier  확장 식별자
     * @param  string  $kind  'module' | 'plugin'
     * @return array{0: class-string, 1: list<string>, 2: string, 3: list<string>}|null
     */
    protected function normalizeDeclaration(mixed $declaration, string $identifier, string $kind): ?array
    {
        if (! is_array($declaration)) {
            return null;
        }

        $class = $declaration['class'] ?? null;
        if (! is_string($class) || $class === '' || ! class_exists($class)) {
            Log::warning('확장 미들웨어 선언의 class 가 유효하지 않아 등록을 건너뜁니다.', [
                'extension' => $identifier,
                'kind' => $kind,
                'class' => $class,
            ]);

            return null;
        }

        $groups = $declaration['groups'] ?? [];
        $groups = is_array($groups)
            ? array_values(array_filter($groups, static fn ($g): bool => in_array($g, self::ALLOWED_GROUPS, true)))
            : [];
        if ($groups === []) {
            Log::warning('확장 미들웨어 선언의 groups 가 비어있거나 허용되지 않은 값이라 등록을 건너뜁니다.', [
                'extension' => $identifier,
                'kind' => $kind,
                'class' => $class,
                'groups' => $declaration['groups'] ?? null,
            ]);

            return null;
        }

        $targets = $declaration['targets'] ?? [];
        $targets = is_array($targets)
            ? array_values(array_filter($targets, static fn ($t): bool => is_string($t) && $t !== ''))
            : [];
        if ($targets === []) {
            Log::warning('확장 미들웨어 선언의 targets 가 비어있어 등록을 건너뜁니다 (대상 명시 필수).', [
                'extension' => $identifier,
                'kind' => $kind,
                'class' => $class,
            ]);

            return null;
        }

        $timing = $declaration['timing'] ?? 'after_core';
        if (! in_array($timing, ['before_core', 'after_core'], true)) {
            $timing = 'after_core';
        }

        return [$class, $groups, $timing, $targets];
    }

    /**
     * target 값을 술어(들)로 정규화합니다 (§1 카탈로그 → 매칭식).
     *
     * brace 는 여러 name 술어로 전개되므로 list 반환.
     *
     * @param  string  $target  targets 항목
     * @param  string  $identifier  확장 식별자 ('self' 치환용)
     * @param  string  $kind  'module' | 'plugin'
     * @param  string  $group  이 엔트리가 펼쳐진 단일 그룹 ('self' 치환용)
     * @return list<array{op: string, arg?: string}>
     */
    protected function normalizeTarget(string $target, string $identifier, string $kind, string $group): array
    {
        // URI 패턴 (무명 라우트 대응) — '/' 로 시작. arg 는 선행 슬래시를 포함한 원형을 유지하고
        // (매칭 시 path 도 선행 슬래시로 정규화), 이렇게 해야 루트('/')·글롭('/admin/*') 모두
        // $request->is() 와 동일하게 동작한다 ($request->path() 는 루트에서 '/' 를 반환).
        if (str_starts_with($target, '/')) {
            return [['op' => 'uri', 'arg' => $target]];
        }

        return match (strtolower($target)) {
            'self' => [['op' => 'name', 'arg' => "{$group}.{$kind}s.{$identifier}.*"]],
            'all_extensions' => [['op' => 'name_ext']],
            'core' => [['op' => 'name_core']],
            'everything', '*' => [['op' => 'always']],
            default => $this->normalizeSpecificTarget($target),
        };
    }

    /**
     * module:{id} / plugin:{id} / brace / 원시 glob 을 name 술어로 변환합니다.
     *
     * @param  string  $target  targets 항목
     * @return list<array{op: string, arg: string}>
     */
    protected function normalizeSpecificTarget(string $target): array
    {
        if (str_starts_with($target, 'module:')) {
            $id = substr($target, strlen('module:'));

            return [['op' => 'name', 'arg' => "*.modules.{$id}.*"]];
        }

        if (str_starts_with($target, 'plugin:')) {
            $id = substr($target, strlen('plugin:'));

            return [['op' => 'name', 'arg' => "*.plugins.{$id}.*"]];
        }

        // Str::is 는 brace 미지원 → 미리 전개해 개별 name 술어로 저장.
        return array_map(
            static fn (string $glob): array => ['op' => 'name', 'arg' => $glob],
            $this->expandTargetBraces($target),
        );
    }

    /**
     * 술어가 현재 요청 라우트명·path 에 매칭되는지 평가합니다.
     *
     * op=uri → path 매칭; op=always → 무조건 true (무명 라우트 포함);
     * name 계열 → 무명 라우트(routeName='')는 항상 miss.
     *
     * @param  array{op: string, arg?: string}  $predicate  술어
     * @param  string  $routeName  요청 라우트명 (무명이면 빈 문자열)
     * @param  string  $path  요청 URI path (선행 슬래시 없음)
     * @return bool 매칭 여부
     */
    protected function predicateMatches(array $predicate, string $routeName, string $path): bool
    {
        $op = $predicate['op'];

        if ($op === 'always') {
            return true;
        }

        if ($op === 'uri') {
            // path 를 선행 슬래시로 정규화해 arg('/...') 와 정렬 ($request->is() 정합).
            return Str::is($predicate['arg'] ?? '', '/'.ltrim($path, '/'));
        }

        // name 계열: 무명 라우트는 URI/always 로만 잡히므로 여기서 miss.
        if ($routeName === '') {
            return false;
        }

        return match ($op) {
            'name' => Str::is($predicate['arg'] ?? '', $routeName),
            'name_ext' => $this->isExtensionRoute($routeName),
            'name_core' => ! $this->isExtensionRoute($routeName),
            default => false,
        };
    }

    /**
     * 라우트명이 확장 라우트(*.modules.* / *.plugins.*)인지 판별합니다.
     *
     * @param  string  $routeName  요청 라우트명 (비어있지 않음)
     * @return bool 확장 라우트 여부
     */
    protected function isExtensionRoute(string $routeName): bool
    {
        return Str::is(self::EXT_GLOB, $routeName);
    }

    /**
     * brace expansion — 'a.{b,c}.d' → ['a.b.d', 'a.c.d']. 단일 그룹만 지원.
     * IDV `IdentityPolicyRepository::expandTargetBraces()` 재사용.
     *
     * @param  string  $target  targets 항목
     * @return list<string>
     */
    protected function expandTargetBraces(string $target): array
    {
        if (! preg_match('/\{([^{}]+)\}/', $target, $matches)) {
            return [$target];
        }
        $options = array_map('trim', explode(',', $matches[1]));

        return array_map(
            static fn (string $opt): string => preg_replace('/\{[^{}]+\}/', $opt, $target, 1),
            $options,
        );
    }

    /**
     * 활성 모듈·플러그인 인스턴스를 [instance, identifier, kind] 로 순회합니다.
     *
     * @return iterable<array{0: object, 1: string, 2: string}>
     */
    protected function activeExtensions(): iterable
    {
        foreach (app(ModuleManager::class)->getActiveModules() as $module) {
            yield [$module, $module->getIdentifier(), 'module'];
        }

        foreach (app(PluginManager::class)->getActivePlugins() as $plugin) {
            yield [$plugin, $plugin->getIdentifier(), 'plugin'];
        }
    }

    /**
     * 인덱스 캐시를 무효화합니다 (확장 활성/비활성/설치/제거/리로드 시).
     *
     * IDV `IdentityPolicy::flushRouteScopeCache()` 미러 정적 메서드.
     */
    public static function flush(): void
    {
        $cache = app(CacheInterface::class);
        if ($cache->supportsTags()) {
            $cache->flushTags([self::CACHE_TAG]);

            return;
        }
        $cache->forget(self::CACHE_KEY);
    }
}
