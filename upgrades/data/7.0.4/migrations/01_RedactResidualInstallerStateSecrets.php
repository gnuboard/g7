<?php

namespace App\Upgrades\Data\V7_0_4\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\Log;

/**
 * 7.0.3 이하 인스톨러가 남긴 storage/installer-state.json 의 평문 비밀 정리 (이슈 #465).
 *
 * 결함:
 *   설치 중 관리자 비밀번호(admin_password / admin_password_confirm) 와 DB 비밀번호가
 *   state.json(0664) 에 평문으로 기록되었다. 정상 완료 시 finalize-env.php 가 파일을
 *   삭제하지만, finalize 미호출/삭제 실패/설치 중단 환경에서는 평문이 무기한 잔존한다.
 *
 * 본 마이그레이션의 동작:
 *
 *   | 조건                                    | 동작                              |
 *   |-----------------------------------------|-----------------------------------|
 *   | state.json 부재                         | no-op                             |
 *   | JSON 파싱 실패                          | 파일 보존 + warning (파괴 금지)   |
 *   | 설치 완료 증거 있음                     | 파일 삭제 (실패 시 redact 폴백)   |
 *   | 완료 증거 없음 (설치 진행 중일 수 있음) | 파일 보존 + 비밀만 redact         |
 *
 *   "설치 완료 증거" = `.env` 의 INSTALLER_COMPLETED truthy 또는 storage/app/g7_installed 존재.
 *
 * 멱등: 재실행 시 이미 삭제/redact 된 상태에서 동일 결과.
 *
 * 권한 정책 (Upgrade Step 은 CLI 컨텍스트 — PHP-FPM 이 아님):
 *   chmod/chown 을 시도하지 않는다. redact 재저장은 file_put_contents 로 수행하여 기존
 *   inode 의 권한·소유자를 그대로 보존한다 (인스톨러의 tmp+rename+chmod 0664 패턴을
 *   복제하면 CLI 사용자 소유의 새 inode 가 생겨 웹 서버가 읽지 못할 수 있다).
 *
 * 격리 원칙 (docs/extension/upgrade-step-guide.md §12):
 *   인스톨러 파일(installer-state.php / config.php / functions.php) 을 require 하지 않는다
 *   (BASE_PATH 상수 충돌 + V-1 안전 격리). 비밀 키 목록, state 파싱/저장, INSTALLER_COMPLETED
 *   판정을 본 클래스 안에 로컬 인라인 중복 구현한다.
 *
 * beta.7 의 01_FinalizeOrphanedInstallerRuntime 과의 관계:
 *   그 스텝은 runtime.php 잔존이 트리거라 "runtime 은 지워지고 state.json 만 남은" 케이스를
 *   잡지 못한다. 본 스텝이 그 사각을 커버한다 (beta.7 스텝은 동결 — 무수정).
 */
final class RedactResidualInstallerStateSecrets implements DataMigration
{
    private const STATE_JSON_RELATIVE = 'storage/installer-state.json';

    private const INSTALLED_FLAG_RELATIVE = 'storage/app/g7_installed';

    /**
     * state.config 에서 제거 대상인 비밀 키 (installer-state.php 의
     * installerSecretConfigKeys() 와 동일 — 격리를 위해 로컬 중복 정의).
     */
    private const SECRET_CONFIG_KEYS = [
        'db_write_password',
        'db_read_password',
        'admin_password',
        'admin_password_confirm',
    ];

    public function name(): string
    {
        return 'RedactResidualInstallerStateSecrets';
    }

