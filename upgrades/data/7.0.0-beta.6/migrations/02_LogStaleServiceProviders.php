<?php

namespace App\Upgrades\Data\V7_0_0_beta_6\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\Log;

/**
 * 부팅 부정합 ServiceProvider 진단 로그 — 자동 삭제 없음.
 *
 * beta.5 이전의 자동 롤백 결함으로 인해 활성 디렉토리 `app/Providers/` 또는 `app/Services/`
 * 등에 신 버전 ServiceProvider 파일이 잔존했을 수 있다. 본 진단은 다음 부정합을 식별한다:
 *
 *   1. `bootstrap/providers.php` 가 register 하는 클래스 FQCN 배열 추출
 *   2. `app/Providers/` 의 모든 *.php 파일 스캔 + 클래스명 변환
 *   3. 디스크에 있지만 register 되지 않은 ServiceProvider 후보 목록 산출
 *   4. `storage/logs/core_upgrade_beta_6_stale_providers.log` 로 기록 (자동 삭제 없음)
 *
 * 자동 삭제하지 않는 이유: 운영자가 직접 추가한 커스텀 ServiceProvider 가 같은 패턴으로
 * 식별되므로 안전 우선. 운영자 검토 후 수동 정리.
 *
 * 격리 원칙: 코어의 신 메서드 (CoreBackupHelper, ExtensionManager 등) 호출 금지. 표준
 * 파일 시스템 API (scandir / file_get_contents) + `Illuminate\Support\Facades\Log` 만 사용.
 */
final class LogStaleServiceProviders implements DataMigration
{
    private const LOG_FILENAME = 'core_upgrade_beta_6_stale_providers.log';

    public function name(): string
    {
        return 'LogStaleServiceProviders';
    }

    public function run(UpgradeContext $context): void
    {
        try {
            $this->runInternal($context);
        } catch (\Throwable $e) {
            $context->logger->warning(sprintf(
                '[7.0.0-beta.6] LogStaleServiceProviders 실패 (계속 진행): %s',
                $e->getMessage(),
            ));
            Log::warning('beta.6 LogStaleServiceProviders 실패', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function runInternal(UpgradeContext $context): void
    {
        $providersFile = base_path('bootstrap'.DIRECTORY_SEPARATOR.'providers.php');
        $providersDir = base_path('app'.DIRECTORY_SEPARATOR.'Providers');

        if (! file_exists($providersFile)) {
            $context->logger->info('[7.0.0-beta.6] bootstrap/providers.php 부재 — stale providers 진단 skip');

            return;
        }

        if (! is_dir($providersDir)) {
            $context->logger->info('[7.0.0-beta.6] app/Providers 부재 — stale providers 진단 skip');

            return;
        }

        $registered = $this->loadRegisteredProviders($providersFile);
        $registeredSet = [];
        foreach ($registered as $fqcn) {
            $registeredSet[strtolower($fqcn)] = true;
        }

        $stale = [];
        foreach ($this->collectProviderFiles($providersDir) as $file) {
            $relative = ltrim(substr($file, strlen($providersDir)), DIRECTORY_SEPARATOR.'/');
            $relative = str_replace('\\', '/', $relative);

            // app/Providers/Sub/Foo.php → App\Providers\Sub\Foo
            $classRelative = substr($relative, 0, -4); // .php strip
            $fqcn = 'App\\Providers\\'.str_replace('/', '\\', $classRelative);

            if (isset($registeredSet[strtolower($fqcn)])) {
                continue;
            }

            $stale[] = [
                'fqcn' => $fqcn,
                'file' => 'app/Providers/'.$relative,
            ];
        }

        if ($stale === []) {
            $context->logger->info('[7.0.0-beta.6] 부팅 부정합 ServiceProvider 후보 없음');

            return;
        }

        $logPath = storage_path('logs'.DIRECTORY_SEPARATOR.self::LOG_FILENAME);
        $header = implode("\n", [
            '=== beta.6 부팅 부정합 ServiceProvider 진단 로그 ===',
            '날짜: '.date('Y-m-d H:i:s'),
            sprintf('업그레이드: %s → %s', $context->fromVersion, $context->toVersion),
            '',
            '아래 ServiceProvider 클래스 파일이 디스크에는 존재하지만 bootstrap/providers.php',
            '의 등록 목록에 없습니다. beta.5 이전의 자동 롤백 결함으로 인해 잔존한 신 버전',
            '파일이거나, 운영자가 직접 추가한 커스텀 ServiceProvider 일 수 있습니다.',
            '',
            '자동 삭제는 수행하지 않습니다. 운영자가 검토 후 수동 정리하세요:',
            '  - 신 버전 잔존 파일 → 삭제',
            '  - 커스텀 ServiceProvider → bootstrap/providers.php 에 등록',
            '',
            '=== 후보 목록 ===',
            '',
        ]);

        $body = '';
        foreach ($stale as $entry) {
            $body .= "  - {$entry['fqcn']}\n";
            $body .= "      파일: {$entry['file']}\n";
        }

        @file_put_contents($logPath, $header.$body);

        $context->logger->warning(sprintf(
            '[7.0.0-beta.6] 부팅 부정합 ServiceProvider %d개 진단 — 운영자 검토 필요. 로그: %s',
            count($stale),
            $logPath,
        ));
    }

    /**
     * bootstrap/providers.php 를 require 하여 등록된 ServiceProvider FQCN 배열 반환.
     *
     * @return array<int, string>
     */
    private function loadRegisteredProviders(string $providersFile): array
    {
        $data = require $providersFile;
        if (! is_array($data)) {
            return [];
        }

        return array_values(array_filter(
            $data,
            fn ($v) => is_string($v) && $v !== '',
        ));
    }

    /**
     * app/Providers/ 의 모든 *.php 파일을 재귀 수집.
     *
     * @return array<int, string>
     */
    private function collectProviderFiles(string $providersDir): array
    {
        $files = [];
        $stack = [$providersDir];

        while ($stack !== []) {
            $cur = array_pop($stack);
            $entries = @scandir($cur);
            if ($entries === false) {
                continue;
            }
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $full = $cur.DIRECTORY_SEPARATOR.$entry;
                if (is_dir($full) && ! is_link($full)) {
                    $stack[] = $full;
                } elseif (is_file($full) && str_ends_with($entry, '.php')) {
                    $files[] = $full;
                }
            }
        }

        sort($files, SORT_STRING);

        return $files;
    }
}
