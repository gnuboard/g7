<?php

namespace App\Services;

use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\Storage\CoreStorageDriver;
use App\Extension\Traits\ClearsTemplateCaches;
use App\Http\View\Composers\TemplateComposer;
use App\Support\AssetCssUrlRewriter;
use App\Support\AssetUrl;
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
     * 원자적 쓰기 임시 파일(`*.tmp.{pid}`)을 잔존물로 보는 나이 (초).
     *
     * pid 는 재사용되므로 "그 pid 가 살아 있는가" 로는 진행 중 여부를 판정할 수 없다.
     */
    private const TEMP_BUNDLE_STALE_SECONDS = 600;

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
     * `cssRelPath` 는 확장 루트 기준 상대 경로다 — 병합 시 CSS 안의 상대 참조를 그 CSS 가
     * 놓인 위치 기준으로 풀어야 하는데, 절대 경로만으로는 확장 루트를 되짚을 수 없다.
     *
     * @param  string  $type  'module' | 'plugin'
     * @return array<string, array{jsAbsPath: ?string, cssAbsPath: ?string, cssRelPath: ?string, priority: int}>
     *                                                                                                           identifier => 절대경로/상대경로/우선순위 (priority 오름차순 정렬)
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

            // CSS 안의 상대 참조를 풀려면 그 CSS 가 확장 안에서 **어디에 놓였는지**가 필요하다.
            // 절대 경로만으로는 확장 루트를 되짚을 수 없으므로 선언된 상대 경로를 함께 싣는다.
            $cssRelPath = $extension->getBuiltAssetPaths()['css'] ?? null;

            $ordered[$extension->getIdentifier()] = [
                'jsAbsPath' => $jsAbsPath,
                'cssAbsPath' => $cssAbsPath,
                'cssRelPath' => $cssRelPath,
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
     * priority 순으로 각 CSS 파일을 읽어 `\n` 구분자로 이어붙인다. CSS 안의 상대
     * `url(...)`·`@import` 참조는 그 확장의 절대 자산 URL 로 치환한다 — 병합본의 주소는
     * 어느 확장의 dist 디렉토리도 아니라 상대 해석이 반드시 어긋나기 때문이다.
     *
     * 치환은 개별 자산 서빙(ServesRewritableCssAssets)과 같은 규칙(AssetCssUrlRewriter)을
     * 쓴다. 두 경로가 서로 다른 코드로 갈라지면 한쪽만 고쳐진 채 남는다.
     *
     * @param  string  $type  'module' | 'plugin'
     * @return string 병합된 CSS (활성 global 에셋이 없으면 빈 문자열)
     */
    public function buildCssBundle(string $type): string
    {
        $ordered = $this->getOrderedGlobalAssetPaths($type);
        $isProduction = app()->environment('production');
        $typeSegment = $type === 'plugin' ? 'plugins' : 'modules';
        $version = $this->getCurrentVersion();
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

                // 상대 참조는 **치환**한다. 병합본의 주소(`/api/{type}/bundle.css` 또는 정적
                // 게시본)는 어느 확장의 dist 디렉토리도 아니므로 상대 해석이 반드시 어긋나는데,
                // 그 실패는 404 하나로만 나타나 서버 로그에 흔적이 없다.
                //
                // 종전에는 그런 CSS 를 가진 확장을 번들에서 통째로 제외했다. 그러나 번들 URL 이
                // 내려오면 프론트는 개별 로딩을 아예 타지 않으므로(TemplateApp.loadExtensionAssets)
                // 제외 = 그 확장의 스타일이 **하나도 적용되지 않음** 이었다. 주석이 말하던
                // "개별 폴백" 은 bundleUrls 부재(구버전 blade) 경로에만 있다.
                $content = AssetCssUrlRewriter::rewrite(
                    $content,
                    (string) ($paths['cssRelPath'] ?? ''),
                    fn (string $path): string => AssetUrl::extensionApiAsset(
                        $typeSegment,
                        $identifier,
                        $path,
                        $version
                    )
                );

                $segments[] = $this->processCssSourceMap($content, $isProduction);
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
     * rebuild 를 즉시 반영한다.
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

        $relativeName = $this->bundleFileName($type, $kind, $version);

        // 디스크 캐시는 **최적화**다 — 쓰기 실패가 공개 엔드포인트의 500 이 되면 안 된다.
        // `ext-bundles` 디스크는 `throw => true` 라 권한 문제(uid 독점 0700 등)에서
        // `UnableToWriteFile` 이 그대로 올라오고, 그러면 모든 확장의 프론트엔드 JS/CSS 가
        // 통째로 나가지 못한다. 병합 결과는 이미 메모리에 있으므로 그것을 그대로 응답하면
        // 화면은 정상이다 (커밋 63a30ab29 의 AbstractCacheDriver fail-soft 와 같은 원칙).
        try {
            $storage = $this->bundleStorage();

            // 비프로덕션은 캐시하지 않고 임시 파일로 매번 build → rebuild 즉시 반영
            if (! app()->environment('production')) {
                return $this->writeAtomically($storage, $relativeName, $content, cache: false);
            }

            // 프로덕션: 동일 version 캐시가 있으면 그대로 사용
            if ($storage->exists('', $relativeName)) {
                return $storage->getBasePath('').'/'.$relativeName;
            }

            return $this->writeAtomically($storage, $relativeName, $content, cache: true);
        } catch (\Throwable $e) {
            Log::warning('확장 번들 디스크 캐시 실패 — 메모리 병합 결과로 서빙합니다', [
                'type' => $type,
                'kind' => $kind,
                'version' => $version,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * 번들을 서빙할 때 쓸 병합 결과를 반환합니다 (디스크 캐시 실패 시 메모리 폴백용).
     *
     * @param  string  $type  'module' | 'plugin'
     * @param  string  $kind  'js' | 'css'
     * @return string 병합 결과 (없으면 빈 문자열)
     */
    public function buildBundleContent(string $type, string $kind): string
    {
        return $kind === 'css'
            ? $this->buildCssBundle($type)
            : $this->buildJsBundle($type);
    }

    /**
     * 해당 타입에서 프론트엔드 에셋을 **선언한** 활성 확장 수를 반환합니다.
     *
     * "선언이 0 이라 빈 번들" (정상)과 "선언은 있는데 병합 결과가 0" (장애 — 배포 중
     * `dist` 가 잠깐 비었거나 경로가 어긋났다)을 구분하는 유일한 근거다. 구분하지 않으면
     * 후자가 빈 200 으로 나가고, 프론트는 404 도 오류도 받지 못한 채 한참 뒤
     * "Unknown action handler" 로 죽는다.
     *
     * 판정은 **kind 별**이다 — js 만 선언한 확장이 있는 상태에서 css 번들이 비는 것은
     * 정상이므로, 그 경우까지 장애로 보면 정상 구성이 503 이 된다.
     *
     * 근거는 manifest 의 `assets.{kind}.output` **선언**이며 산출물 파일의 존재를 보지
     * 않는다. `getOrderedGlobalAssetPaths()` / `hasAssets()` / `getBuiltAssetPaths()` 는
     * 전부 `file_exists()` 게이트를 타므로, 그 경로로 세면 "dist 가 잠깐 빔" 이 곧
     * "선언 0" 이 되어 **막으려던 바로 그 상태가 정상(빈 200)으로 판정된다.** 선언과
     * 산출은 다른 축이고, 이 메서드가 재는 것은 선언 축이다.
     *
     * @param  string  $type  'module' | 'plugin'
     * @param  string  $kind  'js' | 'css'
     * @return int 해당 kind 의 에셋을 선언한 활성 확장 수
     */
    public function countAssetDeclaringExtensions(string $type, string $kind): int
    {
        try {
            $extensions = $type === 'plugin'
                ? $this->pluginManager->getActivePlugins()
                : $this->moduleManager->getActiveModules();

            $declared = 0;

            foreach ($extensions as $extension) {
                // global 전략만 번들 대상 — 병합 대상 모집단과 동일한 필터를 쓴다
                if (($extension->getAssetLoadingConfig()['strategy'] ?? 'global') !== 'global') {
                    continue;
                }

                if (! empty($extension->getAssets()[$kind]['output'] ?? null)) {
                    $declared++;
                }
            }

            return $declared;
        } catch (\Throwable $e) {
            Log::warning('확장 에셋 선언 수 집계 실패', [
                'type' => $type,
                'kind' => $kind,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
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

            // 원자적 쓰기의 임시 파일(`{type}.{v}.{kind}.tmp.{pid}`)은 번들 파일 패턴에
            // 맞지 않아 GC 대상에서 통째로 빠져 있었다 — rename 이 실패한 만큼 영구
            // 잔존한다(실측 560개). 나이 가드를 붙여 진행 중인 쓰기는 건드리지 않는다.
            if ($this->isStaleTempBundleFile($name, $storage)) {
                if ($storage->delete('', $name)) {
                    $deleted++;
                }

                continue;
            }

            if ($this->isBundleFile($name) && $storage->delete('', $name)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * 파일명이 **오래된** 원자적 쓰기 임시 파일인지 판정합니다.
     *
     * 진행 중인 쓰기를 파괴하지 않도록 나이 가드를 둔다 — pid 는 재사용되므로 "그 pid 가
     * 살아 있는가" 로는 판정할 수 없다.
     *
     * @param  string  $name  파일명
     * @param  CoreStorageDriver  $storage  번들 디스크 스토리지
     * @return bool 삭제 대상 여부
     */
    private function isStaleTempBundleFile(string $name, CoreStorageDriver $storage): bool
    {
        if (! preg_match('/^(module|plugin)\.\d+\.(js|css)\.tmp\.\d+$/', $name)) {
            return false;
        }

        $mtime = @filemtime($storage->getBasePath('').'/'.$name);

        // 나이를 읽지 못하면 남긴다 — 진행 중인 쓰기를 지우는 쪽이 더 나쁘다.
        return $mtime !== false && (time() - $mtime) > self::TEMP_BUNDLE_STALE_SECONDS;
    }

    /**
     * 번들 캐시 파일을 삭제합니다(cache-clear 커맨드용).
     *
     * **현재 버전은 보존한다** — `cleanupStaleBundles()` 와 같은 정책이다. 현재 버전까지
     * 지우면 같은 순간 서빙 중인 웹 요청이 "존재함" 판정 직후 `filemtime()` 에서 500 을
     * 낸다(bump 직후 TOCTOU). 캐시 파일은 없으면 다음 요청이 다시 만들므로, 지우는 것의
     * 이득은 없고 그 창의 500 만 남는다.
     *
     * @param  string|null  $type  'module' | 'plugin' 지정 시 해당 타입만, null 이면 전체
     * @return int 삭제된 파일 수
     */
    public function clearBundles(?string $type = null): int
    {
        $storage = $this->bundleStorage();
        $currentVersion = $this->getCurrentVersion();
        $deleted = 0;

        foreach ($storage->files('', '') as $file) {
            $name = basename($file);

            if ($name === '.gitignore' || ! $this->isBundleFile($name)) {
                continue;
            }

            // 현재 버전 보존 (cleanupStaleBundles 와 동형 — 정책이 갈라지면 한쪽이 창을 연다)
            if ($this->matchesVersion($name, $currentVersion)) {
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

            return '//# sourceMappingURL='.AssetUrl::extensionAsset(
                $typeSegment,
                $identifier,
                'dist/js/'.basename($mapFile)
            );
        }, $content) ?? $content;
    }

    /**
     * CSS 소스맵 주석을 환경에 맞게 처리합니다.
     *
     * CSS 는 JS 와 주석 문법이 달라 소스맵 참조를 블록 주석으로 표기하므로
     * processJsSourceMap() 의 `//#` 패턴으로는 검출되지 않는다.
     *
     * prod: 주석 strip(맵 참조 제거). dev: 원본 유지.
     * 병합 번들에서는 개별 맵 URL 이 어차피 어긋나므로 dev rewrite 는 하지 않는다.
     *
     * @param  string  $content  원본 CSS 내용
     * @param  bool  $isProduction  프로덕션 여부
     * @return string 처리된 내용
     */
    private function processCssSourceMap(string $content, bool $isProduction): string
    {
        if (! $isProduction) {
            return $content;
        }

        // 구분자로 `~` 사용 — 패턴에 `/`, `#` 가 포함됨
        return preg_replace('~/\*#\s*sourceMappingURL=\S+?\s*\*/~', '', $content) ?? $content;
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
