<?php

namespace Tests\Feature\LanguagePack;

use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackStatus;
use App\Models\LanguagePack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * language-pack:list 커맨드 회귀 테스트 (이슈 #496 결손 2 — B1).
 *
 * 커맨드가 repository->paginate() 직접 호출에서 Service::list() 경유로 전환되어,
 * 설치된 DB 행뿐 아니라 (1) 번들에 있으나 미설치(uninstalled) 및 (2) DB active 인데
 * 설치본 파일 부재(드리프트)를 함께 표면화하는지 출력으로 검증한다.
 *
 * repository->paginate() 로 되돌리면 미설치/드리프트가 출력에서 사라져 이 테스트가 회귀를 잡는다.
 *
 * Symfony Table 출력은 PendingCommand::expectsOutputToContain 으로 신뢰성 있게 캡처되지 않으므로
 * Artisan::call() + Artisan::output() 로 렌더된 원문을 직접 검사한다.
 */
class ListLanguagePackCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @effects cli_list_surfaces_uninstalled_and_drift
     */
    public function test_lists_drift_row_with_files_missing_marker(): void
    {
        // 설치본 디렉토리(lang-packs/{id})가 실재하지 않는 active 행 → 드리프트
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

        $exit = Artisan::call('language-pack:list');
        $out = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString($id, $out, '드리프트 행이 목록에 나타나야 함');
        $this->assertStringContainsString('파일 없음', $out, 'active + 설치본 부재는 "(파일 없음)" 으로 표기되어야 함');
    }

    /**
     * @effects cli_list_surfaces_uninstalled_and_drift
     */
    public function test_lists_uninstalled_bundle_pack(): void
    {
        // RefreshDatabase 상태 → 설치된 팩 없음. 번들 소스(g7-core-ja)는 uninstalled 가상행으로 노출.
        $exit = Artisan::call('language-pack:list');
        $out = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('g7-core-ja', $out, '미설치 번들 팩이 목록에 나타나야 함');
        $this->assertStringContainsString('uninstalled', $out, '미설치 번들은 uninstalled 상태로 표기되어야 함');
    }

    public function test_scope_filter_limits_output(): void
    {
        $exit = Artisan::call('language-pack:list', ['--scope' => 'core']);
        $out = Artisan::output();

        $this->assertSame(0, $exit);
        // core 스코프만 → 모듈/플러그인/템플릿 팩은 제외
        $this->assertStringContainsString('g7-core-ja', $out);
        $this->assertStringNotContainsString('sirsoft-module-sirsoft-board', $out, 'core 스코프에 모듈 팩이 섞이면 안 됨');
    }
}
