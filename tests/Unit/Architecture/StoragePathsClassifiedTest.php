<?php

namespace Tests\Unit\Architecture;

use App\Support\StoragePaths;
use App\Thumbnails\ThumbnailStore;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * 코드가 쓰는 storage 경로가 모두 storage 경로 분류(`config/g7-storage.php`)에 들어 있는지 잠근다.
 *
 * 분류는 백업 대상과 서버 간 공유 경로를 정하는 선언이라, 코드에 새 저장 경로가 생겼는데 목록에 없으면 그 경로는
 * 백업에서도 공유 설정에서도 조용히 빠진다. 그래서 코어·번들 확장 소스의 `storage_path(…)` 첫 인자에서 정적으로 읽히는
 * 접두(문자열·`DIRECTORY_SEPARATOR`·같은 파일의 클래스 상수)와 디스크 루트(`config/filesystems.php`)를 모아 분류를 묻는다.
 *
 * - `storage_path()`·`storage_path('app')` 처럼 storage 나 `storage/app` 자체를 가리키는 호출은 그 아래 목록을 훑는
 *   용도라 판정하지 않는다.
 * - `storage_path('app/'.$동적값)` 처럼 `app/` 바로 뒤가 동적이면 정적으로 알 수 없다 — {@see self::dynamicSites()} 에
 *   그 호출이 실제로 만드는 경로를 적어 두고 분류를 묻는다. 표에 없는 새 동적 호출은 실패한다.
 * - 번들 확장이 확장 저장소 디스크(StorageInterface) 대신 `storage/app/{자기 식별자}` 에 직접 쓰는 경로는 코어 설정이 확장을
 *   이름으로 지목하지 않도록 목록에 넣지 않는다. 그런 경로는 {@see self::EXTENSION_OWN_PATHS} 에 사유와 함께 적고, 새로 생기면 실패한다.
 */
class StoragePathsClassifiedTest extends TestCase
{
    use DatabaseTransactions;

    /** 코어 검사 경로 */
    private const CORE_PATHS = ['app', 'config', 'routes', 'upgrades', 'bootstrap/app.php'];

    /** 번들 확장 루트 */
    private const BUNDLED_ROOTS = ['modules/_bundled', 'plugins/_bundled', 'templates/_bundled'];

    /** 모집단에서 빼는 경로 조각 */
    private const EXCLUDED_SEGMENTS = ['/vendor/', '/node_modules/', '/tests/', '/dist/', '/lang/'];

    /**
     * 번들 확장이 `storage/app/{자기 식별자}` 에 직접 쓰는 경로 — 확장 소스 경로 => [storage 기준 경로, 사유].
     * 줄이는 방향으로만 고친다(확장이 자기 저장소 디스크로 옮기면 지운다).
     */
    private const EXTENSION_OWN_PATHS = [
        'plugins/_bundled/sirsoft-sso/src/Services/Ldap/ExtLdapDirectoryClient.php' => [
            'app/sirsoft-sso/ldap-ca-',
            '서버별 파일 — 연결 설정의 CA 묶음(PEM)에서 다시 만드는 사본',
        ],
    ];

