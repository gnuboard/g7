<?php

namespace App\Extension\Helpers;

use App\Support\ExecutionEnvironment;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class FilePermissionHelper
{
    /** @var array<string, mixed>|null 직전 `swapDirectory()` 가 운영 자리를 비운 채 실패한 기록 */
    protected static ?array $lastSwapFailure = null;

    /** @var \Closure(string, string): bool|null 이름 바꾸기 대체 함수 (시험 전용) */
    protected static ?\Closure $renamer = null;

    /**
     * 디렉토리를 재귀적으로 복사하면서 기존 파일/디렉토리의 퍼미션을 보존합니다.
     *
     * - 기존 디렉토리: 퍼미션/소유자/그룹 유지
     * - 신규 디렉토리: 부모 디렉토리의 퍼미션/소유자/그룹 상속
     * - 기존 파일: 퍼미션/소유자/그룹 유지한 채 내용만 교체
     * - 신규 파일: 부모 디렉토리의 소유자/그룹 상속 (퍼미션은 PHP 기본 umask)
     * - removeOrphans=false: 소스에 없고 대상에만 있는 파일 유지 (사용자 추가 파일 보호)
     * - removeOrphans=true: 소스에 없고 대상에만 있는 파일/디렉토리 삭제 (excludes 제외)
     * - preserveTopLevelOrphans=true: removeOrphans=true 라도 *최상위 한 레벨* 에서 소스에
     *   없는 항목(디렉토리/파일)은 삭제하지 않는다. 소스에 존재하는 디렉토리 내부의
     *   orphan 정리는 그대로 수행된다. 코어 업데이트가 `{domain}/_bundled` 를 sync 할 때
     *   사용자가 `_bundled/` 바로 아래에 직접 만든 커스텀 확장 디렉토리를 보존하기 위함.
     *
     * 신규 항목의 소유권 상속은 sudo 로 실행된 업데이트 프로세스가 root 소유로 파일을
     * 생성하는 것을 방지한다. vendor/ 처럼 cleanDirectory 후 재생성되는 디렉토리 구조
     * 전체가 기존 부모(= vendor/) 의 소유권을 승계하도록 보장한다.
     *
     * @param  string  $source  소스 디렉토리 경로
     * @param  string  $destination  대상 디렉토리 경로
     * @param  \Closure|null  $onProgress  진행 콜백
     * @param  array  $excludes  제외할 이름 또는 경로 목록 (예: ['node_modules', '.git', 'node_modules/test_dir'])
     * @param  string  $relativePath  현재 상대 경로 (내부 재귀용)
     * @param  bool  $removeOrphans  소스에 없는 대상 파일/디렉토리 삭제 여부
     * @param  bool  $preserveTopLevelOrphans  최상위 한 레벨의 orphan 보존 여부
     * @param  array<string, bool>|null  $applyList  적용 대상 파일 상대경로 화이트리스트
     *                                               (이 디렉토리 루트 기준, `'sub/file.txt' => true` 형태의 lookup 맵). null 이면
     *                                               전체 파일 복사(기존 동작). 지정 시 이 맵에 없는 파일은 복사를 스킵한다 — 코어
     *                                               업데이트 증분 모드에서 "코어가 변경하지 않은 파일"을 건드리지 않기 위함. symlink
     *                                               는 이 필터의 영향을 받지 않고 항상 재생성된다(링크는 목록 산출 대상이 아님).
     * @param  array<int, string>  $preserveLinkPaths  orphan 정리 시 삭제하지 않고 보존할 경로
     *                                                 (링크 여부와 무관 — 그 경로가 일반 디렉토리여도 보존)
     *                                                 symlink/junction 의 상대경로 화이트리스트(이 디렉토리 루트 기준, 예 `['storage']`).
     *                                                 `removeOrphans=true` 라도 이 목록에 매칭되는 orphan 링크는 삭제하지 않는다 — 코어
     *                                                 업데이트 prune 모드에서 `public/storage` symlink 가 orphan 으로 판정되어 삭제되던
     *                                                 결함(#43) 차단. 화이트리스트 밖 orphan 링크는 기존대로 삭제된다.
     * @param  array<int, string>  $skipPaths  복사·orphan 정리를 모두 건너뛸 상대경로(이 디렉토리 루트 기준, 예
     *                                         `['sirsoft-gdpr']`). 경로와 정확히 같거나 그 하위면 건너뛴다 — `excludes` 의 단순 이름과
     *                                         달리 다른 깊이의 같은 이름은 매치하지 않는다. 재귀에 그대로 하향 전달한다. 코어 업데이트가
     *                                         설치되지 않았고 디스크에도 없는 공식 번들을 배치하지 않기 위함(#146). 기본값 `[]` 은 기존 동작.
     * @return void 반환값 없음
     */
    public static function copyDirectory(string $source, string $destination, ?\Closure $onProgress = null, array $excludes = [], string $relativePath = '', bool $removeOrphans = false, bool $preserveTopLevelOrphans = false, ?array $applyList = null, array $preserveLinkPaths = [], array $skipPaths = []): void
    {
        if (! File::isDirectory($destination)) {
            // 신규 디렉토리: 부모 디렉토리의 퍼미션/소유권 상속
            static::createDirectoryInheritingParent($destination);
        }
        // 기존 디렉토리: 퍼미션 건드리지 않음 (그대로 유지)

        $items = new \FilesystemIterator($source, \FilesystemIterator::SKIP_DOTS);

        foreach ($items as $item) {
            $itemName = $item->getFilename();
            $itemRelativePath = $relativePath === '' ? $itemName : $relativePath.'/'.$itemName;

            if (static::isExcluded($itemName, $itemRelativePath, $excludes)) {
                continue;
            }
            if ($skipPaths !== [] && static::isSkippedPath($itemRelativePath, $skipPaths)) {
                continue;
            }

            $destPath = $destination.DIRECTORY_SEPARATOR.$itemName;

            // symlink / Windows junction 자체 보존: target 추적 없이 동일 링크 재생성.
            // SplFileInfo::isDir() 가 symlink 를 추적하므로 검사 순서가 isDir() 보다 먼저여야 한다.
            // 미적용 시 `public/storage` 등 링크가 target 디렉토리 내용으로 복사되어 백업/복원 양쪽에서 손상.
            //
            // Windows JUNCTION(`mklink /J`)은 reparse point 지만 PHP `isLink()`/`isDir()` 이 모두
            // false 를 반환한다(`storage:link` 가 Windows 에서 생성하는 형태). isLink() 만 검사하면
            // junction 이 파일로 오판되어 copyFile → `copy(디렉토리)` 로 코어 업데이트가 실패한다.
            // isReparsePoint() 로 junction 도 이 분기에 포함시킨다.
            if ($item->isLink() || static::isReparsePoint($item->getPathname())) {
                if (static::copyLink($item->getPathname(), $destPath)) {
                    continue;
                }
                // 복원 실패 (Windows SeCreateSymbolicLink 권한 부족 등) — 일반 복사로 fall-through.
                // 단, junction 은 isDir() 이 false 라 아래 else 분기의 copyFile 로 새어 파일 복사가
                // 다시 실패하므로, fall-through 대신 skip 하여 손상 없이 넘어간다.
                // 운영자가 추후 `php artisan storage:link` 수동 실행으로 회복 가능.
                if (! $item->isLink()) {
                    continue;
                }
            }

            if ($item->isDir()) {
                // preserveTopLevelOrphans 는 최상위 한 레벨 한정 — 자식 재귀에는 항상 false 전달.
                // preserveLinkPaths 는 이 디렉토리 루트 기준 상대경로(예 `public/storage` → `storage`)라
                // 재귀에 그대로 하향 전달 — removeOrphanItems 가 각 레벨에서 itemRelativePath 전체로
                // 매칭하므로 중첩 보호 경로도 정확히 판정된다.
                static::copyDirectory($item->getPathname(), $destPath, $onProgress, $excludes, $itemRelativePath, $removeOrphans, preserveTopLevelOrphans: false, applyList: $applyList, preserveLinkPaths: $preserveLinkPaths, skipPaths: $skipPaths);
            } else {
                // 증분 모드(applyList 지정): 화이트리스트에 없는 파일은 스킵 —
                // 코어가 변경하지 않은 파일의 현재 디스크 상태(사용자 수정 포함)를 보존.
                if ($applyList !== null && ! isset($applyList[$itemRelativePath])) {
                    continue;
                }
                static::copyFile($item->getPathname(), $destPath);
            }
        }

        // 소스에 없는 대상 파일/디렉토리 삭제
        if ($removeOrphans && File::isDirectory($destination)) {
            static::removeOrphanItems($source, $destination, $excludes, $relativePath, $preserveTopLevelOrphans, $preserveLinkPaths, $skipPaths);
        }
    }

    /**
     * 디렉토리를 나눠서 복사합니다 (백업·되돌리기의 커서 판).
     *
     * `copyDirectory()` 의 덮어쓰기 복사(고아 정리·증분 목록·건너뛸 경로 없이)와 같은 결과를 내되,
     * `$shouldPause` 가 참을 돌려주면 그 자리에서 멈추고 마지막으로 끝낸 항목의 상대 경로를 커서로
     * 돌려준다. 다음 호출이 그 커서를 넘기면 이어서 복사한다.
     *
     * 순서는 각 단계에서 이름을 정렬한 깊이 우선이다. 커서는 「여기까지 끝났다」는 경로라 요청 사이에
     * 항목이 늘거나 줄어도 빠지는 항목이 없다(정렬 순서상 커서 뒤에 생긴 항목은 이어 갈 때 만난다).
     * 멈춤은 항목 하나를 끝낸 뒤에만 묻는다 — 매 호출이 적어도 한 항목은 진행한다.
     *
     * 링크·정션은 `copyDirectory()` 와 같이 대상을 따라가지 않고 링크째 다시 만든다.
     *
     * @param  string  $source  원본 디렉토리
     * @param  string  $destination  대상 디렉토리 (없으면 부모 권한을 물려받아 만든다)
     * @param  array<int, string>  $excludes  제외할 이름 또는 경로 목록
     * @param  string|null  $resumeAfter  직전 호출이 돌려준 커서 (처음이면 null)
     * @param  \Closure|null  $shouldPause  멈출 때인지 묻는 함수 (null 이면 끝까지)
     * @return array{done: bool, cursor: string|null, copied: int} 끝났는지, 이어 갈 커서, 이번에 처리한 항목 수
     */
    public static function copyDirectoryChunk(string $source, string $destination, array $excludes = [], ?string $resumeAfter = null, ?\Closure $shouldPause = null): array
    {
        $state = ['paused' => false, 'cursor' => $resumeAfter, 'copied' => 0];
        $resume = $resumeAfter === null || $resumeAfter === '' ? null : explode('/', $resumeAfter);

        static::copyDirectoryChunkLevel($source, $destination, $excludes, '', $resume, $shouldPause, $state);

        return ['done' => ! $state['paused'], 'cursor' => $state['paused'] ? $state['cursor'] : null, 'copied' => $state['copied']];
    }

    /**
     * `copyDirectoryChunk()` 의 한 단계(디렉토리 하나)를 처리합니다.
     *
     * @param  string  $source  원본 디렉토리
     * @param  string  $destination  대상 디렉토리
     * @param  array<int, string>  $excludes  제외할 이름 또는 경로 목록
     * @param  string  $relativePath  복사 루트 기준 상대 경로
     * @param  list<string>|null  $resume  커서를 경로 조각으로 나눈 것 (이 단계에서 이어 갈 자리가 없으면 null)
     * @param  \Closure|null  $shouldPause  멈출 때인지 묻는 함수
     * @param  array{paused: bool, cursor: string|null, copied: int}  $state  진행 상태 (참조)
     * @return void 반환값 없음
     */
    protected static function copyDirectoryChunkLevel(string $source, string $destination, array $excludes, string $relativePath, ?array $resume, ?\Closure $shouldPause, array &$state): void
    {
        if (! File::isDirectory($destination)) {
            static::createDirectoryInheritingParent($destination);
        }

        $names = [];
        foreach (new \FilesystemIterator($source, \FilesystemIterator::SKIP_DOTS) as $item) {
            $names[] = $item->getFilename();
        }
        sort($names, SORT_STRING);

        $depth = $relativePath === '' ? 0 : substr_count($relativePath, '/') + 1;

        foreach ($names as $name) {
            $itemRelativePath = $relativePath === '' ? $name : $relativePath.'/'.$name;
            $childResume = null;

            if ($resume !== null) {
                $compare = strcmp($name, (string) ($resume[$depth] ?? ''));

                if ($compare < 0) {
                    continue;
                }

                if ($compare === 0 && count($resume) === $depth + 1) {
                    // 커서가 가리키는 항목 자체 — 앞 호출이 끝냈다.
                    $resume = null;

                    continue;
                }

                $childResume = $compare === 0 ? $resume : null;
                $resume = null;
            }

            if (static::isExcluded($name, $itemRelativePath, $excludes)) {
                continue;
            }

            $path = $source.DIRECTORY_SEPARATOR.$name;
            $destPath = $destination.DIRECTORY_SEPARATOR.$name;
            $handled = false;

            if (is_link($path) || static::isReparsePoint($path)) {
                if (static::copyLink($path, $destPath)) {
                    $handled = true;
                } elseif (! is_link($path)) {
                    // 정션 재생성 실패 — `copyDirectory()` 와 같이 손상 없이 건너뛴다.
                    $handled = true;
                }
            }

            if (! $handled) {
                if (is_dir($path)) {
                    static::copyDirectoryChunkLevel($path, $destPath, $excludes, $itemRelativePath, $childResume, $shouldPause, $state);

                    if ($state['paused']) {
                        return;
                    }
                } else {
                    static::copyFile($path, $destPath);
                }
            }

            $state['copied']++;
            $state['cursor'] = $itemRelativePath;

            if ($shouldPause !== null && $shouldPause()) {
                $state['paused'] = true;

                return;
            }
        }
    }

    /**
     * 디렉토리 트리를 나눠서 지웁니다 (큰 임시 폴더·백업 정리의 커서 판).
     *
     * 지운 항목은 사라지므로 커서가 필요 없다 — 다음 호출이 남은 것부터 다시 훑는다. 링크·정션은
     * 대상을 따라가지 않고 링크 자체만 지운다(백업·전개본 안의 `public/storage` 정션이 운영 중인
     * 업로드 폴더를 가리킨다). 멈춤은 항목 하나를 지운 뒤에만 묻는다.
     *
     * @param  string  $path  지울 경로
     * @param  \Closure|null  $shouldPause  멈출 때인지 묻는 함수 (null 이면 끝까지)
     * @return bool 다 훑었으면 true (지우지 못한 항목이 남았을 수 있다 — 호출부가 존재 여부로 확인)
     */
    public static function deleteDirectoryChunk(string $path, ?\Closure $shouldPause = null): bool
    {
        if (static::removeLinkEntry($path)) {
            return true;
        }

        if (! file_exists($path)) {
            return true;
        }

        if (! is_dir($path)) {
            @unlink($path);

            return true;
        }

        $paused = false;
        static::deleteDirectoryChunkLevel($path, $shouldPause, $paused);

        if (! $paused) {
            @rmdir($path);
        }

        return ! $paused;
    }

    /**
     * `deleteDirectoryChunk()` 의 한 단계를 처리합니다.
     *
     * @param  string  $directory  지울 디렉토리
     * @param  \Closure|null  $shouldPause  멈출 때인지 묻는 함수
     * @param  bool  $paused  멈췄는지 (참조)
     * @return void 반환값 없음
     */
    protected static function deleteDirectoryChunkLevel(string $directory, ?\Closure $shouldPause, bool &$paused): void
    {
        $entries = @scandir($directory);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$entry;

            if (! static::removeLinkEntry($path)) {
                if (is_dir($path)) {
                    static::deleteDirectoryChunkLevel($path, $shouldPause, $paused);

                    if ($paused) {
                        return;
                    }

                    @rmdir($path);
                } else {
                    @unlink($path);
                }
            }

            if ($shouldPause !== null && $shouldPause()) {
                $paused = true;

                return;
            }
        }
    }

    /**
     * 경로가 링크·정션이면 링크 자체만 지웁니다 (대상은 건드리지 않는다).
     *
     * @param  string  $path  경로
     * @return bool 링크·정션이었으면 true
     */
    protected static function removeLinkEntry(string $path): bool
    {
        if (! is_link($path) && ! static::isReparsePoint($path)) {
            return false;
        }

        // Windows 의 디렉토리 링크·정션은 rmdir 로, 그 밖의 링크는 unlink 로 지운다 — 어느 쪽도 대상을 따라가지 않는다.
        if (! @rmdir($path)) {
            @unlink($path);
        }

        return true;
    }

    /**
     * 트리를 나눠서 훑습니다 (소유권·권한 복원의 커서 판).
     *
     * 이름을 정렬한 깊이 우선 전위 순서로 루트 아래 항목마다 `$visit` 을 부르고, `$shouldPause` 가 참이면 마지막으로
     * 방문한 항목의 상대 경로를 커서로 돌려준다. 다음 호출이 그 커서를 넘기면 이어 간다 — 커서 항목이 디렉토리면 그
     * 하위부터 이어 간다. 링크·정션은 방문하되 따라 들어가지 않는다. `$descend` 가 거짓을 돌려준 디렉토리도 들어가지
     * 않는다(소유권 보존 마커가 있는 서브트리 등). 루트 자신은 방문하지 않는다 — 호출부가 처음 호출에서 따로 처리한다.
     *
     * @param  string  $root  루트 디렉토리
     * @param  string|null  $resumeAfter  직전 호출이 돌려준 커서 (처음이면 null)
     * @param  \Closure|null  $shouldPause  멈출 때인지 묻는 함수 (null 이면 끝까지)
     * @param  \Closure(string, string): void  $visit  항목 절대 경로와 상대 경로를 받는 함수
     * @param  \Closure(string): bool|null  $descend  디렉토리에 들어갈지 정하는 함수 (null 이면 늘 들어간다)
     * @return array{done: bool, cursor: string|null} 끝났는지와 이어 갈 커서
     */
    public static function walkTreeChunk(string $root, ?string $resumeAfter, ?\Closure $shouldPause, \Closure $visit, ?\Closure $descend = null): array
    {
        $state = ['paused' => false, 'cursor' => null];
        $resume = $resumeAfter === null || $resumeAfter === '' ? null : explode('/', $resumeAfter);

        if (is_dir($root) && ! is_link($root)) {
            static::walkTreeChunkLevel($root, '', $resume, $shouldPause, $visit, $descend, $state);
        }

        return ['done' => ! $state['paused'], 'cursor' => $state['paused'] ? $state['cursor'] : null];
    }

    /**
     * `walkTreeChunk()` 의 한 단계를 처리합니다.
     *
     * @param  string  $directory  디렉토리
     * @param  string  $relativePath  루트 기준 상대 경로
     * @param  list<string>|null  $resume  커서를 경로 조각으로 나눈 것
     * @param  \Closure|null  $shouldPause  멈출 때인지 묻는 함수
     * @param  \Closure  $visit  방문 함수
     * @param  \Closure|null  $descend  들어갈지 정하는 함수
     * @param  array{paused: bool, cursor: string|null}  $state  진행 상태 (참조)
     * @return void 반환값 없음
     */
    protected static function walkTreeChunkLevel(string $directory, string $relativePath, ?array $resume, ?\Closure $shouldPause, \Closure $visit, ?\Closure $descend, array &$state): void
    {
        $entries = @scandir($directory);

        if ($entries === false) {
            return;
        }

        $names = array_values(array_filter($entries, static fn (string $e): bool => $e !== '.' && $e !== '..'));
        sort($names, SORT_STRING);
        $depth = $relativePath === '' ? 0 : substr_count($relativePath, '/') + 1;

        foreach ($names as $name) {
            $relative = $relativePath === '' ? $name : $relativePath.'/'.$name;
            $path = $directory.DIRECTORY_SEPARATOR.$name;
            $skipVisit = false;
            $childResume = null;

            if ($resume !== null) {
                $compare = strcmp($name, (string) ($resume[$depth] ?? ''));

                if ($compare < 0) {
                    continue;
                }

                if ($compare === 0) {
                    // 커서가 이 항목(이미 방문)이거나 그 아래다 — 방문은 건너뛰고 하위로 이어 간다.
                    $skipVisit = true;
                    $childResume = count($resume) > $depth + 1 ? $resume : null;
                }

                $resume = null;
            }

            if (! $skipVisit) {
                $visit($path, $relative);
                $state['cursor'] = $relative;

                if ($shouldPause !== null && $shouldPause()) {
                    $state['paused'] = true;

                    return;
                }
            }

            // 정션은 `is_dir()` 이 거짓이라 여기서 걸러진다 — 종전 재귀(`chownRecursiveInternal`)와 같은 판정이고,
            // 항목마다 정션 판정(readlink)을 더하면 수만 항목 트리에서 훑기가 눈에 띄게 느려진다.
            if (! is_link($path) && is_dir($path) && ($descend === null || $descend($path))) {
                static::walkTreeChunkLevel($path, $relative, $childResume, $shouldPause, $visit, $descend, $state);

                if ($state['paused']) {
                    return;
                }
            }
        }
    }

    /**
     * 소유권 복원을 나눠서 합니다 (`chownRecursiveDetailed()` 의 커서 판).
     *
     * 판정은 `chownRecursiveDetailed()` 와 같다 — 소유자가 다르면 chown, 그룹은 따로 chgrp, `.preserve-ownership` 마커가
     * 있는 서브트리는 건너뜀, 링크는 따라가지 않음. 집계는 `$report` 로 이어 받는다.
     *
     * @param  string  $root  대상 루트
     * @param  int  $owner  소유자 UID
     * @param  int|false  $group  그룹 GID
     * @param  bool  $respectPreservationMarker  보존 마커 서브트리를 건너뛸지
     * @param  string|null  $resumeAfter  직전 커서
     * @param  \Closure|null  $shouldPause  멈출 때인지 묻는 함수
     * @param  array<string, mixed>  $report  직전 집계 (처음이면 빈 배열)
     * @return array{done: bool, cursor: string|null, report: array{changed: int, failed: int, failed_paths: list<string>, first_failure: string|null, skipped_subtrees: int}} 진행분
     */
    public static function chownRecursiveChunk(string $root, int $owner, int|false $group, bool $respectPreservationMarker, ?string $resumeAfter, ?\Closure $shouldPause, array $report = []): array
    {
        $report += ['changed' => 0, 'failed' => 0, 'failed_paths' => [], 'first_failure' => null, 'skipped_subtrees' => 0];

        if (! function_exists('chown')) {
            return ['done' => true, 'cursor' => null, 'report' => $report];
        }

        $isMarked = static fn (string $path): bool => $respectPreservationMarker
            && is_dir($path) && ! is_link($path)
            && @file_exists($path.DIRECTORY_SEPARATOR.'.preserve-ownership');

        $apply = static function (string $path) use ($owner, $group, &$report): void {
            $currentOwner = @fileowner($path);
            if ($currentOwner !== false && $currentOwner !== $owner) {
                if (@chown($path, $owner)) {
                    $report['changed']++;
                } else {
                    if ($report['first_failure'] === null) {
                        $report['first_failure'] = $path;
                        Log::warning('chown 최초 실패', ['path' => $path, 'owner' => $owner]);
                    }
                    $report['failed']++;
                    if (count($report['failed_paths']) < 50) {
                        $report['failed_paths'][] = $path;
                    }
                }
            }

            if ($group !== false && function_exists('chgrp')) {
                $currentGroup = @filegroup($path);
                if ($currentGroup !== false && $currentGroup !== $group) {
                    @chgrp($path, $group);
                }
            }
        };

        if ($resumeAfter === null) {
            if ($isMarked($root)) {
                $report['skipped_subtrees']++;

                return ['done' => true, 'cursor' => null, 'report' => $report];
            }

            $apply($root);
        }

        $walk = static::walkTreeChunk(
            $root,
            $resumeAfter,
            $shouldPause,
            static function (string $path) use ($isMarked, $apply, &$report): void {
                if ($isMarked($path)) {
                    $report['skipped_subtrees']++;

                    return;
                }

                $apply($path);
            },
            static fn (string $path): bool => ! $isMarked($path),
        );

        return ['done' => $walk['done'], 'cursor' => $walk['cursor'], 'report' => $report];
    }

    /**
     * 그룹 쓰기 권한 정상화를 나눠서 합니다 (`syncGroupWritabilityDetailed()` 의 커서 판).
     *
     * 판정은 `syncGroupWritabilityDetailed()` 와 같다 — 루트가 g+w 일 때만(강제면 루트를 먼저 g+w 로) 하위 g-w 항목에
     * g+w 를 더하고, 링크는 따라가지 않는다.
     *
     * @param  string  $root  대상 루트
     * @param  bool  $force  루트가 g-w 여도 정상화할지
     * @param  string|null  $resumeAfter  직전 커서
     * @param  \Closure|null  $shouldPause  멈출 때인지 묻는 함수
     * @param  array<string, mixed>  $report  직전 집계 (처음이면 빈 배열)
     * @return array{done: bool, cursor: string|null, report: array{changed: int, failed: int, failed_paths: list<string>}} 진행분
     */
    public static function syncGroupWritabilityChunk(string $root, bool $force, ?string $resumeAfter, ?\Closure $shouldPause, array $report = []): array
    {
        $report += ['changed' => 0, 'failed' => 0, 'failed_paths' => []];

        if (! function_exists('chmod') || ! is_dir($root)) {
            return ['done' => true, 'cursor' => null, 'report' => $report];
        }

        if ($resumeAfter === null) {
            $rootPerms = @fileperms($root);

            if ($rootPerms === false) {
                return ['done' => true, 'cursor' => null, 'report' => $report];
            }

            if (($rootPerms & 0020) === 0) {
                if (! $force) {
                    return ['done' => true, 'cursor' => null, 'report' => $report];
                }

                if (! @chmod($root, $rootPerms | 0020)) {
                    $report['failed']++;
                    $report['failed_paths'][] = $root;

                    return ['done' => true, 'cursor' => null, 'report' => $report];
                }
            }
        }

        $walk = static::walkTreeChunk(
            $root,
            $resumeAfter,
            $shouldPause,
            static function (string $path) use (&$report): void {
                if (is_link($path)) {
                    return;
                }

                $perms = @fileperms($path);
                if ($perms !== false && ($perms & 0020) === 0) {
                    if (@chmod($path, $perms | 0020)) {
                        $report['changed']++;
                    } else {
                        $report['failed']++;
                        if (count($report['failed_paths']) < 50) {
                            $report['failed_paths'][] = $path;
                        }
                    }
                }
            },
        );

        return ['done' => $walk['done'], 'cursor' => $walk['cursor'], 'report' => $report];
    }

    /**
     * 부모 → 자식 디렉토리 체인의 그룹 쓰기를 맞춥니다 (재귀하지 않는다).
     *
     * 이웃한 두 단계마다, 부모가 g+w 이고 자식이 g-w 면 자식에 g+w 를 더한다 — `syncGroupWritability()` 와 같은
     * 정책(부모가 g-w 면 운영자 정책으로 보고 손대지 않음, 다른 비트 무변경)이되 체인에 든 디렉토리만 본다. 새 폴더를
     * 만들 자리의 부모 체인만 필요할 때 쓴다 — 그 아래 이미 있는 트리(옛 백업)를 훑지 않는다.
     *
     * @param  string  ...$directories  부모부터 자식 순서의 디렉토리
     * @return int chmod 한 디렉토리 수
     */
    public static function promoteGroupWritableChain(string ...$directories): int
    {
        if (! function_exists('chmod')) {
            return 0;
        }

        $changed = 0;
        $count = count($directories);

        for ($i = 1; $i < $count; $i++) {
            $parent = $directories[$i - 1];
            $child = $directories[$i];

            if (! is_dir($parent) || ! is_dir($child) || is_link($child)) {
                continue;
            }

            $parentPerms = @fileperms($parent);
            $childPerms = @fileperms($child);

            if ($parentPerms === false || $childPerms === false || ($parentPerms & 0020) === 0 || ($childPerms & 0020) !== 0) {
                continue;
            }

            if (@chmod($child, $childPerms | 0020)) {
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * 운영 디렉토리를 다른 디렉토리로 통째로 바꿔 끼웁니다 (vendor 반영·되돌리기).
     *
     * 파일을 하나씩 복사하면 수만 개짜리 vendor 가 한 요청 시간 안에 끝나지 않고, 복사가 중간에 끊긴
     * vendor 로는 다음 요청이 부팅조차 못 한다. 이름 바꾸기 두 번은 같은 파일 시스템 안에서 즉시 끝나고,
     * 끝난 뒤의 운영 디렉토리는 언제나 한쪽 판 전체다.
     *
     * 바꿔 끼울 수 없으면(대상이 없음, 자리가 이미 있음, 이름 바꾸기 실패 — 다른 파일 시스템·쓰기 권한·
     * Windows 의 사용 중 파일) 운영 디렉토리를 원래대로 둔 채 false 를 돌려준다. 호출부는 복사로 넘어간다.
     *
     * @param  string  $live  운영 디렉토리 (예: `vendor`)
     * @param  string  $replacement  끼울 디렉토리 (성공하면 사라진다)
     * @param  string  $parking  옛 운영 디렉토리를 옮겨 둘 자리 (없는 경로여야 한다)
     * @return bool 바꿔 끼웠으면 true
     */
    public static function swapDirectory(string $live, string $replacement, string $parking): bool
    {
        static::$lastSwapFailure = null;

        if (! is_dir($replacement) || is_link($replacement) || static::isReparsePoint($replacement)) {
            return false;
        }

        if (file_exists($parking) || is_link($parking)) {
            return false;
        }

        File::ensureDirectoryExists(dirname($parking));

        $parked = false;

        if (is_dir($live)) {
            if (! static::renamePath($live, $parking)) {
                return false;
            }

            $parked = true;
        }

        if (! static::renamePath($replacement, $live)) {
            if ($parked && ! file_exists($live) && ! static::renamePath($parking, $live)) {
                // 운영 디렉토리를 옮긴 뒤 새 것을 끼우지도, 옛 것을 되돌리지도 못했다 — 운영 자리가 비었다.
                static::recordSwapFailure($live, $replacement, $parking);
            }

            return false;
        }

        return true;
    }

    /**
     * 직전 `swapDirectory()` 가 운영 디렉토리를 비운 채 실패했으면 그 기록을 꺼내고 비웁니다.
     *
     * 기록이 있으면 운영 자리(`live`)가 비어 있을 수 있다. 호출부는 이 기록을 실행 기록·실패 리포트에 싣고, 옮겨 둔
     * 자리(`parked`)를 되돌리기 원본으로 쓴다 — 운영자가 수동 복구를 판단할 근거가 된다.
     *
     * @return array{live: string, replacement: string, parked: string, live_exists: bool, replacement_exists: bool, parked_exists: bool}|null 기록 (없으면 null)
     */
    public static function takeLastSwapFailure(): ?array
    {
        $failure = static::$lastSwapFailure;
        static::$lastSwapFailure = null;

        return $failure;
    }

    /**
     * 바꿔 끼우기 실패 기록을 남깁니다 (시험이 실패 갈래를 만들 때도 쓴다).
     *
     * @param  string  $live  운영 디렉토리
     * @param  string  $replacement  끼우려던 디렉토리
     * @param  string  $parking  옛 운영 디렉토리를 옮겨 둔 자리
     * @return void 반환값 없음
     */
    public static function recordSwapFailureForTests(string $live, string $replacement, string $parking): void
    {
        static::recordSwapFailure($live, $replacement, $parking);
    }

    /**
     * 이름 바꾸기 함수를 바꿔 끼웁니다 (시험 전용 — 사용 중 파일 같은 실패를 흉내 낸다).
     *
     * @param  \Closure(string, string): bool|null  $renamer  대체 함수 (null 이면 `rename`)
     * @return void 반환값 없음
     */
    public static function useRenamerForTests(?\Closure $renamer): void
    {
        static::$renamer = $renamer;
    }

    /**
     * 바꿔 끼우기 실패를 기록하고 오류 로그에 남깁니다.
     *
     * @param  string  $live  운영 디렉토리
     * @param  string  $replacement  끼우려던 디렉토리
     * @param  string  $parking  옛 운영 디렉토리를 옮겨 둔 자리
     * @return void 반환값 없음
     */
    protected static function recordSwapFailure(string $live, string $replacement, string $parking): void
    {
        static::$lastSwapFailure = [
            'live' => $live,
            'replacement' => $replacement,
            'parked' => $parking,
            'live_exists' => is_dir($live),
            'replacement_exists' => is_dir($replacement),
            'parked_exists' => is_dir($parking),
        ];

        Log::error('디렉토리 바꿔 끼우기 실패 — 원래대로 되돌리지도 못해 운영 자리가 비었을 수 있다', static::$lastSwapFailure);
    }

    /**
     * 이름을 바꿉니다 (시험이 실패를 흉내 낼 수 있게 한 곳으로 모은다).
     *
     * @param  string  $from  원래 경로
     * @param  string  $to  새 경로
     * @return bool 바꿨으면 true
     */
    protected static function renamePath(string $from, string $to): bool
    {
        return static::$renamer !== null ? (bool) (static::$renamer)($from, $to) : @rename($from, $to);
    }

    /**
     * 상대경로가 skipPaths 중 하나와 같거나 그 하위인지 판정합니다.
     *
     * @param  string  $itemRelativePath  이 복사 루트 기준 상대경로 (슬래시 구분)
     * @param  array<int, string>  $skipPaths  건너뛸 상대경로 목록
     * @return bool 건너뛸 경로면 true
     */
    protected static function isSkippedPath(string $itemRelativePath, array $skipPaths): bool
    {
        foreach ($skipPaths as $skip) {
            $skip = trim(str_replace('\\', '/', (string) $skip), '/');
            if ($skip === '') {
                continue;
            }
            if ($itemRelativePath === $skip || str_starts_with($itemRelativePath, $skip.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * 경로가 Windows JUNCTION 등 PHP `isLink()` 가 인식하지 못하는 reparse point 인지 판정합니다.
     *
     * Windows JUNCTION(`mklink /J`)은 디렉토리 reparse point 로, `is_link()` / `is_file()` /
     * `is_dir()` 이 **모두** false 를 반환한다(그러나 경로 항목으로는 존재하며 `readlink()` 로
     * target 을 얻을 수 있다). `php artisan storage:link` 가 Windows 에서 생성하는
     * `public/storage` 가 이 형태다.
     *
     * 주의: Windows 의 `readlink()` 는 일반 파일/디렉토리에도 자기 경로를 반환하므로 junction
     * 판별 기준이 될 수 없다. 따라서 "존재하는 경로인데 link/file/dir 어디에도 해당하지 않음"
     * 을 판정 기준으로 삼는다. 여기에 추가로 `readlink()` 성공(!== false)을 요구해 존재하지
     * 않는 경로를 배제한다.
     *
     * 일반 symlink(`is_link()` true)는 호출부가 별도로 처리하므로 여기서는 false 를 반환한다.
     * junction 은 Windows 전용이므로 비-Windows 에서는 항상 false.
     *
     * @param  string  $path  검사할 경로
     * @return bool junction 등 isLink() 미인식 reparse point 여부
     */
    protected static function isReparsePoint(string $path): bool
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return false;
        }

        // 일반 symlink / 파일 / 디렉토리는 각 검사가 true → junction 아님.
        if (is_link($path) || is_file($path) || is_dir($path)) {
            return false;
        }

        // 위 셋에 모두 해당하지 않으면서 readlink 가 target 을 반환하면 junction(reparse point).
        return @readlink($path) !== false;
    }

    /**
     * symlink 또는 Windows junction 자체를 보존 복사합니다 (target 미추적).
     *
     * 기존 dest 가 symlink 또는 파일이면 unlink, 디렉토리면 deleteDirectory 후 링크 재생성.
     * readlink 실패 시 false 반환 — 호출자가 일반 복사로 fall-through.
     *
     * 재생성 전략:
     * - 일반 symlink: `symlink()`
     * - target 이 디렉토리인 경우(Windows junction 포함): `symlink()` 실패 시 `mklink /J` 로 폴백.
     *   Windows 에서 PHP `symlink()` 는 `SeCreateSymbolicLink` 권한이 필요하지만 junction 은
     *   권한 없이 생성 가능하므로, junction 복원의 실질 경로가 된다.
     *
     * 모든 재생성 시도 실패 시 warning 로그 후 false 반환. 운영자는 업그레이드 후
     * `php artisan storage:link` 등 수동 명령으로 링크를 회복할 수 있다.
     *
     * @param  string  $source  소스 링크(symlink/junction) 경로
     * @param  string  $destination  대상 링크 경로
     * @return bool 링크 복원 성공 여부 (false 면 호출자가 일반 복사로 폴백)
     */
    protected static function copyLink(string $source, string $destination): bool
    {
        $target = @readlink($source);
        if ($target === false) {
            Log::warning('copyLink: readlink 실패 — 일반 복사로 폴백', ['source' => $source]);

            return false;
        }

        // 기존 dest 정리 — symlink/파일 은 unlink, 디렉토리는 deleteDirectory
        if (is_link($destination) || is_file($destination)) {
            @unlink($destination);
        } elseif (is_dir($destination)) {
            File::deleteDirectory($destination);
        }

        // `symlink` 가드 사유는 형제(`StorageLinkHelper::createLink`)와 같다 — `@` 는
        // 진단 억제일 뿐이고 차단 환경에서는 이 호출이 `Error`(치명)다. 불가하면 아래
        // junction 폴백을 거쳐 호출자의 일반 복사 폴백으로 넘어간다.
        if (ExecutionEnvironment::hasFunction('symlink') && @symlink($target, $destination)) {
            return true;
        }

        // symlink 실패 + target 이 디렉토리 → Windows junction 으로 폴백 재생성.
        // `exec` 차단 호스팅에서는 무가드 호출이 PHP 8 Error(치명)가 되므로 SSoT 판정을
        // 거치고, 불가하면 일반 복사 폴백(호출자)으로 넘긴다 (G3 이관 — hosting-blocked-function 가드).
        if (PHP_OS_FAMILY === 'Windows' && is_dir($target) && ExecutionEnvironment::hasFunction('exec')) {
            exec(
                'cmd /c mklink /J '.escapeshellarg($destination).' '.escapeshellarg($target).' 2>&1',
                $out,
                $code
            );
            if ($code === 0 && is_dir($destination)) {
                return true;
            }
        }

        Log::warning('copyLink: 링크 생성 실패 — 일반 복사로 폴백', [
            'source' => $source,
            'destination' => $destination,
            'target' => $target,
        ]);

        return false;
    }

    /**
     * 소스에 없고 대상에만 있는 파일/디렉토리를 삭제합니다.
     *
     * excludes 목록에 해당하는 항목은 삭제하지 않습니다.
     *
     * @param  string  $source  소스 디렉토리 경로
     * @param  string  $destination  대상 디렉토리 경로
     * @param  array  $excludes  제외할 이름 또는 경로 목록
     * @param  string  $relativePath  현재 상대 경로
     * @param  bool  $preserveTopLevelOrphans  최상위 한 레벨의 orphan 보존 여부
     * @param  array<int, string>  $preserveLinkPaths  삭제하지 않고 보존할 경로 (링크 여부 무관)
     *                                                 상대경로 화이트리스트(루트 기준). orphan 이 링크이면서 이 목록에 매칭되면 삭제하지
     *                                                 않고 보존한다 — `public/storage` symlink 보호(#43). 화이트리스트 밖 orphan 링크는 삭제.
     * @param  array<int, string>  $skipPaths  orphan 정리에서도 건너뛸 상대경로(루트 기준) — 복사를 건너뛴 경로는 지우지도 않는다
     * @return void 반환값 없음
     */
    protected static function removeOrphanItems(string $source, string $destination, array $excludes, string $relativePath, bool $preserveTopLevelOrphans = false, array $preserveLinkPaths = [], array $skipPaths = []): void
    {
        // 최상위 한 레벨에서 소스에 없는 항목(사용자 추가) 보존 — `_bundled/my-project` 등.
        // 호출자(copyDirectory)는 최상위 진입 시에만 relativePath='' + 플래그 true 로 들어오며,
        // 자식 재귀에는 항상 false 가 전달되므로 본 분기는 최상위에서만 활성화된다.
        if ($preserveTopLevelOrphans && $relativePath === '') {
            return;
        }

        $destItems = new \FilesystemIterator($destination, \FilesystemIterator::SKIP_DOTS);

        foreach ($destItems as $destItem) {
            $itemName = $destItem->getFilename();
            $itemRelativePath = $relativePath === '' ? $itemName : $relativePath.'/'.$itemName;

            // excludes 대상은 삭제하지 않음
            if (static::isExcluded($itemName, $itemRelativePath, $excludes)) {
                continue;
            }
            if ($skipPaths !== [] && static::isSkippedPath($itemRelativePath, $skipPaths)) {
                continue;
            }

            $srcPath = $source.DIRECTORY_SEPARATOR.$itemName;

            // 소스에 존재하지 않는 항목만 삭제
            if (! File::exists($srcPath) && ! File::isDirectory($srcPath)) {
                // 보호 화이트리스트 매칭 링크/junction 은 orphan 이어도 보존 — 코어 업데이트
                // prune 모드에서 `public/storage` symlink 가 릴리즈 소스에 없다는 이유로 삭제되어
                // 업로드 파일이 404 되던 결함(#43) 차단. 화이트리스트 밖 orphan 링크는 아래에서 삭제.
                // 보호는 **경로 기준**이다 — 링크일 때만 걸면 그 경로가 링크가 **아닌**
                // 형태로 존재하는 사이트에서 보호가 통째로 빠진다. `public/storage` 가
                // 일반 디렉토리인 상태(심볼릭 링크를 만들 수 없어 복사로 운영하거나,
                // 7.1.0 정리 대상인 바로 그 상태)에서 종전 판정은 아래 `deleteDirectory()`
                // 로 떨어져 **공개 업로드 전량이 영구 삭제**됐다. 그 경로는 릴리즈 소스에
                // 없는 것이 정상이므로 orphan 판정은 언제나 참이다.
                //
                // `storage.broken.*` 도 같은 이유로 보존한다 — 7.1.0 마이그레이션이
                // 「옮기기만 하고 지우지 않는다, 그 사본이 운영자에게 유일하게 남은
                // 파일일 수 있다」를 불변식으로 선언했는데, 그 스텝은 이 정리보다
                // **뒤에** 돌아 아무것도 보지 못한다.
                if (
                    in_array($itemRelativePath, $preserveLinkPaths, true)
                    || static::isPreservedRuntimeResidue($itemRelativePath, $preserveLinkPaths)
                ) {
                    Log::info('removeOrphanItems: 보호 대상 orphan 보존', [
                        'path' => $destItem->getPathname(),
                        'relative' => $itemRelativePath,
                    ]);

                    continue;
                }

                // symlink / Windows junction 은 링크 자체만 제거 — File::deleteDirectory 는 is_dir()
                // 추적 검사 후 재귀 삭제하므로 link-to-dir 인 경우 target 의 모든 파일을 삭제하는
                // 사고 발생 가능. 링크 검사가 isDir() 보다 먼저 평가되어야 한다.
                //
                // junction 은 is_link() 가 false 지만 isReparsePoint() 가 감지한다. junction 링크
                // 제거는 파일 대상 unlink 가 아닌 rmdir() 로 수행해야 target 내용을 보존한다.
                if (is_link($destItem->getPathname())) {
                    @unlink($destItem->getPathname());
                } elseif (static::isReparsePoint($destItem->getPathname())) {
                    // junction: rmdir 로 링크만 제거 (target 미추적). 폴백으로 unlink 시도.
                    if (! @rmdir($destItem->getPathname())) {
                        @unlink($destItem->getPathname());
                    }
                } elseif ($destItem->isDir()) {
                    File::deleteDirectory($destItem->getPathname());
                } else {
                    File::delete($destItem->getPathname());
                }
            }
        }
    }

    /**
     * 항목이 제외 대상인지 확인합니다.
     *
     * - 단순 이름 (슬래시 미포함): 모든 레벨에서 해당 이름과 매칭
     * - 경로 패턴 (슬래시 포함): 상대 경로와 정확히 매칭
     *
     * @param  string  $itemName  현재 항목의 파일/디렉토리 이름
     * @param  string  $itemRelativePath  루트로부터의 상대 경로
     * @param  array  $excludes  제외 목록
     * @return bool 제외 대상 여부
     */
    public static function isExcluded(string $itemName, string $itemRelativePath, array $excludes): bool
    {
        foreach ($excludes as $exclude) {
            if (str_contains($exclude, '/')) {
                // 경로 패턴: 상대 경로와 정확히 매칭
                if ($itemRelativePath === $exclude) {
                    return true;
                }
            } else {
                // 단순 이름: 모든 레벨에서 매칭
                if ($itemName === $exclude) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 퍼미션과 소유권을 보존하면서 파일을 복사합니다.
     *
     * - 기존 파일: 복사 후 원래 퍼미션/소유자/그룹 복원
     * - 신규 파일: 부모 디렉토리의 소유자/그룹 상속 (퍼미션은 PHP 기본 umask)
     *
     * 신규 파일에 부모 소유권을 상속시키는 이유는 sudo 로 실행된 업데이트가 root 소유로
     * 파일을 생성하는 문제를 방지하기 위함이다. vendor/ 내부처럼 cleanDirectory 후
     * 전량 재생성되는 경로에서 필요하다.
     *
     * @param  string  $source  소스 파일
     * @param  string  $destination  대상 파일
     * @return void 반환값 없음
     */
    public static function copyFile(string $source, string $destination): void
    {
        $isExisting = File::exists($destination);
        $existingPerms = null;
        $existingOwner = null;
        $existingGroup = null;

        if ($isExisting) {
            $existingPerms = fileperms($destination);
            $existingOwner = fileowner($destination);
            $existingGroup = filegroup($destination);
        }

        File::ensureDirectoryExists(dirname($destination));
        File::copy($source, $destination);

        if ($isExisting) {
            // 기존 파일: 원래 퍼미션/소유권 복원
            if ($existingPerms !== null) {
                @chmod($destination, $existingPerms);
            }
            if ($existingOwner !== null && function_exists('chown')) {
                @chown($destination, $existingOwner);
            }
            if ($existingGroup !== null && function_exists('chgrp')) {
                @chgrp($destination, $existingGroup);
            }
        } else {
            // 신규 파일: 부모 디렉토리의 소유자/그룹 상속
            static::inheritOwnershipFromParent($destination);
        }
    }

    /**
     * 보호 경로가 남긴 **런타임 잔해**인지 판정합니다.
     *
     * `public/storage` 를 보호 목록에 넣은 배치에서는 그 경로의 손상 백업
     * (`storage.broken.<타임스탬프>`)도 함께 보호해야 한다. 그 사본은 코어가 만든
     * 것이고, 7.1.0 업그레이드 스텝이 문서 루트 밖으로 옮길 때까지 **운영자에게 남은
     * 유일한 파일일 수 있다.** 그 스텝은 이 정리보다 뒤에 돌므로 여기서 지우면
     * 스텝이 옮길 대상 자체가 사라진다.
     *
     * @param  string  $itemRelativePath  대상의 상대 경로
     * @param  array<int, string>  $preservePaths  보호 경로 목록
     * @return bool 보호 대상이면 true
     */
    protected static function isPreservedRuntimeResidue(string $itemRelativePath, array $preservePaths): bool
    {
        foreach ($preservePaths as $preserved) {
            if (str_starts_with($itemRelativePath, $preserved.'.broken.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * 부모 디렉토리의 퍼미션·소유자·그룹을 상속하여 신규 디렉토리를 생성합니다.
     *
     * @param  string  $path  생성할 디렉토리 경로
     * @return void 반환값 없음
     */
    protected static function createDirectoryInheritingParent(string $path): void
    {
        $parentDir = dirname($path);
        $parentExists = File::isDirectory($parentDir);
        $parentPerms = $parentExists ? (fileperms($parentDir) & 0777) : 0755;

        File::ensureDirectoryExists($path, $parentPerms, true);

        if ($parentExists) {
            static::applyOwnership($path, fileowner($parentDir), filegroup($parentDir));
        }
    }

    /**
     * 부모 디렉토리의 소유자·그룹을 대상 경로에 상속합니다.
     *
     * sudo 컨텍스트에서 root 가 만든 파일을 부모(보통 PHP-FPM owner) 로 정합화하기 위해
     * 외부 호출처(예: `SettingsMigrator::writeJsonFile`) 가 직접 호출 가능하도록 public.
     *
     * @param  string  $path  소유권을 상속받을 파일 또는 디렉토리
     * @return void 반환값 없음
     */
    public static function inheritOwnershipFromParent(string $path): void
    {
        $parentDir = dirname($path);
        if (! File::isDirectory($parentDir)) {
            return;
        }

        static::applyOwnership($path, fileowner($parentDir), filegroup($parentDir));
    }

    /**
     * 이미 존재하는 디렉토리의 퍼미션·소유권을 umask 와 무관하게 정합화합니다.
     *
     * `File::ensureDirectoryExists($dir, 0775)` 등 생성 API 의 mode 인자는 **umask 로 깎인다** —
     * umask 022 환경에서는 0775 요청이 0755 로 만들어져 그룹 공유(웹 계정)가 쓸 수 없다.
     * 명시 `chmod` 로 umask 를 무력화하고, POSIX 에서는 setgid 를 세워 그 아래에 만들어지는
     * 하위 디렉토리가 그룹을 상속하게 한다. setgid 가 없으면 CLI(스케줄러/큐)가 먼저 만든
     * 하위 디렉토리를 웹 프로세스가 쓰지 못한다. Windows 에는 setgid 개념이 없어 제외한다.
     *
     * 마지막으로 부모 소유권을 상속시켜 sudo/CLI 계정 고정을 막는다.
     *
     * @param  string  $path  대상 디렉토리 절대 경로
     * @param  int  $mode  적용할 퍼미션 (예: 0775)
     * @return void 반환값 없음
     */
    public static function hardenDirectory(string $path, int $mode = 0775): void
    {
        @chmod($path, $mode);

        if (PHP_OS_FAMILY !== 'Windows') {
            @chmod($path, $mode | 02000);
        }

        static::inheritOwnershipFromParent($path);
    }

    /**
     * 쓰기 가능한 디렉토리를 확보합니다 — 없으면 만들고, 권한을 정합화한 뒤 실제 쓰기 가능 여부를 판정합니다.
     *
     * 제3자 라이브러리에 넘길 캐시·임시 디렉토리처럼 "확보하지 못하면 그 기능만 끄면 되는"
     * 자리를 위한 프리미티브다. **예외도 PHP 경고도 내지 않는다** — `File::ensureDirectoryExists()`
     * 는 `mkdir()` 을 억제 없이 호출하므로 생성 실패가 `E_WARNING` 으로 나오고 Laravel
     * `HandleExceptions` 가 이를 `ErrorException` 으로 승격시켜 요청이 500 이 된다. 쓰기 경로를
     * 지정하는 목적 자체가 그 500 을 막는 것이므로, 확보 실패가 다시 500 을 내면 무의미하다.
     *
     * 실패 정책은 호출부가 정한다 — 조용히 성능 저하로 이어갈지(정의 캐시), 시끄럽게 실패로
     * 처리할지(정적 게시)가 자리마다 다르기 때문이다. 사유는 `$failure` out 파라미터로 올린다.
     *
     * @param  string  $path  확보할 디렉토리 절대 경로
     * @param  int  $mode  생성 시 적용할 퍼미션
     * @param  array{reason: string, path: string}|null  $failure  out — 실패 사유와 그 대상 경로.
     *                                                             reason 은 `occupied_by_file`(경로가 파일로 점유) /
     *                                                             `ancestor_not_writable`(실재하는 최근접 상위가 쓰기 불가) /
     *                                                             `create_failed`(생성 실패) / `not_writable`(존재하나 쓰기 불가)
     * @return bool 확보 성공 여부
     */
    public static function ensureWritableDirectory(string $path, int $mode = 0775, ?array &$failure = null): bool
    {
        $failure = null;

        if (! is_dir($path)) {
            // 같은 이름의 파일이 자리를 차지하면 mkdir 이 경고를 낸다 — 먼저 걸러낸다.
            if (file_exists($path)) {
                $failure = ['reason' => 'occupied_by_file', 'path' => $path];

                return false;
            }

            // 상위가 쓰기 불가라면 생성 자체가 불가능하다. 여기서 끊어야 호출부가 "무엇을
            // 고쳐야 하는지"(대상 디렉토리가 아니라 그 상위)를 운영자에게 지목할 수 있다.
            $ancestor = static::nearestExistingAncestor($path);

            if ($ancestor === null || ! is_writable($ancestor)) {
                $failure = ['reason' => 'ancestor_not_writable', 'path' => $ancestor ?? dirname($path)];

                return false;
            }

            // force 인자로 경고를 억제한다. 실패는 아래 판정이 흡수한다.
            File::makeDirectory($path, $mode, true, true);

            if (is_dir($path)) {
                static::hardenDirectory($path, $mode);
            }
        }

        // 방금 만든 경로의 stat 은 캐시돼 있을 수 있다 — 판정 전에 비운다.
        clearstatcache(true, $path);

        if (! is_dir($path)) {
            $failure = ['reason' => 'create_failed', 'path' => $path];

            return false;
        }

        if (! is_writable($path)) {
            $failure = ['reason' => 'not_writable', 'path' => $path];

            return false;
        }

        return true;
    }

    /**
     * 경로에서 위로 올라가며 실재하는 첫 디렉토리를 찾습니다.
     *
     * @param  string  $path  기준 경로
     * @return string|null 실재하는 최근접 상위 (루트까지 없으면 null)
     */
    public static function nearestExistingAncestor(string $path): ?string
    {
        $current = dirname($path);

        while (! File::isDirectory($current)) {
            $parent = dirname($current);

            if ($parent === $current) {
                return null;
            }

            $current = $parent;
        }

        return $current;
    }

    /**
     * 소유자·그룹을 적용합니다. sudo 없이 실행 시 silent fail 로 현행 동작 유지.
     *
     * @param  string  $path  대상 경로
     * @param  int|false  $owner  fileowner() 반환값 (false 허용)
     * @param  int|false  $group  filegroup() 반환값 (false 허용)
     * @return void 반환값 없음
     */
    protected static function applyOwnership(string $path, int|false $owner, int|false $group): void
    {
        if ($owner !== false && function_exists('chown')) {
            @chown($path, $owner);
        }
        if ($group !== false && function_exists('chgrp')) {
            @chgrp($path, $group);
        }
    }

    /**
     * 웹서버(www-data 등) 계정의 소유자를 추정합니다.
     *
     * Laravel 표준상 웹서버가 쓰기 접근해야 하는 디렉토리(`storage/*`, `bootstrap/cache`)
     * 를 순회하여 base_path() 소유자와 **다른** 첫 소유자를 "웹서버 계정" 으로 판정한다.
     * 모든 후보가 base_path() 와 동일하면 대칭 구성으로 보고 base_path() 소유자를 반환.
     *
     * 사용 예:
     * - sudo 실행된 업데이트가 원본 스냅샷을 수집하지 못한 경우의 fallback
     * - 외부 프로세스(composer 등) 가 root 로 오염시킨 경로의 원본 추정
     *
     * @return array{0: int|false, 1: int|false, 2: string} [owner, group, source]
     */
    public static function inferWebServerOwnership(): array
    {
        $baseOwner = @fileowner(base_path());
        $baseGroup = @filegroup(base_path());

        if ($baseOwner === false) {
            return [false, false, 'none'];
        }

        $candidates = [
            'storage/logs',
            'storage/framework/views',
            'storage/framework/cache',
            'storage/app',
            'storage',
            'bootstrap/cache',
        ];

        foreach ($candidates as $candidate) {
            $path = base_path($candidate);
            if (! File::isDirectory($path)) {
                continue;
            }

            $owner = @fileowner($path);
            if ($owner !== false && $owner !== $baseOwner) {
                return [$owner, @filegroup($path), $candidate];
            }
        }

        return [$baseOwner, $baseGroup, 'base_path (대칭 구성)'];
    }

    /** 테스트 전용 — `describeWebServerAccount()` 판정 오버라이드 (null = 실판정) */
    private static ?array $webServerAccountForTesting = null;

    /**
     * 테스트 전용 — 실행 환경 판정을 강제합니다 (posix 부재 환경에서 root 분기를 재현하기 위해).
     *
     * @param  array{mode: string, name: string|null}|null  $account  강제할 판정 (null 로 실판정 복귀)
     * @return void 반환값 없음
     */
    public static function fakeWebServerAccountForTesting(?array $account): void
    {
        self::$webServerAccountForTesting = $account;
    }

    /**
     * 현재 프로세스 기준으로 "웹서버 계정" 안내에 필요한 실행 환경을 분류합니다.
     *
     * root(sudo) 로 artisan 을 실행하면 그 명령이 만드는 캐시 샤드·번들·임시 파일이 root 소유로 남아
     * 이후 웹 프로세스의 쓰기가 실패한다. 그래서 root 실행을 감지한 명령은 "웹서버 계정으로 실행"
     * 을 안내해야 하는데, 그 판정을 명령마다 다시 쓰면 한 곳만 어긋나도 서로 다른 안내가 나간다.
     * `core:update` 의 재실행 안내가 처음 세운 4분기를 그대로 옮겨 공유한다 — 동작 불변.
     *
     * 반환 모드:
     *  - `non_root`           : posix 미지원(Windows 등) 또는 현재 유효 사용자가 root 가 아님
     *                           (일반 SSH 사용자 = 파일 소유자, 공유 호스팅 대칭 구성 포함).
     *  - `root_web_known`     : root 실행 + 웹서버 계정 식별 성공 + 실행 사용자(root)와 다름.
     *  - `root_web_symmetric` : root 실행 + 웹서버 계정이 root 로 추정됨 (root 서비스 구성).
     *  - `root_web_unknown`   : root 실행 + 웹서버 계정 추정 실패 (스냅샷/추정 불가).
     *
     * 웹서버 계정은 `inferWebServerOwnership()` 이 storage/bootstrap 쓰기 영역 소유자로 추정한다.
     *
     * @return array{mode: string, name: string|null} 모드와 웹서버 계정명(`root_web_known` 일 때만)
     */
    public static function describeWebServerAccount(): array
    {
        if (self::$webServerAccountForTesting !== null) {
            return self::$webServerAccountForTesting;
        }

        if (! function_exists('posix_geteuid') || ! function_exists('posix_getpwuid')) {
            return ['mode' => 'non_root', 'name' => null];
        }

        if (posix_geteuid() !== 0) {
            return ['mode' => 'non_root', 'name' => null];
        }

        [$owner] = static::inferWebServerOwnership();

        if ($owner === false) {
            return ['mode' => 'root_web_unknown', 'name' => null];
        }

        if ($owner === 0) {
            return ['mode' => 'root_web_symmetric', 'name' => null];
        }

        $entry = posix_getpwuid($owner);
        $name = $entry['name'] ?? null;

        // uid 는 나왔지만 이름 해석 실패 — 미상 경로로 처리 (uid 노출은 오히려 혼란).
        if ($name === null) {
            return ['mode' => 'root_web_unknown', 'name' => null];
        }

        return ['mode' => 'root_web_known', 'name' => $name];
    }

    /**
     * 경로와 그 하위 항목의 소유자·그룹을 재귀적으로 복원합니다.
     *
     * 현재 소유자가 기준과 이미 일치하면 해당 항목은 스킵. symbolic link 는 링크 자체만
     * 처리하고 대상은 따라가지 않는다. @chown/@chgrp suppress 로 권한 부족 / chown 미지원
     * 환경에서도 silent fail.
     *
     * @param  string  $path  대상 경로 (파일 또는 디렉토리)
     * @param  int  $owner  기준 소유자 UID
     * @param  int|false  $group  기준 그룹 GID (false = 그룹 유지)
     * @return int 실제 소유권을 변경한 항목 수
     */
    public static function chownRecursive(string $path, int $owner, int|false $group): int
    {
        return self::chownRecursiveDetailed($path, $owner, $group)['changed'];
    }

    /**
     * `chownRecursive` 의 상세 결과 변형. 실패 경로를 누적하여 반환한다.
     *
     * 코어/확장 업데이트 흐름이 운영자에게 권한 정상화 실패 경로를 노출할 수 있도록
     * 누적 결과를 구조화 반환한다. 실패 경로 수가 많을 때 로그 폭주를 막기 위해
     * `failed_paths` 는 최대 50개로 잘라낸다 (전체 카운트는 `failed` 에 보존).
     *
     * `$respectPreservationMarker = true` 일 때 (트랙 2-A): 트리 순회 중 디렉토리에
     * `.preserve-ownership` 파일이 발견되면 해당 서브트리 전체를 chown 비대상으로 skip.
     * `ModuleStorageDriver` / `PluginStorageDriver` 가 자동 작성하는 마커로 사용자 데이터
     * (storage/app/{modules,plugins}/{id}/) 의 시드 시점 owner/perms 영구 보존.
     *
     * @param  string  $path  대상 경로
     * @param  int  $owner  기준 소유자 UID
     * @param  int|false  $group  기준 그룹 GID (false = 그룹 유지)
     * @param  bool  $respectPreservationMarker  `.preserve-ownership` 마커가 있는 서브트리 skip 여부
     * @return array{changed:int, failed:int, failed_paths:array<int,string>, supported:bool, skipped_subtrees:int}
     */
    public static function chownRecursiveDetailed(string $path, int $owner, int|false $group, bool $respectPreservationMarker = false): array
    {
        if (! function_exists('chown')) {
            return ['changed' => 0, 'failed' => 0, 'failed_paths' => [], 'supported' => false, 'skipped_subtrees' => 0];
        }

        $report = ['changed' => 0, 'failed' => 0, 'failed_paths' => [], 'first_failure' => null, 'skipped_subtrees' => 0];
        self::chownRecursiveInternal($path, $owner, $group, $report, $respectPreservationMarker);

        if ($report['failed'] > 0) {
            Log::warning('chownRecursive: 부분 실패', [
                'root' => $path,
                'owner' => $owner,
                'group' => $group,
                'changed' => $report['changed'],
                'failed' => $report['failed'],
                'first_failure' => $report['first_failure'],
            ]);
        }

        // 마커 skip 카운트는 호출자(restoreOwnership 등) 의 종합 로그에 포함되므로 별도 info 미출력.

        return [
            'changed' => $report['changed'],
            'failed' => $report['failed'],
            'failed_paths' => array_slice($report['failed_paths'], 0, 50),
            'supported' => true,
            'skipped_subtrees' => $report['skipped_subtrees'],
        ];
    }

    /**
     * 루트 디렉토리가 그룹 쓰기 권한을 가질 때, 하위 디렉토리·파일에 동일 권한을 승격합니다.
     *
     * 배경: sudo root 로 실행된 코어 업데이트가 umask 022 환경에서 신규 디렉토리/파일을
     * `0755/0644` 로 생성한 뒤 `chownRecursive` 로 소유자만 원본(`jjh:www-data`) 으로
     * 복원하면, 그룹(`www-data`) 에 쓰기 권한이 없는 비대칭이 영구 잔존한다. 결과적으로
     * php-fpm(www-data) 이 `storage/framework/cache/...` 같은 경로에 쓰기 실패.
     *
     * 본 메서드는 다음 정책으로 비대칭을 해소한다:
     * - 루트가 `g+w` 면 하위 항목 중 `g-w` 인 디렉토리·파일을 `g+w` 로 승격
     * - 루트가 `g-w` 면 no-op (운영자가 의도적으로 그룹 쓰기 차단한 정책 보존)
     * - 다른 비트(other, owner, sticky, setgid 등) 무변경 — `g+w` 만 OR
     * - symbolic link 는 링크 자체만 처리 (대상 미추적)
     * - 멱등 — 이미 정상인 항목은 changed 카운트에 포함 안 함
     * - silent fail — 권한 부족·chmod 미지원 환경에서도 예외 미발생
     *
     * @param  string  $root  대상 루트 (재귀 순회)
     * @return int 실제 chmod 한 항목 수
     */
    public static function syncGroupWritability(string $root): int
    {
        return self::syncGroupWritabilityDetailed($root)['changed'];
    }

    /**
     * `syncGroupWritability` 의 상세 결과 변형. 실패 경로를 누적하여 반환한다.
     *
     * `skipped` 는 루트가 g-w 정책 보존으로 no-op 되었거나 chmod 미지원 환경에서 true.
     * 코어/확장 업데이트가 운영자에게 권한 정상화 실패 경로를 즉시 노출할 때 사용.
     *
     * `$force=true` 시 루트가 g-w 라도 강제로 g+w 부여 후 하위 정상화. sudo root 가 0755 로
     * 신규 디렉토리를 생성한 케이스(권한 정상화가 가장 필요한 시나리오) 에서 운영자 정책 보존
     * 분기로 silent no-op 되던 결함을 차단할 때 사용. 일반 호출은 force=false (기존 동작 유지).
     *
     * @param  string  $root  대상 루트
     * @param  bool  $force  루트 g-w 정책 강제 우회
     * @return array{changed:int, failed:int, failed_paths:array<int,string>, supported:bool, skipped:bool}
     */
    public static function syncGroupWritabilityDetailed(string $root, bool $force = false): array
    {
        if (! function_exists('chmod') || ! is_dir($root)) {
            return ['changed' => 0, 'failed' => 0, 'failed_paths' => [], 'supported' => function_exists('chmod'), 'skipped' => true];
        }

        $rootPerms = @fileperms($root);
        if ($rootPerms === false) {
            return ['changed' => 0, 'failed' => 0, 'failed_paths' => [], 'supported' => true, 'skipped' => true];
        }

        if (($rootPerms & 0020) === 0) {
            if (! $force) {
                return ['changed' => 0, 'failed' => 0, 'failed_paths' => [], 'supported' => true, 'skipped' => true];
            }
            // force 모드: 루트에 g+w 강제 부여 → 이후 하위 정상화로 진행. sudo root 가 0755 로
            // 신규 디렉토리를 생성한 시나리오에서 운영자 정책 보존 분기로 silent no-op 되던 결함 차단.
            if (! @chmod($root, $rootPerms | 0020)) {
                return ['changed' => 0, 'failed' => 1, 'failed_paths' => [$root], 'supported' => true, 'skipped' => false];
            }
        }

        $report = ['changed' => 0, 'failed' => 0, 'failed_paths' => []];
        self::syncGroupWritabilityInternal($root, $report, true);

        if ($report['changed'] > 0) {
            Log::info('syncGroupWritability: 그룹 쓰기 권한 정상화', [
                'root' => $root,
                'changed' => $report['changed'],
            ]);
        }
        if ($report['failed'] > 0) {
            Log::warning('syncGroupWritability: 부분 실패', [
                'root' => $root,
                'changed' => $report['changed'],
                'failed' => $report['failed'],
                'first_failure' => $report['failed_paths'][0] ?? null,
            ]);
        }

        return [
            'changed' => $report['changed'],
            'failed' => $report['failed'],
            'failed_paths' => array_slice($report['failed_paths'], 0, 50),
            'supported' => true,
            'skipped' => false,
        ];
    }

    /**
     * syncGroupWritability 내부 재귀.
     *
     * @param  string  $path  대상 경로
     * @param  array{changed:int}  $report  집계 (참조)
     * @param  bool  $isRoot  루트 자체는 정책 판정용으로만 사용 (chmod 안 함)
     * @return void 반환값 없음
     */
    private static function syncGroupWritabilityInternal(string $path, array &$report, bool $isRoot = false): void
    {
        // symbolic link 는 대상 추적 금지
        if (is_link($path)) {
            return;
        }

        if (! $isRoot) {
            $perms = @fileperms($path);
            if ($perms !== false && ($perms & 0020) === 0) {
                // g+w 만 추가, 다른 비트 무변경
                if (@chmod($path, $perms | 0020)) {
                    $report['changed']++;
                } else {
                    if (! isset($report['failed'])) {
                        $report['failed'] = 0;
                        $report['failed_paths'] = [];
                    }
                    $report['failed']++;
                    if (count($report['failed_paths']) < 50) {
                        $report['failed_paths'][] = $path;
                    }
                }
            }
        }

        if (! is_dir($path)) {
            return;
        }

        $items = new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS);
        foreach ($items as $item) {
            self::syncGroupWritabilityInternal($item->getPathname(), $report, false);
        }
    }

    /**
     * chownRecursive 의 내부 재귀 구현. 실패 카운터를 참조 전달로 집계한다.
     *
     * @param  string  $path  대상 경로
     * @param  int  $owner  기준 소유자 UID
     * @param  int|false  $group  기준 그룹 GID
     * @param  array{changed:int, failed:int, first_failure:string|null, skipped_subtrees:int}  $report  집계 구조 (참조)
     * @param  bool  $respectPreservationMarker  `.preserve-ownership` 마커가 있는 디렉토리 서브트리 skip 여부
     * @return void 반환값 없음
     */
    private static function chownRecursiveInternal(string $path, int $owner, int|false $group, array &$report, bool $respectPreservationMarker = false): void
    {
        // 트랙 2-A — 디렉토리에 .preserve-ownership 마커가 있으면 서브트리 전체 skip (자기 자신 + 하위)
        // ModuleStorageDriver / PluginStorageDriver 가 자동 작성하는 마커로 사용자 데이터 영구 보존.
        if ($respectPreservationMarker && is_dir($path) && ! is_link($path)) {
            $markerPath = $path.DIRECTORY_SEPARATOR.'.preserve-ownership';
            if (@file_exists($markerPath)) {
                $report['skipped_subtrees']++;

                return; // 자기 자신 + 하위 모두 chown 비대상
            }
        }

        $currentOwner = @fileowner($path);
        if ($currentOwner !== false && $currentOwner !== $owner) {
            if (@chown($path, $owner)) {
                $report['changed']++;
            } else {
                if ($report['first_failure'] === null) {
                    $report['first_failure'] = $path;
                    Log::warning('chown 최초 실패', ['path' => $path, 'owner' => $owner]);
                }
                $report['failed']++;
                if (! isset($report['failed_paths'])) {
                    $report['failed_paths'] = [];
                }
                if (count($report['failed_paths']) < 50) {
                    $report['failed_paths'][] = $path;
                }
            }
        }

        // chgrp 는 owner 일치 여부와 무관하게 별도 판정 (이전: chown 분기 안에 있어 owner 일치 시 chgrp 도 스킵되던 결함).
        // 운영자 환경에서 lang-packs 가 base_path owner 와 동일하지만 그룹은 root 등 다른 그룹으로 잔존하는 케이스에서
        // 그룹 변경이 영구히 누락되던 silent fail 차단.
        if ($group !== false && function_exists('chgrp')) {
            $currentGroup = @filegroup($path);
            if ($currentGroup !== false && $currentGroup !== $group) {
                @chgrp($path, $group);
            }
        }

        if (! is_dir($path) || is_link($path)) {
            return;
        }

        $items = new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS);
        foreach ($items as $item) {
            self::chownRecursiveInternal($item->getPathname(), $owner, $group, $report, $respectPreservationMarker);
        }
    }
}
