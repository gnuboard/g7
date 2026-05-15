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

    /**
     * `.env` 가 새로 생성될 때 적용할 기본 권한 (소유자 rw + 그룹 r).
     *
     * 0600 은 PHP-FPM 사용자(www-data) 가 CLI 사용자(jjh/root) 와 다른 운영 환경에서
     * 읽기 차단을 일으키므로 사용 금지. 0640 은 그룹 멤버에게만 읽기 허용 — 그룹을
     * 웹 서버 그룹으로 맞추면 비밀번호 노출 없이 웹 서버가 읽기 가능.
     */
    private const DEFAULT_ENV_PERMISSIONS = 0640;

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

        // 머지 *전에* .env 의 기존 권한/소유자/그룹 스냅샷을 떠둔다 — 머지 후 그대로 복원해
        // CLI 실행 사용자(jjh/root) 와 PHP-FPM 사용자(www-data) 가 다른 환경에서도
        // PHP-FPM 이 계속 .env 를 읽을 수 있도록 한다.
        $envExisted = is_file($envPath);
        $preservedStat = $envExisted ? $this->snapshotFileStat($envPath) : null;

        $merged = $this->mergeRuntimeIntoEnv($envBase, $runtime);

        if (@file_put_contents($envPath, $merged, LOCK_EX) === false) {
            $context->logger->error('[7.0.0-beta.7] .env 쓰기 실패 — runtime.php 보존');

            return;
        }

        $this->applyEnvPermissions($envPath, $preservedStat, $basePath, $context);

        // 부모 프로세스의 process ENV 도 머지된 자격증명으로 갱신 (이슈 #371 후속 회귀 차단)
        //
        // 배경: beta.4~6 손상 환경에서는 .env 가 .env.example 그대로 (DB_WRITE_USERNAME=root,
        // DB_WRITE_PASSWORD= 등) 이고 자격증명은 runtime.php 에 잔존. 부모 프로세스 부팅
        // 시점에 Dotenv 가 stale .env 의 값을 process ENV (getenv() / $_ENV / $_SERVER)
        // 에 적재한 상태이며, Config 메모리만 InstallerRuntimeServiceProvider 가 정상값으로
        // 주입해두었기 때문에 부모 자체는 정상 동작한다.
        //
        // 이 시점에 본 마이그레이션이 .env 머지 + runtime.php 삭제를 수행해도 부모의 process
        // ENV 는 stale 한 채 유지된다. 그 직후 BundledExtensionUpdatePrompt 의 번들 일괄
        // 업데이트가 proc_open 으로 spawn 자식을 띄울 때 5번째 인자에 array_merge(getenv(),
        // $_ENV) 를 전달하므로 자식이 stale 값(root / 빈 비밀번호)을 그대로 상속받는다.
        // 자식의 Laravel 부팅 시 Dotenv::createImmutable() 은 이미 채워진 ENV 를 덮어쓰지
        // 않으므로 자식이 머지된 .env 의 정상 값을 보지 못하고 root@localhost (using
        // password: YES — 환경에 따라 다른 stale 비밀번호) 로 DB 연결 시도 → Access denied.
        //
        // beta.8 이후 정상 .env 환경에서는 부팅 시점부터 process ENV 가 올바르므로 본
        // 보정 로직이 트리거되어도 동일 값으로 재기록되는 멱등 동작이며 부작용이 없다.
        $this->refreshProcessEnvFromRuntime($runtime);

        @unlink($runtimePath);

        if (defined('DELETE_INSTALLER_AFTER_COMPLETE') && DELETE_INSTALLER_AFTER_COMPLETE) {
            $stateFilePath = $basePath.DIRECTORY_SEPARATOR.self::STATE_JSON_RELATIVE;
            if (is_file($stateFilePath)) {
                @unlink($stateFilePath);
            }
        }

        // 본 PR 부수효과 정리 — beta.7 업그레이드 도중 자식 spawn 프로세스 (root 권한)
        // 가 부팅하면서 InstallerRuntimeServiceProvider 의 recover 가 발화 → Config
        // 보정 → CoreServiceProvider DB 가드 통과 → loadModules() → CachesModuleStatus
        // 의 캐시 쓰기 흐름이 진행되어, storage/framework/cache/data/<hash>/ 디렉토리가
        // root:root 권한으로 생성됨. 이후 일반 PHP-FPM 워커(www-data 그룹)가 그
        // 디렉토리에 캐시 파일 쓰기 시도 시 Permission denied → 사이트 500 회귀.
        //
        // 정상 운영 디렉토리 (storage/, bootstrap/cache 등) 의 소유자/그룹을 기준으로
        // storage/framework/cache/data/ 하위의 root 소유 항목만 일괄 chown.
        $this->normalizeCachePermissions($basePath, $context);

        $context->logger->info(
            '[7.0.0-beta.7] 잔존 runtime.php 자동 finalize 완료 — .env 머지 + runtime.php 삭제 + state.json 정리'
        );
    }

    /**
     * beta.7 업그레이드 부수효과 보정 — storage/framework/cache/data/ 하위의 root 소유
     * 디렉토리/파일을 정상 소유자로 chown.
     *
     * 정상 소유자/그룹 결정:
     *   - 1순위: storage/framework/cache/data/ 자신의 owner/group (이미 정상값을 보유)
     *   - 2순위: bootstrap/cache/ 의 owner/group
     *   - 3순위: 그 외 — skip (안전한 fallback 없으면 강제 변경 안 함)
     *
     * @param  string  $basePath  프로젝트 base path
     */
    private function normalizeCachePermissions(string $basePath, UpgradeContext $context): void
    {
        if (! function_exists('chown') || ! function_exists('posix_geteuid')) {
            return;
        }

        $cacheRoot = $basePath.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'data';
        if (! is_dir($cacheRoot)) {
            return;
        }

        // 정상 owner/group 추정
        [$targetUid, $targetGid] = $this->inferCacheOwnership($basePath, $cacheRoot);
        if ($targetUid === null || $targetGid === null) {
            $context->logger->info('[7.0.0-beta.7] 캐시 권한 보정 skip — 정상 owner/group 추정 실패');

            return;
        }

        $changed = 0;
        $failed = 0;
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($cacheRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iter as $entry) {
            /** @var \SplFileInfo $entry */
            $path = $entry->getPathname();
            $currentUid = @fileowner($path);
            $currentGid = @filegroup($path);
            // 이미 정상이면 skip
            if ($currentUid === $targetUid && $currentGid === $targetGid) {
                continue;
            }
            // root (uid=0) 소유 항목만 보정 — 사용자가 의도해서 만든 다른 소유자는 보존
            if ($currentUid !== 0 && $currentGid !== 0) {
                continue;
            }
            $okOwner = @chown($path, $targetUid);
            $okGroup = @chgrp($path, $targetGid);
            if ($okOwner && $okGroup) {
                $changed++;
            } else {
                $failed++;
            }
        }

        $context->logger->info(sprintf(
            '[7.0.0-beta.7] 캐시 권한 보정 완료 — root 소유 항목 changed=%d failed=%d (uid=%d gid=%d)',
            $changed,
            $failed,
            $targetUid,
            $targetGid,
        ));
    }

    /**
     * 캐시 디렉토리의 정상 owner/group 추정.
     *
     * @return array{0:int|null, 1:int|null}
     */
    private function inferCacheOwnership(string $basePath, string $cacheRoot): array
    {
        // 1순위: cache/data 자신의 owner/group (chown 대상 자식이 root 라도 부모는 정상값일 가능성)
        $uid = @fileowner($cacheRoot);
        $gid = @filegroup($cacheRoot);
        if (is_int($uid) && $uid !== 0 && is_int($gid)) {
            return [$uid, $gid];
        }

        // 2순위: bootstrap/cache
        $bootstrapCache = $basePath.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache';
        if (is_dir($bootstrapCache)) {
            $uid = @fileowner($bootstrapCache);
            $gid = @filegroup($bootstrapCache);
            if (is_int($uid) && $uid !== 0 && is_int($gid)) {
                return [$uid, $gid];
            }
        }

        // 3순위 fallback 없음
        return [null, null];
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

    /**
     * 부모 프로세스의 process ENV 를 runtime 자격증명으로 갱신.
     *
     * getenv() / $_ENV / $_SERVER 3곳 모두 갱신 — proc_open 의 ENV 합집합 전파와
     * Dotenv::createImmutable() 의 덮어쓰기 차단 정책 모두에 대응.
     *
     * 트리거 가드는 호출자에서 이미 보장됨:
     *   - runtime.php 부재 (beta.3 정상 환경 포함) → runInternal 진입 직후 return
     *   - .env 의 INSTALLER_COMPLETED=true (이미 finalize 완료) → 별도 return
     *   - 따라서 본 메서드 도달 = beta.4~6 손상 환경의 머지 성공 분기
     *
     * 본 메서드 자체도 빈 값 가드: runtime 의 username/password 키가 부재하거나
     * 빈 문자열이면 그 키에 대한 ENV 갱신을 skip 하여 정상 ENV 를 빈 값으로
     * 덮어쓰는 회귀를 차단.
     *
     * @param  array<string, mixed>  $runtime
     */
    private function refreshProcessEnvFromRuntime(array $runtime): void
    {
        $write = $runtime['db']['write'] ?? null;
        if (! is_array($write)) {
            return;
        }
        $read = $runtime['db']['read'] ?? $write;
        if (! is_array($read)) {
            $read = $write;
        }

        $pairs = [
            'DB_WRITE_HOST' => $write['host'] ?? null,
            'DB_WRITE_PORT' => $write['port'] ?? null,
            'DB_WRITE_DATABASE' => $write['database'] ?? null,
            'DB_WRITE_USERNAME' => $write['username'] ?? null,
            'DB_WRITE_PASSWORD' => $write['password'] ?? null,
            'DB_READ_HOST' => $read['host'] ?? null,
            'DB_READ_PORT' => $read['port'] ?? null,
            'DB_READ_DATABASE' => $read['database'] ?? null,
            'DB_READ_USERNAME' => $read['username'] ?? null,
            'DB_READ_PASSWORD' => $read['password'] ?? null,
        ];

        if (isset($runtime['db']['prefix'])) {
            $pairs['DB_PREFIX'] = (string) $runtime['db']['prefix'];
        }
        $appKey = $runtime['app']['key'] ?? null;
        if (is_string($appKey) && str_starts_with($appKey, 'base64:')) {
            $pairs['APP_KEY'] = $appKey;
        }

        foreach ($pairs as $key => $value) {
            // null / 빈 문자열은 skip — 정상 ENV 를 빈 값으로 덮어쓰는 회귀 차단
            // (단, 비밀번호는 의도적으로 빈 문자열일 수 있으므로 password 만 예외 허용)
            if ($value === null) {
                continue;
            }
            $stringValue = (string) $value;
            if ($stringValue === '' && ! in_array($key, ['DB_WRITE_PASSWORD', 'DB_READ_PASSWORD', 'DB_PREFIX'], true)) {
                continue;
            }

            putenv($key.'='.$stringValue);
            $_ENV[$key] = $stringValue;
            $_SERVER[$key] = $stringValue;
        }
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

    // ---------------------------------------------------------------------
    // 권한·소유자·그룹 자동 조정 — 코어/인스톨러 헬퍼 비의존 (V-1 안전 격리)
    // ---------------------------------------------------------------------

    /**
     * 파일의 권한/소유자/그룹을 스냅샷.
     *
     * @return array{mode:int|null, uid:int|null, gid:int|null}|null 파일 부재/stat 실패 시 null
     */
    private function snapshotFileStat(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }
        $perms = @fileperms($path);
        $uid = @fileowner($path);
        $gid = @filegroup($path);

        if ($perms === false && $uid === false && $gid === false) {
            return null;
        }

        return [
            'mode' => $perms === false ? null : ($perms & 0777),
            'uid' => $uid === false ? null : $uid,
            'gid' => $gid === false ? null : $gid,
        ];
    }

    /**
     * 머지된 .env 에 적절한 권한·소유자·그룹을 적용.
     *
     * 정책:
     *   1. 기존 .env 가 있었으면 스냅샷 그대로 복원 — 운영자가 의도해서 설정한 권한 보존
     *      (PHP-FPM 사용자가 그룹으로 묶여 있는 자체 구축 환경 / 단일 사용자 카페24 환경
     *      모두 자동 보존)
     *   2. 신규 생성된 경우 → 웹 서버 컨텍스트 (소유자/그룹) 를 추정하여 적용
     *      - 추정: bootstrap/cache/ 의 소유자/그룹 (PHP-FPM 이 쓰는 영역)
     *      - 권한: 0640 (소유자 rw + 그룹 r) — 비밀번호 노출 차단 + 웹 서버 그룹 읽기 허용
     *
     * 모든 chown/chgrp/chmod 호출은 실패해도 진행 (운영자가 수동 보정 가능하도록 로그 안내).
     *
     * @param  array{mode:int|null, uid:int|null, gid:int|null}|null  $preservedStat
     *         머지 직전 .env 가 존재했다면 그 stat. 부재였으면 null.
     */
    private function applyEnvPermissions(
        string $envPath,
        ?array $preservedStat,
        string $basePath,
        UpgradeContext $context,
    ): void {
        if ($preservedStat !== null) {
            // 기존 .env 의 권한/소유자/그룹 복원
            $this->restoreFileStat($envPath, $preservedStat, $context);

            return;
        }

        // 신규 생성 .env — 웹 서버 컨텍스트 추정 + 안전한 기본 권한 적용
        $webContext = $this->detectWebServerContext($basePath);
        $processUser = $this->describeProcessUser();

        $context->logger->info(sprintf(
            '[7.0.0-beta.7] .env 신규 생성 시작 — 프로세스 사용자=%s, 웹 서버 컨텍스트 추정: uid=%s, gid=%s',
            $processUser,
            $webContext['uid'] !== null ? (string) $webContext['uid'] : 'unknown',
            $webContext['gid'] !== null ? (string) $webContext['gid'] : 'unknown',
        ));

        if ($webContext['uid'] !== null) {
            $this->tryChown($envPath, $webContext['uid'], $context);
        } else {
            $context->logger->info('[7.0.0-beta.7] chown 시도 skip — 웹 서버 사용자 추정 실패 (bootstrap/cache, storage/logs 모두 신호 없음)');
        }
        if ($webContext['gid'] !== null) {
            $this->tryChgrp($envPath, $webContext['gid'], $context);
        } else {
            $context->logger->info('[7.0.0-beta.7] chgrp 시도 skip — 웹 서버 그룹 추정 실패');
        }

        $chmodResult = @chmod($envPath, self::DEFAULT_ENV_PERMISSIONS);
        $actualMode = fileperms($envPath) & 0777;

        if ($chmodResult === false) {
            $lastError = error_get_last();
            $context->logger->warning(sprintf(
                '[7.0.0-beta.7] chmod(.env, %s) FAILED — 실제 권한: %s, 프로세스=%s, last_error=%s. '
                .'운영자 수동 보정: chmod %s %s',
                decoct(self::DEFAULT_ENV_PERMISSIONS),
                decoct($actualMode),
                $processUser,
                $lastError['message'] ?? 'unknown',
                decoct(self::DEFAULT_ENV_PERMISSIONS),
                $envPath,
            ));
        } else {
            $context->logger->info(sprintf(
                '[7.0.0-beta.7] chmod(.env, %s) 성공 — 실제 권한: %s, 프로세스=%s',
                decoct(self::DEFAULT_ENV_PERMISSIONS),
                decoct($actualMode),
                $processUser,
            ));
        }

        $context->logger->info(sprintf(
            '[7.0.0-beta.7] .env 신규 생성 완료 — 최종 mode=%s, owner_uid=%s, group_gid=%s',
            decoct($actualMode),
            (string) (@fileowner($envPath) ?: 'unknown'),
            (string) (@filegroup($envPath) ?: 'unknown'),
        ));
    }

    /**
     * 스냅샷 stat 을 파일에 그대로 복원.
     *
     * 우선순위: chmod → chown → chgrp. chown 은 root 권한 필요할 수 있어 실패 흔함 — 실패해도 진행.
     */
    private function restoreFileStat(string $path, array $stat, UpgradeContext $context): void
    {
        $processUser = $this->describeProcessUser();

        if ($stat['mode'] !== null) {
            $chmodResult = @chmod($path, $stat['mode']);
            $actualMode = fileperms($path) & 0777;
            if ($chmodResult === false) {
                $lastError = error_get_last();
                $context->logger->warning(sprintf(
                    '[7.0.0-beta.7] chmod(%s, %s) FAILED — 실제 권한: %s, 프로세스=%s, last_error=%s',
                    basename($path),
                    decoct($stat['mode']),
                    decoct($actualMode),
                    $processUser,
                    $lastError['message'] ?? 'unknown',
                ));
            }
        }
        if ($stat['uid'] !== null) {
            $this->tryChown($path, $stat['uid'], $context);
        }
        if ($stat['gid'] !== null) {
            $this->tryChgrp($path, $stat['gid'], $context);
        }

        $context->logger->info(sprintf(
            '[7.0.0-beta.7] .env 기존 권한 보존 시도 — 요청 mode=%s, uid=%s, gid=%s / 실제 mode=%s, uid=%s, gid=%s, 프로세스=%s',
            $stat['mode'] !== null ? decoct($stat['mode']) : 'unchanged',
            $stat['uid'] !== null ? (string) $stat['uid'] : 'unchanged',
            $stat['gid'] !== null ? (string) $stat['gid'] : 'unchanged',
            decoct(fileperms($path) & 0777),
            (string) (@fileowner($path) ?: 'unknown'),
            (string) (@filegroup($path) ?: 'unknown'),
            $processUser,
        ));
    }

    /**
     * 현재 프로세스 사용자 ("name(uid)") 를 문자열로 반환 — 로그 가독성용.
     */
    private function describeProcessUser(): string
    {
        if (! function_exists('posix_geteuid')) {
            return 'unknown(no-posix)';
        }
        $euid = posix_geteuid();
        $name = function_exists('posix_getpwuid') ? (posix_getpwuid($euid)['name'] ?? null) : null;

        return ($name ?? 'uid') . '(' . $euid . ')';
    }

    /**
     * 웹 서버 컨텍스트 (PHP-FPM/Apache 가 쓰는 사용자/그룹) 를 디스크 신호로 추정.
     *
     * `getWebServerUser()` 같은 인스톨러 헬퍼 (현재 PHP 프로세스 = web user 가정) 는
     * CLI 컨텍스트에서 동작하지 않으므로 사용 금지. 대신 PHP-FPM 이 실제로 *쓴 파일*
     * 의 소유자/그룹을 신호로 사용.
     *
     * 우선순위:
     *   1. bootstrap/cache/*.php (services.php / packages.php) — Laravel 부팅 시 PHP-FPM
     *      이 직접 쓰는 파일. 가장 신뢰도 높음
     *   2. bootstrap/cache/ 디렉토리 자체
     *   3. storage/logs/ 디렉토리 (PHP-FPM 의 또 다른 쓰기 영역)
     *   4. 모두 실패 시 null — 호출자가 chown/chgrp skip
     *
     * @return array{uid:int|null, gid:int|null}
     */
    private function detectWebServerContext(string $basePath): array
    {
        $candidates = [
            $basePath.DIRECTORY_SEPARATOR.'bootstrap/cache/services.php',
            $basePath.DIRECTORY_SEPARATOR.'bootstrap/cache/packages.php',
            $basePath.DIRECTORY_SEPARATOR.'bootstrap/cache',
            $basePath.DIRECTORY_SEPARATOR.'storage/logs',
        ];

        foreach ($candidates as $candidate) {
            if (! file_exists($candidate)) {
                continue;
            }
            $uid = @fileowner($candidate);
            $gid = @filegroup($candidate);
            if ($uid === false && $gid === false) {
                continue;
            }

            // root (uid=0) 단독 신호는 회피 — sudo 로 캐시가 생성된 환경일 수 있음.
            // 그룹은 root 라도 사용 가능 (그룹이 web user group 인 경우 흔함).
            if ($uid === 0 && $gid !== false && $gid !== 0) {
                return ['uid' => null, 'gid' => $gid];
            }

            return [
                'uid' => $uid === false ? null : $uid,
                'gid' => $gid === false ? null : $gid,
            ];
        }

        return ['uid' => null, 'gid' => null];
    }

    /**
     * chown 시도. 시도/성공/실패 모두 로그에 명시.
     */
    private function tryChown(string $path, int $uid, UpgradeContext $context): void
    {
        if (! function_exists('chown')) {
            $context->logger->info('[7.0.0-beta.7] chown skip — chown() 함수 사용 불가 (PHP 빌드 옵션)');

            return;
        }
        $beforeUid = @fileowner($path);
        $result = @chown($path, $uid);
        $afterUid = @fileowner($path);

        if ($result === false) {
            $lastError = error_get_last();
            $context->logger->info(sprintf(
                '[7.0.0-beta.7] chown(%s, uid=%d) FAILED — before=%s, after=%s, 프로세스=%s, last_error=%s. '
                .'원인 추정: 비-root 사용자는 다른 사용자로 chown 불가. 운영자 수동 보정: sudo chown %d %s',
                basename($path),
                $uid,
                $beforeUid === false ? 'unknown' : (string) $beforeUid,
                $afterUid === false ? 'unknown' : (string) $afterUid,
                $this->describeProcessUser(),
                $lastError['message'] ?? 'unknown',
                $uid,
                $path,
            ));
        } else {
            $context->logger->info(sprintf(
                '[7.0.0-beta.7] chown(%s, uid=%d) 성공 — before=%s → after=%s',
                basename($path),
                $uid,
                $beforeUid === false ? 'unknown' : (string) $beforeUid,
                $afterUid === false ? 'unknown' : (string) $afterUid,
            ));
        }
    }

    /**
     * chgrp 시도. 시도/성공/실패 모두 로그에 명시.
     */
    private function tryChgrp(string $path, int $gid, UpgradeContext $context): void
    {
        if (! function_exists('chgrp')) {
            $context->logger->info('[7.0.0-beta.7] chgrp skip — chgrp() 함수 사용 불가');

            return;
        }
        $beforeGid = @filegroup($path);
        $result = @chgrp($path, $gid);
        $afterGid = @filegroup($path);

        if ($result === false) {
            $lastError = error_get_last();
            $context->logger->info(sprintf(
                '[7.0.0-beta.7] chgrp(%s, gid=%d) FAILED — before=%s, after=%s, 프로세스=%s, last_error=%s. '
                .'운영자 수동 보정: sudo chgrp %d %s',
                basename($path),
                $gid,
                $beforeGid === false ? 'unknown' : (string) $beforeGid,
                $afterGid === false ? 'unknown' : (string) $afterGid,
                $this->describeProcessUser(),
                $lastError['message'] ?? 'unknown',
                $gid,
                $path,
            ));
        } else {
            $context->logger->info(sprintf(
                '[7.0.0-beta.7] chgrp(%s, gid=%d) 성공 — before=%s → after=%s',
                basename($path),
                $gid,
                $beforeGid === false ? 'unknown' : (string) $beforeGid,
                $afterGid === false ? 'unknown' : (string) $afterGid,
            ));
        }
    }
}
