<?php

namespace App\Upgrades\Data\V7_2_0\Migrations;

use App\Extension\Helpers\StorageLinkHelper;
use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Throwable;

/**
 * `public/storage.broken.*` 사본을 문서 루트 밖으로 옮깁니다.
 *
 * `public/storage` 가 심볼릭 링크가 아니라 일반 디렉토리로 변질된 사이트에서, 코어의 링크
 * 복구(`StorageLinkHelper::ensurePublicStorageLink()`)는 그 디렉토리를 rename 으로 백업한
 * 뒤 링크를 다시 만든다. 7.1.x 이하의 백업 위치는 **문서 루트 안**(`public/storage.broken.YmdHis`)
 * 이었다 — 그 사본은 공개 파일 전량의 복제이고, 그 자리에 있는 동안 제2 URL 로 계속 서빙된다.
 * 즉 운영자가 관리 화면에서 지운 공개 파일이 그 주소로는 여전히 열리고, 그것을 걷는 주체는
 * 코어 어디에도 없다(로그의 「수동 삭제 권장」이 유일한 통보다).
 *
 * 7.2.0 부터 그 백업은 `storage/app/public-storage-broken-*` 로 간다. 그러나 소스를 고쳐도
 * **이미 그 상태인 사이트는 낫지 않는다** — 링크 복구는 `public/storage` 가 정상이면 즉시
 * 반환하므로 잔해에 영영 닿지 않는다. 그래서 이 스텝이 한 번 걷는다.
 *
 * 옮기기만 하고 **지우지 않는다.** 그 사본이 운영자에게 유일하게 남은 파일일 수 있다
 * (링크가 깨진 창에 업로드된 것). 옮기지 못하면 경로를 경고로 남겨 운영자에게 넘긴다.
 *
 * idempotent: 대상이 없으면 no-op 이고, 이미 옮긴 것은 이름이 달라 다시 잡히지 않는다.
 * V-1 안전(경로 C 규율): 네이티브 파일시스템 함수와 `$context->logger` 를 쓴다. 이름 규칙(접두사·
 * 시각 형식)은 `StorageLinkHelper` 상수를 읽지 않고 아래 비공개 상수로 옮겨 두었다 — 그 상수들과
 * 경고 통로 메서드(`recordDocrootExposureWarning()`·`warnOnAccumulatedBackups()`)는 7.2.0 에서
 * 처음 생겼으므로 7.1.3 이하 부모 프로세스가 옛 `StorageLinkHelper` 를 메모리에 둔 채 이 스텝을
 * in-process 로 돌리면 상수·메서드 부재로 치명 종료한다. 그래서 두 메서드는 `method_exists()` 로
 * 확인한 뒤에만 부르고, 없으면 같은 사실을 `$context->logger` 경고로 남긴다. 형제 스텝
 * `RecoverPublicStorageSymlink`(7.0.0-beta.5)는 그 판에 이미 있던 `ensurePublicStorageLink()` 만
 * 부르므로 이 자리와 사정이 다르다. 상수 값이 헬퍼와 같은지는 테스트가 대조한다.
 */
class MovePublicStorageBrokenBackupsOutOfDocroot implements DataMigration
{
    /** 웹 트리 안 손상 백업 접두사 — `StorageLinkHelper::DOCROOT_BROKEN_PREFIX` 와 같은 값. */
    private const DOCROOT_BROKEN_PREFIX = 'storage.broken.';

    /** 웹 트리 밖 손상 백업 접두사 — `StorageLinkHelper::PUBLIC_STORAGE_BROKEN_PREFIX` 와 같은 값. */
    private const PUBLIC_STORAGE_BROKEN_PREFIX = 'public-storage-broken-';

