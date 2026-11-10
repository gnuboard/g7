<?php

namespace Plugins\Sirsoft\CdnCloudfront\Support;

/**
 * 코어 퍼지 항목 묶음 → CloudFront 무효화 경로 목록
 *
 * 코어 액션 `core.thumbnail.after_purge` 는 요청(또는 큐 작업) 하나에서 지운 파생본 디렉터리를 모아 한 번에
 * 넘긴다. 항목마다
 *  - `public_url_prefix`(파생본 디렉터리 공개 URL) → 그 경로 + `/*` 와일드카드 (원본 하나의 모든 규격)
 *  - `original_public_url`(원본 공개 URL, 공개 디스크 원본만) → 그 경로 그대로. 코어가 원본을 디렉터리째
 *    지웠다고 알리면(`original_is_directory`) 그 디렉터리 와일드카드
 * 를 만들고, CDN 호스트가 아닌 URL(서버 로컬 공개 디스크 등)은 버린다.
 *
 * CloudFront 한도: 와일드카드 경로는 배포 하나에서 **진행 중인 무효화 전체 합계** 15개, 경로는 진행 중 합계 3000개까지다.
 * 요청 하나의 와일드카드는 그보다 낮은 MAX_WILDCARDS 로 묶는다 — 앞선 무효화가 아직 진행 중일 때 다음 요청이 들어갈
 * 자리를 남긴다.
 *
 * 상한을 넘는 묶음(규격 하나 비우기, 상품 여러 개 삭제, 확장 제거 등)은 경로가 가장 많은 첫 세그먼트 묶음부터 그 묶음의
 * 공통 상위 디렉터리 하나로 접는다. 더 넓게 지우지만 한도 초과로 통째로 거절되는 것보다 낫다. 첫 세그먼트를 넘어서는
 * 접지 않는다 — 파생본 트리(`/thumbnails/…`)와 원본 트리(`/modules/…`)의 공통 상위는 `/` 라서, 함께 접으면 배포
 * 전체(`/*`)를 지운다. 접어도 상한을 넘으면(첫 세그먼트가 상한보다 많은 드문 묶음) requests() 가 요청을 나눈다.
 * 배포 전체 와일드카드는 어떤 경로로도 내보내지 않는다.
 */
class InvalidationPaths
{
    /** 요청 하나에 넣을 와일드카드 경로 상한 (배포 전체 진행 중 한도 15 보다 낮게) */
    public const MAX_WILDCARDS = 10;

    /** 요청 하나에 넣을 경로 상한 */
    public const MAX_PATHS = 3000;

    /**
     * 인코딩할 문자 — CloudFront 안내대로 RFC 1738 의 unsafe 문자와 `?`(경로에 남으면 질의 구분자가 된다)만.
     * 비 ASCII·제어 문자는 따로 인코딩한다. 그 밖의 문자(`( ) ! ' * + , $ & = ; : @`)를 인코딩하면 브라우저가 요청해
     * 캐시된 경로와 달라져 무효화가 캐시를 비우지 못한다.
     */
    private const UNSAFE = ' <>"#%{}|\\^~[]`?';

    /**
     * 퍼지 항목에서 무효화 경로를 만듭니다.
     *
     * @param  list<array<string, mixed>>  $items  코어 퍼지 항목
     * @param  string  $cdnHost  CDN 호스트 (소문자)
     * @return list<string> 무효화 경로 (`/` 로 시작, 중복·포함 관계 정리, 한도 안으로 접힘)
     */
    public static function fromItems(array $items, string $cdnHost): array
    {
        $paths = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $prefix = self::pathOnHost($item['public_url_prefix'] ?? null, $cdnHost);

            if ($prefix !== null && $prefix !== '/') {
                $paths[] = rtrim($prefix, '/').'/*';
            }

            $original = self::pathOnHost($item['original_public_url'] ?? null, $cdnHost);

            if ($original !== null && $original !== '/') {
                $paths[] = ($item['original_is_directory'] ?? false) === true
                    ? rtrim($original, '/').'/*'
                    : $original;
            }
        }

