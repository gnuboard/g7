<?php

namespace App\Upgrades\Data\V7_0_0_beta_7\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\Log;

/**
 * beta.4~beta.6 인스톨러 finalize 자가 차단 결함의 잔존 환경 자동 복구.
 *
 * 결함 (이슈 #371):
 *   finalize-env.php 가 `_guard.php` 의 installer_guard_or_410() 에 의해
 *   `storage/app/g7_installed` 락 파일 존재만으로 410 차단됨. 그 결과 `.env`
 *   머지와 `storage/installer/runtime.php` 삭제가 영구 누락된 상태로 운영 중일
 *   가능성이 있다 (운영 자체는 InstallerRuntimeServiceProvider 의 메모리 주입
 *   폴백으로 정상 동작 — 평문 자격증명이 runtime.php 에 영구 보존되는 안전
 *   폴백 상태).
 *
 * 본 마이그레이션은 그 폴백 상태를 감지하여 finalize 로직을 인라인 수행한다:
 *   1. `.env` 머지 (DB 자격증명 + APP_KEY + INSTALLER_COMPLETED=true)
 *   2. `storage/installer/runtime.php` 삭제
 *   3. `storage/installer-state.json` 삭제 (DELETE_INSTALLER_AFTER_COMPLETE 분기)
 *
 * 멱등성:
 *   - runtime.php 부재 → no-op (이미 정상 finalize 됨)
 *   - .env 의 INSTALLER_COMPLETED=true 인데 runtime.php 잔존 → 자동 삭제 금지,
 *     운영자 수동 검토 안내 (.env 머지는 이미 끝났으나 runtime.php 정합 이상 신호)
 *   - state.json 부재 → unlink 호출 안 함
 *
 * 실패 시 안전 폴백: runtime.php 보존. 운영은 InstallerRuntimeServiceProvider
 * 의 메모리 주입으로 계속 정상 동작.
 *
 * 격리 원칙 (docs/extension/upgrade-step-guide.md §12):
 *   - 외부 헬퍼 (installer-runtime.php) 의 로직을 본 클래스 안에 중복 구현
 *   - functions.php / config.php / installer-state.php require 금지
 *     (BASE_PATH 상수 충돌 위험 + V-1 안전 격리)
 *   - mergeRuntimeIntoEnv 와 동일한 머지 결과를 생성하지만 단순화 (escapeEnvValue
 *     polyfill 만 로컬 보유)
 */
final class FinalizeOrphanedInstallerRuntime implements DataMigration
{
    private const RUNTIME_RELATIVE = 'storage/installer/runtime.php';

    private const STATE_JSON_RELATIVE = 'storage/installer-state.json';

    public function name(): string
    {
        return 'FinalizeOrphanedInstallerRuntime';
    }

