<?php

namespace App\Support;

use App\Extension\Helpers\FilePermissionHelper;

/**
 * 사이트 설정 파일(`storage/app/settings/*.json`) 권한을 설정 폴더가 허용하는 범위로 맞춥니다.
 *
 * 설정 파일을 만드는 코드가 권한을 정하지 않으면 만든 프로세스의 umask 를 따른다 — 7.2.0 설치본에서 웹 요청·설치기가
 * 만든 파일은 0600, 설치 중 확장 설치 하위 프로세스가 먼저 만든 `identity.json` 만 0664 였다.
 *
 * 규칙: 파일 권한은 설정 폴더 권한에서 실행 비트를 뺀 값(폴더가 허용하는 범위)을 넘지 않는다. 범위를 넘는 비트만 걷어내고
 * 비트를 더하지 않는다(새 파일도 같다 — 0600 으로 만들어진 파일을 폴더가 755 라고 0644 로 넓히면 비밀값이 든 설정이 열린다).
 *
 * 고정값(예: 0600·0660)을 쓰지 않는 이유: 웹서버가 별도 계정(nobody 등)으로 「그 밖 사용자」 권한을 통해 읽는 웹호스팅이
 * 있다(폴더 755, FTP 로 옮긴 사이트). 그런 곳에서 그 밖 사용자 비트를 걷어내면 사이트가 설정을 못 읽는다. 폴더가 이미 허용한
 * 접근은 걷어내지 않으므로 어느 환경에서도 읽기가 깨지지 않는다.
 *
 * 호스팅 호환: `chmod` 가 막혔거나 Windows 면 아무것도 하지 않는다. 새 파일의 소유권 상속(chown·chgrp)은 root 로 돌 때만
 * 실제로 바뀌고, 그 밖에는 조용히 실패해 아무 일도 없다. 셸 명령을 쓰지 않는다.
 */
final class SettingsFilePermission
{
    /**
     * 설정 파일이 가져야 할 권한을 계산합니다.
     *
     * @param  int  $currentMode  지금 파일 권한 (형식 비트가 섞여도 됨)
     * @param  int  $directoryMode  설정 폴더 권한 (형식 비트가 섞여도 됨)
     * @return int 적용할 권한 (0~0777) — 지금 권한에서 폴더 범위를 넘는 비트만 뺀 값
     */
    public static function targetMode(int $currentMode, int $directoryMode): int
    {
        return $currentMode & 0777 & ($directoryMode & 0666);
    }

    /**
     * 파일 권한이 폴더 범위를 넘는지 판정합니다.
     *
     * @param  int  $currentMode  지금 파일 권한
     * @param  int  $directoryMode  설정 폴더 권한
     * @return bool 범위를 넘는 비트가 있으면 true
     */
    public static function needsChange(int $currentMode, int $directoryMode): bool
    {
        return ($currentMode & 0777) !== self::targetMode($currentMode, $directoryMode);
    }

    /**
     * 설정 파일 권한을 폴더 범위로 맞춥니다.
     *
     * @param  string  $path  설정 파일 절대 경로
     * @param  bool  $created  이번에 새로 만든 파일이면 true (부모 폴더 소유·그룹도 상속)
     * @return void 반환값 없음
     */
    public static function apply(string $path, bool $created): void
    {
        if (PHP_OS_FAMILY === 'Windows' || ! is_file($path) || ! ExecutionEnvironment::hasFunction('chmod')) {
            return;
        }

        $directoryMode = @fileperms(dirname($path));
        $fileMode = @fileperms($path);

        if ($directoryMode === false || $fileMode === false) {
            return;
        }

        if (self::needsChange($fileMode, $directoryMode)) {
            @chmod($path, self::targetMode($fileMode, $directoryMode));
        }

        if ($created) {
            FilePermissionHelper::inheritOwnershipFromParent($path);
        }
    }
}
