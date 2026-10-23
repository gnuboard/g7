<?php

namespace App\Upgrades\Data\V7_1_3\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\Artisan;

/**
 * 확장 오토로드 캐시와 훅 캐시를 다시 만들어 7.1.3 의 「활성 확장 파일 지문」을 싣습니다.
 *
 * 7.1.3 부터 두 캐시는 활성 확장의 매니페스트·진입 파일·composer.json 수정 시각을 함께 기록하고, 부팅 때 다르면
 * (확장 파일만 교체) 스스로 다시 만든다. 7.1.2 이하가 만든 캐시에는 지문이 없어 그 판정을 하지 못하므로 한 번 다시 만든다.
 *
 * V-1 판단: 코어 artisan 명령 이름(`extension:update-autoload` — 오토로드·훅·config 캐시를 함께 다시 만든다)만 부른다.
 * 명령이 경고와 함께 끝나면(종료코드 1 + 경고 표지) 업데이트를 막지 않고 경고 로그만 남긴다.
 *
 * idempotent: 다시 돌려도 같은 내용의 캐시를 다시 쓸 뿐이다.
 */
class RegenerateExtensionCachesWithFingerprint implements DataMigration
{
    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'RegenerateExtensionCachesWithFingerprint';
    }

    /**
     * 확장 오토로드·훅 캐시를 다시 만듭니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        try {
            $exitCode = Artisan::call('extension:update-autoload');
        } catch (\Throwable $e) {
            $context->logger->warning('[7.1.3] 확장 캐시 재생성 실패 — 업데이트 뒤 php artisan extension:update-autoload 를 실행하세요: '.$e->getMessage());

            return;
        }

        if ($exitCode === 0) {
            $context->logger->info('[7.1.3] 확장 오토로드·훅 캐시를 지문과 함께 다시 만들었습니다');

            return;
        }

        $context->logger->warning('[7.1.3] 확장 캐시 재생성이 경고와 함께 끝났습니다(종료코드 '.$exitCode.'): '.trim(Artisan::output()));
    }
}
