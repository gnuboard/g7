<?php

namespace App\Services;

use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\Storage\CoreStorageDriver;
use App\Extension\Traits\ClearsTemplateCaches;
use App\Http\View\Composers\TemplateComposer;
use Illuminate\Support\Facades\Log;

/**
 * 확장(모듈/플러그인) 프론트엔드 IIFE/CSS 번들 병합 서비스
 *
 * 활성 모듈/플러그인의 개별 IIFE JS·CSS 에셋을 타입별로 하나의 번들 파일로
 * 이어붙여(concat) 서빙 오버헤드(HTTP 요청 수)를 줄인다. 각 확장 IIFE 는
 * 자체 클로저에서 자가등록(`window.G7ModuleRegistry`/`G7PluginRegistry` +
 * 핸들러/리스너)을 수행하므로, N개 IIFE 를 priority 순으로 이어붙여 1개
 * `<script>` 로 실행해도 등록 로직은 동일하게 동작한다.
 *
 * 정렬/필터(`hasAssets()` && strategy==='global' + `uasort(priority)`) 는
 * TemplateComposer 와 공유하는 SSoT 로 이 서비스에 둔다(drift 방지).
 *
 * 경로는 절대경로 게터(`getBuiltAssetAbsolutePaths()`)를 재사용한다 —
 * `ModuleService::getAssetFilePath()` 의 `base_path("modules/{id}/...")`
 * 하드코딩을 복제하지 않아야 `_bundled` 확장에서도 정확히 읽는다(제약 4).
 *
 * @see TemplateComposer
 */
class ExtensionBundleService
{
    // ext.cache_version 게터 재사용 — 트레이트를 use 해 self:: 로 호출(트레이트명
    // 직접 정적 호출은 PHP 8.3+ deprecated). 인스턴스 캐시 무효화 메서드는 미사용.
    use ClearsTemplateCaches;

    /**
     * 번들 캐시 파일이 저장되는 스토리지 디스크(= storage/app/ext-bundles).
     */
    private const BUNDLE_DISK = 'ext-bundles';

    /**
     * 서비스 주입
     *
     * @param  ModuleManager  $moduleManager  모듈 매니저
     * @param  PluginManager  $pluginManager  플러그인 매니저
     */
    public function __construct(
        private readonly ModuleManager $moduleManager,
        private readonly PluginManager $pluginManager
    ) {}

    /**
     * 확장 타입별 global 전략 에셋을 priority 오름차순으로 정렬해 반환합니다.
     *
     * TemplateComposer 의 개별 에셋 URL 생성과 번들러의 concat 이 동일한
     * 필터/정렬을 쓰도록 하는 SSoT. 순서 제어는 오직 manifest
     * `loading.priority` 숫자 오름차순뿐이며 특정 확장 이름 하드코딩은 없다(제약 1).
     *
     * @param  string  $type  'module' | 'plugin'
     * @return array<string, array{jsAbsPath: ?string, cssAbsPath: ?string, priority: int}>
     *                                                                                      identifier => 절대경로/우선순위 (priority 오름차순 정렬)
     */
    public function getOrderedGlobalAssetPaths(string $type): array
    {
        $extensions = $type === 'plugin'
            ? $this->pluginManager->getActivePlugins()
            : $this->moduleManager->getActiveModules();

        $ordered = [];

        foreach ($extensions as $extension) {
            if (! $extension->hasAssets()) {
                continue;
            }

            $loadingConfig = $extension->getAssetLoadingConfig();

            // global 전략만 번들 대상 (layout, lazy 는 레이아웃에서 개별 처리)
            if (($loadingConfig['strategy'] ?? 'global') !== 'global') {
                continue;
            }

            $absolutePaths = $extension->getBuiltAssetAbsolutePaths();

            $jsAbsPath = $absolutePaths['js'] ?? null;
            $cssAbsPath = $absolutePaths['css'] ?? null;

            // JS/CSS 둘 다 없으면 번들에 기여할 것이 없으므로 제외
            if ($jsAbsPath === null && $cssAbsPath === null) {
                continue;
            }

            $ordered[$extension->getIdentifier()] = [
                'jsAbsPath' => $jsAbsPath,
                'cssAbsPath' => $cssAbsPath,
                'priority' => (int) ($loadingConfig['priority'] ?? 100),
            ];
        }

        // priority 오름차순 (낮을수록 먼저) — 개별 로딩과 동일 규칙
        uasort($ordered, fn ($a, $b) => $a['priority'] <=> $b['priority']);

        return $ordered;
    }

