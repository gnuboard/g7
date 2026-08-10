<?php

namespace Tests\Feature\LanguagePack;

use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackStatus;
use App\Models\LanguagePack;
use App\Services\LanguagePackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LanguagePackService::list() 의 드리프트 파생 상태(files_missing) 검증 (이슈 #496 결손 2).
 *
 * DB 에 active 로 기록됐으나 설치본 디렉토리(lang-packs/{id})가 부재한 행은
 * 런타임에 조용히 base locale 로 폴백한다. list() 가 이를 files_missing=true 로 표면화해야 한다.
 */
class ListDriftDetectionTest extends TestCase
{
    use RefreshDatabase;

    private LanguagePackService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(LanguagePackService::class);
    }

    /**
     * @effects active_row_with_missing_install_dir_flagged_files_missing,
     *          third_party_pack_without_bundle_source_not_offered_for_reinstall
     */
    public function test_active_row_with_missing_install_dir_is_flagged(): void
    {
        // 설치본 디렉토리(lang-packs/{id})가 실재하지 않는 식별자로 active 행 생성 → 드리프트
        $id = 'test-drift-missing-ja';
        LanguagePack::query()->create([
            'identifier' => $id,
            'vendor' => 'test',
            'scope' => LanguagePackScope::Core->value,
            'target_identifier' => null,
            'locale' => 'ja',
            'locale_name' => 'Japanese',
            'locale_native_name' => '日本語',
            'text_direction' => 'ltr',
            'version' => '1.0.0',
            'status' => LanguagePackStatus::Active->value,
            'is_protected' => false,
            'manifest' => [],
            'source_type' => 'zip',
        ]);

        $paginator = $this->service->list([], 100);
        $found = collect($paginator->items())->firstWhere('identifier', $id);

        $this->assertNotNull($found, 'active 행이 목록에 나타나야 함');
        $this->assertTrue(
            (bool) $found->getAttribute('files_missing'),
            '설치본 디렉토리가 없는 active 행은 files_missing=true 여야 함'
        );

        // 이 식별자는 lang-packs/_bundled/ 에 대응 소스가 없다 → 번들 재설치로 복구할 수 없다.
        // 화면은 이 플래그로 재설치 버튼 노출을 가르므로, 복구 불가 팩에 버튼이 뜨면 안 된다.
        $this->assertFalse(
            (bool) $found->getAttribute('bundled_source_available'),
            '번들 소스가 없는 팩은 bundled_source_available=false 여야 함'
        );
    }

    /**
     * @effects virtual_builtin_rows_not_flagged
     */
    public function test_virtual_builtin_rows_are_not_flagged(): void
    {
        // 코어 ko/en 가상 보호 행은 lang/{locale}/ 로 항상 존재 → 드리프트 아님
        $paginator = $this->service->list([], 100);
        $koPack = collect($paginator->items())
            ->first(fn ($p) => $p->scope === LanguagePackScope::Core->value && $p->locale === 'ko');

        $this->assertNotNull($koPack, '코어 ko 가상 행이 존재해야 함');
        $this->assertFalse(
            (bool) $koPack->getAttribute('files_missing'),
            '가상 보호 행은 files_missing 대상이 아님'
        );
    }
}
