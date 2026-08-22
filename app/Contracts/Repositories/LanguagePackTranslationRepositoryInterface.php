<?php

namespace App\Contracts\Repositories;

use App\Models\LanguagePack;

/**
 * 언어팩 활성/비활성 시 DB JSON 다국어 컬럼을 일괄 동기화하는 집계 Repository 의 계약.
 *
 * Permission/Role/Menu/Module/Plugin/Template/NotificationDefinition/NotificationTemplate/
 * IdentityMessageDefinition/IdentityMessageTemplate 10 모델의 JSON 컬럼에 대한 locale 키
 * 병합/제거를 단일 진입점으로 캡슐화합니다 (Service-Repository 패턴: Listener/Service 가 직접
 * 모델 정적 호출을 하지 못하도록 영속 책임을 일원화).
 *
 * 보존 정책 (활성/비활성 공통):
 *  - 모델의 `user_overrides` JSON 컬럼에 등록된 (column, locale) 키 또는 column 전체는 sync
 *    대상에서 제외 — 사용자가 의도적으로 수정한 값을 보존.
 *  - 활성 시: locale 키 부재 → 추가, locale 키 존재 + override 미등록 → 덮어쓰기, override 등록 → skip
 *  - 비활성 시: override 미등록 → 제거, override 등록 → 보존
 *
 * @since 7.0.0-beta.4
 */
interface LanguagePackTranslationRepositoryInterface
{
    /**
     * 언어팩의 seed/*.json 을 DB JSON 컬럼에 병합합니다.
     *
     * @param  LanguagePack  $pack  활성화된 언어팩
     * @param  array<string, array<string, mixed>>  $seedBundle  엔티티별 seed 데이터 (loadSeed 결과 묶음)
     * @return array<int, array<string, mixed>> 감사 로그 항목 (skipped/applied 결정)
     */
    public function applySeedFromPack(LanguagePack $pack, array $seedBundle): array;

    /**
     * 언어팩의 locale 키를 DB JSON 컬럼에서 제거합니다 (user_overrides 컬럼은 보존).
     *
     * @param  LanguagePack  $pack  비활성화된 언어팩
     * @return array<int, array<string, mixed>> 감사 로그 항목 (preserved/stripped 결정)
     */
    public function stripLocaleFromPack(LanguagePack $pack): array;
}
