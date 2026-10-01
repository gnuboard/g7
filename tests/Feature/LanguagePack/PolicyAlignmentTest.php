<?php

namespace Tests\Feature\LanguagePack;

use App\Contracts\Repositories\LanguagePackRepositoryInterface;
use App\Enums\LanguagePackAbility;
use App\Enums\LanguagePackErrorCode;
use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackSourceType;
use App\Enums\LanguagePackStatus;
use App\Enums\TextDirection;
use App\Extension\PluginManager;
use App\Models\LanguagePack;
use App\Models\Plugin;
use App\Services\LanguagePack\LanguagePackBaseLocales;
use App\Services\LanguagePack\LanguagePackBundledRegistrar;
use App\Services\LanguagePackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 언어팩 시스템 8대 요구사항 정합성 검증 통합 테스트.
 *
 * 본 테스트는 abstract-foraging-pony.md 계획서의 §1~§7 핵심 동작을
 * 한 파일로 통합 검증합니다. 도메인 매트릭스: Pure Logic + CRUD 혼합.
 *
 * 소유 시나리오: `language-pack-policy-alignment`
 */
class PolicyAlignmentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 요구사항 #1, #2: base locale 판정 헬퍼가 ko·en 만 기본 로케일로 인정하는지 검증합니다.
     *
     * @effects base_locale_helper_recognizes_ko_en_only
     *
     * @return void 반환값 없음
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
     * 요구사항 #1: DB 가 비어 있을 때 코어 lang/ko·lang/en 에 대해 가상 보호 행이 합성되는지 검증합니다.
     *
     * @scenario row_origin=core_virtual
     *
     * @effects core_locales_synthesized_as_protected_virtual_rows
     *
     * @return void 반환값 없음
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
     * 요구사항 #1: 같은 슬롯에 DB 행이 있으면 가상 행은 목록에서 빠지고 DB 행이 우선하는지 검증합니다.
     *
     * @scenario row_origin=db_row
     *
     * @effects db_row_overrides_virtual_built_in_in_same_slot
     *
     * @return void 반환값 없음
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
     * 요구사항 #5: 활성화 시 호스트 확장이 없으면 실패하는지 검증합니다.
     *
     * @effects activation_requires_installed_host_extension
     *
     * @return void 반환값 없음
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
     * 요구사항 #3: 언어팩 번들 패키지(lang-packs/_bundled)는 비보호 행으로 표현되는지 검증합니다.
     *
     * 실제 설치는 ZIP 추출이 필요하므로 가상 행 합성 결과를 직접 검증한다.
     *
     * @scenario row_origin=bundled_package
     *
     * @effects bundled_language_pack_packages_are_unprotected
     *
     * @return void 반환값 없음
     */
    public function test_install_flow_produces_unprotected_packs(): void
    {
        // 가상 행도 비보호 (요구사항 #3): 미설치 가상 행은 lang-packs/_bundled/ 의 ja 패키지 표현
        $repo = app(LanguagePackRepositoryInterface::class);
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
     * 요구사항 #6: 호스트 확장 비활성화 연쇄의 재활성화 대기 목록이 한 번만 읽히는지 검증합니다.
     *
     * @effects reactivation_stash_is_read_once
     *
     * @return void 반환값 없음
     */
    public function test_reactivation_stash_round_trip(): void
    {
        $registrar = app(LanguagePackBundledRegistrar::class);

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

        // 두 번 호출하면 cache 가 비워져 빈 배열 반환 (요구사항 #8: 모달 표시 안 함)
        $second = $registrar->getPendingForReactivation('module', 'stash-module');
        $this->assertEmpty($second);
    }

    /**
     * 개별 확장 언어팩 관리 화면(target_identifier 필터)에서 코어 팩이 노출되지 않는지 검증합니다.
     *
     * 환경설정 > 언어팩 관리(필터 없음)에서는 코어가 보여야 하지만, 플러그인·모듈·템플릿 개별
     * 화면은 그 확장에 해당하는 행만 보여야 한다. 대상 플러그인은 설치 기록과 설치본 lang/ko 가
     * 있는 픽스처다 — 내장 번역 가상 행은 설치된 확장의 설치본을 근거로 만들어진다.
     *
     * @scenario row_origin=extension_virtual
     *
     * @effects extension_filter_excludes_core_packs
     *
     * @return void 반환값 없음
     */
    public function test_extension_filter_excludes_core_packs(): void
    {
        $pluginRoot = base_path('plugins/test-policy-plug');
        File::ensureDirectoryExists($pluginRoot.'/lang/ko');
        File::put($pluginRoot.'/plugin.json', (string) json_encode(['vendor' => 'test', 'version' => '1.0.0']));
        Plugin::factory()->create(['identifier' => 'test-policy-plug', 'status' => 'active']);
        PluginManager::invalidatePluginStatusCache();

        try {
            $svc = app(LanguagePackService::class);

            // 필터 없음 → 코어 ko/en 가상 보호 행 포함
            $unfiltered = $svc->list([], 200);
            $hasCoreKo = collect($unfiltered->items())->contains(
                fn (LanguagePack $p) => $p->scope === LanguagePackScope::Core->value && $p->locale === 'ko'
            );
            $this->assertTrue($hasCoreKo, '필터 없는 화면에서 코어 ko 가 노출되어야 함');

            // 플러그인 필터 → 코어 팩 제외, 그 플러그인 행만
            $filtered = $svc->list(['target_identifier' => 'test-policy-plug'], 200);
            $corePacksLeaked = collect($filtered->items())->filter(
                fn (LanguagePack $p) => $p->scope === LanguagePackScope::Core->value
            );
            $this->assertCount(0, $corePacksLeaked, '플러그인 필터 시 코어 팩 노출 금지');

            // 그 플러그인의 자체 built_in 은 여전히 노출
            $hasOwnKo = collect($filtered->items())->contains(
                fn (LanguagePack $p) => $p->scope === LanguagePackScope::Plugin->value
                    && $p->target_identifier === 'test-policy-plug'
                    && $p->locale === 'ko'
            );
            $this->assertTrue($hasOwnKo, '플러그인 자체 built_in ko 는 노출되어야 함');
        } finally {
            File::deleteDirectory($pluginRoot);
            PluginManager::invalidatePluginStatusCache();
        }
    }

    /**
     * §3: 폐지된 LanguagePackBundledSeeder 클래스가 남아 있지 않은지 검증합니다.
     *
     * @effects bundled_seeder_is_retired
     *
     * @return void 반환값 없음
     */
    public function test_bundled_seeder_class_does_not_exist(): void
    {
        $this->assertFalse(
            class_exists('Database\\Seeders\\LanguagePackBundledSeeder'),
            'LanguagePackBundledSeeder 는 폐지되어 존재하지 않아야 함'
        );
    }

    /**
     * §9: 언어팩 Enum 클래스가 모두 정의되어 있고 핵심 값을 노출하는지 검증합니다.
     *
     * @effects language_pack_enums_expose_expected_cases
     *
     * @return void 반환값 없음
     */
    public function test_new_enums_are_defined_with_expected_cases(): void
    {
        $this->assertTrue(enum_exists(LanguagePackSourceType::class));
        $this->assertTrue(enum_exists(LanguagePackErrorCode::class));
        $this->assertTrue(enum_exists(TextDirection::class));
        $this->assertTrue(enum_exists(LanguagePackAbility::class));

        $this->assertContains('built_in', LanguagePackSourceType::values());
        $this->assertContains('bundled_with_extension', LanguagePackSourceType::values());
        $this->assertTrue(LanguagePackSourceType::BuiltIn->isProtectedByDefault());
        $this->assertFalse(LanguagePackSourceType::Bundled->isProtectedByDefault());
    }
}