    /**
     * 코드의 `storage_path(…)` 정적 접두가 모두 네 분류 중 하나에 듭니다.
     *
     * @scenario case=code_paths
     *
     * @effects code_storage_paths_classified
     *
     * @return void 반환값 없음
     */
    public function test_code_storage_paths_are_classified(): void
    {
        $root = base_path();
        $dynamic = $this->dynamicSites();
        $unclassified = [];
        $unknownDynamic = [];
        $checked = 0;

        foreach ($this->collectFiles($root) as $file) {
            $relative = str_replace('\\', '/', substr($file, strlen($root) + 1));
            foreach ($this->storagePathCalls((string) file_get_contents($file)) as [$prefix, $complete, $line]) {
                $normalized = StoragePaths::normalize($prefix);

                if ($normalized === '' || ($normalized === 'app' && $complete)) {
                    continue;
                }

                if ($normalized === 'app') {
                    if (! array_key_exists($relative, $dynamic)) {
                        $unknownDynamic[] = "{$relative}:{$line}";

                        continue;
                    }
                    foreach ($dynamic[$relative] as $resolved) {
                        $checked++;
                        if (StoragePaths::classify($resolved) === null) {
                            $unclassified[] = "{$resolved} ({$relative}:{$line})";
                        }
                    }

                    continue;
                }

                $checked++;
                if (isset(self::EXTENSION_OWN_PATHS[$relative]) && StoragePaths::matches($normalized, self::EXTENSION_OWN_PATHS[$relative][0])) {
                    continue;
                }
                if (StoragePaths::classify($normalized) === null) {
                    $unclassified[] = "{$normalized} ({$relative}:{$line})";
                }
            }
        }

        $this->assertGreaterThan(50, $checked, '검사한 storage 경로가 너무 적습니다 — 스캐너가 호출을 놓치고 있습니다.');
        $this->assertSame([], $unknownDynamic, "`storage_path('app/'.동적값)` 호출이 새로 생겼습니다 — 만드는 경로를 config/g7-storage.php 에 분류하고 이 테스트의 dynamicSites() 에 적으세요:\n".implode("\n", $unknownDynamic));
        $this->assertSame([], $unclassified, "config/g7-storage.php 의 네 목록 어디에도 없는 storage 경로입니다 — 성격에 맞는 목록에 넣으세요:\n".implode("\n", $unclassified));
    }

    /**
     * `config/filesystems.php` 의 storage 아래 디스크 루트가 모두 네 분류 중 하나에 듭니다.
     *
     * @scenario case=disk_roots
     *
     * @effects disk_roots_classified
     *
     * @return void 반환값 없음
     */
    public function test_disk_roots_are_classified(): void
    {
        $storage = StoragePaths::normalize(storage_path());
        // 키가 언제나 정해진 하위 폴더로 시작해 루트가 storage/app 자체인 디스크 — 실제로 쓰는 하위 폴더로 판정한다
        $keyedRoots = ['thumbnails' => 'app/'.ThumbnailStore::ROOT];
        $checked = 0;

        foreach ((array) config('filesystems.disks') as $name => $disk) {
            $diskRoot = is_array($disk) && is_string($disk['root'] ?? null) ? StoragePaths::normalize($disk['root']) : '';
            if ($diskRoot === '' || ! str_starts_with(strtolower($diskRoot), strtolower($storage).'/')) {
                continue;
            }

            $relative = substr($diskRoot, strlen($storage) + 1);
            // 시험 전용 격리 디스크(storage/framework/testing/disks/*, 예: 사이트맵 격리 디스크)는 운영에 없는 디스크다 — 판정 대상이 아니다
            if (str_starts_with($relative, 'framework/testing/disks/')) {
                continue;
            }
            // 테스트 환경은 확장 저장 루트를 framework/testing 으로 돌린다(config/filesystems.php) — 운영 위치로 판정한다
            if (str_starts_with($relative, 'framework/testing/')) {
                $relative = 'app/'.substr($relative, strlen('framework/testing/'));
            }
            if ($relative === 'app') {
                $this->assertArrayHasKey($name, $keyedRoots, "디스크 {$name} 의 루트가 storage/app 자체입니다 — 키가 시작하는 하위 폴더를 이 테스트에 적고 분류하세요.");
                $relative = $keyedRoots[$name];
            }

            $checked++;
            $this->assertNotNull(StoragePaths::classify($relative), "디스크 {$name} 의 루트 storage/{$relative} 가 config/g7-storage.php 에 분류돼 있지 않습니다.");
        }

        $this->assertGreaterThanOrEqual(8, $checked, 'storage 아래 디스크를 너무 적게 찾았습니다.');
    }

    /**
     * `app/` 바로 뒤가 동적인 호출과 그 호출이 실제로 만드는 storage 기준 경로.
     *
     * @return array<string, list<string>> 소스 상대 경로 => 만들어지는 경로 목록
     */
    private function dynamicSites(): array
    {
        $cloudIpRanges = 'app/'.config('analytics.cloud_ip_ranges.storage_path', 'analytics/cloud-ip-ranges.json');

        return [
            'app/Analytics/VisitExclusionPipeline.php' => [$cloudIpRanges],
            'app/Services/Analytics/CloudIpRangeService.php' => [$cloudIpRanges],
            // 디스크 설정이 비었을 때만 쓰는 운영 기본 위치 — 디스크 이름은 modules·plugins·templates
            'app/Support/ExtensionStoragePath.php' => ['app/modules', 'app/plugins', 'app/templates'],
        ];
    }

