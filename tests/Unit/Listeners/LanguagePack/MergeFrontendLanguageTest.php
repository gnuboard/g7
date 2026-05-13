<?php

namespace Tests\Unit\Listeners\LanguagePack;

use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackStatus;
use App\Listeners\LanguagePack\MergeFrontendLanguage;
use App\Models\LanguagePack;
use App\Services\LanguagePack\LanguagePackRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * MergeFrontendLanguage 리스너 단위 테스트.
 *
 * `template.language.merge` 필터가 활성 언어팩의 frontend/*.json 데이터를
 * 우선순위(core ⊂ module ⊂ plugin ⊂ template) 로 병합하는지 검증.
 */
class MergeFrontendLanguageTest extends TestCase
{
    use RefreshDatabase;

    private MergeFrontendLanguage $listener;

    private LanguagePackRegistry $registry;

    /** @var array<int, string> 테스트에서 생성한 패키지 디렉토리 */
    private array $packDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = $this->app->make(LanguagePackRegistry::class);
        $this->listener = $this->app->make(MergeFrontendLanguage::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->packDirs as $dir) {
            File::deleteDirectory($dir);
        }
        parent::tearDown();
    }

    /**
     * 활성 ja 언어팩 1건과 frontend/ja.json 을 함께 생성합니다.
     *
     * @param  string  $identifier  팩 식별자
     * @param  string  $scope  scope
     * @param  ?string  $target  target_identifier
     * @param  array<string, mixed>  $frontendData  frontend/ja.json 내용
     * @return void
     */
    private function setupJaPack(string $identifier, string $scope, ?string $target, array $frontendData): void
    {
        $packDir = base_path('lang-packs/'.$identifier);
        File::ensureDirectoryExists($packDir.'/frontend');
        File::put($packDir.'/frontend/ja.json', json_encode($frontendData));
        $this->packDirs[] = $packDir;

        LanguagePack::query()->create([
            'identifier' => $identifier,
            'vendor' => 'g7',
            'scope' => $scope,
            'target_identifier' => $target,
            'locale' => 'ja',
            'locale_name' => 'Japanese',
            'locale_native_name' => '日本語',
            'text_direction' => 'ltr',
            'version' => '1.0.0',
            'status' => LanguagePackStatus::Active->value,
            'is_protected' => false,
            'manifest' => [],
            'source_type' => 'bundled',
        ]);
    }

    public function test_invoke_merges_japanese_frontend_into_template_data(): void
    {
        $this->setupJaPack('test-core-ja', LanguagePackScope::Core->value, null, [
            'common' => ['save' => '保存', 'cancel' => 'キャンセル'],
        ]);
        $this->registry->invalidate();

        $base = ['common' => ['save' => '저장']];
        $result = ($this->listener)($base, 'sirsoft-admin_basic', 'ja');

        // ja 팩 값이 base 위로 병합 (later wins)
        $this->assertSame('保存', $result['common']['save']);
        $this->assertSame('キャンセル', $result['common']['cancel']);
    }

    public function test_template_pack_overrides_module_pack_in_priority(): void
    {
        // 모듈 ja 팩: errors.404 = "見つかりません(モジュール)"
        $this->setupJaPack('test-module-ecommerce-ja', LanguagePackScope::Module->value, 'sirsoft-ecommerce', [
            'errors' => ['404' => '見つかりません(モジュール)'],
        ]);
        // 템플릿 ja 팩: errors.404 = "見つかりません(テンプレート)" — 우선순위 최고
        $this->setupJaPack('test-template-admin-ja', LanguagePackScope::Template->value, 'sirsoft-admin_basic', [
            'errors' => ['404' => '見つかりません(テンプレート)'],
        ]);
        $this->registry->invalidate();

        $result = ($this->listener)([], 'sirsoft-admin_basic', 'ja');

        // 템플릿이 모듈을 override
        $this->assertSame('見つかりません(テンプレート)', $result['errors']['404']);
    }

    public function test_other_template_pack_is_not_merged_for_current_render(): void
    {
        // sirsoft-basic 용 템플릿 ja 팩 — 현재 렌더링 sirsoft-admin_basic 에는 적용 안 됨
        $this->setupJaPack('test-template-basic-ja', LanguagePackScope::Template->value, 'sirsoft-basic', [
            'shop' => ['title' => 'ショップ'],
        ]);
        $this->registry->invalidate();

        $result = ($this->listener)([], 'sirsoft-admin_basic', 'ja');

        // 다른 템플릿 팩이라 병합 안 됨
        $this->assertArrayNotHasKey('shop', $result);
    }

    public function test_skips_when_no_active_pack_for_locale(): void
    {
        // ko 만 active, ja 미활성
        $this->setupJaPack('test-core-ko-via-ja-record', LanguagePackScope::Core->value, null, [
            'common' => ['save' => '保存'],
        ]);
        // locale 을 ko 로 강제 변경 (ja 검색 시 안 잡힘)
        LanguagePack::query()->where('identifier', 'test-core-ko-via-ja-record')->update(['locale' => 'ko']);
        $this->registry->invalidate();

        $base = ['common' => ['save' => '저장']];
        $result = ($this->listener)($base, 'sirsoft-admin_basic', 'ja');

        // ja 활성 팩 없음 → base 그대로
        $this->assertSame('저장', $result['common']['save']);
    }
}
