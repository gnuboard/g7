<?php

namespace Tests\Feature\LanguagePack;

use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackSourceType;
use App\Enums\LanguagePackStatus;
use App\Models\LanguagePack;
use App\Services\LanguagePack\LanguagePackBaseLocales;
use App\Services\LanguagePackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 언어팩 시스템 PO 8대 요구사항 정합성 검증 통합 테스트.
 *
 * 본 테스트는 abstract-foraging-pony.md 계획서의 §1~§7 핵심 동작을
 * 한 파일로 통합 검증합니다. 도메인 매트릭스: Pure Logic + CRUD 혼합.
 */
class PolicyAlignmentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * PO #1, #2: base locale 판정 헬퍼 동작 검증.
     */
    public function test_base_locale_helper_recognizes_ko_en_only(): void
    {
        $this->assertTrue(LanguagePackBaseLocales::isBaseLocale('ko'));
        $this->assertTrue(LanguagePackBaseLocales::isBaseLocale('en'));
        $this->assertFalse(LanguagePackBaseLocales::isBaseLocale('ja'));
        $this->assertFalse(LanguagePackBaseLocales::isBaseLocale('fr'));
        $this->assertSame(['ko', 'en'], LanguagePackBaseLocales::all());
    }

    /**
     * PO #1: DB 비어있을 때 코어 lang/ko, lang/en 디렉토리에 대해 가상 보호 행이 합성된다.
     */
    public function test_virtual_built_in_packs_synthesized_for_core_locales(): void
    {
        // 코어 lang 디렉토리는 실제 존재 가정 (RefreshDatabase 후 lang 디렉토리 영향 없음)
        $svc = app(LanguagePackService::class);
        $virtuals = $svc->getVirtualBuiltInPacks();

        $coreKo = $virtuals->first(fn (LanguagePack $p) => $p->scope === LanguagePackScope::Core->value && $p->locale === 'ko');
        $coreEn = $virtuals->first(fn (LanguagePack $p) => $p->scope === LanguagePackScope::Core->value && $p->locale === 'en');

        $this->assertNotNull($coreKo, '코어 ko 가상 보호 행이 합성되어야 함');
        $this->assertNotNull($coreEn, '코어 en 가상 보호 행이 합성되어야 함');

        foreach ([$coreKo, $coreEn] as $pack) {
            $this->assertSame(LanguagePackStatus::Active->value, $pack->status);
            $this->assertTrue($pack->is_protected);
            $this->assertSame(LanguagePackSourceType::BuiltIn->value, $pack->source_type);
            $this->assertFalse($pack->exists);
        }
    }

    /**
     * PO #1, slot dedup: 같은 슬롯에 DB 행이 있으면 가상 행은 list 응답에서 스킵된다.
     */
    public function test_db_row_overrides_virtual_built_in_in_same_slot(): void
    {
        // 코어 ko 슬롯에 DB 행 INSERT (사용자가 외부 ko 팩 설치한 시나리오)
        LanguagePack::create([
            'identifier' => 'external-core-ko',
            'vendor' => 'external',
            'scope' => LanguagePackScope::Core->value,
            'target_identifier' => null,
            'locale' => 'ko',
            'locale_name' => 'KO',
            'locale_native_name' => '한국어',
            'text_direction' => 'ltr',
            'version' => '1.0.0',
            'status' => LanguagePackStatus::Active->value,
            'is_protected' => false,
            'manifest' => ['identifier' => 'external-core-ko'],
            'source_type' => LanguagePackSourceType::Github->value,
            'source_url' => 'https://github.com/external/core-ko',
        ]);

        $svc = app(LanguagePackService::class);
        $res = $svc->list([], 200);

        $coreKoRows = collect($res->items())->filter(
            fn (LanguagePack $p) => $p->scope === LanguagePackScope::Core->value && $p->locale === 'ko'
        );

        $this->assertCount(1, $coreKoRows, '코어 ko 슬롯에 단 한 행만 있어야 함');
        $first = $coreKoRows->first();
        $this->assertSame('external-core-ko', $first->identifier, 'DB 행이 우선해야 함');
        $this->assertFalse($first->is_protected, 'DB 행은 비보호');
    }

    /**
     * PO #5: activate 시 의존성/버전 검사. 호스트 모듈이 없으면 실패해야 한다.
     */
    public function test_activate_fails_when_target_extension_does_not_exist(): void
    {
        $pack = LanguagePack::create([
            'identifier' => 'test-module-nonexistent-ja',
            'vendor' => 'test',
            'scope' => LanguagePackScope::Module->value,
            'target_identifier' => 'nonexistent-module',
            'locale' => 'ja',
            'locale_name' => 'JA',
            'locale_native_name' => '日本語',
            'text_direction' => 'ltr',
            'version' => '1.0.0',
            'status' => LanguagePackStatus::Inactive->value,
            'is_protected' => false,
            'manifest' => [
                'identifier' => 'test-module-nonexistent-ja',
                'scope' => 'module',
                'target_identifier' => 'nonexistent-module',
                'locale' => 'ja',
                'requires' => ['depends_on_core_locale' => false],
            ],
            'source_type' => LanguagePackSourceType::Bundled->value,
        ]);

        $svc = app(LanguagePackService::class);
        $this->expectException(\RuntimeException::class);
        $svc->activate($pack);
    }

    /**
     * PO #3: install 흐름의 lang-packs/_bundled/ 패키지는 비보호로 등록되어야 한다.
     *
     * 실제 install 은 ZIP 추출이 필요하므로 buildPackData 의 출력을 직접 검증.
     */
    public function test_install_flow_produces_unprotected_packs(): void
    {
        // 가상 행도 비보호 (PO #3): 미설치 가상 행은 lang-packs/_bundled/ 의 ja 패키지 표현
        $repo = app(\App\Contracts\Repositories\LanguagePackRepositoryInterface::class);
        $manifest = [
            'identifier' => 'g7-module-test-ja',
            'vendor' => 'g7',
            'scope' => 'module',
            'target_identifier' => 'test-module',
            'locale' => 'ja',
            'version' => '1.0.0',
        ];
        $virtual = $repo->buildVirtualFromManifest($manifest, 'g7-module-test-ja');
        $this->assertFalse($virtual->is_protected, 'lang-packs/_bundled/ 의 패키지는 비보호여야 함');
    }

    /**
     * PO #6: 호스트 확장 deactivate cascade 헬퍼 stash/get 라운드트립.
     */
    public function test_reactivation_stash_round_trip(): void
    {
        $registrar = app(\App\Services\LanguagePack\LanguagePackBundledRegistrar::class);

        // inactive 팩 생성
        $pack = LanguagePack::create([
            'identifier' => 'test-module-stash-ja',
            'vendor' => 'test',
            'scope' => LanguagePackScope::Module->value,
            'target_identifier' => 'stash-module',
            'locale' => 'ja',
            'locale_name' => 'JA',
            'locale_native_name' => '日本語',
            'text_direction' => 'ltr',
            'version' => '1.0.0',
            'status' => LanguagePackStatus::Inactive->value,
            'is_protected' => false,
            'manifest' => [],
            'source_type' => LanguagePackSourceType::Bundled->value,
        ]);

        $registrar->stashDeactivatedForReactivation('module', 'stash-module', [$pack->id]);
        $pending = $registrar->getPendingForReactivation('module', 'stash-module');

        $this->assertCount(1, $pending);
        $this->assertSame($pack->id, $pending[0]['id']);
        $this->assertSame('日本語', $pending[0]['locale_native_name']);

        // 두 번 호출하면 cache 가 비워져 빈 배열 반환 (PO #8: 모달 표시 안 함)
        $second = $registrar->getPendingForReactivation('module', 'stash-module');
        $this->assertEmpty($second);
    }

    /**
     * 개별 확장 언어팩 관리 화면 (target_identifier 필터) 에서 코어 팩이 노출되지 않는다.
     *
     * 환경설정 > 언어팩 관리 (필터 없음) 에서는 코어가 보여야 하지만, plugin/module/template
     * 개별 화면은 그 확장에 해당되는 행만 노출되어야 함.
     */
    public function test_extension_filter_excludes_core_packs(): void
    {
        $svc = app(LanguagePackService::class);

        // 필터 없음 → 코어 ko/en 가상 보호 행 포함
        $unfiltered = $svc->list([], 200);
        $hasCoreKo = collect($unfiltered->items())->contains(
            fn (LanguagePack $p) => $p->scope === LanguagePackScope::Core->value && $p->locale === 'ko'
        );
        $this->assertTrue($hasCoreKo, '필터 없는 화면에서 코어 ko 가 노출되어야 함');

        // 플러그인 필터 → 코어 팩 제외, 그 플러그인 행만
        $filtered = $svc->list(['target_identifier' => 'sirsoft-tosspayments'], 200);
        $corePacksLeaked = collect($filtered->items())->filter(
            fn (LanguagePack $p) => $p->scope === LanguagePackScope::Core->value
        );
        $this->assertCount(0, $corePacksLeaked, '플러그인 필터 시 코어 팩 노출 금지');

        // 그 플러그인의 자체 built_in 은 여전히 노출
        $hasOwnKo = collect($filtered->items())->contains(
            fn (LanguagePack $p) => $p->scope === LanguagePackScope::Plugin->value
                && $p->target_identifier === 'sirsoft-tosspayments'
                && $p->locale === 'ko'
        );
        $this->assertTrue($hasOwnKo, '플러그인 자체 built_in ko 는 노출되어야 함');
    }

    /**
     * §3: LanguagePackBundledSeeder 클래스 부재 검증.
     */
    public function test_bundled_seeder_class_does_not_exist(): void
    {
        $this->assertFalse(
            class_exists('Database\\Seeders\\LanguagePackBundledSeeder'),
            'LanguagePackBundledSeeder 는 폐지되어 존재하지 않아야 함'
        );
    }

    /**
     * §9: 신규 Enum 클래스가 모두 정의되어 있고 핵심 cases 를 노출한다.
     */
    public function test_new_enums_are_defined_with_expected_cases(): void
    {
        $this->assertTrue(enum_exists(\App\Enums\LanguagePackSourceType::class));
        $this->assertTrue(enum_exists(\App\Enums\LanguagePackErrorCode::class));
        $this->assertTrue(enum_exists(\App\Enums\TextDirection::class));
        $this->assertTrue(enum_exists(\App\Enums\LanguagePackAbility::class));

        $this->assertContains('built_in', \App\Enums\LanguagePackSourceType::values());
        $this->assertContains('bundled_with_extension', \App\Enums\LanguagePackSourceType::values());
        $this->assertTrue(\App\Enums\LanguagePackSourceType::BuiltIn->isProtectedByDefault());
        $this->assertFalse(\App\Enums\LanguagePackSourceType::Bundled->isProtectedByDefault());
    }
}
