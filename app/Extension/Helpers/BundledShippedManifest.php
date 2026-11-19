<?php

namespace App\Extension\Helpers;

use Illuminate\Support\Facades\Log;

/**
 * 코어가 사이트의 번들 디렉토리(`{modules,plugins,templates,lang-packs}/_bundled`)에 놓은 파일 목록(출하 목록) (7.2.0).
 *
 * 코어 업데이트의 기본(증분) 모드는 새 판이 지운 번들 파일을 남긴다(`--prune` 만 지운다). 남은 옛 파일은 뒤이은 확장
 * 업데이트가 활성 폴더로 옮겨, 지운 화면 조각이 다시 등록되는 등의 결함이 됐다(7.0→7.1·7.1→7.2 는 업그레이드 스텝의
 * 동결 목록으로 메웠다). 그래서 업데이트·설치가 그때 놓은 번들 파일의 경로와 본문 해시를 사이트에 남기고
 * (`storage/app/core-update/bundled-shipped.json`), 다음 업데이트가 「직전 목록에는 있고 새 목록에는 없는」 파일만 지운다.
 *
 * - 운영자가 넣은 파일은 직전 목록에 없으므로 지우지 않는다.
 * - 출하했던 파일을 운영자가 고쳤으면(지금 해시 ≠ 직전 목록 해시) 지우지 않고 보고한다 — 지우면 운영자 작업을 잃는데, 남겨도
 *   그 확장의 다음 갱신이 그 파일을 옮길 뿐 코어 동작을 깨지 않는다(되돌릴 수 없는 쪽을 피한다).
 * - 확장 소스 폴더(`src/`)의 PHP 클래스 파일은 지우지 않는다 — 확장 갱신이 옮겨 심는 옛 클래스맵·훅 캐시가 그 클래스를 가리키는
 *   동안 파일이 없으면 요청이 500 이 된다(7.2.0 스텝 11 과 같은 규칙). 그 파일은 목록에서도 빠지므로 한 번만 남긴다.
 * - 여러 판을 건너뛰어도 「사이트의 직전 목록 대 이번 배포본 목록」 한 번의 비교로 정리된다.
 *
 * 새 목록은 배포본(업데이트 대기 폴더의 압축 해제본) 자체에서 만든다 — 배포본이 곧 출하물이라 별도 생성 단계·커밋된 목록
 * 파일이 어긋날 일이 없다. 설치는 설치 직후 디스크(= 배포본)에서 씨앗한다(`core:deploy-ledger-seed`).
 *
 * 되돌리기에 대비해 목록을 쓸 때 직전 목록을 `bundled-shipped.previous.json` 으로 남기고, 되돌리기가 그것으로 돌려놓는다
 * (지운 파일은 백업 복원이 되살린다).
 */
final class BundledShippedManifest
{
    /** 출하 목록 대상 루트 */
    // audit:allow install-path-reads-bundled-or-template-id reason: 코어 업데이트·설치가 사이트에 놓는 번들 원본 트리 자체의 목록을 만든다(설치 경로가 아님)
    public const ROOTS = ['modules/_bundled', 'plugins/_bundled', 'templates/_bundled', 'lang-packs/_bundled'];

    /** 출하 목록 파일 이름 */
    public const FILENAME = 'bundled-shipped.json';

    /** 직전 목록 사본 이름 (되돌리기용) */
    public const PREVIOUS_FILENAME = 'bundled-shipped.previous.json';

    /** 목록 형식 판 */
    public const SCHEMA = 1;

    /** 목록에 넣지 않는 경로 조각 (`app.update.excludes` 의 디렉토리 이름과 같은 뜻) */
    private const EXCLUDED_SEGMENTS = ['node_modules', '.git'];

    /** 지우지 않는 파일 — 확장 소스 폴더의 PHP 클래스 파일 */
    // audit:allow install-path-reads-bundled-or-template-id reason: 번들 원본 트리 경로 판별만 한다(읽지 않음)
    private const RETAIN_PATTERN = '#^(modules|plugins|templates|lang-packs)/_bundled/[^/]+/src/.+\.php$#';

    /**
     * 출하 목록 경로.
     *
     * @param  string|null  $directory  목록 디렉토리 (null 이면 `storage/app/core-update`)
     * @return string 절대 경로
     */
    public static function path(?string $directory = null): string
    {
        return rtrim($directory ?? storage_path('app/core-update'), '/\\').DIRECTORY_SEPARATOR.self::FILENAME;
    }

