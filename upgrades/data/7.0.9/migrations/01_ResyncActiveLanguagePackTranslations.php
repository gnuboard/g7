<?php

namespace App\Upgrades\Data\V7_0_9\Migrations;

use App\Extension\HookManager;
use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use App\Models\LanguagePack;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * 활성 언어팩의 seed 번역을 DB JSON 컬럼에 재동기화합니다.
 *
 * 7.0.9 이전 버전에는 언어팩 번역이 DB 에서 소실되는 두 결함이 있었다:
 *
 *   1) 알림/본인인증 템플릿의 [기본값 복원]이 복원 기본값에 활성 언어팩 로케일을
 *      포함하지 않아, 복원 시 팩이 주입해 둔 로케일(ja 등)과 보존 선언(user_overrides)이
 *      config 의 ko/en 대체로 영구 소실됐다.
 *   2) 코어 시더의 언어팩 필터 주입이 페이로드 형태 불일치로 전면 불능이라, 팩 활성화
 *      이후 코어 업그레이드가 추가한 알림 정의 행에는 팩 로케일이 주입되지 않았다.
 *
 * 소스 교정(7.0.9)만으로는 이미 소실된 설치본 데이터가 낫지 않으므로, 활성 언어팩마다
 * 활성화 훅(core.language_packs.activated)을 재발화해 seed 번역을 병합 복구한다 —
 * 정상 활성화와 완전히 같은 경로이므로 활성화 시점과 동일한 결과가 재현된다.
 *
 * idempotent + 운영자 수정 보존: 병합 리스너(SyncDatabaseTranslations → applySeedFromPack)는
 *   - locale 키 부재 → 추가 (소실분 복구)
 *   - locale 키 존재 + user_overrides 에 컬럼 등록 → 건너뜀 (운영자 수정 보존)
 *   - locale 키 존재 + user_overrides 미등록 → seed 값으로 갱신
 * 이라 여러 번 실행해도 결과가 같고 운영자가 직접 수정한 번역을 덮지 않는다.
 *
 * V-1 안전: 상태값은 enum 참조 대신 'active' 리터럴을 쓴다 (본 디렉토리는 릴리즈 후
 * 동결되므로 스냅샷 시점의 DB 저장값 자체를 고정). LanguagePack 모델과 HookManager 는
 * 언어팩 도입 시점부터 존재하는 기존 클래스의 기존 표면만 사용한다.
 */
class ResyncActiveLanguagePackTranslations implements DataMigration
{
    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'ResyncActiveLanguagePackTranslations';
    }

    /**
     * 활성 언어팩 전수에 활성화 훅을 재발화해 seed 번역을 병합 복구합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void
     */
    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable('language_packs')) {
            $context->logger->info('[core:7.0.9] language_packs 테이블 부재 — 언어팩 seed 재동기화 스킵');

            return;
        }

        $packs = LanguagePack::query()->where('status', 'active')->get();

        if ($packs->isEmpty()) {
            $context->logger->info('[core:7.0.9] 활성 언어팩 없음 — 언어팩 seed 재동기화 스킵');

            return;
        }

        foreach ($packs as $pack) {
            try {
                HookManager::doAction('core.language_packs.activated', $pack);
                $context->logger->info('[core:7.0.9] 언어팩 seed 번역 재동기화 완료: '.$pack->identifier);
            } catch (Throwable $e) {
                // 개별 팩 실패(디렉토리 손상 등)가 업그레이드 전체를 막지 않도록 격리 —
                // 남은 팩은 계속 처리하고, 실패 팩은 로그로 남겨 운영자가 후속 조치한다.
                $context->logger->warning(
                    '[core:7.0.9] 언어팩 seed 재동기화 실패(건너뜀): '.$pack->identifier.' — '.$e->getMessage()
                );
            }
        }
    }
}
