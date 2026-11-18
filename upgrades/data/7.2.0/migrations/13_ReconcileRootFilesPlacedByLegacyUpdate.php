<?php

namespace App\Upgrades\Data\V7_2_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;

/**
 * 7.1.x 코어 업데이트가 프로젝트 루트에 잘못 놓거나 빠뜨린 7.2.0 루트 파일을 바로잡습니다.
 *
 * 7.2.0 배포본은 프로젝트 루트에 FTP 설치용 압축 풀기 파일(`g7-setup.php`)과 루트 진입·보호 파일(`index.php`·
 * `.htaccess`·`web.config`)을 싣는다. 7.1.x → 7.2.0 업데이트의 파일 적용은 부모 프로세스(7.1.x 코드)의
 * 「targets 에 없는 최상위 신규 항목 자동 적용」 폴백이 하는데, 그 폴백은
 *  ① `update.protected_paths` 에 `g7-setup.php` 가 없어(7.2.0 에서 추가) 설치가 끝난 사이트에 압축 풀기 파일을 놓고,
 *  ② `File::files()` 기본값(숨김 파일 제외)이라 루트 `.htaccess` 를 보지 못해, 보호 규칙 없이 루트 `index.php` 만 놓는다.
 * 7.2.0 코드(`CoreUpdateService::applyDiscoveredTopLevelPaths`)는 두 가지를 다 고쳤지만 그 코드는 7.2.0 이후의
 * 업데이트에서만 돈다(7.1.6 → 7.2.0 실측). 이 스텝이 그 전환의 결과를 바로잡는다.
 *
 * 1. `g7-setup.php` — 설치가 끝난 사이트(`storage/app/g7_installed` 또는 `.env` 의 `INSTALLER_COMPLETED=true|1|yes`,
 *    `g7-setup.php` 자신의 판정과 같은 규칙)이고 그 파일이 그누보드7 압축 풀기 파일(머리 표지)이면 지운다. 설치가 끝난
 *    사이트에서 그 파일은 410 만 내므로 지워도 잃는 것이 없고, 설치를 마치면 사라지는 것이 그 파일의 본래 상태다.
 *    표지가 없는 같은 이름의 파일은 운영자 파일이므로 둔다.
 * 2. 루트 `.htaccess` — 루트 `index.php` 가 있고 `.htaccess` 가 없을 때, 이번 업데이트의 배포본 압축 해제본
 *    (`update.pending_path` 아래 `core_{시각}` — 7.1.x 는 업그레이드 스텝 뒤 정리 단계에서 지운다)에서 루트 `index.php` 가
 *    지금 파일과 같은 배포본을 찾아 그 `.htaccess` 를 놓는다. 이미 `.htaccess` 가 있으면(운영자 파일 포함) 건드리지 않는다.
 *    배포본을 찾지 못하면 경고와 직접 올릴 방법을 남긴다(문서 루트가 `public/` 인 표준 배치에서는 영향이 없다).
 *    새로 만든 파일은 root 로 실행 중이면 프로젝트 루트 디렉토리의 소유자·그룹으로 맞춘다(소유권 복원 목록 밖이다).
 *
 * V-1 안전: PHP 내장 파일 함수·`config()`·`$context->logger` 만 쓴다.
 *
 * idempotent: 지운 파일은 다시 생기지 않고, 놓은 `.htaccess` 가 있으면 두 번째 실행은 아무것도 하지 않는다.
 */
class ReconcileRootFilesPlacedByLegacyUpdate implements DataMigration
{
    /** 압축 풀기 파일 이름 */
    private const SETUP_FILE = 'g7-setup.php';

    /** 압축 풀기 파일 머리 표지 — 이 두 글이 모두 있어야 그누보드7 압축 풀기 파일로 본다 */
    private const SETUP_SIGNATURES = ['G7 압축 풀기 부트스트랩', 'G7_SETUP_STATE_FILE'];

    /** 표지를 찾을 머리 길이(바이트) */
    private const SETUP_HEAD_BYTES = 8192;

