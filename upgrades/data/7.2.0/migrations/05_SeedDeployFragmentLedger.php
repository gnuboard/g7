<?php

namespace App\Upgrades\Data\V7_2_0\Migrations;

use App\Extension\Helpers\StorageLinkHelper;
use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Throwable;

/**
 * `deploy/**` 서버 설정 조각의 해시 원장을 현재 디스크로 **씨앗**합니다 (G3 회차 16 W2 A2 ③).
 *
 * 코어 업데이트는 `deploy/**` 조각을 파일마다 「코어가 마지막으로 제시한 판본의 해시 원장」
 * (`storage/app/core-update/deploy-fragments.json`)과 대조해 운영자 수정본을 보존한다. 원장이 없는
 * 설치본(이 규칙 이전에 설치됐거나, 구버전 부모 프로세스가 적용을 수행해 원장을 남기지 않은 사이트)은
 * 다음 업데이트에서 **전 조각이** 「판정 불가 → 보존 + `.dist`」 로 떨어진다 — 오류가 아니라 운영자가
 * 합칠 것이 없는 `.dist` 와 안내를 매번 받는 형태다.
 *
 * 업그레이드 스텝이 도는 시점의 디스크는, **사이드카(`.dist`·`.dist.new`)가 없는 조각에 한해**
 * 릴리즈 판본과 바이트 동일하다 — 통째 복사(구버전)든 조각 판정(신버전)이든, 다른 것을 보존할 때는
 * 반드시 사이드카를 남기기 때문이다. 그래서 그 조각들의 해시로 원장을 만든다. 사이드카가 있는 조각은
 * 씨앗하지 않는다 — 씨앗하면 운영자 수정본이 「코어가 쓴 그대로」로 읽혀 다음 업데이트가 덮어쓴다.
 *
 * idempotent: 완전한 원장에는 아무것도 더하지 않는다. 부분 원장(부모 프로세스의 부분 적용이 남긴)에는
 * 원장에 없는 키만 채우는 병합이다 (17차 C-4). 조각이 없으면 no-op.
 *
 * V-1 안전(경로 C 규율): 기존 클래스의 **신규 메서드**(`CoreUpdateService::seedDeployFragmentLedger()`)를
 * 부르지 않고 네이티브 파일시스템 함수로 같은 일을 로컬에서 한다. 원장 이름·사이드카 접미사는 아래
 * 상수에 리터럴로 두고, 서비스 상수와의 일치와 두 구현의 결과 일치는 계약 테스트
 * (`tests/Unit/Upgrades/SeedDeployFragmentLedgerTest`)가 대조한다. 예외는 원장 쓰기 실패 통보 한 곳으로,
 * 7.2.0 에서 생긴 `StorageLinkHelper::recordDocrootExposureWarning()` 을 부른다 — 옛 헬퍼가 메모리에 있는
 * in-process 실행에서는 그 메서드가 없으므로 `method_exists()` 로 확인한 뒤에만 부르고, 없으면
 * 로그 경고만 남긴다.
 */
class SeedDeployFragmentLedger implements DataMigration
{
    /** 원장 파일 이름 — `CoreUpdateService::DEPLOY_FRAGMENT_LEDGER` 와 같은 값. */
    public const LEDGER_FILE = 'deploy-fragments.json';

    /** 보존 사이드카 접미사 — `CoreUpdateService::DEPLOY_FRAGMENT_DIST_SUFFIX` 와 같은 값. */
    public const DIST_SUFFIX = '.dist';