    /**
     * 확장 타입의 JS 번들 문자열을 생성합니다.
     *
     * priority 순으로 각 IIFE 파일을 읽어 `\n;\n` 구분자로 이어붙인다(제약 2 —
     * ASI 경계 보호). 각 파일 끝의 `//# sourceMappingURL` 주석은 prod 에서는
     * strip(맵 생략), dev 에서는 개별 에셋 서빙 절대 URL 로 rewrite 한다(제약 3).
     *
     * 확장별 fine-grained try/catch — 파일 읽기 실패 시 해당 확장만 skip +
     * Log::warning, 나머지 병합 지속(한 확장 실패가 번들 전체를 붕괴시키지 않음).
     *
     * @param  string  $type  'module' | 'plugin'
     * @return string 병합된 JS (활성 global 에셋이 없으면 빈 문자열)
     */
    public function buildJsBundle(string $type): string
    {
        $ordered = $this->getOrderedGlobalAssetPaths($type);
        $isProduction = app()->environment('production');
        $segments = [];

        foreach ($ordered as $identifier => $paths) {
            if (empty($paths['jsAbsPath'])) {
                continue;
            }

            try {
                $content = @file_get_contents($paths['jsAbsPath']);

                if ($content === false) {
                    Log::warning('확장 JS 번들 병합: 파일 읽기 실패, 해당 확장 skip', [
                        'type' => $type,
                        'identifier' => $identifier,
                        'path' => $paths['jsAbsPath'],
                    ]);

                    continue;
                }

                $segments[] = $this->processJsSourceMap($content, $type, $identifier, $isProduction);
            } catch (\Throwable $e) {
                Log::warning('확장 JS 번들 병합 중 오류, 해당 확장 skip', [
                    'type' => $type,
                    'identifier' => $identifier,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return implode("\n;\n", $segments);
    }

    /**
     * 확장 타입의 CSS 번들 문자열을 생성합니다.
     *
     * priority 순으로 각 CSS 파일을 읽어 `\n` 구분자로 이어붙인다. 상대경로
     * `url(...)` 참조가 있는 CSS 는 병합 시 경로가 깨지므로 번들에서 제외하고
     * 경고 로그를 남긴다(안전장치 — 현재 번들 CSS 는 url() 0건).
     *
     * @param  string  $type  'module' | 'plugin'
     * @return string 병합된 CSS (활성 global 에셋이 없으면 빈 문자열)
     */
    public function buildCssBundle(string $type): string
    {
        $ordered = $this->getOrderedGlobalAssetPaths($type);
        $segments = [];

        foreach ($ordered as $identifier => $paths) {
            if (empty($paths['cssAbsPath'])) {
                continue;
            }

            try {
                $content = @file_get_contents($paths['cssAbsPath']);

                if ($content === false) {
                    Log::warning('확장 CSS 번들 병합: 파일 읽기 실패, 해당 확장 skip', [
                        'type' => $type,
                        'identifier' => $identifier,
                        'path' => $paths['cssAbsPath'],
                    ]);

                    continue;
                }

                // 상대경로 url() 참조가 있으면 병합 시 폰트/이미지 경로가 깨진다.
                // 절대/data URI 는 안전하므로 상대경로만 검출해 해당 CSS 제외.
                if ($this->hasRelativeUrl($content)) {
                    Log::warning('확장 CSS 에 상대경로 url() 존재 — 번들에서 제외(개별 폴백 유지)', [
                        'type' => $type,
                        'identifier' => $identifier,
                    ]);

                    continue;
                }

                $segments[] = $content;
            } catch (\Throwable $e) {
                Log::warning('확장 CSS 번들 병합 중 오류, 해당 확장 skip', [
                    'type' => $type,
                    'identifier' => $identifier,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return implode("\n", $segments);
    }

    /**
     * 캐시된 번들 파일의 절대 경로를 반환합니다(없으면 build → 원자적 write).
     *
     * 파일명에 version 을 포함(`{type}.{version}.{js|css}`)하므로 활성 조합이
     * 바뀌어 version 이 bump 되면 새 파일명으로 자연 무효화된다. 프로덕션에서만
     * 디스크 캐시하며, 비프로덕션(dev/watch)에서는 캐시하지 않고 매 요청 build 해
     * rebuild 를 즉시 반영한다(PO 결정).
     *
     * @param  string  $type  'module' | 'plugin'
     * @param  string  $kind  'js' | 'css'
     * @param  int  $version  확장 캐시 버전(ClearsTemplateCaches::getExtensionCacheVersion)
     * @return string 캐시(또는 방금 build 한) 파일의 절대 경로. 병합 결과가 빈 문자열이면 빈 문자열.
     */
    public function getBundleFilePath(string $type, string $kind, int $version): string
    {
        $content = $kind === 'css'
            ? $this->buildCssBundle($type)
            : $this->buildJsBundle($type);

        // 병합할 에셋이 하나도 없으면 파일을 만들지 않는다(호출측이 빈 문자열로 판단).
        if ($content === '') {
            return '';
        }

        $storage = $this->bundleStorage();
        $relativeName = $this->bundleFileName($type, $kind, $version);

        // 비프로덕션은 캐시하지 않고 임시 파일로 매번 build → rebuild 즉시 반영
        if (! app()->environment('production')) {
            return $this->writeAtomically($storage, $relativeName, $content, cache: false);
        }

        // 프로덕션: 동일 version 캐시가 있으면 그대로 사용
        if ($storage->exists('', $relativeName)) {
            return $storage->getBasePath('').'/'.$relativeName;
        }

        return $this->writeAtomically($storage, $relativeName, $content, cache: true);
    }

    /**
     * 현재 version 외의 오래된 번들 파일을 삭제하고 삭제 건수를 반환합니다.
     *
     * @param  int  $currentVersion  보존할 현재 캐시 버전
     * @return int 삭제된 파일 수
     */
    public function cleanupStaleBundles(int $currentVersion): int
    {
        $storage = $this->bundleStorage();
        $deleted = 0;

        foreach ($storage->files('', '') as $file) {
            $name = basename($file);

            // 현재 version 파일과 .gitignore 는 보존
            if ($name === '.gitignore' || $this->matchesVersion($name, $currentVersion)) {
                continue;
            }

            if ($this->isBundleFile($name) && $storage->delete('', $name)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * 번들 캐시 파일을 삭제합니다(cache-clear 커맨드용).
     *
     * @param  string|null  $type  'module' | 'plugin' 지정 시 해당 타입만, null 이면 전체
     * @return int 삭제된 파일 수
     */
    public function clearBundles(?string $type = null): int
    {
        $storage = $this->bundleStorage();
        $deleted = 0;

        foreach ($storage->files('', '') as $file) {
            $name = basename($file);

            if ($name === '.gitignore' || ! $this->isBundleFile($name)) {
                continue;
            }

            // 타입 필터 (파일명 접두사 `{type}.`)
            if ($type !== null && ! str_starts_with($name, $type.'.')) {
                continue;
            }

            if ($storage->delete('', $name)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * 번들 파일명을 생성합니다(`{type}.{version}.{kind}`).
     *
     * @param  string  $type  'module' | 'plugin'
     * @param  string  $kind  'js' | 'css'
     * @param  int  $version  캐시 버전
     * @return string 파일명 (디렉토리 제외)
     */
    private function bundleFileName(string $type, string $kind, int $version): string
    {
        return "{$type}.{$version}.{$kind}";
    }

    /**
     * 파일명이 번들 파일 패턴(`{type}.{version}.{kind}`)인지 확인합니다.
     *
     * @param  string  $name  파일명
     * @return bool 번들 파일이면 true
     */
    private function isBundleFile(string $name): bool
    {
        return (bool) preg_match('/^(module|plugin)\.\d+\.(js|css)$/', $name);
    }

    /**
     * 파일명이 지정한 version 의 번들인지 확인합니다.
     *
     * @param  string  $name  파일명
     * @param  int  $version  비교할 버전
     * @return bool 해당 version 파일이면 true
     */
    private function matchesVersion(string $name, int $version): bool
    {
        return (bool) preg_match('/^(module|plugin)\.'.preg_quote((string) $version, '/').'\.(js|css)$/', $name);
    }

    /**
     * 병합 결과를 원자적으로(임시 파일 → rename) 기록하고 절대 경로를 반환합니다.
     *
     * @param  CoreStorageDriver  $storage  번들 디스크 스토리지
     * @param  string  $relativeName  대상 파일명
     * @param  string  $content  기록할 내용
     * @param  bool  $cache  true 면 version 파일명 유지, false 면 임시 파일 사용
     * @return string 기록된 파일의 절대 경로
     */
    private function writeAtomically(CoreStorageDriver $storage, string $relativeName, string $content, bool $cache): string
    {
        $basePath = $storage->getBasePath('');

        if (! is_dir($basePath)) {
            @mkdir($basePath, 0o755, true);
        }

        $finalPath = $basePath.'/'.$relativeName;

        if (! $cache) {
            // 비프로덕션: 매 요청 덮어써도 무방(원자성 불요), 그대로 write
            $storage->put('', $relativeName, $content);

            return $finalPath;
        }

        // 프로덕션: 임시 파일에 쓴 뒤 rename 으로 원자적 게시(부분 파일 서빙 방지)
        $tmpName = $relativeName.'.tmp.'.getmypid();
        $storage->put('', $tmpName, $content);

        $tmpPath = $basePath.'/'.$tmpName;

        if (! @rename($tmpPath, $finalPath)) {
            // rename 실패 시(경합으로 이미 존재 등) 임시 파일 정리 후 최종 경로 사용
            $storage->delete('', $tmpName);
        }

        return $finalPath;
    }

    /**
     * IIFE 소스맵 주석을 환경에 맞게 처리합니다.
     *
     * prod: `//# sourceMappingURL` 라인 strip(맵 생략).
     * dev: 개별 에셋 서빙 절대 URL(`/api/{type}s/assets/{id}/dist/js/*.map`)로
     *      rewrite → 브라우저가 확장별 원본 맵을 추적(완벽한 통합 맵은 아님).
     *
     * @param  string  $content  원본 IIFE 내용
     * @param  string  $type  'module' | 'plugin'
     * @param  string  $identifier  확장 식별자
     * @param  bool  $isProduction  프로덕션 여부
     * @return string 처리된 내용
     */
    private function processJsSourceMap(string $content, string $type, string $identifier, bool $isProduction): string
    {
        // 구분자로 `~` 사용 — 패턴 자체에 `#`(`//#`)가 포함되어 `#` 구분자는 못 씀
        $pattern = '~//# sourceMappingURL=(\S+)~';

        if ($isProduction) {
            // prod: 맵 참조 제거
            return preg_replace($pattern, '', $content) ?? $content;
        }

        // dev: 상대 맵 파일명을 개별 에셋 서빙 절대 URL 로 rewrite
        $typeSegment = $type === 'plugin' ? 'plugins' : 'modules';

        return preg_replace_callback($pattern, function (array $m) use ($typeSegment, $identifier) {
            $mapFile = ltrim($m[1], './');

            return '//# sourceMappingURL=/api/'.$typeSegment.'/assets/'.$identifier.'/dist/js/'.basename($mapFile);
        }, $content) ?? $content;
    }

    /**
     * CSS 내용에 상대경로 url() 참조가 있는지 확인합니다.
     *
     * 절대 URL(http/https), 루트 절대경로(/), data URI 는 병합에 안전하므로
     * 그 외의 url() 참조만 상대경로로 간주한다.
     *
     * @param  string  $css  CSS 내용
     * @return bool 상대경로 url() 이 하나라도 있으면 true
     */
    private function hasRelativeUrl(string $css): bool
    {
        if (! preg_match_all('/url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/i', $css, $matches)) {
            return false;
        }

        foreach ($matches[1] as $url) {
            $url = trim($url);

            if ($url === '') {
                continue;
            }

            $isAbsolute = str_starts_with($url, 'http://')
                || str_starts_with($url, 'https://')
                || str_starts_with($url, '//')
                || str_starts_with($url, '/')
                || str_starts_with($url, 'data:');

            if (! $isAbsolute) {
                return true;
            }
        }

        return false;
    }

    /**
     * 번들 디스크용 스토리지 드라이버를 반환합니다(StorageInterface 경유).
     *
     * @return CoreStorageDriver ext-bundles 디스크 스토리지
     */
    private function bundleStorage(): CoreStorageDriver
    {
        return (new CoreStorageDriver)->withDisk(self::BUNDLE_DISK);
    }

    /**
     * 현재 확장 캐시 버전을 반환합니다.
     *
     * @return int 캐시 버전
     */
    public function getCurrentVersion(): int
    {
        return self::getExtensionCacheVersion();
    }
}