    /** 손상 백업 이름의 시각 + 충돌 회피 접미사 형식 — `StorageLinkHelper::BROKEN_STAMP` 와 같은 값. */
    private const BROKEN_STAMP = '\d{14}(?:-[0-9a-f]+)*';

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'MovePublicStorageBrokenBackupsOutOfDocroot';
    }

    /**
     * 문서 루트 안의 손상 백업을 문서 루트 밖으로 이동합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    public function run(UpgradeContext $context): void
    {
        $publicPath = public_path();
        $destinationDir = storage_path('app');

        if (! is_dir($publicPath)) {
            $context->logger->info('[core:7.2.0] public/ 부재 — 손상 백업 정리 스킵');

            return;
        }

        $candidates = $this->findBackups($publicPath);

        // 옮길 곳이 없어도 **노출 사실은 통보한다.** 종전에는 `storage/app` 부재를 info 한
        // 줄로 넘기고 반환했는데, 잔해가 있는 사이트에서 옮길 곳이 없다는 것은 정리가
        // 끝났다는 뜻이 아니라 노출이 **계속된다**는 뜻이다 — 아래 옮기기 실패 경로와 같은
        // 등급의 사실이 이 갈래에서만 로그 파일에서 끝났다.
        if (! is_dir($destinationDir)) {
            $exposed = array_merge($candidates, $this->skipped);

            if ($exposed === []) {
                $context->logger->info('[core:7.2.0] storage/app/ 부재 — 문서 루트 안의 손상 백업도 없어 정리 불필요');

                return;
            }

            $context->logger->warning(
                '[core:7.2.0] storage/app/ 이 없어 손상 백업을 옮기지 못했습니다 — 아래 항목은 **문서 루트 안**에 있어 웹에서 접근 가능합니다. '
                .'내용을 확인한 뒤 문서 루트 밖으로 옮기거나 삭제하세요: '.implode(', ', $exposed)
            );

            foreach ($exposed as $path) {
                $this->recordExposureWarning($context, (string) __(
                    'settings.core_update.storage_link_backup_inside_docroot',
                    ['path' => $path]
                ));
            }

            return;
        }

        if ($candidates === [] && $this->skipped === []) {
            $context->logger->info('[core:7.2.0] 문서 루트 안의 public/storage 손상 백업 없음 — 정리 불필요');

            return;
        }

        // 옮길 대상이 없어도 **건너뛴 항목이 있으면** 그 사실을 먼저 알린다. 종전에는
        // 조기 반환이 아래 skipped 경고 블록을 넘겨, `public/` 안의 `storage.broken.*` 가
        // 전부 링크·파일이거나 junction 인 사이트에서 「정리 불필요」만 남았다 — 그 경로는
        // 문서 루트 안에서 계속 서빙되는데 로그는 정리가 끝난 것처럼 읽힌다.

        $moved = 0;
        $failed = [];

        foreach ($candidates as $source) {
            $destination = $this->uniqueDestination($destinationDir, basename($source));

            if (@rename($source, $destination)) {
                $moved++;
                $context->logger->info('[core:7.2.0] 손상 백업을 문서 루트 밖으로 이동', [
                    'from' => $source,
                    'to' => $destination,
                ]);

                continue;
            }

            $failed[] = $source;
        }

        if ($failed !== []) {
            // 실패를 조용히 넘기지 않는다 — 그 사본은 지금도 웹에서 열린다.
            $context->logger->warning(
                '[core:7.2.0] 손상 백업을 옮기지 못했습니다 — 아래 디렉토리는 **문서 루트 안**에 있어 웹에서 접근 가능합니다. '
                .'내용을 확인한 뒤 문서 루트 밖으로 옮기거나 삭제하세요: '.implode(', ', $failed)
            );
        }

        if ($this->skipped !== []) {
            $context->logger->warning(
                '[core:7.2.0] 아래 항목은 디렉토리가 아니거나 심볼릭 링크라 옮기지 않았습니다 — '
                .'그 경로는 여전히 문서 루트 안에 있어 웹에서 접근 가능합니다. 직접 확인하세요: '
                .implode(', ', $this->skipped)
            );
        }

        // 로그 채널만으로는 부족하다. 이 스텝이 존재하는 이유가 「문서 루트 안의 공개 파일
        // 사본 제거」인데, 옮기지 못한 항목은 **그 상태가 그대로 남았다**는 사실이다 —
        // 형제 생산자(`StorageLinkHelper::ensurePublicStorageLink()`)는 같은 사실을
        // 통보 버퍼로 실어 콘솔·업데이트 기록까지 보내는데 이 자리만 `upgrade` 로그
        // 파일에서 끝났다. 같은 위험 등급이 한쪽은 콘솔, 한쪽은 로그 파일에만 남으면
        // 운영자는 모른다. 버퍼는 이 스텝을 도는 커맨드가 비운다(자식 프로세스이면
        // 라인 프로토콜로 부모에게 넘어간다).
        foreach (array_merge($failed, $this->skipped) as $exposed) {
            $this->recordExposureWarning($context, (string) __(
                'settings.core_update.storage_link_backup_inside_docroot',
                ['path' => $exposed]
            ));
        }

        $context->logger->info(
            "[core:7.2.0] 손상 백업 정리 완료 — 이동 {$moved}건, 실패 ".count($failed).'건, 건너뜀 '.count($this->skipped).'건'
        );

        // 옮긴 **결과**가 곧 누적 더미다 — 그 규모 통보가 이 경로에서만 빠져 있었다.
        //
        // 형제 생산자(링크 복구 폴백)는 백업을 하나 만들 때마다 누적 판정을 부르는데,
        // 이 스텝은 문서 루트 안의 N 개를 한꺼번에 옮겨 놓고 「이동 N건」만 남겼다.
        // 임계도 판정 규칙도 형제와 같은 것을 써야 두 경로의 안내가 갈리지 않는다.
        if ($moved > 0) {
            if ($this->storageLinkHelperHas('warnOnAccumulatedBackups')) {
                StorageLinkHelper::warnOnAccumulatedBackups($context->logger);
            } else {
                // 옛 헬퍼가 메모리에 있는 in-process 실행 — 누적 판정 메서드가 없다. 규모 통보를
                // 빠뜨리지 않도록 이번에 옮긴 개수와 위치를 로그 경고로 남긴다.
                $context->logger->warning(
                    "[core:7.2.0] 손상 백업 {$moved}건을 {$destinationDir} 로 옮겼습니다 — 내용을 확인한 뒤 정리하세요 "
                    .'(코어는 데이터 손실을 피하려 자동 삭제하지 않습니다)'
                );
            }
        }
    }

    /**
     * 문서 루트 노출 통보를 운영자 통로에 싣습니다.
     *
     * 통보 버퍼 메서드는 7.2.0 에서 생겼다. 옛 `StorageLinkHelper` 가 메모리에 있는 in-process
     * 실행에서는 그 메서드가 없으므로 같은 문구를 로그 경고로 남긴다(치명 종료 대신).
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @param  string  $warning  운영자 안내 문구
     * @return void 반환값 없음
     */
    private function recordExposureWarning(UpgradeContext $context, string $warning): void
    {
        if ($this->storageLinkHelperHas('recordDocrootExposureWarning')) {
            StorageLinkHelper::recordDocrootExposureWarning($warning);

            return;
        }

        $context->logger->warning('[core:7.2.0] '.$warning);
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
     * 웹 트리 안 손상 백업 이름을 판정하는 정규식을 돌려줍니다.
     *
     * `StorageLinkHelper::docrootBrokenNamePattern()` 과 같은 규칙을 스텝 안 상수로 만든다
     * (그 메서드는 7.2.0 에서 생겨 옛 헬퍼에는 없다).
     *
     * @return string `preg_match` 에 그대로 넘길 수 있는 완성 패턴
     */
    private static function docrootBrokenNamePattern(): string
    {
        return '/^'.preg_quote(self::DOCROOT_BROKEN_PREFIX, '/').self::BROKEN_STAMP.'$/';
    }

    /**
     * 옮기지 않고 건너뛴 항목 (링크·비디렉토리) — 여전히 문서 루트 안에 있다.
     *
     * @var array<int, string>
     */
    private array $skipped = [];

    /**
     * `public/` 최상위에서 손상 백업 디렉토리를 찾습니다.
     *
     * `glob()` 이 차단된 호스팅이 있으므로 그 함수를 쓰지 않고 `scandir()` 로 순회한다(폴백이 아니라 유일 경로다). 이름 규칙은 7.1.x 이하의
     * `storage.broken.<YmdHis>` 와 그 뒤 판본이 붙이는 유일화 접미사를 함께 받는다.
     *
     * @param  string  $publicPath  문서 루트(앱 public) 절대 경로
     * @return array<int, string> 이동 대상 절대 경로 목록
     */
    private function findBackups(string $publicPath): array
    {
        $found = [];

        try {
            $entries = @scandir($publicPath);
        } catch (Throwable) {
            $entries = false;
        }

        if ($entries === false) {
            return [];
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (! str_starts_with($entry, self::DOCROOT_BROKEN_PREFIX)) {
                continue;
            }

            // 접두사 **뒤 형식**까지 본다.
            //
            // 이 클래스의 독블록은 「7.1.x 이하의 `storage.broken.<YmdHis>` 와 그 뒤 판본의
            // 유일화 접미사」를 받는다고 적었는데, 코드는 접두사만 봤다 — 운영자가 손으로
            // 둔 `storage.broken.메모` 같은 디렉토리까지 말없이 옮겼다. 형제 정리 주체는
            // 같은 상황에서 이름 패턴 통과분만 모집단에 넣고 그 사유를 적어 두었다.
            // 이름 형식 판정은 **생산자 상수와 같은 값**으로 한다 (`StorageLinkHelper`). 종전에는
            // 여기와 정리 주체가 각자 리터럴을 들고 있었고, 접미사 그룹을 하나만 받아
            // 아래 `uniqueDestination()` 이 만든 이름(접미사 두 겹)이 정리 모집단 밖으로
            // 나갔다 — 공개 파일 전량 사본이 영원히 남는 형태다.
            // V-1 안전 때문에 헬퍼 상수를 직접 읽지 않고 이 클래스의 비공개 상수로 옮겨 두었으며,
            // 값 일치는 테스트가 대조한다.
            if (preg_match(self::docrootBrokenNamePattern(), $entry) !== 1) {
                $this->skipped[] = $publicPath.DIRECTORY_SEPARATOR.$entry;

                continue;
            }

            $path = $publicPath.DIRECTORY_SEPARATOR.$entry;

            // 심볼릭 링크는 옮기지 않는다 — 링크를 옮기면 그 대상이 아니라 링크만 이동해
            // 원본이 어디를 가리켰는지 알 수 없게 된다. 그 판정은 운영자에게 남긴다.
            if (is_link($path) || ! is_dir($path)) {
                // 옮기지는 않지만 **문서 루트 안에 남아 웹에서 열린다** — 이 스텝의 존재
                // 이유가 바로 그 상태의 제거다. 「이동 N건, 실패 0건」만 보고하면 운영자는
                // 정리가 끝난 것으로 읽는다.
                $this->skipped[] = $path;

                continue;
            }

            $found[] = $path;
        }

        sort($found);

        return $found;
    }

    /**
     * 대상 디렉토리에서 충돌하지 않는 이름을 만듭니다.
     *
     * @param  string  $destinationDir  이동 대상 디렉토리 (storage/app)
     * @param  string  $sourceName  원본 디렉토리 이름 (`storage.broken.YmdHis`)
     * @return string 사용 가능한 절대 경로
     */
    private function uniqueDestination(string $destinationDir, string $sourceName): string
    {
        // 7.2.0 이후 코어가 쓰는 이름 규칙과 같은 접두사로 맞춘다 — 한 자리에 모여야
        // 운영자가 무엇을 봐야 하는지 알 수 있다.
        $suffix = substr($sourceName, strlen(self::DOCROOT_BROKEN_PREFIX));
        $base = $destinationDir.DIRECTORY_SEPARATOR.self::PUBLIC_STORAGE_BROKEN_PREFIX.$suffix;

        if (! file_exists($base)) {
            return $base;
        }

        // 충돌 회피는 **난수 접미사**로 한다 (형제 생산자와 같은 규칙).
        //
        // 종전에는 무한 카운터였다 — 상한이 없고, `file_exists` 판정과 `rename` 사이가
        // 벌어져 두 실행이 겹치면 같은 이름을 고른다. 뒤 실행의 `rename` 은 대상이 비어
        // 있지 않아 실패하므로 손실은 없지만, 「옮기지 못했다」의 사유가 경합인지 권한인지
        // 구분되지 않는다. 형제 `StorageLinkHelper` 는 같은 문제를 난수로 풀고 그 사유를
        // 독블록에 적어 두었다.
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $candidate = $base.'-'.self::uniqueSuffix();

            if (! file_exists($candidate)) {
                return $candidate;
            }
        }

        // 여덟 번 모두 겹히면 그 자체가 이상 신호다 — 마지막 후보를 돌려주고 `rename`
        // 실패로 보고되게 둔다(조용히 덮어쓰지 않는다).
        return $base.'-'.self::uniqueSuffix();
    }

    /**
     * 겹치지 않는 짧은 접미사 (차단 가능한 함수 없이).
     *
     * 이 값은 비밀이 아니라 같은 디렉토리에서 겹치지 않을 이름이면 충분하다 — 형제
     * 생산자들과 같은 판단이다.
     *
     * @return string 16진 접미사
     */
    private static function uniqueSuffix(): string
    {
        try {
            return bin2hex(random_bytes(4));
        } catch (Throwable) {
            $seed = '';

            for ($i = 0; $i < 4; $i++) {
                $seed .= chr(mt_rand(0, 255));
            }

            return bin2hex($seed);
        }
    }
}