    /** 충돌 사이드카 접미사 — `CoreUpdateService::DEPLOY_FRAGMENT_DIST_NEW_SUFFIX` 와 같은 값. */
    public const DIST_NEW_SUFFIX = '.dist.new';

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'SeedDeployFragmentLedger';
    }

    /**
     * 원장이 없으면 현재 디스크의 조각 해시로 원장을 만듭니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    public function run(UpgradeContext $context): void
    {
        $ledgerPath = storage_path('app'.DIRECTORY_SEPARATOR.'core-update'.DIRECTORY_SEPARATOR.self::LEDGER_FILE);

        // 원장이 있어도 조기 반환하지 않는다 — **없는 키만 채우는 병합**이다 (17차 C-4). 부모
        // 프로세스의 부분 적용이 남긴 부분 원장에는 빠진 조각을 채우고, 완전한 원장에는 아무것도
        // 더하지 않아 멱등이다. 서비스 씨앗기(`CoreUpdateService::seedDeployFragmentLedger`)와
        // 같은 병합 규칙을 로컬에 둔다 — 두 구현의 일치는 계약 테스트가 대조한다.
        $existing = [];
        if (is_file($ledgerPath)) {
            $decoded = json_decode((string) @file_get_contents($ledgerPath), true);
            $existing = is_array($decoded) ? $decoded : [];
        }

        $deployDir = base_path('deploy');

        if (! is_dir($deployDir)) {
            $context->logger->info('[core:7.2.0] deploy/ 부재 — 해시 원장 씨앗 불필요');

            return;
        }

        $ledger = $existing;
        $added = [];
        $this->collect($deployDir, '', $ledger, $added);

        if ($added === []) {
            $context->logger->info('[core:7.2.0] 채울 deploy 조각이 없습니다 (원장이 완전하거나 전부 사이드카를 갖거나 조각이 없음)');

            return;
        }

        ksort($ledger);

        $encoded = (string) json_encode($ledger, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        try {
            $dir = dirname($ledgerPath);
            if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
                throw new \RuntimeException('디렉토리를 만들지 못했습니다: '.$dir);
            }

            $written = @file_put_contents($ledgerPath, $encoded, LOCK_EX);
        } catch (Throwable $e) {
            $written = false;
            $context->logger->warning('[core:7.2.0] deploy 조각 해시 원장 씨앗 실패: '.$e->getMessage());
        }

        if ($written === false || $written !== strlen($encoded)) {
            // 실패했다고 **기존 원장을 지우지 않는다.** 이 스텝은 사이드카 없는 조각만
            // 병합해 씨앗하므로, 이미 채워진 원장이 있는 사이트에서 이 쓰기가 실패하면
            // 지울 때 잃는 것은 「이번에 못 넣은 키」가 아니라 **원장 전체**다. 그러면
            // 다음 코어 업데이트가 전 조각을 판정 불가로 보고 새 판본을 `.dist` 로 옆에
            // 두어, 운영자가 고치지도 않은 조각까지 합치기 작업을 떠안는다.
            // 형제(`CoreUpdateService::writeDeployFragmentLedger`)도 지우지 않는다.
            $context->logger->warning(
                '[core:7.2.0] deploy 조각 해시 원장을 쓰지 못했습니다 ('.$ledgerPath.') — 다음 코어 업데이트가 '
                .'전 조각을 「판정 불가」로 보고 새 판본을 .dist 로 옆에 둡니다. storage/app 쓰기 권한을 확인하세요.'
            );

            // 로그 **파일만**으로는 이 사실이 운영자에게 도달하지 않는다. 이 스텝이 도는
            // 무CLI 업그레이드 동선의 운영자는 `storage/logs/*.log` 를 열 수단이 없다 —
            // 형제 서비스 판본(`CoreUpdateService::writeDeployFragmentLedger`)이 같은 실패를
            // `lastRootFileWarnings[]` 에 실어 콘솔·화면까지 보내는 것과 갈려 있었다. 같은
            // 결함이 어느 통로로 도느냐에 따라 보이거나 보이지 않는다 (23차 G3 라운드 D23-13).
            //
            // 형제 스텝(`04_MovePublicStorageBrokenBackupsOutOfDocroot`)과 같은 통로를 쓴다 —
            // `ExecuteUpgradeStepsCommand` 가 스텝 종료 시 이 버퍼를 비워 운영자에게 낸다.
            // 그 메서드는 7.2.0 에서 생겼다 — 옛 헬퍼가 메모리에 있으면 부르지 않는다(위 로그
            // 경고가 이미 같은 사실을 남겼다).
            if ($this->storageLinkHelperHas('recordDocrootExposureWarning')) {
                StorageLinkHelper::recordDocrootExposureWarning((string) __(
                    'settings.core_update.deploy_fragment_ledger_write_failed',
                    ['ledger' => $ledgerPath]
                ));
            }

            return;
        }

        $context->logger->info(sprintf('[core:7.2.0] deploy 조각 해시 원장 씨앗 완료 (%d건): %s', count($added), implode(', ', $added)));
    }

    /**
     * 메모리에 올라온 `StorageLinkHelper` 가 주어진 정적 메서드를 갖는지 판정합니다.
     *
     * 테스트가 옛 헬퍼 상태(메서드 부재)를 재현할 수 있도록 판정을 한 지점에 모은다.
     *
     * @param  string  $method  메서드 이름
     * @return bool 있으면 true
     */
    protected function storageLinkHelperHas(string $method): bool
    {
        return method_exists(StorageLinkHelper::class, $method);
    }

    /**
     * 디렉토리를 재귀로 훑어 사이드카가 없는 조각의 해시를 모읍니다.
     *
     * @param  string  $dir  절대 경로
     * @param  string  $relativePrefix  `deploy/` 기준 상대 접두 (`nginx/` 등)
     * @param  array<string, string>  $ledger  누적 원장 (참조)
     * @param  list<string>  $added  이 호출에서 새로 채운 키 (참조)
     */
    private function collect(string $dir, string $relativePrefix, array &$ledger, array &$added): void
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            // 점으로 시작하는 항목(`.`·`..`·dot 파일/디렉토리)은 건너뛴다 — 서비스 씨앗기가 쓰는
            // `File::allFiles()` 는 `ignoreDotFiles(true)` 라 점 파일/디렉토리를 모집단에서 뺀다.
            // 종전에는 이 스텝만 `scandir` 로 점 파일을 포함해 두 구현의 모집단이 갈렸다 (17차 C-1).
            if (str_starts_with($entry, '.')) {
                continue;
            }

            $path = $dir.DIRECTORY_SEPARATOR.$entry;

            if (is_dir($path)) {
                $this->collect($path, $relativePrefix.$entry.'/', $ledger, $added);

                continue;
            }

            if (str_ends_with($entry, self::DIST_SUFFIX) || str_ends_with($entry, self::DIST_NEW_SUFFIX)) {
                continue;
            }

            $key = $relativePrefix.$entry;
            if (isset($ledger[$key])) {
                continue; // 원장에 이미 있는 키는 채우지 않는다 (17차 C-4)
            }

            if (is_file($path.self::DIST_SUFFIX) || is_file($path.self::DIST_NEW_SUFFIX)) {
                continue;
            }

            $contents = @file_get_contents($path);
            if ($contents === false) {
                continue;
            }

            $ledger[$key] = hash('sha256', $contents);
            $added[] = $key;
        }
    }
}
