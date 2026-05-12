<?php

namespace App\Upgrades\Data\V7_0_0_beta_5\Migrations;

use App\Extension\Helpers\FilePermissionHelper;
use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use FilesystemIterator;
use Illuminate\Support\Facades\File;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * beta.4 의 `recoverLangPacksBundled` 와 동등한 fallback 을 한 번 더 멱등 실행.
 *
 * beta.4 step 이 이미 보정한 환경에서는 silent skip. beta.4 spawn 자식이 정상 실행되지
 * 못한 환경(예: in-process fallback 실패)에서 누락이 남아있다면 본 step 이 추가 보정.
 *
 * 비표준 ZIP 구조 탐색을 위해 `FilesystemIterator` 기반 무제한 깊이 재귀 탐색을 사용한다
 * (beta.4 의 4단계 깊이 제약을 회피).
 *
 * 멱등성: `lang-packs/_bundled` 가 비어있지 않으면 silent skip.
 */
final class VerifyBundledLangPacksFallback implements DataMigration
{
    public function name(): string
    {
        return 'VerifyBundledLangPacksFallback';
    }

    public function run(UpgradeContext $context): void
    {
        $activeBundled = base_path('lang-packs/_bundled');

        if (File::isDirectory($activeBundled) && count(File::directories($activeBundled)) > 0) {
            $context->logger->info('[7.0.0-beta.5] lang-packs/_bundled 정상 — fallback skip');

            return;
        }

        $context->logger->warning('[7.0.0-beta.5] lang-packs/_bundled 누락 감지 — fallback 탐색 시작');

        $pendingSource = $this->locatePendingLangPacksSourceDeep($context);
        if ($pendingSource === null) {
            $context->logger->warning(
                '[7.0.0-beta.5] _pending 의 lang-packs/_bundled 소스 미발견 — 수동 복구 필요. '
                .'`php artisan core:update --force` 재실행으로 자동 발견 폴백 트리거.'
            );

            return;
        }

        try {
            File::ensureDirectoryExists($activeBundled);
            FilePermissionHelper::copyDirectory($pendingSource, $activeBundled, null, [], removeOrphans: false);
            $count = count(File::directories($activeBundled));
            $context->logger->info(sprintf(
                '[7.0.0-beta.5] lang-packs/_bundled fallback 복원 완료 — %d 개 패키지 ({%s} → {%s})',
                $count,
                $pendingSource,
                $activeBundled,
            ));
        } catch (\Throwable $e) {
            $context->logger->warning('[7.0.0-beta.5] lang-packs/_bundled fallback 복원 실패 — '.$e->getMessage());
        }
    }

    /**
     * _pending 디렉토리에서 `lang-packs/_bundled` 소스를 무제한 깊이로 재귀 탐색.
     *
     * `FilesystemIterator` 기반으로 깊이 제한 없이 첫 매치를 반환한다 (가장 얕은 매치 우선).
     */
    private function locatePendingLangPacksSourceDeep(UpgradeContext $context): ?string
    {
        $pendingPath = config('app.update.pending_path');
        if (! is_string($pendingPath) || $pendingPath === '' || ! File::isDirectory($pendingPath)) {
            return null;
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($pendingPath, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );
        } catch (\Throwable $e) {
            $context->logger->warning('[7.0.0-beta.5] _pending 재귀 탐색 실패 — '.$e->getMessage());

            return null;
        }

        $matches = [];
        foreach ($iterator as $entry) {
            if (! $entry->isDir()) {
                continue;
            }
            $path = $entry->getPathname();
            // basename 이 '_bundled' 이고 부모 basename 이 'lang-packs' 인 경로만 매치
            if (basename($path) !== '_bundled') {
                continue;
            }
            if (basename(dirname($path)) !== 'lang-packs') {
                continue;
            }
            if (count(File::directories($path)) === 0) {
                continue;
            }
            $matches[] = $path;
        }

        if (count($matches) === 0) {
            return null;
        }

        // 가장 짧은 경로 (가장 얕은 매치) 우선 선택
        usort($matches, fn (string $a, string $b): int => strlen($a) <=> strlen($b));
        $picked = $matches[0];
        $context->logger->info('[7.0.0-beta.5] lang-packs 소스 발견: '.$picked);

        return $picked;
    }
}
