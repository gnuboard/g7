<?php

namespace App\Upgrades\Data\V7_2_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;

/**
 * 7.1.x 코어 업데이트가 덮어쓴 운영자 `public/favicon.ico` 를 이번 업데이트의 백업에서 되살립니다.
 *
 * 7.2.0 은 운영자가 넣은 `public/favicon.ico`(비어 있지 않고 배포본과 다른 파일)를 코어 업데이트가 덮지 않는다
 * (`CoreUpdateService::isOperatorFavicon`). 그러나 7.1.x → 7.2.0 업데이트의 파일 적용은 부모 프로세스(7.1.x 코드)가
 * 하므로 그 판정이 돌지 않고, 7.1.x 의 증분 적용은 「현재 파일 ≠ 새 판」이라 운영자 아이콘을 7.2.0 기본 아이콘으로
 * 덮는다(7.1.6 → 7.2.0 실측). 이 스텝은 그 전환에서만 그 결과를 되돌린다.
 *
 * 근거 — 7.1.x `core:update` 의 순서(Step 5 백업 → 6.5 신규 파일 목록 `_new_files_manifest.json` → 7 파일 적용 →
 * 10 업그레이드 스텝(자식 프로세스) → 11 정리에서 백업 삭제)상, 이 스텝이 도는 동안 이번 업데이트의 백업
 * (`storage/app/core_backups/core_{시각}/`)이 남아 있고 그 안의 `public/favicon.ico` 가 업데이트 직전 파일이다. 자식에게
 * 백업 경로를 넘기지는 않으므로, 목록 파일의 `from_version`·`to_version` 이 이 실행과 같은 가장 새 백업을 고른다.
 *
 * 다음을 모두 만족할 때만 되살린다:
 *  - 그 백업의 `public/favicon.ico` 가 비어 있지 않다(7.1.x 이하의 기본 파일은 0바이트라 비어 있지 않으면 운영자 파일이다).
 *  - 지금 파일이 없거나 비었거나 코어 번들 아이콘(`public/assets/core/favicon/favicon.ico`)과 같다 — 업데이트가 덮어쓴
 *    결과다. 그 밖의 내용이면 업데이트 뒤 누군가 넣은 파일이므로 건드리지 않는다.
 *  - 지금 파일이 백업과 다르다(같으면 이미 보존된 것).
 * 백업이 없으면(`--no-backup`, 업데이트 밖에서 스텝만 다시 돌림) 판정할 근거가 없어 아무것도 바꾸지 않고 안내만 남긴다.
 *
 * 되살린 파일은 기존 파일이 있으면 그 위에 덮어써 소유자·권한을 그대로 두고, 없으면(새로 만듦) root 로 실행 중일 때
 * `public/` 디렉토리의 소유자·그룹으로 맞춘다(스텝 13 과 같은 방식 — 소유권 복원 목록 밖일 수 있다).
 *
 * V-1 안전: PHP 내장 파일 함수와 `$context->logger` 만 쓴다.
 *
 * idempotent: 되살린 뒤에는 지금 파일이 백업과 같아 다시 바꾸지 않는다.
 */
class RestoreOperatorFaviconFromBackup implements DataMigration
{
    /** 사이트 아이콘 경로 */
    private const FAVICON = 'public/favicon.ico';

    /** 코어 번들 아이콘 경로 — 7.2.0 기본 `public/favicon.ico` 와 같은 파일 */
    private const BUNDLED_FAVICON = 'public/assets/core/favicon/favicon.ico';

    /** 7.1.x 백업 디렉토리 이름 접두사 (`CoreBackupHelper::createBackup`) */
    private const BACKUP_PREFIX = 'core_';

    /** 7.1.x 백업의 신규 파일 목록 이름 (`CoreBackupHelper::NEW_FILES_MANIFEST`) */
    private const MANIFEST = '_new_files_manifest.json';

