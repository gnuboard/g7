<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\LanguagePack;

use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackSourceType;
use App\Enums\LanguagePackStatus;
use App\Extension\HookManager;
use App\Models\LanguagePack;
use App\Services\LanguagePack\LanguagePackRegistry;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Database\Seeders\ClaimReasonSeeder;
use Modules\Sirsoft\Ecommerce\Database\Seeders\ShippingCarrierSeeder;
use Modules\Sirsoft\Ecommerce\Database\Seeders\ShippingTypeSeeder;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * 이커머스 모듈 — 언어팩 활성/비활성 시 entity 시더 자동 재실행 회귀 테스트.
 *
 * 검증 시나리오:
 *   1. ja 모듈 언어팩 활성 → shipping_types.name 에 ja 키 자동 주입 (ShippingTypeSeeder)
 *   2. ja 활성 → shipping_carriers.name 에 ja 자동 주입 (ShippingCarrierSeeder)
 *   3. ja 활성 → claim_reasons.name 에 ja 자동 주입 (ClaimReasonSeeder)
 *   4. user_overrides=['name.ko'] 인 row 는 ko 보존, ja 자동 추가
 *
 * 실제 훅 체인 (HookManager::doAction → RunSeedersOnLanguagePackLifecycle 리스너 → Artisan::call)
 * 으로 검증하며 mock 미사용.
 */
