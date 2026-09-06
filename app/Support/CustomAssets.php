<?php

namespace App\Support;

use App\Extension\HookManager;
use App\Rules\AllowedTemplateFileType;
use App\Services\ExtensionStaticCacheService;
use Illuminate\Support\Facades\Log;

/**
 * 사용자 추가 에셋(`custom/`) 해석기
 *
 * 운영자가 자기 CSS·JS·정적 파일을 덧붙일 자리를 각 확장이 제공한다. 종전에는 그런
 * 자리가 없어서, CSS 한 줄을 더하려면 확장 소스(`src/styles/`)를 고치고 Node.js 로
 * 빌드해야 했고 — 그렇게 넣은 파일은 다음 확장 업데이트에 통째로 사라졌다.
 *
 * 이 클래스는 **여러 출처를 합쳐 서술자 목록을 만드는 해석기**다. 지금 출처는 둘
 * (선언 파일 `custom/assets.json`, 규약 스캔)이지만, 소비자(뷰 컴포저·blade·프론트
 * 로더·서빙)는 출처를 보지 않는다. 나중에 템플릿 환경설정이 화면에서 입력한 CSS 를
 * 실어 보내더라도 `core.assets.custom_assets` 필터로 항목을 더하면 그만이다.
 *
 * @see docs/extension/module-assets.md "사용자 추가 에셋"
 */
class CustomAssets
{
    /** 운영자 소유 디렉토리명 */
    public const DIRECTORY = 'custom';

    /** 선언 파일명 */
    public const DECLARATION_FILE = 'assets.json';

    /**
     * 운영자 파일 서명 캐시 키 (변경 감지용)
     *
     * 뷰 컴포저(변경 감지)와 관리 API(직접 편집)가 같은 키를 본다. 관리 API 는 자기가
     * 캐시 버전을 올린 뒤 이 키를 지워, 다음 렌더의 감지가 "첫 관측"(기록만)으로 끝나게
     * 한다 — 안 그러면 같은 변경으로 버전이 두 번 오르고 재게시도 두 번 돈다.
     */
    public const SIGNATURE_CACHE_KEY = 'ext.custom_signature';

    /** 규약 스캔이 자동으로 싣는 확장자 → 자산 타입 */
    private const CONVENTION_TYPES = [
        'css' => 'style',
        'js' => 'script',
    ];

    /** 확장 타입 → 확장 루트 디렉토리 */
    private const ROOTS = [
        'templates' => 'templates',
        'modules' => 'modules',
        'plugins' => 'plugins',
    ];

    /** 요청 스코프 메모이즈 (같은 요청에서 같은 확장을 여러 번 묻는 경로 대비) */
    private static array $cache = [];

    /**
     * 게시 대상 파일 열거 결과의 요청 스코프 메모이즈 (키 형식은 `$cache` 와 같다 — `{type}|{id}`).
     *
     * 서술자 메모(`$cache`)와 자리를 나눈다 — 서술자는 **로드 목록**(최상위 css/js), 이것은
     * **게시·변경 감지 집합**(하위 디렉토리 포함 전 허용 확장자)이라 의미가 다르다.
     */
    private static array $fileCache = [];

    /**
     * 확장 하나의 사용자 추가 에셋 목록을 돌려줍니다.
     *
     * @param  string  $extensionType  `templates` | `modules` | `plugins`
     * @param  string  $identifier  확장 식별자
     * @return array<int, array<string, mixed>> 서술자 목록
     */
    public static function forExtension(string $extensionType, string $identifier): array
    {
        $cacheKey = $extensionType.'|'.$identifier;

        if (array_key_exists($cacheKey, self::$cache)) {
            return self::$cache[$cacheKey];
        }

        $assets = self::resolve($extensionType, $identifier);

        // 7.1.0 템플릿 환경설정 등 다른 출처가 항목을 더할 수 있는 지점.
        // 소비자는 출처를 보지 않으므로 여기서 더한 항목도 같은 규칙으로 로드된다.
        $assets = HookManager::applyFilters('core.assets.custom_assets', $assets, $extensionType, $identifier);

        self::$cache[$cacheKey] = is_array($assets) ? array_values($assets) : [];

        return self::$cache[$cacheKey];
    }

