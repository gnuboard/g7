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
                if ($destItem->isDir()) {
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
     * @param string $path 소유권을 상속받을 파일 또는 디렉토리
     * @return void
     */
    protected static function inheritOwnershipFromParent(string $path): void
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
        if (! function_exists('chown')) {
            return 0;
        }

        // 재귀 전체 기간 동안 실패/성공을 집계하고 종료 시 요약 로그를 남긴다.
        // 경로당 개별 로그는 재귀가 깊어지면 로그 폭주 유발 → 최초 실패 1건만 즉시 로깅.
        $report = ['changed' => 0, 'failed' => 0, 'first_failure' => null];
        self::chownRecursiveInternal($path, $owner, $group, $report);

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

        return $report['changed'];
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
        if (! function_exists('chmod') || ! is_dir($root)) {
            return 0;
        }

        $rootPerms = @fileperms($root);
        if ($rootPerms === false) {
            return 0;
        }

        // 루트가 g+w 가 아니면 정책 보존 (no-op)
        if (($rootPerms & 0020) === 0) {
            return 0;
        }

        $report = ['changed' => 0];
        self::syncGroupWritabilityInternal($root, $report, true);

        if ($report['changed'] > 0) {
            Log::info('syncGroupWritability: 그룹 쓰기 권한 정상화', [
                'root' => $root,
                'changed' => $report['changed'],
            ]);
        }

        return $report['changed'];
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
     * @param  array{changed:int, failed:int, first_failure:string|null}  $report  집계 구조 (참조)
     */
    private static function chownRecursiveInternal(string $path, int $owner, int|false $group, array &$report): void
    {
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
            }
            if ($group !== false && function_exists('chgrp')) {
                @chgrp($path, $group);
            }
        }

        if (! is_dir($path) || is_link($path)) {
            return;
        }

        $items = new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS);
        foreach ($items as $item) {
            self::chownRecursiveInternal($item->getPathname(), $owner, $group, $report);
        }
    }
}