class EcommerceLanguagePackSeederTriggerTest extends ModuleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // module:seed 가 outer transaction 외부에서 commit 한 데이터 정리
        DB::table('language_packs')
            ->where('target_identifier', 'sirsoft-ecommerce')
            ->where('locale', 'ja')
            ->delete();
        DB::table('ecommerce_shipping_types')->where('id', '>', 0)->delete();
        DB::table('ecommerce_shipping_carriers')->where('id', '>', 0)->delete();
        DB::table('ecommerce_claim_reasons')->where('id', '>', 0)->delete();

        // 본 테스트는 lang pack 활성 hook 이 LanguagePack::resolveDirectory() →
        // base_path('lang-packs/{identifier}/seed/*.json') 을 읽어 ja 키를 주입하는 경로를 검증한다.
        // 활성 디렉토리가 부재한 dev 환경(사용자가 language-pack:install 미실행) 에서도 테스트가 동작하도록,
        // _bundled 원본을 활성 경로에 복사한다 (RefreshDatabase 가 파일시스템에 영향을 주지 않으므로 영구).
        $bundledDir = base_path('lang-packs/_bundled/g7-module-sirsoft-ecommerce-ja');
        $activeDir = base_path('lang-packs/g7-module-sirsoft-ecommerce-ja');
        if (\Illuminate\Support\Facades\File::isDirectory($bundledDir)
            && ! \Illuminate\Support\Facades\File::isDirectory($activeDir)) {
            \Illuminate\Support\Facades\File::copyDirectory($bundledDir, $activeDir);
        }

        app()->forgetInstance(LanguagePackRegistry::class);
    }

    public function test_module_lang_pack_activation_triggers_shipping_types_seeder(): void
    {
        $this->ensureSeeded();

        $pack = $this->createEcommerceJaLanguagePack(LanguagePackStatus::Inactive);
        $pack->update([
            'status' => LanguagePackStatus::Active->value,
            'activated_at' => now(),
        ]);
        $this->refreshLanguagePackRegistry();
        HookManager::doAction('core.language_packs.activated', $pack->fresh());

        $this->assertJaKeyPresentInTable('ecommerce_shipping_types');
    }

    public function test_module_lang_pack_activation_triggers_shipping_carriers_seeder(): void
    {
        $this->ensureSeeded();

        $pack = $this->createEcommerceJaLanguagePack(LanguagePackStatus::Inactive);
        $pack->update([
            'status' => LanguagePackStatus::Active->value,
            'activated_at' => now(),
        ]);
        $this->refreshLanguagePackRegistry();
        HookManager::doAction('core.language_packs.activated', $pack->fresh());

        $this->assertJaKeyPresentInTable('ecommerce_shipping_carriers');
    }

    public function test_module_lang_pack_activation_triggers_claim_reasons_seeder(): void
    {
        $this->ensureSeeded();

        $pack = $this->createEcommerceJaLanguagePack(LanguagePackStatus::Inactive);
        $pack->update([
            'status' => LanguagePackStatus::Active->value,
            'activated_at' => now(),
        ]);
        $this->refreshLanguagePackRegistry();
        HookManager::doAction('core.language_packs.activated', $pack->fresh());

        $this->assertJaKeyPresentInTable('ecommerce_claim_reasons');
    }

    public function test_user_overridden_ko_is_preserved_while_ja_auto_synced(): void
    {
        $this->ensureSeeded();

        DB::table('ecommerce_shipping_types')
            ->where('code', 'parcel')
            ->update([
                'name' => json_encode(['ko' => '사용자 수정 ko', 'en' => 'Parcel']),
                'user_overrides' => json_encode(['name.ko']),
            ]);

        $pack = $this->createEcommerceJaLanguagePack(LanguagePackStatus::Inactive);
        $pack->update([
            'status' => LanguagePackStatus::Active->value,
            'activated_at' => now(),
        ]);
        $this->refreshLanguagePackRegistry();
        HookManager::doAction('core.language_packs.activated', $pack->fresh());

        $row = DB::table('ecommerce_shipping_types')->where('code', 'parcel')->first(['name']);
        $name = json_decode($row->name, true);

        $this->assertEquals('사용자 수정 ko', $name['ko'] ?? null, 'ko 사용자 수정 보존');
        $this->assertNotNull($name['ja'] ?? null, 'ja 자동 동기화');
    }

    private function ensureSeeded(): void
    {
        // RefreshDatabase 환경 — 시더 클래스 직접 호출 (outer transaction 안에서)
        $this->runSeeder(ShippingTypeSeeder::class);
        $this->runSeeder(ShippingCarrierSeeder::class);
        $this->runSeeder(ClaimReasonSeeder::class);
    }

    private function runSeeder(string $seederClass): void
    {
        $seeder = app($seederClass);
        // Seeder->run() 안에서 $this->command->info() 호출 가능하도록 dummy command 주입
        $command = new \Illuminate\Console\Command();
        $command->setOutput(new \Illuminate\Console\OutputStyle(
            new \Symfony\Component\Console\Input\ArrayInput([]),
            new BufferedOutput(),
        ));
        $seeder->setCommand($command);
        $seeder->run();
    }

    private function assertJaKeyPresentInTable(string $table): void
    {
        $rows = DB::table($table)->get(['id', 'name']);
        $this->assertNotEmpty($rows, "{$table} 시더가 row 를 생성해야 함");
        foreach ($rows as $row) {
            $name = json_decode($row->name, true) ?: [];
            $this->assertArrayHasKey('ja', $name, "{$table} row#{$row->id} 의 name 에 ja 키 누락");
        }
    }

    private function createEcommerceJaLanguagePack(LanguagePackStatus $status): LanguagePack
    {
        return LanguagePack::create([
            'identifier' => 'g7-module-sirsoft-ecommerce-ja',
            'vendor' => 'g7',
            'name' => 'Ecommerce JA Language Pack',
            'version' => '1.0.0-beta.1',
            'scope' => LanguagePackScope::Module->value,
            'target_identifier' => 'sirsoft-ecommerce',
            'locale' => 'ja',
            'locale_name' => 'Japanese',
            'locale_native_name' => '日本語',
            'text_direction' => 'ltr',
            'is_protected' => false,
            'manifest' => [
                'identifier' => 'g7-module-sirsoft-ecommerce-ja',
                'scope' => 'module',
                'target_identifier' => 'sirsoft-ecommerce',
                'locale' => 'ja',
            ],
            'status' => $status->value,
            'source_type' => LanguagePackSourceType::Bundled->value,
            'installed_at' => now(),
            'activated_at' => $status === LanguagePackStatus::Active ? now() : null,
        ]);
    }

    private function refreshLanguagePackRegistry(): void
    {
        app()->forgetInstance(LanguagePackRegistry::class);
        $registry = app(LanguagePackRegistry::class);
        config([
            'app.supported_locales' => $registry->getActiveCoreLocales(),
            'app.locale_names' => $registry->getLocaleNames(),
            'app.translatable_locales' => $registry->getActiveCoreLocales(),
        ]);
    }
}