    /**
     * 사이트의 출하 목록을 읽습니다.
     *
     * @param  string|null  $directory  목록 디렉토리
     * @return array<string, string>|null 경로 → md5 (없거나 깨졌으면 null)
     */
    public static function read(?string $directory = null): ?array
    {
        $path = self::path($directory);
        $decoded = is_file($path) ? json_decode((string) @file_get_contents($path), true) : null;

        if (! is_array($decoded) || ! is_array($decoded['files'] ?? null)) {
            return null;
        }

        return array_filter($decoded['files'], static fn ($hash, $key): bool => is_string($key) && is_string($hash), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * 출하 목록을 씁니다.
     *
     * 업데이트의 파일 적용이 쓸 때(`$keepPrevious`)는 되돌리기에 대비해 쓰기 전 목록(없었으면 「없음」 표지)을 사본으로 남긴다.
     * 사본이 이미 있으면 덮지 않는다 — 실행 시작({@see beginRun()})이 지난 실행의 사본을 버리므로, 남아 있는 사본은 이번 실행의
     * 첫 쓰기 것이다(관리자 화면의 적용 재시도가 사본을 「새 목록」으로 덮지 않게). 씨앗(설치·전환 스텝)은 되돌릴 대상이 아니라
     * 사본을 남기지 않고, 있던 사본도 버린다.
     *
     * @param  array<string, string>  $files  경로 → md5
     * @param  string|null  $version  이 목록의 코어 판 (기록용)
     * @param  string|null  $directory  목록 디렉토리
     * @param  bool  $keepPrevious  되돌리기용 사본을 남길지 (업데이트의 파일 적용만 true)
     * @return bool 썼으면 true
     */
    public static function write(array $files, ?string $version = null, ?string $directory = null, bool $keepPrevious = true): bool
    {
        $path = self::path($directory);
        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return false;
        }

        $previous = $dir.DIRECTORY_SEPARATOR.self::PREVIOUS_FILENAME;
        if (! $keepPrevious) {
            if (is_file($previous)) {
                @unlink($previous);
            }
        } elseif (! is_file($previous)) {
            $previousBody = is_file($path) ? (string) @file_get_contents($path) : (string) json_encode(['absent' => true]);
            @file_put_contents($previous, $previousBody);
        }

        ksort($files);
        $body = (string) json_encode([
            'schema' => self::SCHEMA,
            'version' => $version,
            // audit:allow datetime-display-user-timezone reason: ISO8601 기계값
            'recorded_at' => date('c'),
            'files' => $files,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $temp = $path.'.tmp-'.bin2hex(random_bytes(4));
        if (@file_put_contents($temp, $body) === false) {
            return false;
        }

        if (! @rename($temp, $path)) {
            @unlink($temp);

            return false;
        }

        return true;
    }

    /**
     * 코어 업데이트 실행을 시작합니다 — 지난 실행이 남긴 되돌리기 사본을 버립니다.
     *
     * 사본은 「이번 실행이 목록을 썼다」는 표지다. 지난 실행(성공·중단)의 사본이 남아 있으면 이번 실행이 파일 적용 전에 실패해
     * 되돌릴 때 목록이 그 사본으로 후퇴하거나 지워진다. 실행 잠금을 잡은 뒤, 파일 적용 전에 부른다.
     *
     * @param  string|null  $directory  목록 디렉토리
     * @return void 반환값 없음
     */
    public static function beginRun(?string $directory = null): void
    {
        $previous = dirname(self::path($directory)).DIRECTORY_SEPARATOR.self::PREVIOUS_FILENAME;

        if (is_file($previous)) {
            @unlink($previous);
        }
    }

    /**
     * 되돌리기 — 이번 실행의 첫 쓰기 전 목록으로 돌려놓습니다(그때 목록이 없었으면 지웁니다). 이번 실행이 쓰지 않았으면(사본 없음)
     * 아무것도 하지 않습니다.
     *
     * @param  string|null  $directory  목록 디렉토리
     * @return void 반환값 없음
     */
    public static function restorePrevious(?string $directory = null): void
    {
        $path = self::path($directory);
        $previous = dirname($path).DIRECTORY_SEPARATOR.self::PREVIOUS_FILENAME;

        if (! is_file($previous)) {
            return;
        }

        $decoded = json_decode((string) @file_get_contents($previous), true);

        if (is_array($decoded) && ($decoded['absent'] ?? false) === true) {
            @unlink($path);
        } else {
            @copy($previous, $path);
        }

        @unlink($previous);
    }

    /**
     * 트리(배포본 또는 설치 디스크)에서 출하 목록을 한 번에 만듭니다.
     *
     * @param  string  $root  트리 루트 (배포본 압축 해제본 또는 프로젝트 루트)
     * @param  array<int, string>  $skipPaths  넣지 않는 경로 (이번에 놓지 않는 공식 번들 `{type}/_bundled/{id}`)
     * @return array<string, string> 경로 → md5
     */
    public static function collect(string $root, array $skipPaths = []): array
    {
        $result = self::collectChunk($root, [], null, $skipPaths);

        return $result['files'];
    }

    /**
     * 출하 목록을 나눠 만듭니다 — 관리자 화면 업데이트가 요청 시간 제한 안에서 이어 가게 한다.
     *
     * 첫 호출이 경로를 정렬해 모으고, 이후 순번부터 본문 해시를 이어 간다. `$shouldPause` 가 참을 돌려주면 진행분을 돌려준다.
     *
     * @param  string  $root  트리 루트
     * @param  array<string, mixed>  $progress  직전 진행분 (처음이면 빈 배열)
     * @param  \Closure|null  $shouldPause  멈출 때인지
     * @param  array<int, string>  $skipPaths  넣지 않는 경로
     * @return array{done: bool, paths: list<string>, index: int, files: array<string, string>} 진행분
     */
    public static function collectChunk(string $root, array $progress = [], ?\Closure $shouldPause = null, array $skipPaths = []): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $paths = is_array($progress['paths'] ?? null) ? array_values($progress['paths']) : self::enumerate($root, $skipPaths);
        $index = (int) ($progress['index'] ?? 0);
        $files = is_array($progress['files'] ?? null) ? $progress['files'] : [];
        $total = count($paths);

        while ($index < $total) {
            $relative = (string) $paths[$index];
            $hash = @md5_file($root.'/'.$relative);
            if (is_string($hash)) {
                $files[$relative] = $hash;
            }
            $index++;

            if ($shouldPause !== null && $index < $total && $index % 200 === 0 && $shouldPause()) {
                return ['done' => false, 'paths' => $paths, 'index' => $index, 'files' => $files];
            }
        }

        return ['done' => true, 'paths' => [], 'index' => $index, 'files' => $files];
    }

    /**
     * 직전 목록에는 있고 새 목록에는 없는 파일을 사이트에서 지웁니다.
     *
     * @param  string  $siteRoot  프로젝트 루트
     * @param  array<string, string>  $previous  사이트의 직전 목록
     * @param  array<string, string>  $next  이번 배포본 목록
     * @return array{removed: list<string>, kept_modified: list<string>, retained_classes: list<string>} 지운 파일·고쳐져 남긴 파일·남긴 클래스 파일
     */
    public static function pruneRemoved(string $siteRoot, array $previous, array $next): array
    {
        $siteRoot = rtrim(str_replace('\\', '/', $siteRoot), '/');
        $report = ['removed' => [], 'kept_modified' => [], 'retained_classes' => []];

        // 새 배포본에 아직 있는 확장 디렉토리 안에서만 지운다 — 확장 디렉토리째 사라졌으면(공식 번들 제외·사용자 커스텀 확장)
        // 종전 증분 적용처럼 최상위는 건드리지 않는다(`applyUpdate()` 의 preserveTopLevelOrphans 와 같은 경계).
        $shippedExtensions = [];
        foreach (array_keys($next) as $path) {
            $root = self::extensionRoot((string) $path);
            if ($root !== null) {
                $shippedExtensions[$root] = true;
            }
        }

        // 대소문자를 가리지 않는 파일 시스템(Windows·macOS)에서는 이름의 대소문자만 바뀐 새 판 파일이 옛 키로 열린다 — 지우면
        // 새 판 파일이 사라진다. 새 목록에 대소문자만 다른 같은 경로가 있으면 지우지 않는다.
        $nextFolded = [];
        foreach (array_keys($next) as $path) {
            $nextFolded[mb_strtolower(str_replace('\\', '/', (string) $path))] = true;
        }
        $excludes = self::updateExcludes();

        foreach ($previous as $relative => $shippedHash) {
            $relative = str_replace('\\', '/', (string) $relative);

            if (isset($next[$relative]) || ! self::isInsideExtension($relative) || str_contains($relative, '..')) {
                continue;
            }

            if (isset($nextFolded[mb_strtolower($relative)]) || self::isExcluded($relative, $excludes)) {
                continue;
            }

            if (! isset($shippedExtensions[(string) self::extensionRoot($relative)])) {
                continue;
            }

            $absolute = $siteRoot.'/'.$relative;

            if (is_link($absolute) || ! is_file($absolute)) {
                continue;
            }

            if (preg_match(self::RETAIN_PATTERN, $relative)) {
                $report['retained_classes'][] = $relative;

                continue;
            }

            if (@md5_file($absolute) !== $shippedHash) {
                $report['kept_modified'][] = $relative;

                continue;
            }

            if (! @unlink($absolute)) {
                $report['kept_modified'][] = $relative;
                Log::warning('[core-update] 새 판이 지운 번들 파일 삭제 실패 (권한 확인)', ['path' => $relative]);

                continue;
            }

            $report['removed'][] = $relative;
            self::pruneEmptyParents($siteRoot, $relative);
        }

        if ($report['removed'] !== [] || $report['kept_modified'] !== []) {
            Log::channel('upgrade')->info('[core-update] 출하 목록 대조 — 새 판이 지운 번들 파일 정리', $report);
        }

        return $report;
    }

    /**
     * 대상 루트 아래 파일 경로를 정렬해 모읍니다.
     *
     * @param  string  $root  트리 루트 (`/` 구분)
     * @param  array<int, string>  $skipPaths  넣지 않는 경로
     * @return list<string> 상대 경로
     */
    private static function enumerate(string $root, array $skipPaths): array
    {
        $skip = array_values(array_filter(array_map(static fn ($p): string => trim(str_replace('\\', '/', (string) $p), '/'), $skipPaths)));
        $excludes = self::updateExcludes();
        $paths = [];

        foreach (self::ROOTS as $target) {
            $base = $root.'/'.$target;
            if (! is_dir($base)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
                    static fn (\SplFileInfo $item): bool => ! in_array($item->getFilename(), self::EXCLUDED_SEGMENTS, true) && ! $item->isLink(),
                ),
            );

            foreach ($iterator as $item) {
                /** @var \SplFileInfo $item */
                if (! $item->isFile()) {
                    continue;
                }

                $relative = $target.'/'.ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen($base))), '/');

                foreach ($skip as $prefix) {
                    if ($relative === $prefix || str_starts_with($relative, $prefix.'/')) {
                        continue 2;
                    }
                }

                // 운영자가 업데이트에서 뺀 경로는 업데이트가 놓지 않으므로 「출하했던 파일」이 아니다
                if (self::isExcluded($relative, $excludes)) {
                    continue;
                }

                $paths[] = $relative;
            }
        }

        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * 업데이트 제외 설정(`app.update.excludes`)과 기본 제외 이름을 합칩니다.
     *
     * @return list<string> 제외 패턴 (이름 또는 슬래시를 포함한 경로)
     */
    private static function updateExcludes(): array
    {
        $configured = function_exists('config') ? (array) config('app.update.excludes', []) : [];
        $patterns = array_map(static fn ($p): string => trim(str_replace('\\', '/', (string) $p), '/'), array_merge(self::EXCLUDED_SEGMENTS, $configured));

        return array_values(array_unique(array_filter($patterns, static fn (string $p): bool => $p !== '')));
    }

    /**
     * 상대 경로가 제외 패턴에 걸리는지 — 코어 업데이트의 백업·적용과 같은 규칙(슬래시 없는 이름은 어느 단계와도, 경로는 접두사로).
     *
     * @param  string  $relative  `/` 구분 상대 경로
     * @param  list<string>  $excludes  제외 패턴
     * @return bool 걸리면 true
     */
    private static function isExcluded(string $relative, array $excludes): bool
    {
        $segments = explode('/', $relative);

        foreach ($excludes as $exclude) {
            if (str_contains($exclude, '/')
                ? ($relative === $exclude || str_starts_with($relative, $exclude.'/'))
                : in_array($exclude, $segments, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 경로의 확장 루트(`{type}/_bundled/{id}`)를 돌려줍니다.
     *
     * @param  string  $relative  상대 경로
     * @return string|null 확장 루트 (번들 확장 내부가 아니면 null)
     */
    private static function extensionRoot(string $relative): ?string
    {
        // audit:allow install-path-reads-bundled-or-template-id reason: 번들 원본 트리 경로 판별만 한다(읽지 않음)
        return preg_match('#^((?:modules|plugins|templates|lang-packs)/_bundled/[^/]+)/.+#', $relative, $m) ? $m[1] : null;
    }

    /**
     * 경로가 번들 확장 디렉토리 내부(`{type}/_bundled/{id}/…`)인지.
     *
     * @param  string  $relative  상대 경로
     * @return bool 내부면 true
     */
    private static function isInsideExtension(string $relative): bool
    {
        // audit:allow install-path-reads-bundled-or-template-id reason: 번들 원본 트리 경로 판별만 한다(읽지 않음)
        return (bool) preg_match('#^(modules|plugins|templates|lang-packs)/_bundled/[^/]+/.+#', $relative);
    }

    /**
     * 지운 파일의 비게 된 상위 디렉토리를 확장 루트 아래까지만 지웁니다.
     *
     * @param  string  $siteRoot  프로젝트 루트
     * @param  string  $relative  지운 파일
     * @return void 반환값 없음
     */
    private static function pruneEmptyParents(string $siteRoot, string $relative): void
    {
        $segments = explode('/', $relative);
        array_pop($segments);

        while (count($segments) > 3) {
            $dir = $siteRoot.'/'.implode('/', $segments);

            if (is_link($dir) || ! is_dir($dir) || (scandir($dir) ?: ['.', '..', 'x']) !== ['.', '..'] || ! @rmdir($dir)) {
                return;
            }

            array_pop($segments);
        }
    }
}
