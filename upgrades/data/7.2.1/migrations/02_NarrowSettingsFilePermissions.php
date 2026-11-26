<?php

namespace App\Upgrades\Data\V7_2_1\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\Log;

/**
 * 사이트 설정 파일 권한을 설정 폴더가 허용하는 범위로 좁힙니다 (7.2.1).
 *
 * 배경:
 *   7.2.0 까지 설정 파일(`storage/app/settings/*.json`)을 만드는 코드가 권한을 정하지 않아, 만든 프로세스의 umask 를
 *   따랐다. 설치 중 확장 설치 하위 프로세스가 먼저 만든 `identity.json` 만 0664 였고 나머지는 0600 이었다(7.2.0 실측).
 *   7.2.1 부터 새로 쓰는 설정 파일은 폴더 범위로 맞추고, 이미 있는 파일은 이 스텝이 한 번 맞춘다.
 *
 * 규칙: 파일 권한에서 폴더 권한(실행 비트 뺀 값)을 넘는 비트만 걷어낸다. 넓히지 않는다. 폴더가 이미 허용한 접근은
 *   그대로라 웹서버가 「그 밖 사용자」 권한으로 읽는 웹호스팅(폴더 755)에서도 읽기가 깨지지 않는다.
 *
 * 멱등: 이미 범위 안이면 건드리지 않는다. 재실행해도 부작용이 없다.
 *
 * 실패 정책: 권한을 바꾸지 못해도 업그레이드 전체를 막지 않는다(경고 후 계속). `chmod` 가 막힌 호스팅·Windows 는 건너뛴다.
 *
 * 격리 원칙 (docs/extension/upgrade-step-guide.md §13):
 *   `App\Support\SettingsFilePermission` 을 참조하지 않는다. 판정과 대상 폴더를 본 클래스에 동결한다 — 미래 버전에서
 *   그 클래스가 바뀌어도 본 스텝은 7.2.1 시점의 규칙으로 동작해야 한다.
 */
final class NarrowSettingsFilePermissions implements DataMigration
{
    /**
     * 설정 파일 폴더 (storage 기준 상대 경로, 7.2.1 시점 고정값).
     */
    private const SETTINGS_DIRECTORY = 'app/settings';

    /**
     * 마이그레이션 식별자 (로그용).
     *
     * @return string 식별자
     */
    public function name(): string
    {
        return 'NarrowSettingsFilePermissions';
    }

    /**
     * 설정 파일 권한을 폴더 범위로 좁힙니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        try {
            $this->runInternal($context);
        } catch (\Throwable $e) {
            $context->logger->warning(sprintf('[7.2.1] 설정 파일 권한 정리 실패 (계속 진행): %s', $e->getMessage()));
            Log::warning('7.2.1 NarrowSettingsFilePermissions 실패', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 판정 — 지금 권한에서 폴더 범위를 넘는 비트만 뺀 권한 (7.2.1 시점 규칙).
     *
     * @param  int  $fileMode  지금 파일 권한
     * @param  int  $directoryMode  설정 폴더 권한
     * @return int 적용할 권한
     */
    public static function narrowedMode(int $fileMode, int $directoryMode): int
    {
        return $fileMode & 0777 & ($directoryMode & 0666);
    }

    /**
     * 실제 정리 로직.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    private function runInternal(UpgradeContext $context): void
    {
        if (PHP_OS_FAMILY === 'Windows' || ! function_exists('chmod') || ! function_exists('fileperms')) {
            $context->logger->info('[7.2.1] 설정 파일 권한 — 이 환경은 권한을 바꾸지 않음(skip)');

            return;
        }

        $directory = storage_path(self::SETTINGS_DIRECTORY);
        $directoryMode = is_dir($directory) ? @fileperms($directory) : false;

        if ($directoryMode === false) {
            $context->logger->info('[7.2.1] 설정 파일 권한 — 설정 폴더 없음(skip)');

            return;
        }

        $changed = 0;
        $failed = 0;

        foreach (glob($directory.DIRECTORY_SEPARATOR.'*.json') ?: [] as $path) {
            $fileMode = @fileperms($path);

            if ($fileMode === false || is_link($path)) {
                continue;
            }

            $target = self::narrowedMode($fileMode, $directoryMode);

            if (($fileMode & 0777) === $target) {
                continue;
            }

            if (@chmod($path, $target)) {
                $changed++;
            } else {
                $failed++;
            }
        }

        $context->logger->info(sprintf('[7.2.1] 설정 파일 권한을 폴더 범위로 좁힘 — 바꿈 %d · 실패 %d', $changed, $failed));
    }
}