    public function run(UpgradeContext $context): void
    {
        try {
            $this->runInternal($context);
        } catch (\Throwable $e) {
            $context->logger->warning(sprintf(
                '[7.0.0-beta.7] FinalizeOrphanedInstallerRuntime 실패 (runtime.php 보존, 계속 진행): %s',
                $e->getMessage(),
            ));
            Log::warning('beta.7 FinalizeOrphanedInstallerRuntime 실패', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    private function runInternal(UpgradeContext $context): void
    {
        $basePath = base_path();
        $runtimePath = $basePath.DIRECTORY_SEPARATOR.self::RUNTIME_RELATIVE;
        $envPath = $basePath.DIRECTORY_SEPARATOR.'.env';
        $envExamplePath = $basePath.DIRECTORY_SEPARATOR.'.env.example';

        if (! is_file($runtimePath)) {
            $context->logger->info('[7.0.0-beta.7] runtime.php 부재 — 정상 finalize 완료 상태로 판정, skip');

            return;
        }

        if ($this->envInstallerCompletedIsTrue($envPath)) {
            $context->logger->warning(sprintf(
                '[7.0.0-beta.7] .env 의 INSTALLER_COMPLETED=true 이지만 %s 가 잔존. '
                .'평문 자격증명 보안 우려 — 수동 검토 후 삭제 권장',
                self::RUNTIME_RELATIVE,
            ));

            return;
        }

        $runtime = $this->readRuntime($runtimePath);
        if ($runtime === null) {
            $context->logger->info('[7.0.0-beta.7] runtime.php 형식 불일치 (배열 아님) — runtime.php 보존, skip');

            return;
        }

        $envBase = $this->loadEnvBase($envPath, $envExamplePath);
        if ($envBase === null) {
            $context->logger->error('[7.0.0-beta.7] .env / .env.example 모두 읽기 실패 — runtime.php 보존');

            return;
        }

        $merged = $this->mergeRuntimeIntoEnv($envBase, $runtime);

        if (@file_put_contents($envPath, $merged, LOCK_EX) === false) {
            $context->logger->error('[7.0.0-beta.7] .env 쓰기 실패 — runtime.php 보존');

            return;
        }

        @chmod($envPath, 0600);

        @unlink($runtimePath);

        if (defined('DELETE_INSTALLER_AFTER_COMPLETE') && DELETE_INSTALLER_AFTER_COMPLETE) {
            $stateFilePath = $basePath.DIRECTORY_SEPARATOR.self::STATE_JSON_RELATIVE;
            if (is_file($stateFilePath)) {
                @unlink($stateFilePath);
            }
        }

        $context->logger->info(
            '[7.0.0-beta.7] 잔존 runtime.php 자동 finalize 완료 — .env 머지 + runtime.php 삭제 + state.json 정리'
        );
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

    /**
     * runtime.php 를 읽어 배열 반환. 부재/형식 불일치 시 null.
     *
     * installer-runtime.php 의 readInstallerRuntime() 와 동일 로직 — 본 클래스
     * 안에 중복 구현하여 BASE_PATH 상수 의존 회피 (V-1 안전 격리).
     */
    private function readRuntime(string $runtimePath): ?array
    {
        if (! is_file($runtimePath)) {
            return null;
        }
        $data = @include $runtimePath;

        return is_array($data) ? $data : null;
    }

    /**
     * `.env` 가 존재하면 그 내용을, 아니면 `.env.example` 을 base 로 반환.
     *
     * 둘 다 없으면 null.
     */
    private function loadEnvBase(string $envPath, string $envExamplePath): ?string
    {
        if (is_file($envPath)) {
            $content = @file_get_contents($envPath);

            return $content === false ? null : $content;
        }
        if (is_file($envExamplePath)) {
            $content = @file_get_contents($envExamplePath);

            return $content === false ? null : $content;
        }

        return null;
    }

    /**
     * runtime 배열을 .env 본문에 머지.
     *
     * installer-runtime.php 의 mergeRuntimeIntoEnv() 와 동일 결과 — escapeEnvValue
     * / replaceEnvLine 을 로컬 private 메서드로 인라인 구현.
     */
    private function mergeRuntimeIntoEnv(string $envContent, array $runtime): string
    {
        $write = $runtime['db']['write'] ?? null;
        if (is_array($write)) {
            $envContent = $this->replaceEnvLine($envContent, 'DB_WRITE_HOST', (string) ($write['host'] ?? ''));
            $envContent = $this->replaceEnvLine($envContent, 'DB_WRITE_PORT', (string) ($write['port'] ?? ''));
            $envContent = $this->replaceEnvLine($envContent, 'DB_WRITE_DATABASE', (string) ($write['database'] ?? ''));
            $envContent = $this->replaceEnvLine($envContent, 'DB_WRITE_USERNAME', (string) ($write['username'] ?? ''));
            $envContent = $this->replaceEnvLine($envContent, 'DB_WRITE_PASSWORD', $this->escapeEnvValue((string) ($write['password'] ?? '')));
        }

        $read = $runtime['db']['read'] ?? $write;
        if (is_array($read)) {
            $envContent = $this->replaceEnvLine($envContent, 'DB_READ_HOST', (string) ($read['host'] ?? ''));
            $envContent = $this->replaceEnvLine($envContent, 'DB_READ_PORT', (string) ($read['port'] ?? ''));
            $envContent = $this->replaceEnvLine($envContent, 'DB_READ_DATABASE', (string) ($read['database'] ?? ''));
            $envContent = $this->replaceEnvLine($envContent, 'DB_READ_USERNAME', (string) ($read['username'] ?? ''));
            $envContent = $this->replaceEnvLine($envContent, 'DB_READ_PASSWORD', $this->escapeEnvValue((string) ($read['password'] ?? '')));
        }

        if (isset($runtime['db']['prefix'])) {
            $envContent = $this->replaceEnvLine($envContent, 'DB_PREFIX', (string) $runtime['db']['prefix']);
        }

        $appKey = $runtime['app']['key'] ?? null;
        if (is_string($appKey) && str_starts_with($appKey, 'base64:')) {
            $envContent = $this->replaceEnvLine($envContent, 'APP_KEY', $appKey);
        }

        if (! preg_match('/^INSTALLER_COMPLETED=/m', $envContent)) {
            $envContent = rtrim($envContent)."\n\n# Installation Status\nINSTALLER_COMPLETED=true\n";
        }

        return $envContent;
    }

    private function escapeEnvValue(string $value): string
    {
        if ($value !== '') {
            $value = str_replace(["\r", "\n"], '', $value);
        }
        if ($value === '') {
            return '""';
        }
        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);

        return '"'.$escaped.'"';
    }

    private function replaceEnvLine(string $envContent, string $key, string $value): string
    {
        $line = $key.'='.$value;
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
        $replaced = preg_replace($pattern, $line, $envContent, 1, $count);

        if ($count === 0) {
            return rtrim($envContent)."\n".$line."\n";
        }

        return $replaced;
    }
}