    public function run(UpgradeContext $context): void
    {
        try {
            $this->runInternal($context);
        } catch (\Throwable $e) {
            // 업그레이드 중단 금지 — 잔존 파일 정리 실패가 업그레이드 전체를 막지 않는다.
            $context->logger->warning(sprintf(
                '[7.0.4] RedactResidualInstallerStateSecrets 실패 (state.json 보존, 계속 진행): %s',
                $e->getMessage(),
            ));
            Log::warning('7.0.4 RedactResidualInstallerStateSecrets 실패', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    private function runInternal(UpgradeContext $context): void
    {
        $basePath = base_path();
        $statePath = $basePath.DIRECTORY_SEPARATOR.self::STATE_JSON_RELATIVE;

        if (! is_file($statePath)) {
            $context->logger->info('[7.0.4] installer-state.json 부재 — 정리 대상 없음, skip');

            return;
        }

        $state = $this->readStateJson($statePath);
        if ($state === null) {
            $context->logger->warning(
                '[7.0.4] installer-state.json 파싱 실패 — 파일 보존 (파괴 금지). '
                .'평문 비밀이 남아 있을 수 있으니 수동 검토 후 삭제 권장: '.$statePath
            );

            return;
        }

        if ($this->installationIsComplete($basePath)) {
            if (@unlink($statePath)) {
                $context->logger->info('[7.0.4] 설치 완료 확인 — 잔존 installer-state.json 삭제 완료');

                return;
            }

            $context->logger->warning(sprintf(
                '[7.0.4] installer-state.json 삭제 실패 (%s) — 비밀 필드 redact 로 폴백',
                error_get_last()['message'] ?? 'unknown',
            ));

            $this->redactAndSave($statePath, $state, $context);

            return;
        }

        // 완료 증거 없음 — 설치가 진행 중이거나 중단된 상태일 수 있으므로 파일을 삭제하면
        // 진행 중인 설치를 파괴한다. 비밀만 제거하고 파일은 보존한다.
        $context->logger->info('[7.0.4] 설치 완료 증거 없음 — 파일 보존, 비밀 필드만 redact');
        $this->redactAndSave($statePath, $state, $context);
    }

    /**
     * state 배열에서 비밀 4종을 제거한 뒤 재저장한다.
     *
     * 이미 비밀이 없으면 쓰기를 건너뛴다 (멱등 + 불필요한 mtime 갱신 회피).
     *
     * @param  array<string, mixed>  $state
     */
    private function redactAndSave(string $statePath, array $state, UpgradeContext $context): void
    {
        if (! isset($state['config']) || ! is_array($state['config'])) {
            $context->logger->info('[7.0.4] installer-state.json 에 config 섹션 없음 — redact 불필요');

            return;
        }

        $removed = [];
        foreach (self::SECRET_CONFIG_KEYS as $key) {
            if (array_key_exists($key, $state['config'])) {
                unset($state['config'][$key]);
                $removed[] = $key;
            }
        }

        if ($removed === []) {
            $context->logger->info('[7.0.4] installer-state.json 에 잔존 비밀 없음 — redact 불필요 (멱등)');

            return;
        }

        $encoded = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            $context->logger->warning('[7.0.4] installer-state.json 재인코딩 실패 — 파일 보존');

            return;
        }

        // file_put_contents — 기존 inode 의 권한·소유자를 그대로 유지 (CLI 컨텍스트에서
        // tmp+rename 하면 새 inode 가 CLI 사용자 소유가 되어 웹 서버 읽기가 깨질 수 있다).
        if (@file_put_contents($statePath, $encoded, LOCK_EX) === false) {
            $context->logger->warning(sprintf(
                '[7.0.4] installer-state.json redact 재저장 실패 (%s) — 평문 잔존. 수동 삭제 권장: %s',
                error_get_last()['message'] ?? 'unknown',
                $statePath,
            ));

            return;
        }

        $context->logger->info(sprintf(
            '[7.0.4] installer-state.json 비밀 필드 redact 완료 — 제거된 키: %s',
            implode(', ', $removed),
        ));
    }

    /**
     * state.json 을 읽어 배열로 반환. 파싱 실패 시 null.
     *
     * @return array<string, mixed>|null
     */
    private function readStateJson(string $statePath): ?array
    {
        $content = @file_get_contents($statePath);
        if ($content === false) {
            return null;
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * 설치 완료 증거 판정.
     *
     * `.env` 의 INSTALLER_COMPLETED 가 truthy 이거나 storage/app/g7_installed 가 존재하면
     * 설치가 끝난 것으로 본다 (installer-state.php 의 isInstallationCompleted() 와 동일 신호).
     */
    private function installationIsComplete(string $basePath): bool
    {
        if (is_file($basePath.DIRECTORY_SEPARATOR.self::INSTALLED_FLAG_RELATIVE)) {
            return true;
        }

        return $this->envInstallerCompletedIsTrue($basePath.DIRECTORY_SEPARATOR.'.env');
    }

    /**
     * `.env` 의 INSTALLER_COMPLETED 가 truthy 값인지 판정.
     *
     * `_guard.php` 의 installer_finalize_is_completed() 와 동일 정책.
     */
    private function envInstallerCompletedIsTrue(string $envPath): bool
    {
        if (! is_file($envPath)) {
            return false;
        }

        $parsed = @parse_ini_file($envPath, false, INI_SCANNER_RAW);
        if (! is_array($parsed)) {
            return false;
        }

        $flag = strtolower(trim((string) ($parsed['INSTALLER_COMPLETED'] ?? '')));
        $flag = trim($flag, "\"'");

        return in_array($flag, ['true', '1', 'yes'], true);
    }
}
