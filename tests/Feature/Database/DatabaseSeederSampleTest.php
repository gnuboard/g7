<?php

namespace Tests\Feature\Database;

use App\Models\Template;
use App\Models\TemplateLayout;
use App\Models\TemplateLayoutVersion;
use App\Models\User;
use Database\Seeders\Sample\TemplateLayoutVersionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 코어 샘플 시드(`db:seed --sample --force`) 전체 경로가 끝까지 돈다 (W3 실측).
 *
 * 레이아웃 버전 샘플 시더가 정수 칸(`template_layout_versions.version`, 레이아웃별 자동 증가 번호)에 `'1.0.0'` 같은 문자열을 넣어,
 * MySQL strict 모드에서 「Data truncated for column 'version'」 로 멈추고 뒤 순서 시더(활동 로그 샘플 등)가 돌지 않았다. 개별 시더만
 * 시드하면 순서 의존을 못 잡으므로 DatabaseSeeder 전체를 돈다. 또 이 시더는 모든 레이아웃 버전 이력을 지웠다 — 설치가 끝난 사이트의
 * 운영자 편집 이력이 사라지지 않게 샘플 표시가 있는 행만 다시 만든다.
 */
class DatabaseSeederSampleTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * `db:seed --sample --force` 가 성공 종료한다.
     *
     * @scenario seed_path=database_seeder_sample, existing_history=none
     *
     * @effects core_sample_seed_completes_whole_chain
     *
     * @return void 반환값 없음
     */
    public function test_core_sample_seed_completes(): void
    {
        // 설치가 끝난 사이트처럼 관리자 템플릿과 그 레이아웃이 있어야 레이아웃 버전 샘플이 돈다 (빈 DB 는 건너뛰어 결함을 못 잡는다)
        $template = Template::factory()->create(['type' => 'admin', 'status' => 'active']);
        TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'sample_chain_probe',
            'content' => ['version' => '1.0.0', 'layout_name' => 'sample_chain_probe', 'components' => []],
        ]);

        $this->artisan('db:seed', ['--sample' => true, '--force' => true])->assertExitCode(0);

        $this->assertGreaterThan(0, TemplateLayoutVersion::count(), '레이아웃 버전 샘플이 만들어져야 한다');
    }

    /**
     * 레이아웃 버전 샘플은 레이아웃별 기존 최대 번호 다음 정수로 붙고, 샘플이 아닌 기존 이력은 지우지 않는다. 다시 돌려도 샘플만 바뀐다.
     *
     * @scenario seed_path=layout_version_seeder, existing_history=operator_versions
     *
     * @effects layout_version_sample_keeps_operator_history_and_uses_integer_versions
     *
     * @return void 반환값 없음
     */
    public function test_layout_version_sample_keeps_operator_history(): void
    {
        User::factory()->create(['is_super' => true]);
        $template = Template::factory()->create();
        $layout = TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'sample_seed_probe',
            'content' => ['version' => '1.0.0', 'layout_name' => 'sample_seed_probe', 'components' => []],
        ]);
        $operator = TemplateLayoutVersion::create([
            'layout_id' => $layout->id,
            'version' => 1,
            'content' => $layout->content,
            'changes_summary' => ['added' => 1, 'removed' => 0, 'is_restored' => false, 'restored_from' => null],
        ]);

        $this->seed(TemplateLayoutVersionSeeder::class);
        $this->seed(TemplateLayoutVersionSeeder::class);

        $this->assertNotNull(TemplateLayoutVersion::find($operator->id), '운영자 편집 이력을 지우면 안 된다');
        $versions = TemplateLayoutVersion::where('layout_id', $layout->id)->orderBy('version')->pluck('version')->all();
        $this->assertSame([1, 2, 3, 4], array_map('intval', $versions), '샘플은 기존 번호 다음 정수로 붙고, 다시 돌려도 샘플만 바뀐다');
    }
}
