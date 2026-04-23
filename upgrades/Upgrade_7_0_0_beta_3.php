<?php

namespace App\Upgrades;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Extension\UpgradeContext;
use FilesystemIterator;
use RuntimeException;

/**
 * 코어 7.0.0-beta.3 업그레이드 스텝
 *
 * sudo root 로 실행된 beta.2 → beta.3 업데이트가 `storage/framework/cache` 등 하위
 * 디렉토리를 umask 022 환경에서 `0755` (drwxr-xr-x) 로 생성한 뒤, 이후 첫 웹 요청에서
 * php-fpm(www-data 그룹) 이 그 안에 파일을 쓰지 못해 "Permission denied" 500 에러가
 * 발생하던 문제를 1회성으로 구조적으로 정정한다.
 *
 * 실패한 1차 접근 (spawn 자식, 경로 B):
 *
 *   초기에 경로 B 로 설계했으나 근본 원인을 해결하지 못함이 밝혀졌다.
 *
 *   - 문제 생성 시점: beta.2 CoreUpdateCommand (부모) 의 Step 11 cleanup 이후 일괄
 *     확장 업데이트 프롬프트 내에서 Laravel 재부팅 (CoreServiceProvider::boot →
 *     ModuleManager::loadModules → CachesModuleStatus → FileStore::put) 이
 *     `storage/framework/cache/data/<hash>` 디렉토리를 신규 생성하는 순간.
 *   - 이 cache 쓰기는 beta.2 **부모 프로세스 내 Artisan::call** 경로를 타므로 부모의
 *     umask 022 로 `drwxr-xr-x` 생성.
 *   - spawn 자식에서 아무리 `umask(0002)` 를 호출하거나 `syncGroupWritability` 를
 *     돌려도 그것은 자식에만 영향. 자식 종료 후 부모가 재생성하는 디렉토리에 무효.
 *
 * 채택 방식 (경로 C, in-process):
 *
 *   본 스텝은 **beta.2 CoreUpdateCommand 의 in-process fallback 경로로만 실행되도록**
 *   설계한다. 그 경로에서 `umask(0002)` 을 호출하면 beta.2 부모 프로세스 자체의 umask
 *   가 바뀌어 Step 11 이후 모든 cache/session/view/log 파일 생성이 `g+w` 를 유지한다.
 *
 *   - spawn 자식 (argv 에 `core:execute-upgrade-steps` 포함) 에서 실행되면 **의도적으로
 *     예외를 던져 exit 1 유도**. beta.2 의 `spawnUpgradeStepsProcess` 가 false 반환
 *     → in-process fallback 경로 진입 → 본 스텝이 부모 프로세스 내에서 재실행.
 *   - in-process 실행에서 `umask(0002)` 주입 + 인라인 재귀 chmod 로 기존 g-w 디렉토리도
 *     g+w 로 승격.
 *
 * @upgrade-path C
 *
 * 경로 C 규율 준수:
 *   - 이전 버전(beta.2) 의 메모리에서 실행되므로 beta.2 에 없는 **신설 메서드/클래스 호출
 *     금지**. 과거 구현에 있던 `FilePermissionHelper` 의 `syncGroupWritability` 정적
 *     호출을 제거하고 인라인 private 메서드로 재작성.
 *   - Laravel Facade (`File::isDirectory` 등) 사용은 beta.2 에도 존재하므로 허용이지만
 *     의존성 최소화를 위해 순수 PHP 함수만 사용.
 *
 * 운영자 의도 존중:
 *   - `storage/` 가 g-w 로 설정된 공유 호스팅 등 특수 환경에서는 umask 변경·chmod 순회
 *     모두 스킵. 업그레이드 후 권한 이슈가 없는 구성이므로 정상.
 *
 * 상세: docs/extension/upgrade-step-guide.md 섹션 9, 10.
 */
class Upgrade_7_0_0_beta_3 implements UpgradeStepInterface
{
    /**
     * 그룹 쓰기 권한 정상화 대상 — 인스톨러 SSoT 와 1:1 정렬.
     *
     * 출처: `public/install/includes/config.php` 의 `REQUIRED_DIRECTORIES` 키 목록.
     * config/app.php 의 `restore_ownership_group_writable` 기본값과 동일.
     *
     * @var list<string>
     */
    private const TARGETS = [
        'storage',
        'bootstrap/cache',
        'vendor',
        'modules',
        'modules/_pending',
        'plugins',
        'plugins/_pending',
        'templates',
        'templates/_pending',
        'storage/app/core_pending',
    ];

