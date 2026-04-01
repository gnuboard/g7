<?php

namespace App\Extension\Helpers;

use Illuminate\Support\Facades\File;

class FilePermissionHelper
{
    /**
     * 디렉토리를 재귀적으로 복사하면서 기존 파일/디렉토리의 퍼미션을 보존합니다.
     *
     * - 기존 디렉토리: 퍼미션/소유자/그룹 유지
     * - 신규 디렉토리: 부모 디렉토리의 퍼미션 상속
     * - 기존 파일: 퍼미션/소유자/그룹 유지한 채 내용만 교체
     * - 신규 파일: PHP 기본 퍼미션 적용 (umask 기반)
     * - removeOrphans=false: 소스에 없고 대상에만 있는 파일 유지 (사용자 추가 파일 보호)
     * - removeOrphans=true: 소스에 없고 대상에만 있는 파일/디렉토리 삭제 (excludes 제외)
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
            // 신규 디렉토리: 부모 디렉토리의 퍼미션 상속
            $parentDir = dirname($destination);
            $parentPerms = File::isDirectory($parentDir) ? (fileperms($parentDir) & 0777) : 0755;
            File::ensureDirectoryExists($destination, $parentPerms, true);
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
     * - 신규 파일: PHP 기본 퍼미션 적용 (umask 기반)
     *
     * @param string $source 소스 파일
     * @param string $destination 대상 파일
     * @return void
     */
    public static function copyFile(string $source, string $destination): void
    {
        $existingPerms = null;
        $existingOwner = null;
        $existingGroup = null;

        if (File::exists($destination)) {
            $existingPerms = fileperms($destination);
            $existingOwner = fileowner($destination);
            $existingGroup = filegroup($destination);
        }

        File::ensureDirectoryExists(dirname($destination));
        File::copy($source, $destination);

        if ($existingPerms !== null) {
            @chmod($destination, $existingPerms);
        }
        if ($existingOwner !== null && function_exists('chown')) {
            @chown($destination, $existingOwner);
        }
        if ($existingGroup !== null && function_exists('chgrp')) {
            @chgrp($destination, $existingGroup);
        }
    }
}
