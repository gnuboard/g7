<?php

namespace App\Extension\Helpers;

use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

/**
 * `public/storage` symlink 멱등 복구 헬퍼.
 *
 * 코어 업데이트(특히 `--prune`) 이후 `public/storage` symlink 가 부재/손상 상태로 남는
 * 결함(#43)을 종료 시점에 방어 복구한다. `is_link` 이면 no-op, 부재/일반 디렉토리이면
 * `storage/app/public` 을 가리키는 symlink 를 (필요 시 백업 후) 재생성한다.
 *
 * V-1 안전: 이 헬퍼는 beta.5 DataMigration `RecoverPublicStorageSymlink` 가 in-process
 * fallback 으로 호출할 수 있으므로, `Illuminate\Support\Facades\*` 파사드 + 네이티브
 * 함수 + 로컬 로직만 사용한다 (신규 Service/Manager/Repository 메서드 의존 금지).
 *
 * 로거 주입: 두 호출자의 로깅 채널이 다르다 — migration 은 `$context->logger`(채널
 * `upgrade`), 코어 업데이트 커맨드는 `Log::channel('upgrade')`. 파사드를 직접 쓰면
 * migration 의 채널 격리가 깨지므로 로거를 주입받고, `null` 이면 `upgrade` 채널로 폴백한다.
 */
class StorageLinkHelper
{
    /**
     * `public/storage` 가 정상 symlink 인지 확인하고, 아니면 멱등 재생성합니다.
     *
     * 멱등 판정 순서:
     *   1. `is_link(public/storage)` → 이미 정상 symlink, no-op.
     *   2. `storage/app/public` 부재 → Laravel storage:link 컨벤션 미사용 환경, skip
     *      (false positive 차단 — 운영자 의도적 구성 존중).
     *   3. `public/storage` 부재 → `symlink()` 로 신규 생성 (prune 삭제 갭 복구 핵심).
     *   4. `public/storage` 가 일반 디렉토리 → `.broken.{YmdHis}` rename 백업 후 재생성
     *      (dereferenced 콘텐츠 데이터 손실 없이 보존).
     *
     * Windows `SeCreateSymbolicLink` 권한 부족으로 `symlink()` 가 실패하면 target 이
     * 디렉토리일 때 `mklink /J`(junction) 로 폴백한다. 그래도 실패하면 rename 백업을
     * 원위치로 복원하고 warning 로그만 남긴다 (graceful degrade — 데이터 손실 없음).
     *
     * @param  LoggerInterface|null  $logger  로그 채널 격리용 주입 로거.
     *                                        `null` 이면 `Log::channel('upgrade')` 로 폴백.
     */
    public static function ensurePublicStorageLink(?LoggerInterface $logger = null): void
    {
        $logger ??= Log::channel('upgrade');

        $publicStorage = public_path('storage');
        $storageSource = storage_path('app/public');

        // 1) 이미 정상 symlink → no-op (멱등)
        if (is_link($publicStorage)) {
            return;
        }

        // 2) Laravel storage:link source 부재 → 표준 미사용 환경, skip (false positive 차단)
        if (! is_dir($storageSource)) {
            return;
        }

        // 3) public/storage 부재 → 신규 생성 (prune 이 삭제한 케이스 — 기존 스텝 미커버)
        if (! file_exists($publicStorage)) {
            if (static::createLink($storageSource, $publicStorage)) {
                $logger->info('[storage-link] public/storage symlink 신규 생성', [
                    'target' => $storageSource,
                ]);
            } else {
                $logger->warning('[storage-link] public/storage symlink 생성 실패 — 수동 `php artisan storage:link` 필요', [
                    'target' => $storageSource,
                ]);
            }

            return;
        }

        // Windows junction(`mklink /J`)은 `is_link()` 가 false 지만 이미 정상 링크다
        // (reparse point). rename 백업 대상에서 제외 — no-op 로 존중한다.
        if (static::isReparsePoint($publicStorage)) {
            return;
        }

        // 4) public/storage 가 일반 디렉토리(손상) → rename 백업 후 재생성
        $backup = $publicStorage.'.broken.'.date('YmdHis');
        if (! @rename($publicStorage, $backup)) {
            $logger->warning('[storage-link] public/storage rename 실패 — 복구 skip', [
                'path' => $publicStorage,
            ]);

            return;
        }

        if (static::createLink($storageSource, $publicStorage)) {
            $logger->info('[storage-link] public/storage symlink 재생성 완료 — 백업 디렉토리 검증 후 수동 삭제 권장', [
                'backup' => $backup,
                'target' => $storageSource,
            ]);

            return;
        }

        // 재생성 실패 → rename 원복 (데이터 손실 없음)
        @rename($backup, $publicStorage);
        $logger->warning('[storage-link] public/storage symlink 재생성 실패 — rename 원복, 수동 `php artisan storage:link` 필요', [
            'target' => $storageSource,
        ]);
    }