    /**
     * @param  string|null  $basePath  설치 루트 (null 이면 base_path() — 테스트가 임시 루트를 주입한다)
     * @param  string|null  $pendingRoot  코어 업데이트 대기 루트 (null 이면 config('app.update.pending_path'))
     */
    public function __construct(
        private readonly ?string $basePath = null,
        private readonly ?string $pendingRoot = null,
    ) {}

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'ReconcileRootFilesPlacedByLegacyUpdate';
    }

    /**
     * 루트 압축 풀기 파일을 거두고 빠진 루트 보호 규칙을 놓습니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        $root = rtrim($this->basePath ?? base_path(), '/\\');

        $this->removeSetupFile($root, $context);
        $this->placeRootHtaccess($root, $context);
    }

    /**
     * 설치가 끝난 사이트의 루트 압축 풀기 파일을 지웁니다.
     *
     * @param  string  $root  설치 루트
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    private function removeSetupFile(string $root, UpgradeContext $context): void
    {
        $setup = $root.'/'.self::SETUP_FILE;

        if (is_link($setup) || ! is_file($setup)) {
            $context->logger->info('[core:7.2.0] 루트 g7-setup.php 없음 — 정리할 것 없음');

            return;
        }

        if (! $this->installationCompleted($root)) {
            $context->logger->info('[core:7.2.0] 설치 완료 표식이 없어 루트 g7-setup.php 를 그대로 둠');

            return;
        }

        $head = (string) @file_get_contents($setup, false, null, 0, self::SETUP_HEAD_BYTES);

        foreach (self::SETUP_SIGNATURES as $signature) {
            if (! str_contains($head, $signature)) {
                $context->logger->info('[core:7.2.0] 루트 g7-setup.php 가 그누보드7 압축 풀기 파일이 아님 — 운영자 파일로 보고 둠');

                return;
            }
        }

        if (! @unlink($setup)) {
            $context->logger->warning('[core:7.2.0] 설치가 끝난 사이트의 루트 g7-setup.php 삭제 실패 (권한 확인) — 이 파일은 설치가 끝난 사이트에서 동작하지 않으므로 직접 지워도 됩니다');

            return;
        }

        $context->logger->info('[core:7.2.0] 7.1.x 업데이트가 놓은 루트 g7-setup.php 를 지움 (설치가 끝난 사이트)');
    }

    /**
     * 루트 `index.php` 만 있고 `.htaccess` 가 없으면 배포본의 루트 `.htaccess` 를 놓습니다.
     *
     * @param  string  $root  설치 루트
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    private function placeRootHtaccess(string $root, UpgradeContext $context): void
    {
        $index = $root.'/index.php';
        $htaccess = $root.'/.htaccess';

        if (! is_file($index) || file_exists($htaccess) || is_link($htaccess)) {
            $context->logger->info('[core:7.2.0] 루트 index.php 가 없거나 루트 .htaccess 가 이미 있음 — 루트 보호 규칙 배치 생략');

            return;
        }

        $source = $this->findReleaseHtaccess((string) @md5_file($index));

        if ($source === null) {
            $context->logger->warning('[core:7.2.0] 루트 index.php 는 있는데 루트 .htaccess 가 없음 — 배포본의 루트 .htaccess 를 프로젝트 루트에 올리세요 (문서 루트가 public/ 이면 영향 없음)');

            return;
        }

        if (! @copy($source, $htaccess)) {
            $context->logger->warning('[core:7.2.0] 루트 .htaccess 배치 실패 (권한 확인) — 배포본의 루트 .htaccess 를 프로젝트 루트에 올리세요', [
                'source' => $source,
            ]);

            return;
        }

        $this->matchDirectoryOwnership($htaccess, $root);
        $context->logger->info('[core:7.2.0] 7.1.x 업데이트가 빠뜨린 루트 .htaccess 를 배포본에서 배치', ['source' => $source]);
    }

    /**
     * 루트 `index.php` 가 지금 파일과 같은 배포본 압축 해제본의 `.htaccess` 경로를 찾습니다.
     *
     * 7.1.x 의 배포본 소스 위치는 `{pending}/core_{시각}/` (`--source`), `…/local_source/` (`--local`),
     * `…/extracted/` 또는 그 아래 단일 래퍼 디렉토리(ZIP·GitHub) 중 하나다.
     *
     * @param  string  $indexHash  지금 루트 `index.php` 의 md5
     * @return string|null 배포본 `.htaccess` 경로 (없으면 null)
     */
    private function findReleaseHtaccess(string $indexHash): ?string
    {
        if ($indexHash === '') {
            return null;
        }

        $pendingRoot = rtrim($this->pendingRoot ?? (string) config('app.update.pending_path', storage_path('app/core_pending')), '/\\');

        if ($pendingRoot === '' || ! is_dir($pendingRoot)) {
            return null;
        }

        $stages = array_filter(
            scandir($pendingRoot) ?: [],
            static fn (string $name): bool => str_starts_with($name, 'core_') && is_dir($pendingRoot.'/'.$name),
        );
        rsort($stages, SORT_STRING);

        foreach ($stages as $stage) {
            $stagePath = $pendingRoot.'/'.$stage;
            $candidates = [$stagePath, $stagePath.'/local_source', $stagePath.'/extracted'];

            foreach (glob($stagePath.'/extracted/*', GLOB_ONLYDIR) ?: [] as $wrapped) {
                $candidates[] = $wrapped;
            }

            foreach ($candidates as $candidate) {
                $releaseIndex = $candidate.'/index.php';
                $releaseHtaccess = $candidate.'/.htaccess';

                if (is_file($releaseIndex) && is_file($releaseHtaccess) && @md5_file($releaseIndex) === $indexHash) {
                    return $releaseHtaccess;
                }
            }
        }

        return null;
    }

    /**
     * 설치가 끝난 사이트인지 판정합니다 (`g7-setup.php`·`public/install/api/_guard.php` 와 같은 규칙).
     *
     * @param  string  $root  설치 루트
     * @return bool 설치 완료면 true
     */
    private function installationCompleted(string $root): bool
    {
        if (is_file($root.'/storage/app/g7_installed')) {
            return true;
        }

        $raw = is_file($root.'/.env') ? @file_get_contents($root.'/.env') : false;

        if (! is_string($raw) || preg_match_all('/^[ \t]*INSTALLER_COMPLETED[ \t]*=(.*)$/m', $raw, $matches) < 1) {
            return false;
        }

        $flag = strtolower(trim(trim((string) end($matches[1])), "\"'"));

        return in_array($flag, ['true', '1', 'yes'], true);
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
}
