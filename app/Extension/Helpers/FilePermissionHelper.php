<?php

namespace App\Extension\Helpers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class FilePermissionHelper
{
    /**
     * 디렉토리를 재귀적으로 복사하면서 기존 파일/디렉토리의 퍼미션을 보존합니다.
     *
     * - 기존 디렉토리: 퍼미션/소유자/그룹 유지
     * - 신규 디렉토리: 부모 디렉토리의 퍼미션/소유자/그룹 상속
     * - 기존 파일: 퍼미션/소유자/그룹 유지한 채 내용만 교체
     * - 신규 파일: 부모 디렉토리의 소유자/그룹 상속 (퍼미션은 PHP 기본 umask)
     * - removeOrphans=false: 소스에 없고 대상에만 있는 파일 유지 (사용자 추가 파일 보호)
     * - removeOrphans=true: 소스에 없고 대상에만 있는 파일/디렉토리 삭제 (excludes 제외)
     *
     * 신규 항목의 소유권 상속은 sudo 로 실행된 업데이트 프로세스가 root 소유로 파일을
     * 생성하는 것을 방지한다. vendor/ 처럼 cleanDirectory 후 재생성되는 디렉토리 구조
     * 전체가 기존 부모(= vendor/) 의 소유권을 승계하도록 보장한다.
     *
     * @param string $source 소스 디렉토리 경로
     * @param string $destination 대상 디렉토리 경로
     * @param \Closure|null $onProgress 진행 콜백
     * @param array $excludes 제외할 이름 또는 경로 목록 (예: ['node_modules', '.git', 'node_modules/test_dir'])
     * @param string $relativePath 현재 상대 경로 (내부 재귀용)
     * @param bool $removeOrphans 소스에 없는 대상 파일/디렉토리 삭제 여부
     * @return void
     */
    public static function copyDirectory(string $source, string $destination, ?\Closure $onProgress = null, array $excludes = [], string $relativePath = '', bool $removeOrphans = false): void
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

            $destPath = $destination.DIRECTORY_SEPARATOR.$itemName;

            // symlink 자체 보존: target 추적 없이 동일 symlink 재생성.
            // SplFileInfo::isDir() 가 symlink 를 추적하므로 검사 순서가 isDir() 보다 먼저여야 한다.
            // 미적용 시 `public/storage` 등 symlink 가 target 디렉토리 내용으로 복사되어 백업/복원 양쪽에서 손상.
            if ($item->isLink()) {
                if (static::copySymlink($item->getPathname(), $destPath)) {
                    continue;
                }
                // 복원 실패 (Windows SeCreateSymbolicLink 권한 부족 등) — 일반 복사로 fall-through.
                // 운영자가 추후 `php artisan storage:link` 수동 실행으로 회복 가능.
            }

