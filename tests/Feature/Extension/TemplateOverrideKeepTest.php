<?php

namespace Tests\Feature\Extension;

use App\Extension\TemplateManager;
use App\Models\TemplateLayout;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Tests\Support\Concerns\BuildsLayoutMergeFixtures;
use Tests\TestCase;

/**
 * 템플릿 오버라이드 레이아웃(`layouts/overrides/{모듈}/`)의 운영자 수정이 keep 갱신에 보존되는지 고정한다 (#727 R7).
 *
 * 결함(수정 전): 오버라이드는 원본 해시 없이 `updateOrCreate` 로만 등록돼, keep 을 골라도 갱신마다 파일로
 * 덮어쓰고 「수정한 레이아웃」 목록에도 뜨지 않았다. 번들 템플릿에는 오버라이드가 0개라 브라우저로는 볼 수
 * 없어 이 기능 테스트가 유일한 커버다.
 */
class TemplateOverrideKeepTest extends TestCase
{
    use BuildsLayoutMergeFixtures;
    use DatabaseTransactions;

    /**
     * 픽스처 디스크 산출물을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->cleanupLayoutMergeFixtures();

        parent::tearDown();
    }

    /**
     * 오버라이드 파일을 씁니다.
     *
     * @param  string  $title  제목 문구
     * @param  string  $note  안내 문구
     * @return void 반환값 없음
     */
    private function writeOverride(string $title, string $note): void
    {
        $dir = base_path("templates/{$this->fixtureTemplate}/layouts/overrides/some-module");
        File::ensureDirectoryExists($dir);
        File::put($dir.'/some_page.json', json_encode([
            'version' => '1.0.0',
            'layout_name' => 'some-module.some_page',
            'meta' => ['title' => $title],
            'slots' => ['content' => [['id' => 'note', 'type' => 'basic', 'name' => 'Div', 'text' => $note]]],
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * R7 — keep 갱신은 운영자가 고친 오버라이드 부분을 두고 나머지만 새 판으로 바꾼다.
     *
     * @effects template_override_layouts_are_merged
     *
     * @return void 반환값 없음
     */
    public function test_keep_refresh_merges_template_override_layout(): void
    {
        $this->makeFixtureTemplate([
            'home.json' => ['version' => '1.0.0', 'layout_name' => 'home', 'components' => []],
        ]);
        $this->writeOverride('TITLE1', 'NOTE1');
        $manager = app(TemplateManager::class);
        $manager->refreshTemplateLayouts($this->fixtureTemplate, false);

        $row = TemplateLayout::where('template_id', $this->fixtureTemplateModel->id)->where('name', 'some-module.some_page')->firstOrFail();
        $this->assertNotNull($row->original_content_hash, '오버라이드도 원본 해시를 남겨야 수정 여부를 판정할 수 있다');

        $this->operatorEdits($row, function (array $c) {
            $c['slots']['content'][0]['text'] = '운영자 안내';

            return $c;
        });

        $this->writeOverride('TITLE2', 'NOTE1');
        $manager->refreshTemplateLayouts($this->fixtureTemplate, true);

        $content = $this->flat($row->fresh()->content);
        $this->assertStringContainsString('운영자 안내', $content, 'keep 인데 오버라이드 운영자 수정이 덮어써졌다');
        $this->assertStringContainsString('TITLE2', $content, '운영자가 안 고친 meta 에는 새 판이 들어와야 한다');
    }
}
