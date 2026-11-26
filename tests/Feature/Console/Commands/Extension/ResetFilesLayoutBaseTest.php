<?php

namespace Tests\Feature\Console\Commands\Extension;

use App\Extension\ModuleManager;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\Concerns\BuildsLayoutMergeFixtures;
use Tests\TestCase;

/**
 * `extension:reset-files-from-bundled` 는 교체 직전 활성 폴더(이전 판)로 비어 있는 레이아웃 병합 기준을 채운다.
 *
 * DB 를 함께 쓰는 검수 서버가 먼저 업데이트하면 그 서버의 업데이트 백업이 채운 기준은 검수 서버 표에만 남는다. 운영 서버 표의
 * 오래된 행(`original_content` 가 빈 행)은 keep 레이아웃 갱신에서 기준이 없어 새 판을 받지 못했다(`kept_no_base`). 이 서버의 활성
 * 폴더는 교체 전까지 이전 판이므로, 원본 해시가 맞는 행에만 기준을 채운다 — 본문·해시는 그대로다.
 *
 * @scenario-manifest tests/scenarios/extension-sync-files.yaml
 */
class ResetFilesLayoutBaseTest extends TestCase
{
    use BuildsLayoutMergeFixtures;
    use DatabaseTransactions;

    /**
     * 픽스처 디스크 산출물을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->cleanupLayoutMergeFixtures();

        parent::tearDown();
    }

    /**
     * 기준이 빈 옛 행은 교체 전 파일(이전 판)로 기준을 얻고, 본문·원본 해시는 바뀌지 않는다.
     *
     * @scenario case=layout_base_fill
     *
     * @effects reset_files_fills_empty_layout_base_from_previous_files
     *
     * @return void 반환값 없음
     */
    public function test_empty_layout_base_is_filled_from_previous_active_files(): void
    {
        $this->makeFixtureTemplate();
        $manager = $this->makeFixtureModule($this->listScreenFiles('A1', 'B1'));
        $register = new \ReflectionMethod(ModuleManager::class, 'registerModuleLayouts');
        $register->setAccessible(true);
        $register->invoke($manager, $this->fixtureModule);

        $row = $this->fixtureModuleLayout('merge_list');
        $this->assertNotNull($row);
        $row->forceFill(['original_content' => null])->saveQuietly();
        $hash = $row->original_content_hash;
        $content = $this->flat($row->fresh()->content);

        // 번들은 같은 판(1.0.0)·새 레이아웃(B2) — 배포로 번들만 바뀐 운영 서버의 모양
        $bundled = base_path('modules/_bundled/'.$this->fixtureModule);
        $this->fixturePaths[] = $bundled;
        File::copyDirectory(base_path('modules/'.$this->fixtureModule), $bundled);
        File::deleteDirectory($bundled.'/resources/layouts');
        $this->writeFiles($bundled.'/resources/layouts', $this->listScreenFiles('A1', 'B2'));

        $buffer = new BufferedOutput;
        $code = Artisan::call('extension:reset-files-from-bundled', ['identifier' => $this->fixtureModule, '--type' => 'module', '--json' => true], $buffer);
        $output = trim($buffer->fetch());
        $result = (array) json_decode(substr($output, (int) strpos($output, '{')), true);

        $this->assertSame(0, $code);
        $this->assertSame(1, $result['items'][0]['layout_bases_filled'] ?? null, '기준이 빈 행 하나를 채운다');

        $fresh = $row->fresh();
        $base = (string) $fresh->getRawOriginal('original_content');
        $this->assertNotSame('', $base, '병합 기준이 채워졌다');
        $this->assertStringContainsString('B1', $base, '기준은 교체 전(이전 판) 파일이다');
        $this->assertStringNotContainsString('B2', $base);
        $this->assertSame($hash, $fresh->original_content_hash, '원본 해시는 그대로');
        $this->assertSame($content, $this->flat($fresh->content), '본문은 그대로');
        $this->assertStringContainsString('B2', (string) File::get(base_path('modules/'.$this->fixtureModule.'/resources/layouts/admin/partials/merge_list/_b.json')), '파일은 번들 판으로 바뀌었다');
    }
}