    /**
     * 요청 스코프 캐시를 비웁니다 (테스트·파일 변경 직후용).
     *
     * @return void
     */
    public static function flushCache(): void
    {
        self::$cache = [];
        self::$fileCache = [];
    }

    /**
     * 확장 하나의 `custom/` 아래 **게시 대상 파일 전체**를 열거합니다.
     *
     * 정적 게시(`ExtensionStaticCacheService`)와 변경 감지(`CollectsCustomAssets`)가 **같은
     * 열거자**를 쓴다. 종전에는 게시가 `custom/**` 를 재귀로 복사하고 감지는 최상위 css/js 의
     * mtime 만 서명해 범위가 어긋났다 — 문서가 권장하는 `url('./fonts/x.woff2')` 의 글꼴만
     * 교체하면 게시본이 영영 갱신되지 않았다(#651 F7). 감지 범위와 게시 범위를 한 코드가
     * 정의해야 다시 어긋나지 않는다.
     *
     * 규약 스캔의 **로드 목록**(`forExtension()` — 최상위 css/js) 과는 별개다. 글꼴·이미지는
     * CSS 가 상대 경로로 참조하는 대상이지 그 자체로 로드할 것이 아니므로 로드 목록은 그대로다.
     *
     * 허용 확장자는 종전 게시와 동일하게 `AllowedTemplateFileType::allowedExtensions()`(환경
     * 무관 게터)에서 소스맵(`map`)을 뺀 목록이다 — 배포 금지 정책(`*.map` gitignore)과 같다.
     *
     * @param  string  $extensionType  `templates` | `modules` | `plugins`
     * @param  string  $identifier  확장 식별자
     * @return array<int, array{relative: string, absolute: string, mtime: int, size: int}> 상대 경로 오름차순
     */
    public static function publishableFiles(string $extensionType, string $identifier): array
    {
        $cacheKey = $extensionType.'|'.$identifier;

        if (array_key_exists($cacheKey, self::$fileCache)) {
            return self::$fileCache[$cacheKey];
        }

        return self::$fileCache[$cacheKey] = self::enumeratePublishableFiles($extensionType, $identifier);
    }

