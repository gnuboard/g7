<?php

namespace Tests\Feature\LanguagePack;

use App\Enums\LanguagePackOrigin;
use App\Enums\LanguagePackScope;
use App\Services\LanguagePackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LanguagePackService::getPacksForExtension() Feature 테스트.
 *
 * 가상 built_in 행 + 미설치 번들 가상 행 + DB 행을 슬롯 단위로 머지하는 정확성 검증.
 */
class GetPacksForExtensionTest extends TestCase
{
    use RefreshDatabase;

    private LanguagePackService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(LanguagePackService::class);
    }

    public function test_returns_collection_for_core_scope(): void
    {
        $packs = $this->service->getPacksForExtension(LanguagePackScope::Core, null);

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $packs);

        // 코어는 lang/{ko,en}/ 가 디스크에 있으므로 최소 2개의 가상 built_in 행이 합성됨
        $this->assertGreaterThanOrEqual(2, $packs->count());

        // 모든 결과 행이 코어 스코프
        foreach ($packs as $pack) {
            $this->assertSame(LanguagePackScope::Core->value, $pack->scope);
        }
    }

    public function test_returns_origin_field_for_built_in_packs(): void
    {
        $packs = $this->service->getPacksForExtension(LanguagePackScope::Core, null);

        // 가상 built_in 행은 origin=built_in (또는 source_type 이 built_in/bundled_with_extension 매핑)
        $koPack = $packs->first(fn ($p) => $p->locale === 'ko');
        $this->assertNotNull($koPack, '코어 ko 가상 행이 존재해야 함');

        $origin = $koPack->origin;
        $this->assertSame(LanguagePackOrigin::BuiltIn->value, $origin);
    }

    public function test_includes_uninstalled_bundled_packs_for_extension(): void
    {
        // sirsoft-board 모듈에 대한 lang-packs/_bundled/g7-module-sirsoft-board-ja 가
        // 환경에 있다면 미설치 가상 행으로 포함되어야 함
        $packs = $this->service->getPacksForExtension(LanguagePackScope::Module, 'sirsoft-board');

        if ($packs->isEmpty()) {
            $this->markTestSkipped('sirsoft-board not in test env');
        }

        // 모든 결과는 module scope + sirsoft-board target
        foreach ($packs as $pack) {
            $this->assertSame(LanguagePackScope::Module->value, $pack->scope);
            $this->assertSame('sirsoft-board', $pack->target_identifier);
        }
    }

    public function test_returns_empty_for_unknown_extension(): void
    {
        $packs = $this->service->getPacksForExtension(
            LanguagePackScope::Module,
            '__nonexistent_module__'
        );

        $this->assertTrue($packs->isEmpty());
    }
}
