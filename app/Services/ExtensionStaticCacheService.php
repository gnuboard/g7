<?php

namespace App\Services;

use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Exceptions\StaticCachePublishException;
use App\Extension\Helpers\FilePermissionHelper;
use App\Extension\Traits\ClearsTemplateCaches;
use App\Helpers\ResponseHelper;
use App\Models\Template;
use App\Rules\AllowedTemplateFileType;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * 부트스트랩 리소스 정적 게시(bake) 서비스.
 *
 * 병합 결과물(다국어·컴포넌트 정의·라우트·확장 번들·템플릿 dist 에셋)을
 * 캐시 버전 디렉토리(`public/build/ext/{v}/`)에 실파일로 게시해 웹서버가
 * rewrite 전에 직접 서빙하게 한다 (#122). 병합 로직은 새로 만들지 않고
 * 전부 기존 SSoT(TemplateService/ExtensionBundleService)를 호출한다.
 *
 * 원자성: `{v}.tmp/` 에 전부 쓴 뒤 디렉토리 rename → `{v}/`, manifest.json 은
 * rename 후 마지막에 기록한다. manifest 존재 = 게시 완료(부분 게시 참조 방지).
 *
 * 실패 정책: 쓰기 실패는 예외를 삼키고 Log::warning + tmp 정리 — 사이트는
 * API 폴백으로 정상 유지된다(fail-open 이 아니라 "정적 fast path 미적용" 상태).
 */
class ExtensionStaticCacheService
{
    use ClearsTemplateCaches;

    /** 게시 완료 마커 파일명 */
    private const MANIFEST_FILE = 'manifest.json';

    /** 게시 락 이름 접두사 */
    private const LOCK_PREFIX = 'ext-static.publish.';

    /** 확장 식별자 패턴 (vendor-name) — 경로 세그먼트 화이트리스트 */
    private const IDENTIFIER_PATTERN = '/^[a-z0-9]+-[a-z0-9_]+$/';

    /** 로케일 패턴 — 경로 세그먼트 화이트리스트 */
    private const LOCALE_PATTERN = '/^[a-z]{2}(?:[-_][A-Za-z0-9]{2,8})?$/';

    /** terminating 게시 예약 플래그 (프로세스당 1회) */
    private static bool $publishScheduled = false;

    /** 테스트 전용 — root 프로세스 판정 오버라이드 (null = 실판정) */
    private static ?bool $rootProcessForTesting = null;

    /** isPublished 요청당 메모이즈 (version => 존재 여부) */
    private array $publishedMemo = [];

    public function __construct(
        private TemplateService $templateService,
        private TemplateRepositoryInterface $templateRepository,
        private ExtensionBundleService $bundleService,
        private LanguagePackService $languagePackService,
    ) {}

    /**
     * 현재 확장 캐시 버전 기준으로 게시합니다.
     *
     * 이미 게시 완료(manifest 존재) 상태면 skip(멱등). `Cache::lock` 으로 단일
     * 실행을 보장하며, 락 미획득 시 다른 프로세스가 게시 중인 것으로 보고 skip.
     *
     * @param  bool  $force  게시 완료 상태여도 강제 재게시
     * @return bool 게시 완료 상태로 끝났으면 true (skip 포함), 실패/비활성이면 false
     */
    public function publishCurrent(bool $force = false): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $version = self::getExtensionCacheVersion();

        if (! $force && $this->isPublished($version)) {
            return true;
        }

        $lock = Cache::lock(self::LOCK_PREFIX.$version, 300);

        if (! $lock->get()) {
            return false;
        }

        try {
            // 락 대기 중 다른 프로세스가 완료했을 수 있다 (멱등 재확인)
            unset($this->publishedMemo[$version]);
            if (! $force && $this->isPublished($version)) {
                return true;
            }

            return $this->publishVersion($version);
        } finally {
            $lock->release();
        }
    }

    /**
     * 해당 버전이 게시 완료 상태인지 확인합니다 (manifest 존재 = 완료).
     *
     * AssetUrl 게이트가 요청당 여러 번 호출하므로 메모이즈한다.
     *
     * @param  int  $version  확장 캐시 버전
     * @return bool 게시 완료 여부
     */
    public function isPublished(int $version): bool
    {
        return $this->publishedMemo[$version] ??= is_file(
            $this->versionDir($version).DIRECTORY_SEPARATOR.self::MANIFEST_FILE
        );
    }

    /**
     * 현재 버전 + 직전 1개를 보존하고 나머지 게시 디렉토리를 삭제합니다.
     *
     * 직전 버전을 남기는 이유: 브라우저에 캐시된 직전 렌더 HTML 이 아직 구버전
     * 정적 URL 을 참조할 수 있다 (asset-url-recovery 파샬이 최후 방어).
     *
     * @return int 삭제된 디렉토리 수
     */
    public function cleanup(): int
    {
        $base = $this->baseDir();

        if (! File::isDirectory($base)) {
            return 0;
        }

        $current = self::getExtensionCacheVersion();
        $versions = [];
        $deleted = 0;

        foreach (File::directories($base) as $dir) {
            $name = basename($dir);

            // 미완료 tmp 잔존물은 무조건 제거 대상 (rename 전 실패 흔적)
            if (str_ends_with($name, '.tmp')) {
                File::deleteDirectory($dir);
                $deleted++;

                continue;
            }

            if (ctype_digit($name)) {
                $versions[(int) $name] = $dir;
            }
        }

        // 현재 버전과, 현재를 제외한 최신 1개(직전) 보존
        $keep = [$current];
        $others = array_keys($versions);
        rsort($others);
        foreach ($others as $v) {
            if ($v !== $current) {
                $keep[] = $v;
                break;
            }
        }

        foreach ($versions as $v => $dir) {
            if (! in_array($v, $keep, true)) {
                File::deleteDirectory($dir);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * 수명주기 이벤트에서 호출되는 terminating 게시 예약.
     *
     * `incrementExtensionCacheVersion()` 내부 단일 지점에서 호출된다.
     * 프로세스당 1회만 등록하며, 게시는 예약 시점이 아니라 **실행 시점의 현재
     * 버전**으로 수행되어 연속 bump(일괄 업데이트)를 자연 병합한다.
     *
     * 프로덕션 전용 — 비프로덕션은 blade 가 정적 URL 을 방출하지 않으므로
     * 게시 자체가 무의미하고(§2-2), testing 환경의 파일 쓰기 부수효과도 차단한다.
     */
    public static function schedulePublishOnTerminate(): void
    {
        if (self::$publishScheduled || ! app()->environment('production')) {
            return;
        }

        // root 프로세스(sudo 코어 업데이트 등)에서는 예약하지 않는다 — 게시가 만드는
        // 캐시 락 샤드 디렉토리(storage/framework/cache/data/xx)와 병합 번들
        // (storage/app/ext-bundles)이 root 소유로 남아, 이후 웹 프로세스의 캐시
        // 쓰기가 그 샤드에 해시되는 순간 Permission denied 로 죽는다 (실사례:
        // sudo 업데이트 직후 전면 500). normalizeOwnership 은 게시 트리(build/ext)만
        // 다루므로 storage 측 부수 산출물은 회피가 정답이다. 게시는 다음 웹 렌더의
        // 자가 치유(웹 계정)가 수행하고, 명시적 `ext-static:publish` 커맨드는 이
        // 게이트를 거치지 않는다 (운영자 책임 — 규정 문서 §6).
        if (self::isRootProcess()) {
            return;
        }

        self::$publishScheduled = true;

        app()->terminating(static function (): void {
            // 실행 시점에 재무장 가능 상태로 복귀 — 요청마다 앱 인스턴스를 새로 쓰는
            // 장수 프로세스(Octane 류)에서는 static 플래그만 살아남으므로, 리셋 없이는
            // 2번째 이후 bump 가 새 앱에 콜백을 등록하지 못한 채 영구 미게시가 된다
            // (자가 치유도 같은 플래그 공유). FPM(요청=프로세스)에서는 무영향.
            self::$publishScheduled = false;

            try {
                app(self::class)->publishCurrent();
            } catch (\Throwable $e) {
                Log::warning('정적 게시 terminating 실행 실패 — 다음 렌더의 자가 치유가 재시도합니다', [
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * 테스트 격리용 — terminating 예약 플래그를 초기화합니다.
     */
    public static function resetPublishScheduleForTesting(): void
    {
        self::$publishScheduled = false;
        self::$rootProcessForTesting = null;
    }

    /**
     * 테스트 전용 — root 프로세스 판정을 강제합니다 (null 로 실판정 복귀).
     *
     * @param  bool|null  $isRoot  강제할 판정값
     */
    public static function fakeRootProcessForTesting(?bool $isRoot): void
    {
        self::$rootProcessForTesting = $isRoot;
    }

    /**
     * 현재 프로세스가 root(euid 0)로 실행 중인지 판정합니다.
     *
     * posix 확장이 없는 환경(Windows, 함수 비활성 호스팅)은 root 아님으로 본다.
     *
     * @return bool root 실행 여부
     */
    private static function isRootProcess(): bool
    {
        if (self::$rootProcessForTesting !== null) {
            return self::$rootProcessForTesting;
        }

        return function_exists('posix_geteuid') && posix_geteuid() === 0;
    }

    /**
     * 게시 루트 디렉토리 절대 경로를 반환합니다.
     *
     * @return string `public/build/ext` 절대 경로
     */
    public function baseDir(): string
    {
        return public_path('build/ext');
    }

    /**
     * 버전 디렉토리 절대 경로를 반환합니다.
     *
     * @param  int  $version  확장 캐시 버전
     * @return string 버전 디렉토리 절대 경로
     */
    public function versionDir(int $version): string
    {
        return $this->baseDir().DIRECTORY_SEPARATOR.$version;
    }

    /**
     * kill-switch 판정 (`core.static_cache.enabled`, .env `G7_STATIC_CACHE`).
     *
     * @return bool 정적 게시 활성 여부
     */
    public function isEnabled(): bool
    {
        return (bool) config('core.static_cache.enabled', true);
    }

    /**
     * 한 버전의 게시를 실제 수행합니다 (tmp 쓰기 → rename → manifest → GC).
     *
     * @param  int  $version  게시할 확장 캐시 버전
     * @return bool 성공 여부
     */
    private function publishVersion(int $version): bool
    {
        $base = $this->baseDir();
        $tmp = $base.DIRECTORY_SEPARATOR.$version.'.tmp';
        $final = $this->versionDir($version);

        try {
            File::deleteDirectory($tmp);
            File::ensureDirectoryExists($tmp, 0775);

            $files = [];

            $this->writeHtaccess($tmp, $files);

            $locales = $this->publishableLocales();

            foreach ($this->templateRepository->getActive() as $template) {
                $this->publishTemplate($tmp, $template, $locales, $files);
            }

            $this->publishBundles($tmp, $version, $files);

            // 원자적 스왑 — force 재게시 시 기존 디렉토리를 비켜낸 뒤 rename
            if (File::isDirectory($final)) {
                File::deleteDirectory($final);
            }

            if (! @rename($tmp, $final)) {
                throw new StaticCachePublishException("Failed to rename publish directory: {$tmp} -> {$final}");
            }

            // manifest 는 rename 후 마지막 기록 — 존재 = 게시 완료
            $this->writeManifest($final, $version, $files);
            unset($this->publishedMemo[$version]);

            // sudo/root CLI 게시 대응 — terminating 게시는 코어 업데이트의
            // restoreOwnership **이후**(프로세스 종료 시)에 실행되므로, root 소유로
            // 남으면 이후 php-fpm 의 재게시·GC 가 영구 실패한다. 부모(public/build)
            // 소유권을 상속시킨다 (FilePermissionHelper::copyFile 의 sudo 대응 선례).
            $this->normalizeOwnership();

            // 인라인 GC (현재 + 직전 1개 보존)
            $this->cleanup();

            Log::info('부트스트랩 리소스 정적 게시 완료', [
                'version' => $version,
                'files' => count($files),
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('부트스트랩 리소스 정적 게시 실패 — API 폴백으로 동작합니다', [
                'version' => $version,
                'error' => $e->getMessage(),
            ]);
            File::deleteDirectory($tmp);

            return false;
        }
    }

    /**
     * root 로 실행된 CLI 게시의 산출물 소유권을 부모 디렉토리 기준으로 정상화합니다.
     *
     * root 가 아닌 프로세스는 chown 자체가 불가능하고 필요도 없다(자기 소유로 생성됨)
     * — 그 경우 즉시 no-op. 실패는 chownRecursive 가 경고 로그로 누적한다.
     * 게시 루트 전체(`build/ext`)를 대상으로 하므로 방금 게시된 버전 디렉토리와
     * 잔존 구버전이 함께 정상화된다.
     */
    private function normalizeOwnership(): void
    {
        if (! function_exists('chown') || ! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return;
        }

        $parent = dirname($this->baseDir());
        $owner = @fileowner($parent);
        $group = @filegroup($parent);

        if ($owner === false || $owner === 0) {
            return;
        }

        FilePermissionHelper::chownRecursive($this->baseDir(), $owner, $group);
    }

    /**
     * 활성 템플릿 1개의 게시물(lang/components/routes/assets)을 기록합니다.
     *
     * @param  string  $tmp  tmp 디렉토리 절대 경로
     * @param  Template  $template  활성 템플릿
     * @param  array<string>  $locales  게시 대상 로케일
     * @param  array<string>  $files  기록된 상대 경로 누적 (참조)
     */
    private function publishTemplate(string $tmp, Template $template, array $locales, array &$files): void
    {
        $identifier = (string) $template->identifier;

        if (! preg_match(self::IDENTIFIER_PATTERN, $identifier)) {
            Log::warning('정적 게시 제외 — 식별자 패턴 불일치', ['identifier' => $identifier]);

            return;
        }

        $templateDir = $tmp.DIRECTORY_SEPARATOR.'templates'.DIRECTORY_SEPARATOR.$identifier;

        // 1. lang 병합 결과 (코어→템플릿→모듈→플러그인→언어팩 훅) — 현 lang API 와 동일 형상(raw)
        foreach ($locales as $locale) {
            $result = $this->templateService->getLanguageDataWithModules($identifier, $locale);

            if (! ($result['success'] ?? false) || ! is_array($result['data'] ?? null)) {
                continue;
            }

            $this->writeJson(
                $templateDir.DIRECTORY_SEPARATOR.'lang'.DIRECTORY_SEPARATOR.$locale.'.json',
                $result['data'],
                $files,
                $tmp
            );
        }

        // 2. components.json 사본 (raw)
        $componentsPath = base_path("templates/{$identifier}/components.json");
        if (is_file($componentsPath)) {
            $this->copyFile($componentsPath, $templateDir.DIRECTORY_SEPARATOR.'components.json', $files, $tmp);
        }

        // 3. routes 병합 결과 — 프론트 소비 코드 무변경을 위한 성공 봉투 포함.
        //    열화 스냅샷(확장 업데이트 진행 중 등)은 게시하지 않는다 — 정적 파일은
        //    스스로 회복되지 않으므로 열화 상태가 다음 bump 까지 박제된다 (getRoutes 와 동일 규율)
        $routesResult = $this->templateService->getRoutesDataWithModules($identifier);

        if (($routesResult['success'] ?? false) && ! $this->templateService->lastRouteMergeWasDegraded()) {
            $this->writeJson(
                $templateDir.DIRECTORY_SEPARATOR.'routes.json',
                ['success' => true, 'message' => '', 'data' => $routesResult['data']],
                $files,
                $tmp
            );
        } elseif ($routesResult['success'] ?? false) {
            Log::warning('정적 게시에서 routes 제외 — 라우트 병합 열화 상태 (확장 업데이트 진행 중 추정)', [
                'template' => $identifier,
            ]);
        }

        // 4. dist 에셋 사본 (확장자 화이트리스트, *.map 제외)
        $this->publishDistAssets(
            base_path("templates/{$identifier}/dist"),
            $templateDir.DIRECTORY_SEPARATOR.'assets',
            $files,
            $tmp
        );
    }

    /**
     * 템플릿 dist 디렉토리를 재귀 복사합니다 (허용 확장자만, 소스맵 제외).
     *
     * @param  string  $sourceDir  원본 dist 절대 경로
     * @param  string  $targetDir  게시 대상 절대 경로
     * @param  array<string>  $files  기록된 상대 경로 누적 (참조)
     * @param  string  $tmp  tmp 루트 (상대 경로 계산용)
     */
    private function publishDistAssets(string $sourceDir, string $targetDir, array &$files, string $tmp): void
    {
        $realSource = realpath($sourceDir);

        if ($realSource === false || ! is_dir($realSource)) {
            return;
        }

        // 소스맵은 배포 금지 정책(`*.map` gitignore)과 동일하게 제외
        $allowed = array_diff(AllowedTemplateFileType::getAllowedExtensions(), ['map']);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($realSource, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $extension = strtolower($file->getExtension());
            if (! in_array($extension, $allowed, true)) {
                continue;
            }

            // 컨테인먼트 검증 — 심볼릭 링크 등으로 dist 밖을 가리키는 실경로 차단
            $realFile = $file->getRealPath();
            if ($realFile === false || ! str_starts_with($realFile, $realSource.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $relative = substr($realFile, strlen($realSource) + 1);
            $this->copyFile($realFile, $targetDir.DIRECTORY_SEPARATOR.$relative, $files, $tmp);
        }
    }

    /**
     * 확장 병합 번들 4종(modules/plugins × js/css)을 게시합니다.
     *
     * @param  string  $tmp  tmp 디렉토리 절대 경로
     * @param  int  $version  확장 캐시 버전
     * @param  array<string>  $files  기록된 상대 경로 누적 (참조)
     */
    private function publishBundles(string $tmp, int $version, array &$files): void
    {
        $map = ['module' => 'modules', 'plugin' => 'plugins'];

        foreach ($map as $type => $plural) {
            foreach (['js', 'css'] as $kind) {
                $path = $this->bundleService->getBundleFilePath($type, $kind, $version);

                if ($path === '' || ! is_file($path)) {
                    continue;
                }

                $this->copyFile(
                    $path,
                    $tmp.DIRECTORY_SEPARATOR.'bundles'.DIRECTORY_SEPARATOR."{$plural}.{$kind}",
                    $files,
                    $tmp
                );
            }
        }
    }

    /**
     * 게시 대상 로케일을 열거합니다 — `/api/locales/active` 와 동일 소스
     * (언어팩이 추가한 로케일 포함).
     *
     * @return array<string> 로케일 목록 (패턴 검증 통과분)
     */
    private function publishableLocales(): array
    {
        $locales = $this->languagePackService->getActiveLocales();

        return array_values(array_filter(
            is_array($locales) ? $locales : [],
            static fn ($locale) => is_string($locale) && preg_match(self::LOCALE_PATTERN, $locale)
        ));
    }

    /**
     * JSON 파일을 API 와 동일 인코딩 옵션으로 기록합니다.
     *
     * @param  string  $absolutePath  기록 대상 절대 경로
     * @param  mixed  $data  직렬화할 데이터
     * @param  array<string>  $files  기록된 상대 경로 누적 (참조)
     * @param  string  $tmp  tmp 루트 (상대 경로 계산용)
     */
    private function writeJson(string $absolutePath, mixed $data, array &$files, string $tmp): void
    {
        File::ensureDirectoryExists(dirname($absolutePath), 0775);

        $json = json_encode($data, ResponseHelper::JSON_ENCODE_OPTIONS);

        if ($json === false) {
            throw new StaticCachePublishException("Failed to encode JSON payload: {$absolutePath}");
        }

        if (File::put($absolutePath, $json) === false) {
            throw new StaticCachePublishException("Failed to write file: {$absolutePath}");
        }

        $files[] = $this->relativePath($absolutePath, $tmp);
    }

    /**
     * 파일 1개를 복사합니다.
     *
     * @param  string  $source  원본 절대 경로
     * @param  string  $target  대상 절대 경로
     * @param  array<string>  $files  기록된 상대 경로 누적 (참조)
     * @param  string  $tmp  tmp 루트 (상대 경로 계산용)
     */
    private function copyFile(string $source, string $target, array &$files, string $tmp): void
    {
        File::ensureDirectoryExists(dirname($target), 0775);

        if (! File::copy($source, $target)) {
            throw new StaticCachePublishException("Failed to copy file: {$source} -> {$target}");
        }

        $files[] = $this->relativePath($target, $tmp);
    }

    /**
     * Apache 용 불변 캐시 헤더 + 압축 .htaccess 를 기록합니다.
     *
     * 정적 서빙은 Laravel 압축 미들웨어(GzipEncodeResponse)를 우회하므로 압축을
     * 여기서 직접 선언한다 — 없으면 종전 API 대비 전송량 회귀다 (실측: lang/ko.json
     * 524,915B 비압축). nginx 는 서버 기본(ETag/Last-Modified) 재검증으로 충분하며
     * 권장 gzip/expires 스니펫은 규정 문서(§8)에 안내한다.
     *
     * @param  string  $tmp  tmp 디렉토리 절대 경로
     * @param  array<string>  $files  기록된 상대 경로 누적 (참조)
     */
    private function writeHtaccess(string $tmp, array &$files): void
    {
        $content = <<<'HTACCESS'
        <IfModule mod_headers.c>
            Header set Cache-Control "public, max-age=31536000, immutable"
        </IfModule>
        <IfModule mod_deflate.c>
            AddOutputFilterByType DEFLATE application/json application/javascript text/css image/svg+xml
        </IfModule>

        HTACCESS;

        if (File::put($tmp.DIRECTORY_SEPARATOR.'.htaccess', $content) === false) {
            throw new StaticCachePublishException('Failed to write .htaccess');
        }

        $files[] = '.htaccess';
    }

    /**
     * 게시 완료 마커(manifest.json)를 기록합니다.
     *
     * @param  string  $finalDir  최종 버전 디렉토리 절대 경로
     * @param  int  $version  확장 캐시 버전
     * @param  array<string>  $files  게시된 상대 경로 목록
     */
    private function writeManifest(string $finalDir, int $version, array $files): void
    {
        $manifest = [
            'cache_version' => $version,
            'published_at' => now()->toIso8601String(),
            'files' => array_values($files),
        ];

        $json = json_encode($manifest, ResponseHelper::JSON_ENCODE_OPTIONS);

        if ($json === false || File::put($finalDir.DIRECTORY_SEPARATOR.self::MANIFEST_FILE, $json) === false) {
            throw new StaticCachePublishException('Failed to write manifest');
        }
    }

    /**
     * tmp 루트 기준 상대 경로를 반환합니다.
     *
     * @param  string  $absolutePath  절대 경로
     * @param  string  $tmp  tmp 루트
     * @return string 상대 경로 (구분자 `/` 정규화)
     */
    private function relativePath(string $absolutePath, string $tmp): string
    {
        return str_replace('\\', '/', substr($absolutePath, strlen($tmp) + 1));
    }
}