    /**
     * 업그레이드 스텝을 실행합니다.
     *
     * spawn 자식에서 호출된 경우에는 의도적으로 실패(throw)하여 beta.2 부모의 in-process
     * fallback 경로로 재실행 유도. in-process 에서는 부모 프로세스 umask 를 0002 로
     * 주입하고 기존 g-w 디렉토리를 g+w 로 승격한다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     *
     * @throws RuntimeException spawn 자식에서 호출된 경우 (의도적 실패)
     */
    public function run(UpgradeContext $context): void
    {
        // spawn 자식 탐지 — beta.2 의 spawnUpgradeStepsProcess 가 proc_open 으로 띄운
        // `php artisan core:execute-upgrade-steps ...` 프로세스에서 실행 중이면
        // argv 에 해당 커맨드명이 포함된다.
        if ($this->isSpawnedChild()) {
            $context->logger->warning(
                '[beta.3] spawn 자식에서 호출됨 — 부모 프로세스 umask 주입을 위해 의도적 실패 '
                .'(beta.2 CoreUpdateCommand 가 in-process fallback 으로 자동 재실행)'
            );

            throw new RuntimeException(
                'Upgrade_7_0_0_beta_3 은 부모 프로세스 in-process 실행이 필요합니다. '
                .'beta.2 CoreUpdateCommand 의 spawn exit != 0 감지 후 fallback 으로 재진입합니다.'
            );
        }

        $context->logger->info('[beta.3] 그룹 쓰기 권한 정상화 시작 (부모 프로세스 in-process)');

        if (! function_exists('chmod')) {
            $context->logger->info('[beta.3] chmod 미지원 환경 — 스킵');

            return;
        }

        // 운영자 의도 존중 — storage 가 g-w 면 전체 스킵
        $storagePath = base_path('storage');
        if (! is_dir($storagePath)) {
            $context->logger->info('[beta.3] storage/ 디렉토리 없음 — 스킵');

            return;
        }

        $storagePerms = @fileperms($storagePath);
        if ($storagePerms === false || ($storagePerms & 0020) === 0) {
            $context->logger->info('[beta.3] storage/ 에 그룹 쓰기 비활성 — 운영자 의도 존중, 스킵');

            return;
        }

        // 1) 부모 프로세스 umask 를 0002 로 전환. Step 11 이후 beta.2 가 생성할 모든
        //    cache/session/view/log 파일이 `g+w` 를 유지하도록 한다.
        if (function_exists('umask')) {
            $previousUmask = umask(0002);
            $context->logger->info(sprintf(
                '[beta.3] 부모 프로세스 umask 전환: 0%03o → 0002',
                $previousUmask & 0777
            ));
        }

        // 2) 기존에 이미 g-w 로 생성된 디렉토리/파일을 g+w 로 승격 (재귀 순회).
        $totalChanged = 0;
        $touched = [];

        foreach (self::TARGETS as $rel) {
            $path = base_path($rel);
            if (! is_dir($path)) {
                $context->logger->info("[beta.3] 경로 없음 — 스킵: {$rel}");

                continue;
            }

            $changed = $this->syncGroupWritableLocally($path);
            $totalChanged += $changed;

            if ($changed > 0) {
                $touched[$rel] = $changed;
                $context->logger->info("[beta.3] 권한 복구: {$rel} (변경 {$changed}건)");
            } else {
                $context->logger->info("[beta.3] 정상 상태 — 변경 불필요: {$rel}");
            }
        }

        $context->logger->info('[beta.3] 그룹 쓰기 권한 정상화 완료', [
            'total_changed' => $totalChanged,
            'touched' => $touched,
        ]);
    }

    /**
     * 현재 프로세스가 `core:execute-upgrade-steps` spawn 자식인지 판정.
     *
     * beta.2 의 spawnUpgradeStepsProcess 가 `php artisan core:execute-upgrade-steps
     * --from=... --to=...` 형태로 띄우므로 argv 에 해당 커맨드명이 포함된다.
     */
    private function isSpawnedChild(): bool
    {
        $argv = $_SERVER['argv'] ?? null;
        if (! is_array($argv)) {
            return false;
        }

        foreach ($argv as $arg) {
            if ($arg === 'core:execute-upgrade-steps') {
                return true;
            }
        }

        return false;
    }

    /**
     * 로컬 재귀 chmod — `FilePermissionHelper::syncGroupWritability` 와 동등한 로직을
     * 경로 C 규율에 맞춰 스텝 파일 내부에 인라인 복제.
     *
     * 정책:
     *   - 루트가 g-w 면 no-op (정책 보존)
     *   - 하위 재귀 순회하며 g-w 항목을 g+w 로 승격
     *   - 그 외 비트 무변경 (g+w 만 OR)
     *   - symbolic link 는 링크 자체만 처리 (대상 미추적)
     *   - silent fail — 권한 부족 / chmod 실패 시 카운트만 누락
     *
     * @return int  실제 chmod 한 항목 수
     */
    private function syncGroupWritableLocally(string $root): int
    {
        $rootPerms = @fileperms($root);
        if ($rootPerms === false || ($rootPerms & 0020) === 0) {
            return 0;
        }

        $changed = 0;
        $this->walkAndElevate($root, $changed, isRoot: true);

        return $changed;
    }

    /**
     * 재귀 순회 내부 구현. $changed 를 참조로 누적.
     */
    private function walkAndElevate(string $path, int &$changed, bool $isRoot): void
    {
        if (is_link($path)) {
            return;
        }

        if (! $isRoot) {
            $perms = @fileperms($path);
            if ($perms !== false && ($perms & 0020) === 0) {
                if (@chmod($path, $perms | 0020)) {
                    $changed++;
                }
            }
        }

        if (! is_dir($path)) {
            return;
        }

        $items = new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS);
        foreach ($items as $item) {
            $this->walkAndElevate($item->getPathname(), $changed, isRoot: false);
        }
    }
}
