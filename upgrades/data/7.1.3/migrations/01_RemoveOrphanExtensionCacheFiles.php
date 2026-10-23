<?php

namespace App\Upgrades\Data\V7_1_3\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;

/**
 * 만드는 코드도 읽는 코드도 없는 고아 확장 캐시 파일을 지웁니다.
 *
 * `bootstrap/cache/extension-schedule-commands.php`·`bootstrap/cache/extension-artifacts.json` 은 지난 판에서 생성기가
 * 사라졌는데 파일만 남아 있을 수 있다. 쓰는 곳이 없으니 지워도 동작이 바뀌지 않고, 남겨 두면 운영자가 「확장 캐시」로
 * 오해해 문제를 엉뚱한 곳에서 찾는다(7.1.3 캐시 전수 조사).
 *
 * V-1 판단: 파일 경로 리터럴과 PHP 파일 함수만 쓴다 — 모델·서비스를 부르지 않는다.
 *
 * idempotent: 파일이 없으면 건너뛴다(로그만 남긴다).
 */
class RemoveOrphanExtensionCacheFiles implements DataMigration
{
    /**
     * 지울 고아 파일 (base_path 기준 상대 경로, 동결 리터럴).
     */
    private const ORPHANS = [
        'bootstrap/cache/extension-schedule-commands.php',
        'bootstrap/cache/extension-artifacts.json',
    ];

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'RemoveOrphanExtensionCacheFiles';
    }

    /**
     * 고아 파일이 있으면 지웁니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        foreach (self::ORPHANS as $relative) {
            $path = base_path($relative);

            if (! is_file($path)) {
                $context->logger->info("[7.1.3] 고아 캐시 파일 없음 — 건너뜀: {$relative}");

                continue;
            }

            if (@unlink($path)) {
                $context->logger->info("[7.1.3] 고아 캐시 파일 삭제: {$relative}");
            } else {
                $context->logger->warning("[7.1.3] 고아 캐시 파일을 지우지 못했습니다(권한) — 지워도 되는 파일입니다: {$relative}");
            }
        }
    }
}
