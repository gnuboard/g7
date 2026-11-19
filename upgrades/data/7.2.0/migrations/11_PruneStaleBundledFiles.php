<?php

namespace App\Upgrades\Data\V7_2_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;

/**
 * 7.1.x 코어 업데이트가 번들 디렉토리(`{modules,plugins,templates,lang-packs}/_bundled`)에 남긴, 7.2.0 에서 지운
 * 파일을 정리합니다.
 *
 * 7.1.x → 7.2.0 업데이트의 파일 적용은 부모 프로세스(7.1.x 코드)가 한다. 7.1.x 의 증분 적용도 7.0.x 와 같이 번들 확장
 * 디렉토리 *내부*의 지워진 파일을 prune 모드에서만 지우므로, 7.2.0 에서 지운 번들 파일이 그대로 남는다. 뒤이은 확장
 * 갱신은 `_bundled` 를 활성 디렉토리로 통째로 교체 복사하고(`ExtensionPendingHelper::copyToActive`), 조각 등록은 활성
 * 디렉토리를 glob 하며, 고아 정리(`LayoutExtensionService::removeOrphanFragments`)는 「파일이 실재하는 키」 기준이라 옛
 * 조각 파일을 살아 있는 조각으로 판정했다.
 * 실측(7.1.6 → 7.2.0): 7.2.0 이 지운 조각 — 쇼핑몰 `register-currency-field`·`register-shipping-country-field`·
 * `admin-user-currency-field`·`admin-user-shipping-country-field`, 마케팅 `user-marketing-register`·`user-marketing-form`·
 * `user-marketing-profile`·`user-marketing-profile-view`, 게시판 `user-notification-settings`·`user-notification-detail` —
 * 의 행이 그대로 살아, 가입 화면·관리자 회원 수정/상세·마이페이지에 지워진 문구 키가 그대로 보이고 결제 통화·동의 칸이
 * 두 벌로 나왔다(새 설치에는 없는 상태).
 *
 * 7.1.0 의 같은 이름 스텝(`data/7.1.0/migrations/09_PruneStaleBundledFiles`)과 같은 규칙이다 — 다음을 모두 만족하는
 * 파일만 지운다:
 *  - 동결 목록(`upgrades/data/7.2.0/stale_bundled_files.snapshot.json`)에 있는 경로다. 7.1.0~7.1.6 정식 배포본의 번들에
 *    있었고 7.2.0 번들에 없는 파일을 git 으로 산출해 박았다 — 번들 확장 디렉토리 *내부* 경로만 담긴다.
 *  - 본문(CRLF→LF 정규화)의 sha256 이 그 경로가 7.0.0~7.1.6 배포본에서 가졌던 값 중 하나다. 누군가 고친 파일은
 *    지우지 않고 경고만 남긴다.
 *  - 일반 파일이다(심볼릭 링크·디렉토리는 건드리지 않는다).
 *  - 확장 소스 폴더(`src/`)의 PHP 클래스 파일이 아니다 — 그 파일은 남긴다. 확장 갱신이 옮겨 심는 옛 `vendor/composer`
 *    클래스맵과 갱신 끝에야 다시 굽는 훅 캐시가 그 클래스를 가리키는 동안 파일이 없으면 요청이 500 이 된다.
 *    새 판 선언은 그 클래스를 부르지 않으므로 남겨도 동작은 같다(종전 「남은 파일」 상태와 같음 — 화면 조각만 문제였다).
 * 지운 뒤 비게 된 상위 디렉토리는 확장 루트(`{type}/_bundled/{id}`) 아래까지만 정리한다.
 *
 * 활성 디렉토리는 건드리지 않는다. 이 스텝은 번들 확장 일괄 업데이트보다 먼저 도는데, 그 시점의 활성 디렉토리는
 * 아직 7.1.x 판 확장이 쓰는 파일이다(지우면 갱신 전 확장이 깨진다). 확장 갱신은 활성 디렉토리를 소스로 통째로
 * 교체하므로(운영자 소유 `custom/` 만 옮겨 심는다) `_bundled` 만 바로잡으면 갱신 뒤 활성 디렉토리도 새 판과 같아지고,
 * 이미 등록된 옛 조각 행은 그 갱신의 고아 정리가 지운다(운영자가 고치지 않은 행 — 레이아웃 보존(keep) 선택에서도
 * 고친 행만 남는다). 번들 일괄 업데이트를 건너뛴 사이트는 확장이 7.1.x 판 그대로라 옛 조각이 그 판의 문구와 함께
 * 맞게 그려지고, 나중에 그 확장을 갱신할 때 같은 정리가 일어난다.
 *
 * V-1 안전: PHP 내장 파일 함수와 `$context->logger` 만 쓴다 — 7.2.0 신규 헬퍼·모델·파사드 메서드를 참조하지 않는다.
 *
 * idempotent: 두 번째 실행에서는 지울 파일이 없어 아무것도 바꾸지 않는다.
 */
class PruneStaleBundledFiles implements DataMigration
{
    /**
     * 번들 확장 루트 경로 패턴 — 이 아래(확장 디렉토리 내부)만 지운다.
     */
    private const BUNDLED_PATH_PATTERN = '#^(modules|plugins|templates|lang-packs)/_bundled/[A-Za-z0-9][A-Za-z0-9_.-]*/.+#';