    /**
     * `symlink()` 로 링크를 생성하고, 실패 시 Windows junction 폴백을 시도합니다.
     *
     * Windows 에서 PHP `symlink()` 는 `SeCreateSymbolicLink` 권한이 필요하지만 junction
     * (`mklink /J`)은 권한 없이 생성 가능하므로, target 이 디렉토리이면 junction 으로 폴백한다.
     *
     * @param  string  $target  링크가 가리킬 대상 (`storage/app/public`)
     * @param  string  $link  생성할 링크 경로 (`public/storage`)
     * @return bool 링크 생성 성공 여부
     */
    protected static function createLink(string $target, string $link): bool
    {
        if (@symlink($target, $link)) {
            static::inheritParentOwnership($link);

            return true;
        }

        // symlink 실패 + target 이 디렉토리 → Windows junction 폴백.
        if (PHP_OS_FAMILY === 'Windows' && is_dir($target)) {
            exec(
                'cmd /c mklink /J '.escapeshellarg($link).' '.escapeshellarg($target).' 2>&1',
                $out,
                $code
            );
            if ($code === 0 && is_dir($link)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 갓 생성한 링크의 소유자/그룹을 부모 디렉토리로부터 상속시킵니다.
     *
     * `symlink()` 는 소유권 지정 인자가 없어 실행 프로세스(sudo → root)의 소유로 링크를
     * 만든다. 이 때문에 sudo 로 실행된 코어 업데이트가 종료 시점에 `public/storage` 를
     * 재생성하면 링크가 root:root 로 남아, 원래 앱 실행 유저 소유였던 링크의 소유권이
     * 바뀌던 문제(#43 후속)를 차단한다. 부모 `public/` 의 owner/group 을 기준으로 삼아
     * 이 프로젝트의 "신규 항목은 부모 소유권 상속" 컨벤션(`FilePermissionHelper`)과 일치시킨다.
     *
     * `chown`/`chgrp` 은 심볼릭 링크의 target 을 따라가므로, 링크 *자체* 를 대상으로 하는
     * `lchown`/`lchgrp` 을 사용한다. 비-POSIX(Windows) 또는 함수 부재/권한 부족 시 무해하게 skip.
     *
     * @param  string  $link  방금 생성한 링크 경로
     */
    protected static function inheritParentOwnership(string $link): void
    {
        // lchown/lchgrp 은 POSIX 전용 — Windows 및 함수 비활성 환경에서는 no-op.
        if (! function_exists('lchown') || ! function_exists('lchgrp')) {
            return;
        }

        $parent = dirname($link);
        $owner = @fileowner($parent);
        $group = @filegroup($parent);

        if ($owner !== false) {
            @lchown($link, $owner);
        }
        if ($group !== false) {
            @lchgrp($link, $group);
        }
    }

    /**
     * 경로가 Windows JUNCTION 인지 판정합니다 (`is_link()` 미인식 reparse point).
     *
     * Windows 에서 junction 과 일반 디렉토리는 둘 다 `is_dir()` 이 true 라 구분되지 않고,
     * `readlink()` 는 일반 디렉토리에는 **자기 경로**를, junction 에는 **target 경로**를
     * 반환한다. 따라서 "readlink 가 자기 경로와 다른 target 을 반환" 하는 경우만 junction 으로
     * 판정한다. 일반 symlink 는 호출부(case 1)가 먼저 처리하므로 여기서 false. 비-Windows 는
     * 항상 false. 이 판정으로 정상 junction 은 case 4(rename 재생성) 대상에서 제외된다.
     *
     * @param  string  $path  검사할 경로
     * @return bool junction 여부
     */
    protected static function isReparsePoint(string $path): bool
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return false;
        }

        // 일반 symlink / 파일 은 junction 아님.
        if (is_link($path) || is_file($path)) {
            return false;
        }

        $target = @readlink($path);
        if ($target === false) {
            return false;
        }

        // readlink 가 자기 경로와 다른 target 을 반환하면 junction. (일반 디렉토리는 자기 경로
        // 반환 → 같으므로 false.) 경로 구분자/대소문자 차이를 흡수하기 위해 정규화 후 비교.
        $normalize = static fn (string $p): string => strtolower(str_replace('/', '\\', rtrim($p, '/\\')));

        return $normalize($target) !== $normalize($path);
    }
}