            if ($item->isDir()) {
                static::copyDirectory($item->getPathname(), $destPath, $onProgress, $excludes, $itemRelativePath, $removeOrphans);
            } else {
                static::copyFile($item->getPathname(), $destPath);
            }
        }

        // 소스에 없는 대상 파일/디렉토리 삭제
        if ($removeOrphans && File::isDirectory($destination)) {
            static::removeOrphanItems($source, $destination, $excludes, $relativePath);
        }
    }

    /**
     * symlink 자체를 보존 복사합니다 (target 미추적).
     *
     * 기존 dest 가 symlink 또는 파일이면 unlink, 디렉토리면 deleteDirectory 후 symlink 재생성.
     * readlink / symlink 실패 시 false 반환 — 호출자가 일반 복사로 fall-through.
     *
     * Windows 환경에서 PHP `symlink()` 는 `SeCreateSymbolicLink` 권한이 필요하며 일반 사용자는
     * 권한이 없을 가능성이 높다. 실패 시 warning 로그 후 false 반환하여 호출자가 일반 복사로
     * fall-through 하도록 한다. 운영자는 업그레이드 후 `php artisan storage:link` 등 수동
     * 명령으로 symlink 를 회복할 수 있다.
     *
     * @param  string  $source  소스 symlink 경로
     * @param  string  $destination  대상 symlink 경로
     * @return bool  symlink 복원 성공 여부 (false 면 호출자가 일반 복사로 폴백)
     */
    protected static function copySymlink(string $source, string $destination): bool
    {
        $target = @readlink($source);
        if ($target === false) {
            Log::warning('copySymlink: readlink 실패 — 일반 복사로 폴백', ['source' => $source]);

            return false;
        }

        // 기존 dest 정리 — symlink/파일 은 unlink, 디렉토리는 deleteDirectory
        if (is_link($destination) || is_file($destination)) {
            @unlink($destination);
        } elseif (is_dir($destination)) {
            File::deleteDirectory($destination);
        }

        if (! @symlink($target, $destination)) {
            Log::warning('copySymlink: symlink 생성 실패 — 일반 복사로 폴백', [
                'source' => $source,
                'destination' => $destination,
                'target' => $target,
            ]);

            return false;
        }

        return true;
    }

    /**
     * 소스에 없고 대상에만 있는 파일/디렉토리를 삭제합니다.
     *
     * excludes 목록에 해당하는 항목은 삭제하지 않습니다.
     *
     * @param string $source 소스 디렉토리 경로
     * @param string $destination 대상 디렉토리 경로
     * @param array $excludes 제외할 이름 또는 경로 목록
     * @param string $relativePath 현재 상대 경로
     * @return void
     */
    protected static function removeOrphanItems(string $source, string $destination, array $excludes, string $relativePath): void
    {
        $destItems = new \FilesystemIterator($destination, \FilesystemIterator::SKIP_DOTS);

        foreach ($destItems as $destItem) {
            $itemName = $destItem->getFilename();
            $itemRelativePath = $relativePath === '' ? $itemName : $relativePath.'/'.$itemName;

            // excludes 대상은 삭제하지 않음
            if (static::isExcluded($itemName, $itemRelativePath, $excludes)) {
                continue;
            }

            $srcPath = $source.DIRECTORY_SEPARATOR.$itemName;

            // 소스에 존재하지 않는 항목만 삭제
            if (! File::exists($srcPath) && ! File::isDirectory($srcPath)) {
                // symlink 는 항상 unlink — File::deleteDirectory 는 is_dir() 추적 검사 후
                // 재귀 삭제하므로 symlink-to-dir 인 경우 target 의 모든 파일을 삭제하는 사고
                // 발생 가능. is_link() 가 isDir() 보다 먼저 평가되어야 한다.
                if (is_link($destItem->getPathname())) {
                    @unlink($destItem->getPathname());
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
     * @param string $itemName 현재 항목의 파일/디렉토리 이름
     * @param string $itemRelativePath 루트로부터의 상대 경로
     * @param array $excludes 제외 목록
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
     * @param string $source 소스 파일
     * @param string $destination 대상 파일
     * @return void
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
     * 부모 디렉토리의 퍼미션·소유자·그룹을 상속하여 신규 디렉토리를 생성합니다.
     *
     * @param string $path 생성할 디렉토리 경로
     * @return void
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
     * @return void
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
     * 소유자·그룹을 적용합니다. sudo 없이 실행 시 silent fail 로 현행 동작 유지.
     *
     * @param string $path 대상 경로
     * @param int|false $owner fileowner() 반환값 (false 허용)
     * @param int|false $group filegroup() 반환값 (false 허용)
     * @return void
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
     * @return array{0: int|false, 1: int|false, 2: string}  [owner, group, source]
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

    /**
     * 경로와 그 하위 항목의 소유자·그룹을 재귀적으로 복원합니다.
     *
     * 현재 소유자가 기준과 이미 일치하면 해당 항목은 스킵. symbolic link 는 링크 자체만
     * 처리하고 대상은 따라가지 않는다. @chown/@chgrp suppress 로 권한 부족 / chown 미지원
     * 환경에서도 silent fail.
     *
     * @param string $path 대상 경로 (파일 또는 디렉토리)
     * @param int $owner 기준 소유자 UID
     * @param int|false $group 기준 그룹 GID (false = 그룹 유지)
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
     * @return int  실제 chmod 한 항목 수
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