    /**
     * @param  string|null  $basePath  설치 루트 (null 이면 base_path() — 테스트가 임시 루트를 주입한다)
     * @param  string|null  $backupRoot  코어 백업 루트 (null 이면 storage/app/core_backups)
     */
    public function __construct(
        private readonly ?string $basePath = null,
        private readonly ?string $backupRoot = null,
    ) {}

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'RestoreOperatorFaviconFromBackup';
    }

    /**
     * 백업의 운영자 아이콘을 되살립니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        $root = rtrim($this->basePath ?? base_path(), '/\\');
        $current = $root.'/'.self::FAVICON;
        $backup = $this->findBackup($context);

        if ($backup === null) {
            $context->logger->info('[core:7.2.0] 이번 업데이트의 백업이 없어 운영자 파비콘 복원 판정 생략 — 직접 넣은 public/favicon.ico 가 기본 아이콘으로 바뀌었으면 다시 올리거나 환경설정의 파비콘으로 지정하세요');

            return;
        }

        $saved = $backup.'/'.self::FAVICON;

        if (! is_file($saved) || is_link($saved) || (int) @filesize($saved) === 0) {
            $context->logger->info('[core:7.2.0] 업데이트 전 public/favicon.ico 가 기본(빈) 파일 — 복원할 운영자 파비콘 없음');

            return;
        }

        $savedHash = @md5_file($saved);

        if (is_file($current) && @md5_file($current) === $savedHash) {
            $context->logger->info('[core:7.2.0] 운영자 파비콘이 이미 보존됨');

            return;
        }

        if (! $this->wasOverwrittenByUpdate($root, $current)) {
            $context->logger->info('[core:7.2.0] public/favicon.ico 가 업데이트 전 파일도 기본 아이콘도 아님 — 건드리지 않음');

            return;
        }

        $created = ! is_file($current);

        if (! @copy($saved, $current)) {
            $context->logger->warning('[core:7.2.0] 운영자 파비콘 복원 실패 (권한 확인) — 백업의 public/favicon.ico 를 직접 되돌리세요', [
                'backup' => $saved,
            ]);

            return;
        }

        // 새로 만든 파일만 소유자를 맞춘다 — 덮어쓴 파일은 기존 소유자·권한을 그대로 둔다.
        if ($created) {
            $this->matchDirectoryOwnership($current, dirname($current));
        }

        $context->logger->info('[core:7.2.0] 7.1.x 업데이트가 덮어쓴 운영자 public/favicon.ico 를 백업에서 복원', [
            'backup' => $saved,
        ]);
    }

    /**
     * 지금 파일이 업데이트가 놓은 기본 아이콘인지(또는 없는지·비었는지) 판정합니다.
     *
     * @param  string  $root  설치 루트
     * @param  string  $current  지금 `public/favicon.ico` 경로
     * @return bool 업데이트가 덮어쓴 결과로 볼 수 있으면 true
     */
    private function wasOverwrittenByUpdate(string $root, string $current): bool
    {
        if (is_link($current)) {
            return false;
        }

        if (! is_file($current) || (int) @filesize($current) === 0) {
            return true;
        }

        $bundled = $root.'/'.self::BUNDLED_FAVICON;

        return is_file($bundled) && @md5_file($bundled) === @md5_file($current);
    }

    /**
     * 새 파일의 소유자·그룹을 디렉토리와 같게 맞춥니다(root 로 실행 중일 때만 실제로 바뀐다).
     *
     * @param  string  $path  파일 경로
     * @param  string  $directory  기준 디렉토리
     * @return void 반환값 없음
     */
    private function matchDirectoryOwnership(string $path, string $directory): void
    {
        if (! function_exists('chown') || ! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return;
        }

        clearstatcache(true, $path);
        $owner = @fileowner($directory);
        $group = @filegroup($directory);

        if ($owner !== false && @fileowner($path) !== $owner) {
            @chown($path, $owner);
        }
        if ($group !== false && @filegroup($path) !== $group) {
            @chgrp($path, $group);
        }
    }

    /**
     * 이번 실행(from → to)의 가장 새 코어 백업 디렉토리를 찾습니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return string|null 백업 디렉토리 경로 (없으면 null)
     */
    private function findBackup(UpgradeContext $context): ?string
    {
        $backupRoot = rtrim($this->backupRoot ?? storage_path('app/core_backups'), '/\\');

        if (! is_dir($backupRoot)) {
            return null;
        }

        $names = array_filter(
            scandir($backupRoot) ?: [],
            static fn (string $name): bool => str_starts_with($name, self::BACKUP_PREFIX) && is_dir($backupRoot.'/'.$name),
        );
        rsort($names, SORT_STRING);

        foreach ($names as $name) {
            $manifest = $backupRoot.'/'.$name.'/'.self::MANIFEST;
            $decoded = is_file($manifest) ? json_decode((string) @file_get_contents($manifest), true) : null;

            if (! is_array($decoded)) {
                continue;
            }

            if (($decoded['from_version'] ?? null) === $context->fromVersion && ($decoded['to_version'] ?? null) === $context->toVersion) {
                return $backupRoot.'/'.$name;
            }
        }

        return null;
    }
}
