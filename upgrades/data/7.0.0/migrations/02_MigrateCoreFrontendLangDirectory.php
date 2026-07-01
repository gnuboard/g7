<?php

namespace App\Upgrades\Data\V7_0_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;

/**
 * 코어 프론트엔드 다국어 JSON 의 위치를 모듈/플러그인 lang 디렉토리 구조에
 * 정렬한다 — `resources/js/core/lang/{ko,en}.json` → `lang/{ko,en}.json`.
 *
 * 결함:
 *   beta.8 이전의 코어 프론트엔드 다국어 자원(`resources/js/core/lang/`)은
 *   빌드 시 `public/build/core/lang/` 으로 복사만 되고 런타임에서 fetch
 *   되지 않아 `TemplateEngineError` 의 `$t:core.errors.*` 키가 raw 로
 *   노출되었다. beta.8 부터 `TemplateService::getLanguageDataWithModules`
 *   가 `lang/{locale}.json` 을 베이스 레이어로 자동 병합한다.
 *
 *   본 마이그레이션은:
 *     1) 사용자가 `resources/js/core/lang/{ko,en}.json` 을 커스터마이즈
 *        했을 가능성에 대비해 그 내용을 신 위치(`lang/{ko,en}.json`) 가
 *        부재한 경우에만 이동시키고 (덮어쓰기 금지),
 *     2) 잔존 빈 디렉토리(`resources/js/core/lang/`) 를 정리한다.
 *
 *   대부분의 환경은 `applyUpdate` 단계에서 디스크 트리가 새 버전으로
 *   덮어쓰여 이미 정상 상태이므로 본 마이그레이션은 빈 디렉토리 정리만
 *   수행한다 (덮어쓰기 금지 가드로 안전).
 *
 * 멱등성:
 *   - 이미 신 위치에 파일이 있으면 이동 skip (덮어쓰기 금지)
 *   - 레거시 디렉토리가 부재하면 즉시 skip
 *   - 두 번 실행해도 동일 결과
 *
 * 부분 마이그레이션 안전성:
 *   - ko 만 있고 en 이 없는 경우도 정상 처리
 *
 * V-1 안전 격리 (docs/extension/upgrade-step-guide.md):
 *   - 파일 시스템 조작만 수행 — DB/Eloquent/Service 의존 없음
 *   - PHP 빌트인 함수(`file_exists`, `rename`, `is_dir`, `rmdir`) 만 사용
 */
final class MigrateCoreFrontendLangDirectory implements DataMigration
{
    /**
     * 마이그레이션 식별자 (로그용).
     *
     * @return string 사람이 읽을 수 있는 짧은 식별자
     */
    public function name(): string
    {
        return 'MigrateCoreFrontendLangDirectory';
    }

    /**
     * 레거시 코어 프론트엔드 lang JSON 을 신 위치로 이동하고 빈 디렉토리를 정리한다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트 (로거 등)
     */
    public function run(UpgradeContext $context): void
    {
        $basePath = base_path();
        $legacyDir = $basePath.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'js'
            .DIRECTORY_SEPARATOR.'core'.DIRECTORY_SEPARATOR.'lang';

        if (! is_dir($legacyDir)) {
            $context->logger->info('[7.0.0] 코어 프론트엔드 lang 마이그레이션 skip — 레거시 디렉토리 부재');

            return;
        }

        $moved = 0;
        $skipped = 0;

        foreach (['ko', 'en'] as $locale) {
            $legacyFile = $legacyDir.DIRECTORY_SEPARATOR.$locale.'.json';
            $newFile = $basePath.DIRECTORY_SEPARATOR.'lang'.DIRECTORY_SEPARATOR.$locale.'.json';

            if (! file_exists($legacyFile)) {
                continue;
            }

            if (file_exists($newFile)) {
                // 신 위치에 이미 파일이 있으면 덮어쓰지 않고 레거시만 정리
                @unlink($legacyFile);
                $skipped++;
                $context->logger->info(sprintf(
                    '[7.0.0] 코어 프론트엔드 lang 이동 skip — 신 위치에 이미 존재: %s.json',
                    $locale
                ));

                continue;
            }

            if (@rename($legacyFile, $newFile)) {
                $moved++;
                $context->logger->info(sprintf(
                    '[7.0.0] 코어 프론트엔드 lang 이동 완료: resources/js/core/lang/%s.json → lang/%s.json',
                    $locale,
                    $locale
                ));
            } else {
                $context->logger->warning(sprintf(
                    '[7.0.0] 코어 프론트엔드 lang 이동 실패: %s.json (권한/잠금 가능성)',
                    $locale
                ));
            }
        }

        // 빈 레거시 디렉토리 정리 (다른 파일이 남아 있으면 rmdir 실패 — 보존)
        if (is_dir($legacyDir)) {
            $remaining = @scandir($legacyDir);
            if (is_array($remaining) && count(array_diff($remaining, ['.', '..'])) === 0) {
                @rmdir($legacyDir);
                $context->logger->info('[7.0.0] 빈 레거시 디렉토리 정리: resources/js/core/lang/');
            } else {
                $context->logger->info('[7.0.0] 레거시 디렉토리에 잔존 파일 — 보존');
            }
        }

        $context->logger->info(sprintf(
            '[7.0.0] 코어 프론트엔드 lang 마이그레이션 완료 — 이동 %d건, skip %d건',
            $moved,
            $skipped
        ));
    }
}