    /**
     * 검사할 PHP 파일 목록을 모읍니다.
     *
     * @param  string  $root  저장소 루트
     * @return list<string> 절대 경로 목록
     */
    private function collectFiles(string $root): array
    {
        $targets = array_map(static fn (string $path): string => $root.'/'.$path, self::CORE_PATHS);
        foreach (self::BUNDLED_ROOTS as $bundled) {
            foreach (glob($root.'/'.$bundled.'/*', GLOB_ONLYDIR) ?: [] as $extension) {
                $targets[] = $extension;
            }
        }

        $files = [];
        foreach ($targets as $target) {
            if (is_file($target)) {
                $files[] = $target;

                continue;
            }
            if (! is_dir($target)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $path = str_replace('\\', '/', $file->getPathname());
                if (! str_ends_with($path, '.php')) {
                    continue;
                }
                foreach (self::EXCLUDED_SEGMENTS as $segment) {
                    if (str_contains($path, $segment)) {
                        continue 2;
                    }
                }
                $files[] = $path;
            }
        }

        return $files;
    }

    /**
     * 소스의 `storage_path(` 호출마다 첫 인자의 정적 접두를 읽습니다.
     *
     * 문자열 리터럴(보간 앞까지), `DIRECTORY_SEPARATOR`, 같은 파일에 정의된 문자열 클래스 상수(`self::`·`static::`),
     * `str_replace('/', DIRECTORY_SEPARATOR, self::상수)` 를 `.` 로 이은 앞부분만 읽고, 그 밖의 식을 만나면 멈춘다.
     *
     * @param  string  $source  PHP 소스
     * @return list<array{0: string, 1: bool, 2: int}> [접두, 인자 전체가 정적인지, 줄 번호]
     */
    private function storagePathCalls(string $source): array
    {
        $constants = [];
        if (preg_match_all("/const\s+([A-Z0-9_]+)\s*=\s*'([^'\\\\]*)'\s*;/", $source, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $constants[$match[1]] = $match[2];
            }
        }

        if (! preg_match_all('/(?<![\w$>:])storage_path\(/', $source, $hits, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $calls = [];
        foreach ($hits[0] as [$hit, $offset]) {
            $position = $offset + strlen($hit);
            $prefix = '';
            $complete = false;

            while (true) {
                $rest = substr($source, $position);
                if (preg_match("/^\s*'([^'\\\\]*)'/", $rest, $token) === 1
                    || preg_match('/^\s*"([^"\\\\{$]*)"/', $rest, $token) === 1) {
                    $prefix .= $token[1];
                } elseif (preg_match('/^\s*\\\\?DIRECTORY_SEPARATOR\b/', $rest, $token) === 1) {
                    $prefix .= '/';
                } elseif (preg_match('/^\s*(?:self|static)::([A-Z0-9_]+)\b(?!\s*\()/', $rest, $token) === 1 && isset($constants[$token[1]])) {
                    $prefix .= $constants[$token[1]];
                } elseif (preg_match("/^\s*str_replace\(\s*'\/'\s*,\s*\\\\?DIRECTORY_SEPARATOR\s*,\s*(?:self|static)::([A-Z0-9_]+)\s*\)/", $rest, $token) === 1 && isset($constants[$token[1]])) {
                    $prefix .= $constants[$token[1]];
                } else {
                    break;
                }

                $position += strlen($token[0]);
                if (preg_match('/^\s*\./', substr($source, $position), $dot) !== 1) {
                    $complete = preg_match('/^\s*[),]/', substr($source, $position)) === 1;
                    break;
                }
                $position += strlen($dot[0]);
            }

            $calls[] = [$prefix, $complete, substr_count(substr($source, 0, $offset), "\n") + 1];
        }

        return $calls;
    }
}