    /**
     * `publishableFiles()` 의 실제 열거 (메모 없음).
     *
     * @param  string  $extensionType  확장 타입
     * @param  string  $identifier  확장 식별자
     * @return array<int, array{relative: string, absolute: string, mtime: int, size: int}> 상대 경로 오름차순
     */
    private static function enumeratePublishableFiles(string $extensionType, string $identifier): array
    {
        $directory = self::directory($extensionType, $identifier);

        if ($directory === null) {
            return [];
        }

        $realRoot = realpath($directory);

        if ($realRoot === false || ! is_dir($realRoot)) {
            return [];
        }

        $allowed = array_diff(AllowedTemplateFileType::allowedExtensions(), ['map']);

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($realRoot, \FilesystemIterator::SKIP_DOTS)
            );
        } catch (\UnexpectedValueException $e) {
            Log::warning('사용자 추가 에셋 디렉토리를 열 수 없습니다.', [
                'identifier' => $identifier,
                'directory' => $realRoot,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $files = [];

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $extension = strtolower($file->getExtension());

            if (! in_array($extension, $allowed, true)) {
                continue;
            }

            // 컨테인먼트 검증 — 심볼릭 링크 등으로 custom 밖을 가리키는 실경로 차단 (게시와 동일)
            $realFile = $file->getRealPath();

            if ($realFile === false || ! str_starts_with($realFile, $realRoot.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($realFile, strlen($realRoot) + 1));

            $files[$relative] = [
                'relative' => $relative,
                'absolute' => $realFile,
                'mtime' => (int) (@$file->getMTime() ?: 0),
                'size' => (int) (@$file->getSize() ?: 0),
            ];
        }

        ksort($files, SORT_STRING);

        return array_values($files);
    }

    /**
     * 확장의 `custom/` 디렉토리 절대 경로를 돌려줍니다.
     *
     * @param  string  $extensionType  확장 타입
     * @param  string  $identifier  확장 식별자
     * @return string|null 경로, 타입이 유효하지 않으면 null
     */
    public static function directory(string $extensionType, string $identifier): ?string
    {
        $root = self::ROOTS[$extensionType] ?? null;

        if ($root === null || $identifier === '' || ! self::isSafeIdentifier($identifier)) {
            return null;
        }

        return base_path($root.'/'.$identifier.'/'.self::DIRECTORY);
    }

    /**
     * 선언 파일 또는 규약 스캔으로 자산 목록을 만듭니다.
     *
     * @param  string  $extensionType  확장 타입
     * @param  string  $identifier  확장 식별자
     * @return array<int, array<string, mixed>> 서술자 목록
     */
    private static function resolve(string $extensionType, string $identifier): array
    {
        $directory = self::directory($extensionType, $identifier);

        if ($directory === null || ! is_dir($directory)) {
            return [];
        }

        $declaration = $directory.DIRECTORY_SEPARATOR.self::DECLARATION_FILE;

        if (is_file($declaration)) {
            return self::fromDeclaration($declaration, $extensionType, $identifier, $directory);
        }

        return self::fromConvention($extensionType, $identifier, $directory);
    }

    /**
     * `custom/assets.json` 선언을 해석합니다.
     *
     * 선언이 있으면 규약 스캔은 하지 않는다 — 둘을 합치면 "선언에서 뺐는데 왜 아직
     * 로드되나" 가 된다. 선언이 깨졌을 때도 스캔으로 되돌아가지 않는다. 되돌아가면
     * 운영자가 의도적으로 뺀 파일이 되살아난다.
     *
     * @param  string  $declaration  선언 파일 경로
     * @param  string  $extensionType  확장 타입
     * @param  string  $identifier  확장 식별자
     * @param  string  $directory  `custom/` 절대 경로
     * @return array<int, array<string, mixed>> 서술자 목록
     */
    private static function fromDeclaration(
        string $declaration,
        string $extensionType,
        string $identifier,
        string $directory
    ): array {
        $decoded = json_decode((string) file_get_contents($declaration), true);

        if (! is_array($decoded) || ! isset($decoded['assets']) || ! is_array($decoded['assets'])) {
            Log::warning('사용자 추가 에셋 선언을 읽을 수 없습니다 (해당 확장의 custom 자산을 로드하지 않습니다).', [
                'declaration' => $declaration,
                'json_error' => json_last_error_msg(),
            ]);

            return [];
        }

        $assets = [];

        foreach ($decoded['assets'] as $index => $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $type = $entry['type'] ?? null;

            if (! in_array($type, ['style', 'script'], true)) {
                Log::warning('사용자 추가 에셋 항목의 type 이 올바르지 않습니다.', [
                    'declaration' => $declaration,
                    'index' => $index,
                    'type' => is_scalar($type) ? $type : gettype($type),
                ]);

                continue;
            }

            // 외부 URL — 운영자가 자기 사이트에 직접 등록한 것만 허용한다 (D14).
            // 확장 저작자의 기본값이 아니라 운영자 본인의 선택이라서 성립하는 예외이며,
            // 왜 외부로 나가는지가 파일에 남도록 사유를 요구한다.
            if (isset($entry['url'])) {
                $url = $entry['url'];
                $reason = $entry['reason'] ?? null;

                if (! is_string($url) || ! preg_match('#^https://#i', $url)) {
                    Log::warning('사용자 추가 에셋의 외부 URL 은 https 여야 합니다.', [
                        'declaration' => $declaration,
                        'index' => $index,
                    ]);

                    continue;
                }

                if (! is_string($reason) || trim($reason) === '') {
                    Log::warning('사용자 추가 에셋의 외부 URL 에는 reason(사유)이 필요합니다.', [
                        'declaration' => $declaration,
                        'index' => $index,
                        'url' => $url,
                    ]);

                    continue;
                }

                $assets[] = [
                    'id' => self::assetId($extensionType, $identifier, $url),
                    'type' => $type,
                    'url' => $url,
                    'version' => null,
                    'source' => 'url',
                ];

                continue;
            }

            $file = $entry['file'] ?? null;

            if (! is_string($file) || $file === '') {
                Log::warning('사용자 추가 에셋 항목에 file 또는 url 이 없습니다.', [
                    'declaration' => $declaration,
                    'index' => $index,
                ]);

                continue;
            }

            $descriptor = self::fileDescriptor($extensionType, $identifier, $directory, $file, $type);

            if ($descriptor !== null) {
                $assets[] = $descriptor;
            }
        }

        return $assets;
    }

    /**
     * 규약 스캔으로 자산 목록을 만듭니다.
     *
     * `custom/*.css` · `custom/*.js` 를 파일명 오름차순으로 싣는다. 하위 디렉토리는
     * 훑지 않는다 — 폰트·이미지는 CSS 가 상대 경로로 참조하는 대상이지 그 자체로
     * 로드할 것이 아니다.
     *
     * @param  string  $extensionType  확장 타입
     * @param  string  $identifier  확장 식별자
     * @param  string  $directory  `custom/` 절대 경로
     * @return array<int, array<string, mixed>> 서술자 목록
     */
    private static function fromConvention(string $extensionType, string $identifier, string $directory): array
    {
        $entries = scandir($directory);

        if ($entries === false) {
            return [];
        }

        sort($entries, SORT_STRING);

        $styles = [];
        $scripts = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || ! is_file($directory.DIRECTORY_SEPARATOR.$entry)) {
                continue;
            }

            $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            $type = self::CONVENTION_TYPES[$extension] ?? null;

            if ($type === null) {
                continue;
            }

            $descriptor = self::fileDescriptor($extensionType, $identifier, $directory, $entry, $type);

            if ($descriptor === null) {
                continue;
            }

            // CSS 를 JS 보다 먼저 — 스타일이 먼저 붙어야 스크립트가 만드는 DOM 도 즉시 적용된다
            if ($type === 'style') {
                $styles[] = $descriptor;
            } else {
                $scripts[] = $descriptor;
            }
        }

        return array_merge($styles, $scripts);
    }

    /**
     * 파일 기반 서술자를 만듭니다.
     *
     * `version` 은 파일 수정 시각이다. 확장 캐시 버전(`ext.cache_version`)은 운영자가
     * 파일을 고쳤다고 오르지 않으므로, 그 값으로 URL 을 만들면 수정이 브라우저에
     * 반영되지 않는다.
     *
     * @param  string  $extensionType  확장 타입
     * @param  string  $identifier  확장 식별자
     * @param  string  $directory  `custom/` 절대 경로
     * @param  string  $file  `custom/` 기준 상대 경로
     * @param  string  $type  `style` | `script`
     * @return array<string, mixed>|null 서술자, 유효하지 않으면 null
     */
    private static function fileDescriptor(
        string $extensionType,
        string $identifier,
        string $directory,
        string $file,
        string $type
    ): ?array {
        $relative = ltrim(str_replace('\\', '/', $file), '/');

        if ($relative === '' || str_contains($relative, '..') || str_contains($relative, "\0")) {
            Log::warning('사용자 추가 에셋 경로가 안전하지 않습니다.', [
                'identifier' => $identifier,
                'file' => $file,
            ]);

            return null;
        }

        if (! self::isAllowedExtension($relative)) {
            Log::warning('사용자 추가 에셋의 확장자가 허용 목록에 없습니다.', [
                'identifier' => $identifier,
                'file' => $relative,
            ]);

            return null;
        }

        $absolute = $directory.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);

        if (! is_file($absolute)) {
            Log::warning('선언된 사용자 추가 에셋 파일이 없습니다.', [
                'identifier' => $identifier,
                'file' => $relative,
            ]);

            return null;
        }

        $servePath = self::DIRECTORY.'/'.$relative;
        $version = @filemtime($absolute) ?: null;

        return [
            'id' => self::assetId($extensionType, $identifier, $relative),
            'type' => $type,
            'url' => self::assetUrl($extensionType, $identifier, $servePath, $version),
            'version' => $version,
            'source' => 'file',
        ];
    }

    /**
     * 확장 타입에 맞는 자산 URL 을 만듭니다.
     *
     * 템플릿은 서버가 `dist/` 를 자동 부가하지만 `custom/` 은 그 밖에 있다 —
     * `TemplateService::getAssetFilePath` 가 `custom/` 접두를 따로 해석한다.
     *
     * @param  string  $extensionType  확장 타입
     * @param  string  $identifier  확장 식별자
     * @param  string  $servePath  서빙 경로 (`custom/...`)
     * @param  int|null  $version  캐시 무효화 버전
     * @return string 자산 URL
     */
    private static function assetUrl(string $extensionType, string $identifier, string $servePath, ?int $version): string
    {
        // 세 타입 모두 확장 자산과 **같은 메커니즘**으로 정적 게시되므로 URL 축도 같다.
        // 파일 서명(mtime)을 넘기지 않는 이유: 정적 경로는 언제나 **현재 게시 버전**이라,
        // 다른 값을 넘기면 `AssetUrl` 의 버전 일치 게이트에 걸려 정적 분기가 영영 선택되지
        // 않는다. 운영자가 파일을 고치면 `CollectsCustomAssets` 가 그것을 감지해 확장 캐시
        // 버전을 올리고, 그 단일 지점이 재게시까지 예약한다.
        //
        // 게시본이 아직 없으면(게시 직전 창·비프로덕션·kill-switch) `AssetUrl` 이 API 경로로
        // 떨어지고, 그 응답은 디스크의 최신 내용을 그대로 준다. 그 경로의 `?v` 도 캐시
        // 버전이라 파일 수정 → 감지 → bump 로 함께 갱신된다.
        //
        // `$version`(파일 mtime)은 URL 에 쓰지 않는다. 서술자의 `version` 필드로만 남아
        // 변경 감지 서명의 재료가 된다 — URL 축과 감지 축은 목적이 다르다.
        $current = ExtensionStaticCacheService::getExtensionCacheVersion();

        return match ($extensionType) {
            'templates' => AssetUrl::templateAsset($identifier, $servePath, $current),
            'modules' => AssetUrl::moduleAsset($identifier, $servePath, $current, allowStatic: true),
            default => AssetUrl::pluginAsset($identifier, $servePath, $current, allowStatic: true),
        };
    }

    /**
     * 서술자 식별자를 만듭니다 (중복 로드 방지 · 실패 표면화 키).
     *
     * @param  string  $extensionType  확장 타입
     * @param  string  $identifier  확장 식별자
     * @param  string  $suffix  파일 경로 또는 URL
     * @return string 식별자
     */
    private static function assetId(string $extensionType, string $identifier, string $suffix): string
    {
        return 'custom:'.$extensionType.':'.$identifier.':'.$suffix;
    }

    /**
     * 확장자가 허용 목록에 있는지 판정합니다.
     *
     * 자산 서빙이 다시 검증하지만, 목록에 없는 파일을 URL 로 만들어 페이지에 실어
     * 보낼 이유가 없다.
     *
     * @param  string  $relative  상대 경로
     * @return bool 허용되면 true
     */
    private static function isAllowedExtension(string $relative): bool
    {
        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));

        return in_array($extension, ['css', 'js', 'mjs'], true);
    }

    /**
     * 확장 식별자가 경로로 안전한지 판정합니다.
     *
     * @param  string  $identifier  확장 식별자
     * @return bool 안전하면 true
     */
    private static function isSafeIdentifier(string $identifier): bool
    {
        return preg_match('/^[A-Za-z0-9._-]+$/', $identifier) === 1 && ! str_contains($identifier, '..');
    }
}
