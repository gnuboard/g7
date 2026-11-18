<?php

namespace App\Upgrades\Data\V7_2_0\Migrations;

use App\Extension\Helpers\BundledShippedManifest;
use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;

/**
 * 7.1.x 에서 올라온 사이트에 번들 출하 목록(`storage/app/core-update/bundled-shipped.json`)을 씨앗합니다.
 *
 * 7.2.0 부터 코어 업데이트의 기본(증분) 모드는 사이트의 직전 출하 목록과 새 배포본 목록을 대조해, 새 판이 지운 번들 파일을
 * 정리한다(`BundledShippedManifest`). 그 목록은 업데이트가 파일을 적용하며 기록하는데, 7.1.x → 7.2.0 업데이트의 파일 적용은
 * 7.1.x 코드라 목록을 남기지 않는다. 그대로 두면 7.2.0 → 7.2.x 첫 업데이트가 대조할 직전 목록이 없어 그 업데이트가 지운 번들
 * 파일이 남는다. 이 스텝이 이번 배포본(업데이트 대기 폴더의 압축 해제본 — 7.1.x 는 업그레이드 스텝 뒤 정리 단계에서 지운다)
 * 에서 목록을 만들어 남긴다.
 *
 * 디스크가 아니라 배포본에서 만든다 — 디스크의 번들 폴더에는 이전 판이 남긴 파일·운영자 파일이 있을 수 있어, 그것을 「출하했던
 * 파일」로 기록하면 다음 업데이트가 지울 수 있다. 배포본을 찾지 못하면(스텝만 다시 돌림 등) 목록을 만들지 않는다 — 다음
 * 업데이트가 정리 없이 목록만 남긴다(지우지 않는 쪽).
 *
 * 이미 목록이 있으면 건드리지 않는다(멱등). 배포본 식별: 대기 폴더의 `core_*` 후보(바로 아래·`local_source`·`extracted`·
 * `extracted/*`) 중 루트 `index.php` 가 사이트의 루트 `index.php` 와 같은 것 — 스텝 13 과 같은 판정.
 *
 * V-1 안전: 이 스텝이 부르는 `BundledShippedManifest` 는 7.2.0 에서 새로 생긴 클래스라 옛 프로세스 메모리에 실려 있을 수 없다
 * (디스크의 새 파일이 오토로드된다). 그래도 클래스가 없으면 건너뛴다.
 */
class SeedBundledShippedManifest implements DataMigration
{
    /**
     * @param  string|null  $basePath  설치 루트 (null 이면 base_path() — 테스트가 주입한다)
     * @param  string|null  $pendingRoot  코어 업데이트 대기 루트 (null 이면 config('app.update.pending_path'))
     * @param  string|null  $manifestDirectory  출하 목록 디렉토리 (null 이면 storage/app/core-update)
     */
    public function __construct(
        private readonly ?string $basePath = null,
        private readonly ?string $pendingRoot = null,
        private readonly ?string $manifestDirectory = null,
    ) {}

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'SeedBundledShippedManifest';
    }

    /**
     * 이번 배포본에서 번들 출하 목록을 만들어 남깁니다(없을 때만).
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        if (! class_exists(BundledShippedManifest::class)) {
            $context->logger->warning('[core:7.2.0] 번들 출하 목록 도우미가 없어 씨앗 건너뜀');

            return;
        }

        if (BundledShippedManifest::read($this->manifestDirectory) !== null) {
            $context->logger->info('[core:7.2.0] 번들 출하 목록이 이미 있음 — 씨앗 생략');

            return;
        }

        $root = rtrim($this->basePath ?? base_path(), '/\\');
        $release = $this->findRelease((string) @md5_file($root.'/index.php'));

        if ($release === null) {
            $context->logger->info('[core:7.2.0] 이번 배포본을 찾지 못해 번들 출하 목록을 씨앗하지 않음 — 다음 코어 업데이트가 목록만 남긴다');

            return;
        }

        try {
            $files = BundledShippedManifest::collect($release);
            $written = BundledShippedManifest::write($files, $context->toVersion, $this->manifestDirectory, keepPrevious: false);
        } catch (\Throwable $e) {
            $context->logger->warning('[core:7.2.0] 번들 출하 목록 씨앗 실패', ['error' => $e->getMessage()]);

            return;
        }

        $written
            ? $context->logger->info(sprintf('[core:7.2.0] 번들 출하 목록 씨앗 (%d개 파일)', count($files)))
            : $context->logger->warning('[core:7.2.0] 번들 출하 목록을 쓰지 못함 (권한 확인)');
    }

    /**
     * 루트 `index.php` 가 지금 파일과 같은 배포본 압축 해제본 루트를 찾습니다.
     *
     * @param  string  $indexHash  지금 루트 `index.php` 의 md5
     * @return string|null 배포본 루트 (없으면 null)
     */
    private function findRelease(string $indexHash): ?string
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
                if (is_file($candidate.'/index.php') && @md5_file($candidate.'/index.php') === $indexHash) {
                    return $candidate;
                }
            }
        }

        return null;
    }
}
