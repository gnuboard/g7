<?php

namespace App\Upgrades\Data\V7_0_0_beta_5\Migrations;

use App\Extension\Helpers\FilePermissionHelper;
use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * 4개 도메인의 활성 확장 디렉토리 자동 복구 (modules/plugins/templates/language_packs).
 *
 * beta.4 의 `applyDiscoveredTopLevelPaths` 회귀 (#347) 로 인해 손실된 활성 확장 디렉토리를
 * `_bundled/{identifier}` 기준으로 자동 복구한다.
 *
 * 흐름:
 *   1. DB `status='active'` row 식별자 조회
 *   2. 활성 디렉토리 존재 확인 → 있으면 skip
 *   3. `_bundled/{identifier}` 존재 시 비파괴 복사 (removeOrphans:false)
 *   4. `_bundled` 부재 시 (외부 확장) → 자동 복구 불가 안내 + DB row 단발성 비활성화
 *
 * 멱등성: 활성 디렉토리 존재 시 silent skip. 모든 보정은 비파괴.
 */
final class RecoverActiveExtensionDirs implements DataMigration
{
    /**
     * 도메인별 경로/컬럼 매핑.
     *
     * @var array<string, array{table:string, dir:string, sourceColumn:string}>
     */
    private const DOMAIN_MAP = [
        'modules' => [
            'table' => 'modules',
            'dir' => 'modules',
            'sourceColumn' => 'github_url',
        ],
        'plugins' => [
            'table' => 'plugins',
            'dir' => 'plugins',
            'sourceColumn' => 'github_url',
        ],
        'templates' => [
            'table' => 'templates',
            'dir' => 'templates',
            'sourceColumn' => 'github_url',
        ],
        'language_packs' => [
            'table' => 'language_packs',
            'dir' => 'lang-packs',
            'sourceColumn' => 'source_url',
        ],
    ];

    /**
     * 외부 확장으로 판정된 row 의 `deactivated_reason` raw 값 (단발성 식별자).
     */
    private const DEACTIVATION_REASON_DIR_LOST = 'extension_dir_lost_beta5';

    public function name(): string
    {
        return 'RecoverActiveExtensionDirs';
    }

    public function run(UpgradeContext $context): void
    {
        $context->logger->info('[7.0.0-beta.5] 활성 확장 디렉토리 정합성 검증 시작');

        $totalRestored = 0;
        $totalSkipped = 0;
        $externalLost = [];

        foreach (self::DOMAIN_MAP as $domain => $cfg) {
            $stats = $this->recoverDomain($context, $domain, $cfg);
            $totalRestored += $stats['restored'];
            $totalSkipped += $stats['skipped'];
            $externalLost = array_merge($externalLost, $stats['external_lost']);
        }

        $context->logger->info(sprintf(
            '[7.0.0-beta.5] 활성 확장 디렉토리 복구 완료 — 복원 %d 건, 정상 %d 건, 외부 확장 손실 %d 건',
            $totalRestored,
            $totalSkipped,
            count($externalLost),
        ));

        if (count($externalLost) > 0) {
            $context->logger->warning(sprintf(
                '[7.0.0-beta.5] 외부 확장 %d개 자동 복구 불가 — 운영자 수동 처리 필요. 상세는 위 개별 항목 로그 참조.',
                count($externalLost),
            ));
        }
    }

    /**
     * @param  array{table:string, dir:string, sourceColumn:string}  $cfg
     * @return array{restored:int, skipped:int, external_lost:array<int, string>}
     */
    private function recoverDomain(UpgradeContext $context, string $domain, array $cfg): array
    {
        $stats = ['restored' => 0, 'skipped' => 0, 'external_lost' => []];

        if (! Schema::hasTable($cfg['table'])) {
            $context->logger->info(sprintf('[7.0.0-beta.5] %s 테이블 부재 — 도메인 skip', $cfg['table']));

            return $stats;
        }

        $sourceColumnExists = Schema::hasColumn($cfg['table'], $cfg['sourceColumn']);

        $rows = DB::table($cfg['table'])
            ->where('status', 'active')
            ->get(['identifier', 'version', $sourceColumnExists ? $cfg['sourceColumn'] : DB::raw("NULL as {$cfg['sourceColumn']}")]);

        foreach ($rows as $row) {
            $identifier = $row->identifier;
            $activePath = base_path($cfg['dir'].DIRECTORY_SEPARATOR.$identifier);

            if (File::isDirectory($activePath) && ! $this->isEmptyDirectory($activePath)) {
                $stats['skipped']++;

                continue;
            }

            $bundledPath = base_path($cfg['dir'].DIRECTORY_SEPARATOR.'_bundled'.DIRECTORY_SEPARATOR.$identifier);

            if (File::isDirectory($bundledPath) && ! $this->isEmptyDirectory($bundledPath)) {
                $this->restoreFromBundled($context, $domain, $identifier, $bundledPath, $activePath);
                $stats['restored']++;
            } else {
                $this->handleExternalExtensionLoss($context, $domain, $cfg, $row, $sourceColumnExists);
                $stats['external_lost'][] = $identifier;
            }
        }

        return $stats;
    }

    private function restoreFromBundled(
        UpgradeContext $context,
        string $domain,
        string $identifier,
        string $bundledPath,
        string $activePath,
    ): void {
        try {
            File::ensureDirectoryExists(dirname($activePath));
            FilePermissionHelper::copyDirectory($bundledPath, $activePath, null, [], removeOrphans: false);

            $ownerInfo = $this->describeOwnership($activePath);
            $context->logger->info(sprintf(
                '[7.0.0-beta.5] %s/%s 활성 디렉토리 복원 완료 — %s → %s (%s)',
                $domain,
                $identifier,
                $bundledPath,
                $activePath,
                $ownerInfo,
            ));
        } catch (\Throwable $e) {
            $context->logger->warning(sprintf(
                '[7.0.0-beta.5] %s/%s 활성 디렉토리 복원 실패 — %s: %s',
                $domain,
                $identifier,
                $activePath,
                $e->getMessage(),
            ));
        }
    }

    /**
     * @param  array{table:string, dir:string, sourceColumn:string}  $cfg
     */
    private function handleExternalExtensionLoss(
        UpgradeContext $context,
        string $domain,
        array $cfg,
        object $row,
        bool $sourceColumnExists,
    ): void {
        $identifier = $row->identifier;
        $version = $row->version ?? '?';
        $sourceColumn = $cfg['sourceColumn'];
        $sourceUrl = $sourceColumnExists ? ($row->{$sourceColumn} ?? null) : null;

        if (is_string($sourceUrl) && $sourceUrl !== '') {
            $command = $this->describeReinstallCommand($domain, $identifier);
            $context->logger->warning(sprintf(
                "[7.0.0-beta.5] ⚠ 외부 %s 자동 복구 불가 — %s/%s @ %s\n  %s: %s\n  수동 재설치: %s",
                $this->describeDomainSingular($domain),
                $cfg['dir'],
                $identifier,
                $version,
                $sourceColumn,
                $sourceUrl,
                $command,
            ));
        } else {
            $context->logger->warning(sprintf(
                "[7.0.0-beta.5] ⚠ 외부 %s 자동 복구 불가 — %s/%s @ %s\n  외부 source 정보 부재 (%s 미저장)\n  운영자가 원본 소스로 직접 재설치 필요",
                $this->describeDomainSingular($domain),
                $cfg['dir'],
                $identifier,
                $version,
                $sourceColumn,
            ));
        }

        $this->markDeactivatedIfSupported($context, $cfg['table'], $identifier);
    }

    private function markDeactivatedIfSupported(UpgradeContext $context, string $table, string $identifier): void
    {
        $hasReason = Schema::hasColumn($table, 'deactivated_reason');
        $hasAt = Schema::hasColumn($table, 'deactivated_at');

        if (! $hasReason || ! $hasAt) {
            $context->logger->info(sprintf(
                '[7.0.0-beta.5] %s.deactivated_* 컬럼 부재 — %s DB row 비활성화 skip (stdout 안내만 적용)',
                $table,
                $identifier,
            ));

            return;
        }

        try {
            DB::table($table)
                ->where('identifier', $identifier)
                ->update([
                    'status' => 'inactive',
                    'deactivated_reason' => self::DEACTIVATION_REASON_DIR_LOST,
                    'deactivated_at' => now(),
                ]);

            $context->logger->info(sprintf(
                '[7.0.0-beta.5] %s/%s DB row 단발성 비활성화 — deactivated_reason=%s',
                $table,
                $identifier,
                self::DEACTIVATION_REASON_DIR_LOST,
            ));
        } catch (\Throwable $e) {
            $context->logger->warning(sprintf(
                '[7.0.0-beta.5] %s/%s DB row 비활성화 실패 — %s',
                $table,
                $identifier,
                $e->getMessage(),
            ));
        }
    }

    private function describeDomainSingular(string $domain): string
    {
        return match ($domain) {
            'modules' => '모듈',
            'plugins' => '플러그인',
            'templates' => '템플릿',
            'language_packs' => '언어팩',
            default => $domain,
        };
    }

    private function describeReinstallCommand(string $domain, string $identifier): string
    {
        return match ($domain) {
            'modules' => sprintf('php artisan module:install %s --source=github', $identifier),
            'plugins' => sprintf('php artisan plugin:install %s --source=github', $identifier),
            'templates' => sprintf('php artisan template:install %s --source=github', $identifier),
            'language_packs' => sprintf('php artisan language-pack:install %s --source=url', $identifier),
            default => sprintf('php artisan %s:install %s', $domain, $identifier),
        };
    }

    private function describeOwnership(string $path): string
    {
        if (! File::isDirectory($path)) {
            return 'unknown';
        }

        $owner = function_exists('fileowner') ? @fileowner($path) : null;
        $group = function_exists('filegroup') ? @filegroup($path) : null;
        $perms = function_exists('fileperms') ? @fileperms($path) : null;
        $permsOctal = $perms !== false && $perms !== null ? sprintf('%04o', $perms & 0777) : '?';

        $ownerName = '?';
        if ($owner !== false && $owner !== null && function_exists('posix_getpwuid')) {
            $info = @posix_getpwuid($owner);
            $ownerName = is_array($info) && isset($info['name']) ? $info['name'] : (string) $owner;
        } elseif ($owner !== false && $owner !== null) {
            $ownerName = (string) $owner;
        }

        $groupName = '?';
        if ($group !== false && $group !== null && function_exists('posix_getgrgid')) {
            $info = @posix_getgrgid($group);
            $groupName = is_array($info) && isset($info['name']) ? $info['name'] : (string) $group;
        } elseif ($group !== false && $group !== null) {
            $groupName = (string) $group;
        }

        return sprintf('owner=%s group=%s perms=%s', $ownerName, $groupName, $permsOctal);
    }

    private function isEmptyDirectory(string $path): bool
    {
        if (! File::isDirectory($path)) {
            return true;
        }

        $entries = @scandir($path);
        if ($entries === false) {
            return true;
        }

        return count(array_diff($entries, ['.', '..'])) === 0;
    }
}
