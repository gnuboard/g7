<?php

namespace Modules\Sirsoft\Board\Tests\Feature\LanguagePack;

use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackSourceType;
use App\Enums\LanguagePackStatus;
use App\Extension\HookManager;
use App\Models\LanguagePack;
use App\Services\LanguagePack\LanguagePackRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 언어팩 활성/비활성 시 board_types entity 시더 자동 재실행 회귀 테스트.
 *
 * 검증 시나리오:
 *   1. ja 모듈 언어팩 활성 → board_types.name 에 ja 키 자동 주입
 *   2. user_overrides=['name.ko'] 인 row 는 ko 보존, ja 자동 추가
 *
 * 실제 훅 체인 (HookManager::doAction → RunSeedersOnLanguagePackLifecycle 리스너 → Artisan::call)
 * 으로 검증하며 mock 미사용.
 */
class BoardLanguagePackSeederTriggerTest extends ModuleTestCase
{
    /**
     * 이전 실행이 남긴 언어팩·게시판 유형 행을 비우고 번역 레지스트리 캐시를 무효화합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        // module:seed 가 outer transaction 외부에서 commit 한 데이터 정리
        DB::table('language_packs')
            ->where('target_identifier', 'sirsoft-board')
            ->where('locale', 'ja')
            ->delete();
        DB::table('board_types')->where('id', '>', 0)->delete();

        // 캐시된 LanguagePackRegistry 정리 — 싱글톤을 갈아 끼우지 않고 캐시만 비운다. 부팅 때 등록된 번역 필터·주입기가
        // 그 인스턴스를 쥐고 있어, 교체하면 옛 인스턴스가 캐시를 이미 채운 실행에서만 새 활성 팩이 안 보여 흔들렸다(#597 규칙).
        app(LanguagePackRegistry::class)->invalidate();
    }

    /**
     * ja 모듈 언어팩을 켜면 게시판 유형 이름에 ja 가 채워지는지 검증합니다.
     *
     * @scenario entity=board_types,override=none
     *
     * @effects lang_pack_activation_injects_ja_into_seeded_rows
     *
     * @return void 반환값 없음
     */
    public function test_module_lang_pack_activation_triggers_board_types_seeder(): void
    {
        // ja lang pack 비활성/없는 상태에서 시더 실행 — 환경에 따라 ja 키가 이미 있을 수 있으나
        // 본 테스트의 핵심은 "활성화 후 ja 키가 모든 row 에 보장됨" 검증.
        $this->ensureBoardTypesSeeded();

        $pack = $this->createBoardJaLanguagePack(LanguagePackStatus::Inactive);
        $pack->update([
            'status' => LanguagePackStatus::Active->value,
            'activated_at' => now(),
        ]);
        $this->refreshLanguagePackRegistry();
        HookManager::doAction('core.language_packs.activated', $pack->fresh());

        $this->assertJaKeyPresentInAllBoardTypes();
    }

    /**
     * 운영자가 고친 ko 이름은 지키고 ja 만 채우는지 검증합니다.
     *
     * @scenario entity=board_types,override=name_ko
     *
     * @effects operator_ko_edit_survives_ja_sync
     *
     * @return void 반환값 없음
     */
    public function test_user_overridden_ko_is_preserved_while_ja_auto_synced(): void
    {
        $this->ensureBoardTypesSeeded();

        DB::table('board_types')
            ->where('slug', 'basic')
            ->update([
                'name' => json_encode(['ko' => '사용자 수정 ko', 'en' => 'Basic List']),
                'user_overrides' => json_encode(['name.ko']),
            ]);

        $pack = $this->createBoardJaLanguagePack(LanguagePackStatus::Inactive);
        $pack->update([
            'status' => LanguagePackStatus::Active->value,
            'activated_at' => now(),
        ]);
        $this->refreshLanguagePackRegistry();
        HookManager::doAction('core.language_packs.activated', $pack->fresh());

        $row = DB::table('board_types')->where('slug', 'basic')->first(['name']);
        $name = json_decode($row->name, true);

        $this->assertEquals('사용자 수정 ko', $name['ko'] ?? null, 'ko 사용자 수정 보존');
        $this->assertEquals('基本形', $name['ja'] ?? null, 'ja 자동 동기화 (lang pack seed/board_types.json SSoT)');
    }

    /**
     * 게시판 유형 시더를 실행합니다.
     *
     * @return void 반환값 없음
     */
    private function ensureBoardTypesSeeded(): void
    {
        if (DB::table('board_types')->count() === 0) {
            // module:seed 가 Seeder->command 를 정상 주입하므로 Seeder 직접 호출 대신 Artisan 경유
            Artisan::call('module:seed', ['identifier' => 'sirsoft-board', '--force' => true]);
        }
    }

    /**
     * 모든 게시판 유형 이름에 ja 키가 있는지 단언합니다.
     *
     * @return void 반환값 없음
     */
    private function assertJaKeyPresentInAllBoardTypes(): void
    {
        $rows = DB::table('board_types')->get(['slug', 'name']);
        $this->assertNotEmpty($rows, 'board_types 시더가 기본 row 를 생성해야 함');
        foreach ($rows as $row) {
            $name = json_decode($row->name, true) ?: [];
            $this->assertArrayHasKey('ja', $name, "board_type '{$row->slug}' 에 ja 키 누락");
        }
    }

    /**
     * 게시판 ja 모듈 언어팩 행을 만듭니다.
     *
     * @param  LanguagePackStatus  $status  초기 상태
     * @return LanguagePack 생성된 언어팩
     */
    private function createBoardJaLanguagePack(LanguagePackStatus $status): LanguagePack
    {
        return LanguagePack::create([
            'identifier' => 'g7-module-sirsoft-board-ja',
            'vendor' => 'g7',
            'name' => 'Board JA Language Pack',
            'version' => '1.0.0-beta.1',
            'scope' => LanguagePackScope::Module->value,
            'target_identifier' => 'sirsoft-board',
            'locale' => 'ja',
            'locale_name' => 'Japanese',
            'locale_native_name' => '日本語',
            'text_direction' => 'ltr',
            'is_protected' => false,
            'manifest' => [
                'identifier' => 'g7-module-sirsoft-board-ja',
                'scope' => 'module',
                'target_identifier' => 'sirsoft-board',
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