        return self::fold($paths);
    }

    /**
     * 이미 인코딩된 무효화 경로 목록을 정리하고 한도 안으로 접습니다 (재시도가 여러 대기 건을 합칠 때도 쓴다).
     *
     * 같은 경로 집합이면 입력 순서와 상관없이 같은 결과다 — 재시도가 같은 대기 건 묶음을 다시 보낼 때 본문이 같아야
     * 같은 호출자 참조를 쓸 수 있다.
     *
     * @param  list<string>  $paths  무효화 경로 (`/*` 로 끝나면 디렉터리 와일드카드)
     * @return list<string> 정리된 경로 (와일드카드 먼저, 그다음 정확 경로 — 각각 정렬). 상한을 넘을 수 있어 requests() 로 나눠 보낸다
     */
    public static function fold(array $paths): array
    {
        $directories = [];
        $files = [];

        foreach ($paths as $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }

            if ($path === '/*' || $path === '/') {
                continue;
            }

            if (str_ends_with($path, '/*')) {
                $directories[substr($path, 0, -2)] = true;
            } else {
                $files[$path] = true;
            }
        }

        $directories = self::withoutCovered(array_keys($directories));
        $files = self::uncovered(array_keys($files), $directories);

        // 경로 상한을 넘으면 정확 경로를 와일드카드로 바꾼다.
        $overPaths = count($directories) + count($files) - self::MAX_PATHS;

        if ($overPaths > 0) {
            $directories = self::withoutCovered(array_merge($directories, self::collapse($files, $overPaths)));
            $files = self::uncovered($files, $directories);
        }

        // 와일드카드 상한은 디렉터리만 접는다 — 정확 경로를 함께 넣으면 트리마다 와일드카드가 늘어 더 넓게 지운다.
        $overWildcards = count($directories) - self::MAX_WILDCARDS;

        if ($overWildcards > 0) {
            $collapsed = self::collapse($directories, $overWildcards);
            $directories = self::withoutCovered(array_merge($directories, $collapsed));
            $files = self::uncovered($files, $directories);
        }

        return array_merge(array_map(static fn (string $dir) => $dir.'/*', $directories), $files);
    }

    /**
     * 정리된 경로를 CreateInvalidation 요청 단위로 나눕니다.
     *
     * 요청마다 와일드카드는 MAX_WILDCARDS, 경로는 MAX_PATHS 까지다. 입력 순서를 지키고 fold() 의 결과는 입력 순서와
     * 무관하므로, 같은 경로 집합은 같은 요청들로 나뉜다 — 재시도가 같은 호출자 참조를 쓸 수 있다.
     *
     * @param  list<string>  $paths  fold() 가 정리한 경로
     * @return list<list<string>> 요청별 경로 (경로가 없으면 빈 목록)
     */
    public static function requests(array $paths): array
    {
        $wildcards = array_values(array_filter($paths, static fn (string $path) => str_ends_with($path, '/*')));
        $files = array_values(array_filter($paths, static fn (string $path) => ! str_ends_with($path, '/*')));

        $requests = array_chunk($wildcards, self::MAX_WILDCARDS);
        $last = array_pop($requests) ?? [];

        return array_merge($requests, array_chunk(array_merge($last, $files), self::MAX_PATHS));
    }

    /**
     * URL 이 CDN 호스트를 가리키면 그 경로를 돌려줍니다 (세그먼트 단위로 URL 인코딩을 맞춘다).
     *
     * 브라우저는 인코딩한 경로로 요청하고 CloudFront 는 그 경로로 캐시하므로, 저장소가 만든 URL 에 공백이나
     * 한글이 그대로 들어 있어도 같은 모양으로 맞춘다. 이미 인코딩된 세그먼트는 풀었다가 다시 인코딩한다.
     *
     * @param  mixed  $url  URL
     * @param  string  $cdnHost  CDN 호스트
     * @return string|null 경로 (CDN 호스트가 아니면 null)
     */
    private static function pathOnHost(mixed $url, string $cdnHost): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || strtolower($parts['host'] ?? '') !== $cdnHost) {
            return null;
        }

        $segments = array_map(
            static fn (string $segment) => self::encodeSegment(rawurldecode($segment)),
            explode('/', ltrim($parts['path'] ?? '', '/')),
        );

        return '/'.implode('/', $segments);
    }

    /**
     * 경로 세그먼트 하나를 CloudFront 무효화 경로 규칙으로 인코딩합니다.
     *
     * @param  string  $segment  디코딩된 세그먼트
     * @return string 인코딩한 세그먼트
     */
    private static function encodeSegment(string $segment): string
    {
        return (string) preg_replace_callback(
            '/[^\x21-\x7E]|['.preg_quote(self::UNSAFE, '/').']/',
            static fn (array $match) => rawurlencode($match[0]),
            $segment,
        );
    }

    /**
     * 다른 디렉터리 아래에 든 디렉터리를 뺍니다 (상위 와일드카드가 이미 덮는다).
     *
     * @param  list<string>  $directories  디렉터리 경로 (끝 `/` 없음)
     * @return list<string> 정리된 디렉터리 경로 (정렬)
     */
    private static function withoutCovered(array $directories): array
    {
        $directories = array_values(array_unique($directories));
        sort($directories);
        $kept = [];

        foreach ($directories as $directory) {
            if (! self::coveredBy($directory, $kept)) {
                $kept[] = $directory;
            }
        }

        return $kept;
    }

    /**
     * 디렉터리 와일드카드가 덮지 않는 정확 경로만 남깁니다.
     *
     * @param  list<string>  $files  정확 경로
     * @param  list<string>  $directories  디렉터리 경로 (끝 `/` 없음)
     * @return list<string> 남은 정확 경로 (정렬)
     */
    private static function uncovered(array $files, array $directories): array
    {
        $files = array_values(array_filter(
            $files,
            static fn (string $path) => ! self::coveredBy($path, $directories),
        ));
        sort($files);

        return $files;
    }

    /**
     * 경로가 디렉터리 목록 중 하나의 아래에 있는지
     *
     * @param  string  $path  경로
     * @param  list<string>  $directories  디렉터리 경로 (끝 `/` 없음)
     * @return bool 아래에 있으면 true
     */
    private static function coveredBy(string $path, array $directories): bool
    {
        foreach ($directories as $directory) {
            if (str_starts_with($path, $directory.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * 경로를 첫 세그먼트로 묶어, 많은 묶음부터 공통 상위 디렉터리 하나로 접습니다.
     *
     * 줄인 개수가 $excess 에 닿으면 멈춘다 — 넓게 지우는 범위를 필요한 만큼만 쓴다. 같은 개수면 첫 세그먼트 이름순이라
     * 같은 경로 집합은 늘 같은 결과다. 경로가 하나뿐인 묶음은 접어도 줄지 않아 건너뛴다(루트에 바로 놓인 파일도 여기 든다).
     *
     * @param  list<string>  $paths  디렉터리(끝 `/` 없음) 또는 정확 경로
     * @param  int  $excess  줄여야 할 개수
     * @return list<string> 접은 디렉터리 (끝 `/` 없음) — 접은 경로를 덮는다
     */
    private static function collapse(array $paths, int $excess): array
    {
        $groups = [];

        foreach ($paths as $path) {
            $groups[explode('/', ltrim($path, '/'))[0]][] = $path;
        }

        uksort($groups, static fn (string $a, string $b) => (count($groups[$b]) <=> count($groups[$a])) ?: strcmp($a, $b));

        $collapsed = [];

        foreach ($groups as $group) {
            if ($excess <= 0 || count($group) < 2) {
                break;
            }

            $collapsed[] = self::commonDirectory($group);
            $excess -= count($group) - 1;
        }

        return $collapsed;
    }

    /**
     * 경로들의 공통 상위 디렉터리 (끝 `/` 없음)
     *
     * 첫 세그먼트가 같은 경로끼리만 부르므로 결과는 루트가 되지 않는다. 디렉터리끼리 접을 때는 마지막 세그먼트도 디렉터리
     * 이름이라 그대로 비교한다. 정확 경로끼리 접을 때는 서로 다른 경로만 들어오므로 공통 부분에 파일 이름까지 들어가는 일은 없다.
     *
     * @param  list<string>  $paths  경로
     * @return string 디렉터리
     */
    private static function commonDirectory(array $paths): string
    {
        $common = null;

        foreach ($paths as $path) {
            $segments = explode('/', trim($path, '/'));
            $common = $common === null
                ? $segments
                : array_slice($common, 0, self::sharedLength($common, $segments));
        }

        return '/'.implode('/', $common ?? []);
    }

    /**
     * 두 세그먼트 목록의 앞쪽 공통 길이
     *
     * @param  list<string>  $a  세그먼트
     * @param  list<string>  $b  세그먼트
     * @return int 공통 길이
     */
    private static function sharedLength(array $a, array $b): int
    {
        $length = 0;
        $max = min(count($a), count($b));

        while ($length < $max && $a[$length] === $b[$length]) {
            $length++;
        }

        return $length;
    }
}
