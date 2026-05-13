<?php

namespace App\Support;

/**
 * 프로세스 umask 를 운영자 의도에 동조시키는 헬퍼.
 *
 * 배경: Laravel 의 `FileStore::ensureCacheDirectoryExists()` 는 신규 캐시 하위
 * 디렉토리를 `mkdir(0777, true, true)` 로 생성한다. 이 요청 모드는 프로세스 umask
 * 로 마스킹되어, 서버 기본 umask 022 환경에서는 실제 `0755` (drwxr-xr-x) 로 생성된다.
 *
 * 그 결과 G7 표준 구성(`storage/` 가 jjh:www-data `drwxrwxr-x`) 에서 www-data
 * 그룹의 php-fpm 이 새 하위 디렉토리(`cache/data/2c/...`) 에 쓸 수 없어
 * `Permission denied` 가 발생한다. 업데이트 시점의 1회성 `syncGroupWritability`
 * 로는 해결 불가 — 업데이트 직후 런타임이 재생성하는 새 디렉토리가 그 뒤
 * umask 022 로 다시 깨진다.
 *
 * 본 헬퍼는 **운영자 의도를 존중하는 방식** 으로 umask 를 조정한다:
 *
 *   - `storage/` 디렉토리에 그룹 쓰기(`g+w`) 비트가 설정되어 있으면
 *     → 운영자가 그룹 공유 정책을 선언한 것 → umask 를 `0002` 로 조정
 *     → 이후 런타임이 만드는 모든 파일/디렉토리도 g+w 포함
 *
 *   - `storage/` 에 g-w 면 → 운영자가 그룹 공유를 원하지 않음 → 기본 umask 보존
 *
 *   - `umask` 함수 자체가 비활성(일부 강화된 호스팅) → 조용히 스킵
 *
 * 호출은 Laravel 부팅 최초 지점(`bootstrap/app.php` 최상단)에서 1회면 충분.
 * 이후 모든 런타임 파일 생성에 새 umask 가 적용된다.
 *
 * Windows 환경에서는 `fileperms` 결과가 POSIX 와 의미가 다르고 umask 자체가
 * 실효가 제한적이므로, 본 헬퍼는 POSIX 환경에서만 의미 있는 결과를 낸다.
 */
class UmaskHelper
{
    /**
     * 그룹 공유 친화 umask(0002) 로 조정한다. 조건이 맞지 않으면 no-op.
     *
     * @param  string  $storagePath  판정 기준 디렉토리 (일반적으로 `base_path('storage')`)
     * @return int|null  새 umask 로 전환한 경우 이전 umask 값. 조건 불충족 시 null.
     */
    public static function configureForGroupSharing(string $storagePath): ?int
    {
        if (! function_exists('umask')) {
            return null;
        }

        if (! is_dir($storagePath)) {
            return null;
        }

        $perms = @fileperms($storagePath);
        if ($perms === false) {
            return null;
        }

        // 그룹 쓰기 비트(020) 가 없으면 운영자 의도 존중 → 건드리지 않는다
        if (($perms & 0020) === 0) {
            return null;
        }

        return umask(0002);
    }
}
