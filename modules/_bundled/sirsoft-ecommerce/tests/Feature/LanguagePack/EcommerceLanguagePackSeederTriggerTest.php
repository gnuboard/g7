<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\LanguagePack;

use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackSourceType;
use App\Enums\LanguagePackStatus;
use App\Extension\HookManager;
use App\Models\LanguagePack;
use App\Services\LanguagePack\LanguagePackRegistry;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Ecommerce\Database\Seeders\ClaimReasonSeeder;
use Modules\Sirsoft\Ecommerce\Database\Seeders\ShippingCarrierSeeder;
use Modules\Sirsoft\Ecommerce\Database\Seeders\ShippingTypeSeeder;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Symfony\Component\Console\Input\ArrayInput;
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
    /**
     * 활성 모듈 시더 디렉토리를 확인하고, 이전 실행이 남긴 언어팩·시드 행을 비우고 번역 레지스트리 캐시를 무효화합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 본 테스트는 활성 hook 체인이 module:seed 를 호출하는 경로를 검증한다.
        // SeedModuleCommand 는 base_path('modules/{id}/database/seeders') 활성 디렉토리를
        // is_dir() 로 확인하므로, 활성 디렉토리가 부재한 환경에서는 시더가 silent skip 된다.
        // 활성 디렉토리가 없으면 본 테스트의 production-like 시나리오를 재현할 수 없으므로 skip.
        $activeModuleSeederDir = base_path('modules/sirsoft-ecommerce/database/seeders');
        if (! is_dir($activeModuleSeederDir)) {
            $this->markTestSkipped(
                'sirsoft-ecommerce 모듈이 활성 디렉토리에 설치되지 않은 환경 — '
                .'본 테스트는 module:seed → entity 시더 → ja 자동 동기 production 경로를 검증하므로 '
                .'php artisan module:install sirsoft-ecommerce 후 재실행 (또는 CI 환경에서 자동 통과)'
            );
        }

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
        if (File::isDirectory($bundledDir)
            && ! File::isDirectory($activeDir)) {
            File::copyDirectory($bundledDir, $activeDir);
        }

        // 레지스트리 싱글톤을 갈아 끼우지 않고 캐시만 비운다 — 부팅 때 등록된 번역 필터·주입기가 그 인스턴스를
        // 쥐고 있어, 교체하면 새 활성 팩이 필터에 보이지 않는다(리스너 refreshRegistry 와 같은 규칙, #597).
        app(LanguagePackRegistry::class)->invalidate();
    }

    /**
     * ja 모듈 언어팩을 켜면 배송유형 이름에 ja 가 채워지는지 검증합니다.
     *
     * @scenario entity=shipping_types,override=none
     *
     * @effects lang_pack_activation_injects_ja_into_seeded_rows
     *
     * @return void 반환값 없음
     */
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

    /**
     * ja 모듈 언어팩을 켜면 택배사 이름에 ja 가 채워지는지 검증합니다.
     *
     * @scenario entity=shipping_carriers,override=none
     *
     * @effects lang_pack_activation_injects_ja_into_seeded_rows
     *
     * @return void 반환값 없음
     */
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

    /**
     * ja 모듈 언어팩을 켜면 클레임 사유 이름에 ja 가 채워지는지 검증합니다.
     *
     * @scenario entity=claim_reasons,override=none
     *
     * @effects lang_pack_activation_injects_ja_into_seeded_rows
     *
     * @return void 반환값 없음
     */
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

    /**
     * 운영자가 고친 ko 이름은 지키고 ja 만 채우는지 검증합니다.
     *
     * @scenario entity=shipping_types,override=name_ko
     *
     * @effects operator_ko_edit_survives_ja_sync
     *
     * @return void 반환값 없음
     */
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

    /**
     * 세 엔티티 시더를 테스트 트랜잭션 안에서 실행합니다.
     *
     * @return void 반환값 없음
     */
    private function ensureSeeded(): void
    {
        // RefreshDatabase 환경 — 시더 클래스 직접 호출 (outer transaction 안에서)
        $this->runSeeder(ShippingTypeSeeder::class);
        $this->runSeeder(ShippingCarrierSeeder::class);
        $this->runSeeder(ClaimReasonSeeder::class);
    }

    /**
     * 시더 한 개를 콘솔 출력 대역을 붙여 실행합니다.
     *
     * @param  string  $seederClass  시더 클래스명
     * @return void 반환값 없음
     */
    private function runSeeder(string $seederClass): void
    {
        $seeder = app($seederClass);
        // Seeder->run() 안에서 $this->command->info() 호출 가능하도록 dummy command 주입
        $command = new Command;
        $command->setOutput(new OutputStyle(
            new ArrayInput([]),
            new BufferedOutput,
        ));
        $seeder->setCommand($command);
        $seeder->run();
    }

    /**
     * 테이블의 모든 행 이름에 ja 키가 있는지 단언합니다.
     *
     * @param  string  $table  테이블명
     * @return void 반환값 없음
     */
    private function assertJaKeyPresentInTable(string $table): void
    {
        $rows = DB::table($table)->get(['id', 'name']);
        $this->assertNotEmpty($rows, "{$table} 시더가 row 를 생성해야 함");
        foreach ($rows as $row) {
            $name = json_decode($row->name, true) ?: [];
            $this->assertArrayHasKey('ja', $name, "{$table} row#{$row->id} 의 name 에 ja 키 누락");
        }
    }

    /**
     * 이커머스 ja 모듈 언어팩 행을 만듭니다.
     *
     * @param  LanguagePackStatus  $status  초기 상태
     * @return LanguagePack 생성된 언어팩
     */
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

    /**
     * 번역 레지스트리 캐시를 비우고 지원 로케일 설정을 다시 맞춥니다.
     *
     * @return void 반환값 없음
     */
    private function refreshLanguagePackRegistry(): void
    {
        $registry = app(LanguagePackRegistry::class);
        $registry->invalidate();
        config([
            'app.supported_locales' => $registry->getActiveCoreLocales(),
            'app.locale_names' => $registry->getLocaleNames(),
            'app.translatable_locales' => $registry->getActiveCoreLocales(),
        ]);
    }
}