    /**
     * 지우지 않고 남기는 파일 — 확장 소스 폴더(`src/`)의 PHP 클래스 파일.
     *
     * 확장 갱신은 composer 의존성이 그대로면 활성 폴더의 옛 `vendor/` 를 새 판에 옮겨 심는다(`copyVendorFromActive`). 그
     * `vendor/composer` 클래스맵은 옛 `src/` 기준이라 지운 클래스를 여전히 가리키고, 갱신이 훅 캐시를 다시 굽기 전까지 요청은
     * 옛 훅 캐시로 그 리스너를 부른다 — 파일이 없으면 include 실패로 그 요청이 500 이 된다(7.1.6 부모가 번들 일괄
     * 전에 점검 모드를 풀어 열린 구간의 홈 1건). 남긴 클래스 파일은 새 판 선언(`getHookListeners` 등)이 부르지 않아 무해하다.
     */
    private const KEEP_PATH_PATTERN = '#^(modules|plugins|templates|lang-packs)/_bundled/[A-Za-z0-9][A-Za-z0-9_.-]*/src/.+\.php$#';

    /**
     * @param  string|null  $basePath  설치 루트 (null 이면 base_path() — 테스트가 임시 루트를 주입한다)
     * @param  array<string, array<int, string>>|null  $manifest  경로 → 허용 해시 목록 (null 이면 동결 목록)
     */
    public function __construct(
        private readonly ?string $basePath = null,
        private readonly ?array $manifest = null,
    ) {}

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'PruneStaleBundledFiles';
    }

    /**
     * 동결 목록의 7.1.x 판 번들 파일을 지웁니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        $files = $this->manifest ?? $this->loadManifest($context);

        if ($files === null) {
            return;
        }

        $root = rtrim(str_replace('\\', '/', $this->basePath ?? base_path()), '/');
        $removed = [];
        $kept = [];
        $retainedClasses = [];

        foreach ($files as $relative => $hashes) {
            $relative = str_replace('\\', '/', (string) $relative);

            if (! preg_match(self::BUNDLED_PATH_PATTERN, $relative) || str_contains($relative, '..')) {
                $context->logger->warning('[core:7.2.0] 번들 확장 내부가 아닌 경로는 정리하지 않음', ['path' => $relative]);

                continue;
            }

            $absolute = $root.'/'.$relative;

            if (is_link($absolute) || ! is_file($absolute)) {
                continue;
            }

            if (preg_match(self::KEEP_PATH_PATTERN, $relative)) {
                $retainedClasses[] = $relative;

                continue;
            }

            $content = @file_get_contents($absolute);

            if ($content === false) {
                $kept[] = $relative;
                $context->logger->warning('[core:7.2.0] 남은 7.1.x 번들 파일을 읽을 수 없어 보존', ['path' => $relative]);

                continue;
            }

            if (! in_array(hash('sha256', str_replace("\r\n", "\n", $content)), (array) $hashes, true)) {
                $kept[] = $relative;
                $context->logger->warning('[core:7.2.0] 7.2.0 에서 지운 번들 파일이지만 본문이 이전 배포본과 달라 보존 — 확인 후 직접 지우세요', ['path' => $relative]);

                continue;
            }

            if (! @unlink($absolute)) {
                $kept[] = $relative;
                $context->logger->warning('[core:7.2.0] 남은 7.1.x 번들 파일 삭제 실패 (권한 확인)', ['path' => $relative]);

                continue;
            }

            $removed[] = $relative;
            $this->pruneEmptyParents($root, $relative);
        }

        if ($retainedClasses !== []) {
            $context->logger->info(sprintf(
                '[core:7.2.0] 7.2.0 에서 지운 확장 PHP 클래스 파일 %d건은 남김 — 확장 갱신이 옛 클래스맵·훅 캐시로 부르는 동안 사라지면 그 요청이 500 이 된다. 새 판은 이 클래스를 쓰지 않는다',
                count($retainedClasses)
            ), ['retained' => $retainedClasses]);
        }

        if ($removed === [] && $kept === []) {
            $context->logger->info('[core:7.2.0] 7.1.x 가 남긴 번들 파일 0건');

            return;
        }

        $context->logger->info(sprintf(
            '[core:7.2.0] 7.1.x 가 남긴 번들 파일 %d건 정리, %d건 보존',
            count($removed),
            count($kept)
        ), ['removed' => $removed, 'kept' => $kept]);
    }

    /**
     * 동결 목록을 읽습니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return array<string, array<int, string>>|null 경로 → 허용 해시 목록 (읽지 못하면 null)
     */
    private function loadManifest(UpgradeContext $context): ?array
    {
        $path = dirname(__DIR__).'/stale_bundled_files.snapshot.json';
        $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (! is_array($decoded) || ! is_array($decoded['files'] ?? null)) {
            $context->logger->warning('[core:7.2.0] 동결 목록 stale_bundled_files.snapshot.json 을 읽을 수 없음 — 번들 파일 정리 스킵');

            return null;
        }

        return $decoded['files'];
    }

    /**
     * 지운 파일의 상위 디렉토리 중 비게 된 것을 확장 루트 아래까지만 지웁니다.
     *
     * @param  string  $root  설치 루트
     * @param  string  $relative  지운 파일의 상대 경로
     * @return void 반환값 없음
     */
    private function pruneEmptyParents(string $root, string $relative): void
    {
        // {type}/_bundled/{id} 세 단계는 확장 루트까지 — 그보다 깊은 디렉토리만 정리 대상이다.
        $segments = explode('/', $relative);
        array_pop($segments);

        while (count($segments) > 3) {
            $dir = $root.'/'.implode('/', $segments);

            if (is_link($dir) || ! is_dir($dir) || (scandir($dir) ?: ['.', '..', 'x']) !== ['.', '..'] || ! @rmdir($dir)) {
                return;
            }

            array_pop($segments);
        }
    }
}
