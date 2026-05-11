<?php

namespace Tests\Unit\Services\LanguagePack;

use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackStatus;
use App\Models\LanguagePack;
use App\Services\LanguagePack\LanguagePackRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LanguagePackRegistry 단위 테스트.
 *
 * 계획서 §16.3 의 8개 케이스를 커버합니다.
 */
class LanguagePackRegistryTest extends TestCase
{
    use RefreshDatabase;

    private LanguagePackRegistry $registry;

    /**
     * 테스트 픽스처 초기화.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = $this->app->make(LanguagePackRegistry::class);
    }

    /**
     * 활성 코어 언어팩 1건을 만듭니다.
     *
     * @param  string  $vendor  벤더
     * @param  string  $locale  로케일
     * @param  string  $status  상태
     * @return LanguagePack 생성된 언어팩
     */
    private function makePack(string $vendor, string $locale, string $status = LanguagePackStatus::Active->value, string $scope = LanguagePackScope::Core->value, ?string $target = null): LanguagePack
    {
        return LanguagePack::query()->create([
            'identifier' => sprintf('%s-%s%s-%s', $vendor, $scope, $target ? "-{$target}" : '', $locale),
            'vendor' => $vendor,
            'scope' => $scope,
            'target_identifier' => $target,
            'locale' => $locale,
            'locale_name' => strtoupper($locale),
            'locale_native_name' => $locale === 'ja' ? '日本語' : $locale,
            'text_direction' => 'ltr',
            'version' => '1.0.0',
            'status' => $status,
            'is_protected' => false,
            'manifest' => [],
        ]);
    }

    public function test_case_26_get_core_installed_locales_includes_bundled_and_active(): void
    {
        $this->makePack('sirsoft', 'ja');
        $this->registry->invalidate();

        $locales = $this->registry->getActiveCoreLocales();

        $this->assertContains('ko', $locales);
        $this->assertContains('en', $locales);
        $this->assertContains('ja', $locales);
    }

    public function test_case_27_get_core_installed_locales_excludes_inactive(): void
    {
        $this->makePack('sirsoft', 'ja', LanguagePackStatus::Inactive->value);
        $this->registry->invalidate();

        $locales = $this->registry->getActiveCoreLocales();

        $this->assertNotContains('ja', $locales);
    }

    public function test_case_28_has_active_core_locale_true_for_any_vendor(): void
    {
        $this->makePack('acme', 'ja');
        $this->registry->invalidate();

        $this->assertTrue($this->registry->hasActiveCoreLocale('ja'));
    }

    public function test_case_29_has_active_core_locale_false_when_all_inactive(): void
    {
        $this->makePack('acme', 'ja', LanguagePackStatus::Inactive->value);
        $this->registry->invalidate();

        $this->assertFalse($this->registry->hasActiveCoreLocale('ja'));
    }

    public function test_case_30_get_active_pack_for_slot_returns_pack(): void
    {
        $pack = $this->makePack('sirsoft', 'ja');
        $this->registry->invalidate();

        $found = $this->registry->getActivePackForSlot('core', null, 'ja');

        $this->assertNotNull($found);
        $this->assertSame($pack->id, $found->id);
    }

    public function test_case_31_get_active_pack_for_slot_returns_null_when_empty(): void
    {
        $this->registry->invalidate();
        $found = $this->registry->getActivePackForSlot('core', null, 'fr');

        $this->assertNull($found);
    }

    public function test_case_32_get_locale_names_returns_native_names(): void
    {
        $this->makePack('sirsoft', 'ja');
        $this->registry->invalidate();

        $names = $this->registry->getLocaleNames();

        $this->assertSame('한국어', $names['ko']);
        $this->assertSame('English', $names['en']);
        $this->assertSame('日本語', $names['ja']);
    }

    public function test_case_33_get_active_packs_filters_by_scope(): void
    {
        $this->makePack('sirsoft', 'ja'); // core
        $this->makePack('sirsoft', 'ja', LanguagePackStatus::Active->value, LanguagePackScope::Module->value, 'sirsoft-ecommerce');
        $this->registry->invalidate();

        $modulePacks = $this->registry->getActivePacks(LanguagePackScope::Module->value);

        $this->assertCount(1, $modulePacks);
        $this->assertSame(LanguagePackScope::Module->value, $modulePacks->first()->scope);
    }
}
