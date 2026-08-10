<?php

namespace Tests\Feature\Upgrades;

use App\Extension\UpgradeContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\TestCase;

/**
 * 코어 7.0.0-beta.2 업그레이드 스텝의 레이아웃 hash 백필 누락 회귀 테스트.
 *
 * 재현 대상 결함: `whereNull('original_content_hash')` 로 필터한 결과를 `chunk()`
 * (OFFSET 페이지네이션)로 순회하면서 콜백이 그 필터 컬럼을 채운다. 처리된 행이
 * 다음 페이지 조회 결과에서 이탈하므로 OFFSET 이 미처리 행을 건너뛴다.
 *
 * 이 테스트는 동일 백필 코드를 공유하는 마이그레이션 2건
 * (`add_original_content_hash_to_template_layouts_table`,
 *  `add_original_content_hash_to_template_layout_extensions_table`) 의 정본 증명이기도 하다.
 */
class Beta2LayoutHashBackfillTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 시드 건수 (스텝의 청크 크기 100 보다 충분히 커야 skip 이 드러남)
     */
    private const SEED_COUNT = 250;

    private object $upgrade;

    private UpgradeContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        require_once base_path('upgrades/Upgrade_7_0_0_beta_2.php');

        $class = 'App\\Upgrades\\Upgrade_7_0_0_beta_2';
        $this->upgrade = new $class;
        $this->context = new UpgradeContext(
            fromVersion: '7.0.0-beta.1',
            toVersion: '7.0.0-beta.2',
            currentStep: '7.0.0-beta.2',
        );
    }

    /**
     * 백필 private 메서드만 호출합니다.
     *
     * (전체 run() 은 알림 시스템 이관 등 무관한 단계를 함께 실행하므로 검증 대상인
     *  백필 로직만 reflection 으로 직접 실행합니다 — 기존 업그레이드 테스트와 동일 방식)
     *
     * @return void
     */
    private function runBackfill(): void
    {
        $reflection = new ReflectionClass($this->upgrade);
        $method = $reflection->getMethod('backfillModuleAndPluginLayoutHashes');
        $method->setAccessible(true);
        $method->invoke($this->upgrade, $this->context);
    }

    /**
     * 테스트용 템플릿 1건을 생성합니다.
     *
     * @return int 생성된 템플릿 ID
     */
    private function seedTemplate(): int
    {
        return DB::table('templates')->insertGetId([
            'identifier' => 'test-chunkskip_template',
            'vendor' => 'test',
            'name' => json_encode(['ko' => '청크 테스트 템플릿', 'en' => 'Chunk Test Template']),
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * hash 가 NULL 인 모듈/플러그인 레이아웃을 시드합니다.
     *
     * @param  int  $templateId  템플릿 ID
     * @return void
     */
    private function seedLayoutsWithoutHash(int $templateId): void
    {
        $rows = [];

        for ($i = 0; $i < self::SEED_COUNT; $i++) {
            $sourceType = $i % 2 === 0 ? 'module' : 'plugin';

            $rows[] = [
                'template_id' => $templateId,
                'name' => 'chunkskip/layout_'.$i,
                'content' => json_encode(['type' => 'page', 'index' => $i]),
                'source_type' => $sourceType,
                'source_identifier' => $sourceType === 'module' ? 'test-module' : 'test-plugin',
                'original_content_hash' => null,
                'original_content_size' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 100) as $batch) {
            DB::table('template_layouts')->insert($batch);
        }
    }

    public function test_layout_hash_backfill_leaves_no_null_rows_when_filter_column_is_mutated_during_iteration(): void
    {
        $templateId = $this->seedTemplate();
        $this->seedLayoutsWithoutHash($templateId);

        $this->assertSame(
            self::SEED_COUNT,
            DB::table('template_layouts')->whereNull('original_content_hash')->count(),
            '시드 직후에는 hash NULL 레이아웃이 시드 건수만큼 존재해야 합니다.'
        );

        $this->runBackfill();

        $this->assertSame(
            0,
            DB::table('template_layouts')
                ->whereIn('source_type', ['module', 'plugin'])
                ->whereNull('original_content_hash')
                ->count(),
            'OFFSET 순회로 인해 백필되지 않고 남은 레이아웃이 있습니다 (chunk → chunkById 필요).'
        );
    }

    public function test_backfilled_hash_and_size_match_normalized_content(): void
    {
        $templateId = $this->seedTemplate();
        $this->seedLayoutsWithoutHash($templateId);

        $this->runBackfill();

        $rows = DB::table('template_layouts')
            ->where('template_id', $templateId)
            ->get(['content', 'original_content_hash', 'original_content_size']);

        foreach ($rows as $row) {
            $normalized = json_encode(
                json_decode($row->content, true),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            $this->assertSame(hash('sha256', $normalized), $row->original_content_hash);
            $this->assertSame(strlen($normalized), (int) $row->original_content_size);
        }
    }

    public function test_template_source_layouts_are_not_touched(): void
    {
        $templateId = $this->seedTemplate();
        $this->seedLayoutsWithoutHash($templateId);

        DB::table('template_layouts')->insert([
            'template_id' => $templateId,
            'name' => 'chunkskip/template_owned',
            'content' => json_encode(['type' => 'page']),
            'source_type' => 'template',
            'original_content_hash' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runBackfill();

        $row = DB::table('template_layouts')
            ->where('template_id', $templateId)
            ->where('source_type', 'template')
            ->first(['original_content_hash']);

        $this->assertNull($row->original_content_hash, '템플릿 소유 레이아웃은 백필 대상이 아닙니다.');
    }
}
