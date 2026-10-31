<?php

namespace Tests\Feature\Extension;

use App\Enums\ExtensionStatus;
use App\Extension\Layout\LayoutContentComparator;
use App\Extension\Layout\LayoutRowSynchronizer;
use App\Extension\Layout\LayoutSyncSummary;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Extension\Traits\ComputesLayoutContentHash;
use App\Extension\Traits\ValidatesLayoutFiles;
use App\Models\LayoutExtension;
use App\Models\Module;
use App\Models\Plugin;
use App\Models\TemplateLayout;
use App\Models\TemplateLayoutExtensionVersion;
use App\Models\TemplateLayoutVersion;
use App\Services\LayoutExtensionService;
use App\Support\LayoutMarkup\LayoutPartialOrigin;
use App\Support\LayoutMarkup\MarkupCompileContextResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Support\Concerns\BuildsLayoutMergeFixtures;
use Tests\TestCase;

/**
 * 레이아웃 동기화 경로 × 출처(템플릿·모듈·플러그인) × 병합 단위 매트릭스 (#727 계획 §6.3·§6.4).
 *
 * 업데이트 keep·백업 기준·기준 없음·overwrite·수동 갱신·활성화·템플릿 활성화·다른 확장 활성화·새 버전에서 빠짐·
 * 꺼 둔 채 업데이트 후 켜기 경로를 가짜 템플릿·모듈·플러그인 파일로 실제 실행한다. 경로 코드가 출처마다 대칭이라
 * 출처·단위를 데이터 제공자로 돌린다.
 *
 * 단위 뜻(평문 요약): partial = 레이아웃 안 조각 파일, top_level_key = 레이아웃 최상위 키, extension_fragment = 확장
 * 조각 행(템플릿은 조각 오버라이드 행).
 *
 * 병합이 일어난 칸은 공통 단언({@see assertMergeWrite()})으로 잠금 +1·버전 이력 `source=extension_update`·원본 해시가
 * 새 판으로 옮겨감·정규화 비교를 함께 본다.
 */
class LayoutSyncPathMatrixTest extends TestCase
{
    use BuildsLayoutMergeFixtures;
    use ComputesLayoutContentHash;
    use DatabaseTransactions;

    /** 병합 대상 화면 이름 (접두어 제외) */
    private const SCREEN = 'merge_list';

    /** 템플릿 조각 오버라이드의 대상 모듈 디렉토리 */
    private const OVERRIDE_TARGET = 'some-module';

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

    // ──────────────────────────────────────────────────────────────────────
    // 데이터 제공자
    // ──────────────────────────────────────────────────────────────────────

    /**
     * 출처 3 × 단위 3 — 업데이트 keep 칸.
     *
     * @return array<string, array{0: string, 1: string}> [출처, 단위]
     */
    public static function everySourceAndUnit(): array
    {
        $cells = [];
        foreach (['template', 'module', 'plugin'] as $source) {
            foreach (['partial', 'top_level_key', 'extension_fragment'] as $unit) {
                $cells["{$source}/{$unit}"] = [$source, $unit];
            }
        }

        return $cells;
    }

    /**
     * 출처 3 × (조각·확장 조각) — 행 단위로만 갈리는 경로(덮어쓰기·기준 없음·수동 갱신).
     *
     * @return array<string, array{0: string, 1: string}> [출처, 단위]
     */
    public static function everySourceRowUnits(): array
    {
        $cells = [];
        foreach (['template', 'module', 'plugin'] as $source) {
            foreach (['partial', 'extension_fragment'] as $unit) {
                $cells["{$source}/{$unit}"] = [$source, $unit];
            }
        }

        return $cells;
    }

    /**
     * 템플릿(레이아웃만) + 모듈·플러그인(레이아웃·확장 조각) — 백업 기준·새 버전에서 빠짐·꺼 둔 채 업데이트 칸.
     *
     * @return array<string, array{0: string, 1: string}> [출처, 단위]
     */
    public static function templateLayoutAndExtensionUnits(): array
    {
        return [
            'template/partial' => ['template', 'partial'],
            'module/partial' => ['module', 'partial'],
            'module/extension_fragment' => ['module', 'extension_fragment'],
            'plugin/partial' => ['plugin', 'partial'],
            'plugin/extension_fragment' => ['plugin', 'extension_fragment'],
        ];
    }

    /**
     * 템플릿(레이아웃·조각 오버라이드) + 모듈·플러그인(레이아웃·확장 조각) — 템플릿 활성화 칸.
     *
     * @return array<string, array{0: string, 1: string}> [출처, 단위]
     */
    public static function templateActivationCells(): array
    {
        return ['template/extension_fragment' => ['template', 'extension_fragment']] + self::templateLayoutAndExtensionUnits();
    }

    /**
     * 모듈·플러그인 × (조각·확장 조각) — 확장 자기 활성화 칸.
     *
     * @return array<string, array{0: string, 1: string}> [출처, 단위]
     */
    public static function extensionSourceRowUnits(): array
    {
        return [
            'module/partial' => ['module', 'partial'],
            'module/extension_fragment' => ['module', 'extension_fragment'],
            'plugin/partial' => ['plugin', 'partial'],
            'plugin/extension_fragment' => ['plugin', 'extension_fragment'],
        ];
    }

    /**
     * 모듈·플러그인 — 다른 확장 활성화(전 확장 조각 재등록) 칸.
     *
     * @return array<string, array{0: string}> [출처]
     */
    public static function extensionSources(): array
    {
        return ['module' => ['module'], 'plugin' => ['plugin']];
    }

    /**
     * 출처 3 — 마크업 백업 기준 칸.
     *
     * @return array<string, array{0: string}> [출처]
     */
    public static function everySource(): array
    {
        return ['template' => ['template'], 'module' => ['module'], 'plugin' => ['plugin']];
    }

    // ──────────────────────────────────────────────────────────────────────
    // 매트릭스 — update_keep
    // ──────────────────────────────────────────────────────────────────────

    // 이 DataProvider 가 함께 검증하는 9조합 (docblock 은 @scenario 를 1건만 인식하므로 라인 주석으로 병기).
    // @scenario source=template, path=update_keep, unit=top_level_key
    // @scenario source=template, path=update_keep, unit=extension_fragment
    // @scenario source=module, path=update_keep, unit=partial
    // @scenario source=module, path=update_keep, unit=top_level_key
    // @scenario source=module, path=update_keep, unit=extension_fragment
    // @scenario source=plugin, path=update_keep, unit=partial
    // @scenario source=plugin, path=update_keep, unit=top_level_key
    // @scenario source=plugin, path=update_keep, unit=extension_fragment

    /**
     * keep 업데이트는 운영자가 고친 단위는 두고 새 버전이 바꾼 다른 단위만 반영한다 — 세 출처·세 단위 모두.
     *
     * @scenario source=template, path=update_keep, unit=partial
     *
     * @effects keep_update_merges_new_version_into_modified_layout, merge_bumps_lock_version_and_records_versions, fragment_modification_is_merged_not_lost, template_override_layouts_are_merged
     *
     * @param  string  $source  출처
     * @param  string  $unit  병합 단위
     * @return void 반환값 없음
     */
    #[DataProvider('everySourceAndUnit')]
    public function test_keep_update_merges_new_version_into_operator_edit(string $source, string $unit): void
    {
        $this->install($source);
        $row = $this->operatorEdit($source, $unit);
        $lock = (int) $row->lock_version;
        $backup = $this->backup($source);
        $this->writeVersion($source, $this->v2($unit));

        $sync = $this->refresh($source, true, $backup)['layout_sync'];

        $theirs = $this->theirs($source, $unit);
        $this->assertMergeWrite($row, $lock, $this->applyOperatorEdit($unit, $theirs), $theirs);
        $this->assertSame(1, $sync['modified_count'], '운영자가 고친 행 하나만 보고된다');
        $this->assertSame('merged', $sync['entries'][0]['verdict']);
        $this->assertSame(1, $sync['applied_count'], '새 버전이 바꾼 단위 1곳이 자동 반영돼야 한다');
        $this->assertSame(0, $sync['conflict_count']);

        if ($unit === 'extension_fragment') {
            $this->assertStringContainsString('FB2', $this->flat($this->fragmentRow($source, 'frag_b')->content), '운영자가 안 고친 조각 행은 새 판이어야 한다');
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // 매트릭스 — update_keep_backup_base (원본 본문 없는 옛 행 + 백업 컴파일 해시 일치)
    // ──────────────────────────────────────────────────────────────────────

    // @scenario source=module, path=update_keep_backup_base, unit=partial
    // @scenario source=module, path=update_keep_backup_base, unit=extension_fragment
    // @scenario source=plugin, path=update_keep_backup_base, unit=partial
    // @scenario source=plugin, path=update_keep_backup_base, unit=extension_fragment

    /**
     * 원본 본문이 없는 옛 행(업그레이드 직후 첫 업데이트)은 해시가 맞는 백업 컴파일 결과를 기준으로 병합한다 (W3 ②).
     *
     * @scenario source=template, path=update_keep_backup_base, unit=partial
     *
     * @effects base_is_recovered_from_backup_when_hash_matches, merge_bumps_lock_version_and_records_versions
     *
     * @param  string  $source  출처
     * @param  string  $unit  병합 단위
     * @return void 반환값 없음
     */
    #[DataProvider('templateLayoutAndExtensionUnits')]
    public function test_keep_update_recovers_base_from_backup_for_legacy_row(string $source, string $unit): void
    {
        $this->install($source);
        $row = $this->operatorEdit($source, $unit);
        $row->forceFill(['original_content' => null])->saveQuietly();
        $lock = (int) $row->lock_version;
        $backup = $this->backup($source);
        $this->writeVersion($source, $this->v2($unit));

        $sync = $this->refresh($source, true, $backup)['layout_sync'];

        $theirs = $this->theirs($source, $unit);
        $this->assertMergeWrite($row, $lock, $this->applyOperatorEdit($unit, $theirs), $theirs);
        $this->assertSame('merged', $sync['entries'][0]['verdict'] ?? null, '백업 원본을 기준으로 병합돼야 한다');
        $this->assertSame(0, $sync['kept_no_base_count']);
    }

    // 이 DataProvider 가 함께 검증하는 나머지 출처 (docblock 은 @scenario 를 1건만 인식하므로 라인 주석으로 병기).
    // @scenario source=module, path=update_keep_backup_base, unit=partial
    // @scenario source=plugin, path=update_keep_backup_base, unit=partial

    /**
     * 마크업(`.g7.html`)으로 설치된 옛 행도 업데이트 백업 디렉토리를 다시 컴파일해 병합 기준을 되찾는다 (W3 ②).
     *
     * 위 JSON 칸과 달리 백업의 레이아웃·조각이 `.g7.html` 이라 백업 경로(`{식별자}_{Ymd_His}_…`)에서 마크업 컴파일이
     * 돈다. 경로로는 대상 템플릿·소유 확장을 뽑을 수 없어 `BackupLayoutReader` 가 컴파일 문맥을 강제 지정
     * (`compileContextOverride`)해야 하고, 템플릿은 그 지정 없이는 레지스트리를 못 찾아 컴파일 자체가 실패한다
     * ({@see assertBackupIsMarkupNeedingOverride()} 가 그 전제를 함께 잰다).
     *
     * 순서: v1 마크업 설치(조각 `<include>` 두 개) → 운영자가 조각 A 수정 + 원본 본문 NULL(7.1.0 이전 행, 해시는 설치
     * 그대로) → v1 백업 → v2 파일(조각 B 변경) → keep 갱신에 백업 경로 전달. 병합 판정·조각 B 반영·운영자 수정 유지·
     * 원본 본문 재충전(조각 출처 표식 포함)을 본다.
     *
     * @scenario source=template, path=update_keep_backup_base, unit=partial
     *
     * @effects base_is_recovered_from_backup_when_hash_matches, merge_bumps_lock_version_and_records_versions
     *
     * @param  string  $source  출처
     * @return void 반환값 없음
     */
    #[DataProvider('everySource')]
    public function test_keep_update_recovers_base_by_compiling_markup_backup_for_legacy_row(string $source): void
    {
        $this->installMarkup($source);
        $row = $this->layoutRow($source, self::SCREEN);
        $this->assertNotNull($row, '마크업으로 설치된 화면 행이 있어야 한다');
        $installedHash = $row->original_content_hash;
        $this->assertNotNull($installedHash);

        $this->operatorEdits($row, fn (array $content) => $this->editMarkupPartA($content));
        $row->fresh()->forceFill(['original_content' => null])->saveQuietly();
        $row = $row->fresh();
        $this->assertNull($row->getRawOriginal('original_content'), '7.1.0 이전 행처럼 원본 본문이 비어 있어야 한다');
        $this->assertSame($installedHash, $row->original_content_hash, '원본 해시는 설치 당시 그대로');
        $lock = (int) $row->lock_version;

        $backup = $this->backup($source);
        $this->assertBackupIsMarkupNeedingOverride($source, $backup);
        $this->writeMarkupScreen($source, 'A1', 'B2-new');

        $sync = $this->refresh($source, true, $backup)['layout_sync'];

        $this->assertSame('merged', $sync['entries'][0]['verdict'] ?? null, '백업 마크업 컴파일 결과를 기준으로 병합돼야 한다');
        $this->assertSame(0, $sync['kept_no_base_count'], '백업 기준을 찾았으므로 기준 없음이 아니다');

        $theirs = $this->theirs($source, 'partial');
        $this->assertMergeWrite($row, $lock, $this->editMarkupPartA($theirs), $theirs);

        $fresh = $row->fresh();
        $content = $this->flat(json_decode((string) $fresh->getRawOriginal('content'), true));
        $this->assertStringContainsString('운영자 A', $content, '운영자가 고친 조각 A 는 남아야 한다');
        $this->assertStringContainsString('B2-new', $content, '새 버전이 바꾼 조각 B 는 반영돼야 한다');
        $this->assertStringNotContainsString(LayoutPartialOrigin::KEY, $content, '화면 본문에는 조각 출처 표식이 없어야 한다');

        $original = json_decode((string) $fresh->getRawOriginal('original_content'), true);
        $origins = array_values(array_filter(array_map(fn ($node) => $node[LayoutPartialOrigin::KEY] ?? null, $original['components'] ?? [])));
        $prefix = $source === 'template' ? '' : 'admin/';
        $this->assertSame([$prefix.'partials/merge_list/_a', $prefix.'partials/merge_list/_b'], $origins, '원본 본문은 조각 출처 표식과 함께 다시 채워져야 한다');
    }

    // ──────────────────────────────────────────────────────────────────────
    // 매트릭스 — update_keep_no_base (원본 본문 없음 + 백업·새 파일 해시 불일치)
    // ──────────────────────────────────────────────────────────────────────

    // @scenario source=template, path=update_keep_no_base, unit=extension_fragment
    // @scenario source=module, path=update_keep_no_base, unit=partial
    // @scenario source=module, path=update_keep_no_base, unit=extension_fragment
    // @scenario source=plugin, path=update_keep_no_base, unit=partial
    // @scenario source=plugin, path=update_keep_no_base, unit=extension_fragment

    /**
     * 기준을 찾지 못하면(원본 본문 없음 + 백업·새 파일 해시 불일치) 운영자 판·원본 컬럼·해시·잠금을 모두 그대로 두고
     * 「기준 없음」 으로 보고한다 (W17).
     *
     * @scenario source=template, path=update_keep_no_base, unit=partial
     *
     * @effects missing_base_keeps_operator_layout_untouched, update_api_message_summarizes_layout_sync
     *
     * @param  string  $source  출처
     * @param  string  $unit  병합 단위
     * @return void 반환값 없음
     */
    #[DataProvider('everySourceRowUnits')]
    public function test_keep_update_without_base_keeps_row_untouched(string $source, string $unit): void
    {
        $this->install($source);
        $row = $this->operatorEdit($source, $unit);
        $row->forceFill(['original_content' => null])->saveQuietly();
        $before = $row->fresh();
        $backup = $this->backup($source);
        $this->tamperBackup($source, $backup);
        $this->writeVersion($source, $this->v2($unit));

        $sync = $this->refresh($source, true, $backup)['layout_sync'];

        $fresh = $before->fresh();
        $this->assertTrue(LayoutContentComparator::sameDocument($fresh->getRawOriginal('content'), $before->getRawOriginal('content')), '운영자 판이 그대로여야 한다');
        $this->assertNull($fresh->getRawOriginal('original_content'), '원본 본문을 채우면 안 된다');
        $this->assertSame($before->original_content_hash, $fresh->original_content_hash, '원본 해시가 그대로여야 한다');
        $this->assertSame((int) $before->lock_version, (int) $fresh->lock_version, '내용이 안 바뀌었으니 잠금도 그대로');
        $this->assertSame(1, $sync['kept_no_base_count']);
        $this->assertSame('kept_no_base', $sync['entries'][0]['verdict']);
        $this->assertStringContainsString(
            __('extensions.layout_sync.no_base', ['count' => 1]),
            (string) LayoutSyncSummary::text($sync),
            '업데이트 알림 문구에 「기준 없음」 안내가 붙어야 한다'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 매트릭스 — update_overwrite
    // ──────────────────────────────────────────────────────────────────────

    // @scenario source=template, path=update_overwrite, unit=extension_fragment
    // @scenario source=module, path=update_overwrite, unit=partial
    // @scenario source=module, path=update_overwrite, unit=extension_fragment
    // @scenario source=plugin, path=update_overwrite, unit=partial
    // @scenario source=plugin, path=update_overwrite, unit=extension_fragment

    /**
     * overwrite 업데이트는 운영자 수정을 새 판으로 덮고(잠금 +1, 원본 = 새 판) 보고에는 남기지 않는다.
     *
     * @scenario source=template, path=update_overwrite, unit=partial
     *
     * @effects overwrite_replaces_operator_layout
     *
     * @param  string  $source  출처
     * @param  string  $unit  병합 단위
     * @return void 반환값 없음
     */
    #[DataProvider('everySourceRowUnits')]
    public function test_overwrite_update_replaces_operator_edit(string $source, string $unit): void
    {
        $this->install($source);
        $row = $this->operatorEdit($source, $unit);
        $lock = (int) $row->lock_version;
        $backup = $this->backup($source);
        $this->writeVersion($source, $this->v2($unit));

        $sync = $this->refresh($source, false, $backup)['layout_sync'];

        $theirs = $this->theirs($source, $unit);
        $this->assertOverwritten($row, $lock, $theirs);
        $this->assertSame('overwrite', $sync['strategy']);
        $this->assertSame(0, $sync['modified_count'], '덮어쓰기는 운영자 관련 보고를 만들지 않는다');
        $this->assertNull(LayoutSyncSummary::text($sync), '덮어쓰기에는 요약 문구가 없다');
    }

    // ──────────────────────────────────────────────────────────────────────
    // 매트릭스 — manual_refresh (수동 「레이아웃 갱신」 = 덮어쓰기, L12)
    // ──────────────────────────────────────────────────────────────────────

    // @scenario source=template, path=manual_refresh, unit=extension_fragment
    // @scenario source=module, path=manual_refresh, unit=partial
    // @scenario source=module, path=manual_refresh, unit=extension_fragment
    // @scenario source=plugin, path=manual_refresh, unit=partial
    // @scenario source=plugin, path=manual_refresh, unit=extension_fragment

    /**
     * 수동 「레이아웃 갱신」(식별자만 넘기는 호출 — 서비스·커맨드가 쓰는 모양)은 파일이 그대로여도 운영자 수정을 파일 판으로
     * 되돌린다 — keep 도입 뒤에도 덮어쓰기 의미를 유지한다 (L12).
     *
     * @scenario source=template, path=manual_refresh, unit=partial
     *
     * @effects overwrite_replaces_operator_layout, manual_refresh_keeps_overwrite_semantics
     *
     * @param  string  $source  출처
     * @param  string  $unit  병합 단위
     * @return void 반환값 없음
     */
    #[DataProvider('everySourceRowUnits')]
    public function test_manual_refresh_overwrites_operator_edit(string $source, string $unit): void
    {
        $this->install($source);
        $row = $this->operatorEdit($source, $unit);
        $lock = (int) $row->lock_version;

        match ($source) {
            'template' => app(TemplateManager::class)->refreshTemplateLayouts($this->fixtureTemplate),
            'module' => app(ModuleManager::class)->refreshModuleLayouts($this->fixtureModule),
            'plugin' => app(PluginManager::class)->refreshPluginLayouts($this->fixturePlugin),
        };

        $this->assertOverwritten($row, $lock, $this->theirs($source, $unit));
    }

    // ──────────────────────────────────────────────────────────────────────
    // 매트릭스 — activate (확장 자기 활성화, D3)
    // ──────────────────────────────────────────────────────────────────────

    // @scenario source=module, path=activate, unit=extension_fragment
    // @scenario source=plugin, path=activate, unit=partial
    // @scenario source=plugin, path=activate, unit=extension_fragment

    /**
     * 모듈·플러그인을 다시 켜는 활성화 순서(레이아웃 등록 → 조각 복원 → 조각 등록 → 전 확장 조각 재등록)는 운영자 수정을
     * 지우지 않는다 — 내용·잠금·버전 이력 모두 그대로 (D3).
     *
     * @scenario source=module, path=activate, unit=partial
     *
     * @effects activation_preserves_operator_modification
     *
     * @param  string  $source  출처
     * @param  string  $unit  병합 단위
     * @return void 반환값 없음
     */
    #[DataProvider('extensionSourceRowUnits')]
    public function test_extension_activation_preserves_operator_edit(string $source, string $unit): void
    {
        $this->install($source);
        $row = $this->operatorEdit($source, $unit);

        $this->activateExtension($source);

        $this->assertPreserved($row);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 매트릭스 — template_activate (템플릿 활성화가 부르는 등록들, D3·D11)
    // ──────────────────────────────────────────────────────────────────────

    // @scenario source=template, path=template_activate, unit=extension_fragment
    // @scenario source=module, path=template_activate, unit=partial
    // @scenario source=module, path=template_activate, unit=extension_fragment
    // @scenario source=plugin, path=template_activate, unit=partial
    // @scenario source=plugin, path=template_activate, unit=extension_fragment

    /**
     * 템플릿 활성화 순서(자기 레이아웃 keep 갱신 → 활성 확장 조각 등록 → 활성 모듈·플러그인 레이아웃 등록)는 템플릿·모듈·
     * 플러그인 레이아웃과 조각의 운영자 수정을 지우지 않는다.
     *
     * @scenario source=template, path=template_activate, unit=partial
     *
     * @effects activation_preserves_operator_modification
     *
     * @param  string  $source  출처
     * @param  string  $unit  병합 단위
     * @return void 반환값 없음
     */
    #[DataProvider('templateActivationCells')]
    public function test_template_activation_preserves_operator_edit(string $source, string $unit): void
    {
        $this->install($source);
        $row = $this->operatorEdit($source, $unit);

        // TemplateManager::activateTemplate() 의 레이아웃 단계와 같은 순서 (호출 계약은 LayoutKeepMergeTest 가 잠근다)
        app(TemplateManager::class)->refreshTemplateLayouts($this->fixtureTemplate, true);
        app(LayoutExtensionService::class)->registerAllActiveExtensionsToTemplate($this->fixtureTemplateModel->id);
        if ($source === 'module') {
            $this->invokeManager('module', 'registerModuleLayouts', $this->fixtureModule);
        } elseif ($source === 'plugin') {
            $this->invokeManager('plugin', 'registerPluginLayouts', $this->fixturePlugin);
        }

        $this->assertPreserved($row);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 매트릭스 — other_extension_activate (다른 확장 활성화의 전 확장 조각 재등록)
    // ──────────────────────────────────────────────────────────────────────

    // @scenario source=plugin, path=other_extension_activate, unit=extension_fragment

    /**
     * 아무 확장이나 켤 때 도는 전 활성 확장 조각 재등록은 이 확장 조각의 운영자 수정을 지우지 않는다.
     *
     * @scenario source=module, path=other_extension_activate, unit=extension_fragment
     *
     * @effects activation_preserves_operator_modification, fragment_modification_is_merged_not_lost
     *
     * @param  string  $source  출처
     * @return void 반환값 없음
     */
    #[DataProvider('extensionSources')]
    public function test_other_extension_activation_preserves_fragment_edit(string $source): void
    {
        $this->install($source);
        $row = $this->operatorEdit($source, 'extension_fragment');

        app(LayoutExtensionService::class)->registerAllActiveExtensionsToTemplate($this->fixtureTemplateModel->id);

        $this->assertPreserved($row);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 매트릭스 — removed_in_new_version (D4)
    // ──────────────────────────────────────────────────────────────────────

    // @scenario source=module, path=removed_in_new_version, unit=partial
    // @scenario source=module, path=removed_in_new_version, unit=extension_fragment
    // @scenario source=plugin, path=removed_in_new_version, unit=partial
    // @scenario source=plugin, path=removed_in_new_version, unit=extension_fragment

    /**
     * keep 업데이트는 새 버전에서 빠진 화면·조각이라도 운영자 수정본이면 남기고(`kept_removed`), 손대지 않은 것은 지운다 (D4).
     *
     * @scenario source=template, path=removed_in_new_version, unit=partial
     *
     * @effects modified_layout_removed_in_new_version_is_kept
     *
     * @param  string  $source  출처
     * @param  string  $unit  병합 단위
     * @return void 반환값 없음
     */
    #[DataProvider('templateLayoutAndExtensionUnits')]
    public function test_keep_update_keeps_modified_row_removed_in_new_version(string $source, string $unit): void
    {
        $this->install($source);
        $row = $this->operatorEdit($source, $unit);
        $edited = $row->fresh()->getRawOriginal('content');
        $backup = $this->backup($source);

        // 새 버전: 고친 화면(또는 조각 둘 다)과 손대지 않은 화면 merge_gone 이 빠진다
        $spec = $this->v1Spec();
        $spec['gone'] = false;
        if ($unit === 'extension_fragment') {
            $spec['fragments'] = false;
        } else {
            $spec['screen'] = false;
        }
        $this->writeVersion($source, $spec);

        $sync = $this->refresh($source, true, $backup)['layout_sync'];

        $kept = $row instanceof TemplateLayout ? TemplateLayout::find($row->id) : LayoutExtension::find($row->id);
        $this->assertNotNull($kept, '새 버전에서 빠졌지만 운영자 수정본인 행이 지워졌다');
        $this->assertTrue(LayoutContentComparator::sameDocument($kept->getRawOriginal('content'), $edited));
        $this->assertSame(1, $sync['kept_removed_count']);
        $this->assertSame('kept_removed', $sync['entries'][0]['verdict']);

        if ($unit === 'extension_fragment') {
            $this->assertNull(LayoutExtension::find($this->fragmentRow($source, 'frag_b', true)->id), '손대지 않은 빠진 조각은 지워져야 한다');
        } else {
            $this->assertNull($this->layoutRow($source, 'merge_gone'), '손대지 않은 빠진 화면은 지워져야 한다');
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // 매트릭스 — inactive_update_then_activate (D11)
    // ──────────────────────────────────────────────────────────────────────

    // @scenario source=module, path=inactive_update_then_activate, unit=partial
    // @scenario source=module, path=inactive_update_then_activate, unit=extension_fragment
    // @scenario source=plugin, path=inactive_update_then_activate, unit=partial
    // @scenario source=plugin, path=inactive_update_then_activate, unit=extension_fragment

    /**
     * 꺼 둔 확장을 업데이트하면 백업을 지우기 전에 이전 판 원본을 미리 저장하고(원본 본문 없는 옛 행에만), 켤 때 그 원본을
     * 기준으로 운영자 수정은 두고 새 판을 병합한다 (D11 · R17 — 템플릿·모듈·플러그인).
     *
     * @scenario source=template, path=inactive_update_then_activate, unit=partial
     *
     * @effects inactive_update_stashes_base_for_activation, keep_update_merges_new_version_into_modified_layout, merge_bumps_lock_version_and_records_versions
     *
     * @param  string  $source  출처
     * @param  string  $unit  병합 단위
     * @return void 반환값 없음
     */
    #[DataProvider('templateLayoutAndExtensionUnits')]
    public function test_inactive_update_then_activation_merges_new_version(string $source, string $unit): void
    {
        $this->install($source);
        $row = $this->operatorEdit($source, $unit);
        $row->forceFill(['original_content' => null])->saveQuietly();
        $lock = (int) $row->lock_version;

        // 비활성 상태에서 업데이트: 백업(v1) → 파일 교체(v2) → 원본 미리 저장 → 백업 삭제
        $backup = $this->backup($source);
        $this->setStatus($source, ExtensionStatus::Inactive->value);
        $this->writeVersion($source, $this->v2($unit));
        $filled = app(LayoutRowSynchronizer::class)->stashBaseForInactiveUpdate($backup, $source.'s', $this->identifierOf($source));
        File::deleteDirectory($backup);

        $this->assertGreaterThanOrEqual(1, $filled, '원본 본문 없는 행에 이전 판 원본이 미리 저장돼야 한다');
        $this->assertNotNull($row->fresh()->getRawOriginal('original_content'));

        // 켜기 — 템플릿은 자기 레이아웃 keep 갱신, 모듈·플러그인은 활성화 등록 순서
        $this->setStatus($source, ExtensionStatus::Active->value);
        if ($source === 'template') {
            app(TemplateManager::class)->refreshTemplateLayouts($this->fixtureTemplate, true);
        } else {
            $this->activateExtension($source);
        }

        $theirs = $this->theirs($source, $unit);
        $this->assertMergeWrite($row, $lock, $this->applyOperatorEdit($unit, $theirs), $theirs);
    }

    // ──────────────────────────────────────────────────────────────────────
    // W17 — 모듈 keep 업데이트 충돌 뒤 원본 이동
    // ──────────────────────────────────────────────────────────────────────

    /**
     * 모듈 keep 업데이트가 운영자와 같은 조각을 새 버전도 고쳐 충돌로 끝나도, 운영자 판은 남기고 원본·해시는 새 판으로 옮기며
     * 버전 이력에 충돌 기록을 남긴다 — 겹친 새 판 변경을 다음 업데이트가 다시 제안하지 않게 (W17).
     *
     * @effects conflicting_change_keeps_operator_version_and_is_reported, conflict_moves_base_to_new_version
     *
     * @return void 반환값 없음
     */
    public function test_module_keep_update_conflict_moves_base_to_new_version(): void
    {
        $this->install('module');
        $row = $this->operatorEdit('module', 'partial');
        $edited = $row->fresh()->getRawOriginal('content');
        $backup = $this->backup('module');

        $spec = $this->v1Spec();
        $spec['a'] = 'A-new';
        $this->writeVersion('module', $spec);

        $sync = $this->refresh('module', true, $backup)['layout_sync'];

        $fresh = $row->fresh();
        $theirs = $this->theirs('module', 'partial');
        $this->assertSame('conflict', $sync['entries'][0]['verdict']);
        $this->assertSame(['both_changed'], array_column($sync['entries'][0]['conflicts'], 'reason'));
        $this->assertTrue(LayoutContentComparator::sameDocument($fresh->getRawOriginal('content'), $edited), '충돌이면 운영자 판을 남긴다');
        $this->assertSame($this->computeContentHash($theirs), $fresh->original_content_hash, '충돌 뒤 원본 해시는 새 판');
        $this->assertStringContainsString('A-new', (string) $fresh->getRawOriginal('original_content'), '충돌 뒤 원본 본문은 새 판');
        $this->assertSame('extension_update', TemplateLayoutVersion::where('layout_id', $row->id)->orderByDesc('version')->first()?->changes_summary['source'] ?? null);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 픽스처 — 파일 판
    // ──────────────────────────────────────────────────────────────────────

    /**
     * v1 명세 — 화면(조각 A·B, meta.title, computed) + 손대지 않는 화면 merge_gone + 조각 두 개.
     *
     * @return array<string, mixed> 판 명세
     */
    private function v1Spec(): array
    {
        return ['screen' => true, 'gone' => true, 'fragments' => true, 'a' => 'A1', 'b' => 'B1', 'computed' => 'C1', 'title' => 'T1', 'fa' => 'FA1', 'fb' => 'FB1', 'priority' => 900];
    }

    /**
     * 단위별 v2 명세 — 운영자가 고친 곳과 겹치지 않는 곳만 새 버전이 바꾼다.
     *
     * @param  string  $unit  병합 단위
     * @return array<string, mixed> 판 명세
     */
    private function v2(string $unit): array
    {
        $spec = $this->v1Spec();

        switch ($unit) {
            case 'partial':             // 운영자: 조각 A / 새 버전: 조각 B
                $spec['b'] = 'B2';
                break;
            case 'top_level_key':       // 운영자: meta / 새 버전: computed
                $spec['computed'] = 'C2';
                break;
            case 'extension_fragment':  // 운영자: frag_a 문구 / 새 버전: frag_a priority + frag_b 문구
                $spec['priority'] = 950;
                $spec['fb'] = 'FB2';
                break;
        }

        return $spec;
    }

    /**
     * 화면 파일 묶음을 만듭니다.
     *
     * @param  string  $prefix  레이아웃 디렉토리 안 접두 경로 (모듈·플러그인은 `admin/`)
     * @param  array<string, mixed>  $spec  판 명세
     * @return array<string, array<string, mixed>> 상대 경로 => JSON
     */
    private function screenFiles(string $prefix, array $spec): array
    {
        $files = [];

        if ($spec['screen']) {
            $files[$prefix.self::SCREEN.'.json'] = [
                'version' => '1.0.0',
                'layout_name' => self::SCREEN,
                'meta' => ['title' => $spec['title']],
                'computed' => ['c' => $spec['computed']],
                'data_sources' => [['id' => 'items', 'type' => 'api', 'endpoint' => '/api/items']],
                'slots' => ['content' => [
                    ['id' => 'title', 'type' => 'basic', 'name' => 'H1', 'text' => '목록'],
                    ['partial' => 'partials/merge_list/_a.json'],
                    ['partial' => 'partials/merge_list/_b.json'],
                ]],
            ];
            $files[$prefix.'partials/merge_list/_a.json'] = ['meta' => ['is_partial' => true], 'id' => 'part_a', 'type' => 'basic', 'name' => 'Div', 'text' => $spec['a']];
            $files[$prefix.'partials/merge_list/_b.json'] = ['meta' => ['is_partial' => true], 'id' => 'part_b', 'type' => 'basic', 'name' => 'Div', 'text' => $spec['b']];
        }

        if ($spec['gone']) {
            $files[$prefix.'merge_gone.json'] = ['version' => '1.0.0', 'layout_name' => 'merge_gone', 'components' => [['id' => 'gone', 'type' => 'basic', 'name' => 'Div', 'text' => 'G']]];
        }

        return $files;
    }

    /**
     * 조각 파일 묶음을 만듭니다 — 템플릿 호스트 화면 `merge_host` 의 `anchor` 에 붙습니다.
     *
     * @param  array<string, mixed>  $spec  판 명세
     * @return array<string, array<string, mixed>> 파일명 => JSON
     */
    private function fragmentFiles(array $spec): array
    {
        if (! $spec['fragments']) {
            return [];
        }

        $fragment = fn (string $ref, string $text, int $priority) => [
            'target_layout' => 'merge_host',
            'priority' => $priority,
            'injections' => [[
                'target_id' => 'anchor',
                'position' => 'append_child',
                'components' => [['id' => $ref, 'type' => 'basic', 'name' => 'Div', 'text' => $text]],
            ]],
        ];

        return [
            'frag_a.json' => $fragment('frag_a', $spec['fa'], $spec['priority']),
            'frag_b.json' => $fragment('frag_b', $spec['fb'], 900),
        ];
    }

    /**
     * 조각이 붙을 템플릿 호스트 화면.
     *
     * @return array<string, array<string, mixed>> 상대 경로 => JSON
     */
    private function hostLayout(): array
    {
        return ['merge_host.json' => ['version' => '1.0.0', 'layout_name' => 'merge_host', 'components' => [['id' => 'anchor', 'type' => 'basic', 'name' => 'Div']]]];
    }

    /**
     * 출처의 v1 을 설치합니다 — 템플릿은 설치 등록(자기 레이아웃 + 조각 오버라이드), 모듈·플러그인은 활성화 등록.
     *
     * @param  string  $source  출처
     * @return void 반환값 없음
     */
    private function install(string $source): void
    {
        $spec = $this->v1Spec();
        $this->makeFixtureTemplate($this->hostLayout() + ($source === 'template' ? $this->screenFiles('', $spec) : []));

        if ($source === 'template') {
            $this->writeTemplateExtensionOverrides(self::OVERRIDE_TARGET, $this->fragmentFiles($spec));
        }

        app(TemplateManager::class)->refreshTemplateLayouts($this->fixtureTemplate, false);

        if ($source === 'module') {
            $this->makeFixtureModule($this->screenFiles('admin/', $spec), $this->fragmentFiles($spec));
            $this->activateExtension('module', false);
        } elseif ($source === 'plugin') {
            $this->makeFixturePlugin($this->screenFiles('admin/', $spec), $this->fragmentFiles($spec));
            $this->activateExtension('plugin', false);
        }
    }

    /**
     * 출처의 레이아웃 소스 디렉토리 (템플릿은 `layouts`, 모듈·플러그인은 `resources/layouts/admin`).
     *
     * @param  string  $source  출처
     * @param  string|null  $root  출처 디렉토리 대신 쓸 뿌리 (백업 경로), null 이면 설치 디렉토리
     * @return string 디렉토리 경로
     */
    private function markupLayoutsDir(string $source, ?string $root = null): string
    {
        $root ??= match ($source) {
            'template' => base_path("templates/{$this->fixtureTemplate}"),
            'module' => base_path("modules/{$this->fixtureModule}"),
            'plugin' => base_path("plugins/{$this->fixturePlugin}"),
        };

        return $source === 'template' ? $root.'/layouts' : $root.'/resources/layouts/admin';
    }

    /**
     * 마크업 화면(`merge_list.g7.html` + `<partial>` 루트 조각 두 개)을 씁니다 — 조각 문구만 판마다 다르게.
     *
     * 판마다 조각 파일 길이를 다르게 쓴다 — 조각 컴파일 캐시 키가 경로·수정 시각(초)·크기라, 같은 초에 같은 크기로
     * 다시 쓰면 앞 판 컴파일 결과를 재사용한다.
     *
     * @param  string  $source  출처
     * @param  string  $aText  조각 A 문구
     * @param  string  $bText  조각 B 문구
     * @return void 반환값 없음
     */
    private function writeMarkupScreen(string $source, string $aText, string $bText): void
    {
        $dir = $this->markupLayoutsDir($source);
        File::ensureDirectoryExists($dir.'/partials/merge_list');

        File::put($dir.'/'.self::SCREEN.'.g7.html', <<<'HTML'
<layout name="merge_list" version="1.0.0">
  <meta title="T1"/>
  <h1 data-ref="title">목록</h1>
  <include src="partials/merge_list/_a.g7.html"/>
  <include src="partials/merge_list/_b.g7.html"/>
</layout>
HTML);
        File::put($dir.'/partials/merge_list/_a.g7.html', "<partial description=\"merge A\">\n  <div data-ref=\"part_a\">{$aText}</div>\n</partial>\n");
        File::put($dir.'/partials/merge_list/_b.g7.html', "<partial description=\"merge B\">\n  <div data-ref=\"part_b\">{$bText}</div>\n</partial>\n");
        clearstatcache();
    }

    /**
     * 출처의 v1 을 마크업 레이아웃으로 설치합니다 — 템플릿은 설치 등록, 모듈·플러그인은 활성화 등록.
     *
     * 가짜 템플릿 자기 레이아웃을 컴파일하려면 그 템플릿의 `components.json` 이 있어야 하므로 번들 관리자 템플릿의
     * 매니페스트를 복사한다. 모듈·플러그인의 `admin/` 레이아웃은 경로 규칙상 번들 관리자 템플릿 레지스트리를 쓴다.
     *
     * @param  string  $source  출처
     * @return void 반환값 없음
     */
    private function installMarkup(string $source): void
    {
        $this->makeFixtureTemplate($this->hostLayout());

        if ($source === 'template') {
            File::copy(
                base_path('templates/_bundled/sirsoft-admin_basic/components.json'),
                base_path("templates/{$this->fixtureTemplate}/components.json")
            );
            $this->writeMarkupScreen('template', 'A1', 'B1');
        }

        app(TemplateManager::class)->refreshTemplateLayouts($this->fixtureTemplate, false);

        if ($source === 'module') {
            $this->makeFixtureModule([]);
            $this->writeMarkupScreen('module', 'A1', 'B1');
            $this->activateExtension('module', false);
        } elseif ($source === 'plugin') {
            $this->makeFixturePlugin([]);
            $this->writeMarkupScreen('plugin', 'A1', 'B1');
            $this->activateExtension('plugin', false);
        }
    }

    /**
     * 운영자가 마크업 화면의 조각 A 문구를 고친 본문을 만듭니다 — 기대 병합 결과를 만들 때도 같은 함수를 쓴다.
     *
     * @param  array<string, mixed>  $content  본문 (`components` 루트)
     * @return array<string, mixed> 고친 본문
     */
    private function editMarkupPartA(array $content): array
    {
        foreach ($content['components'] as $i => $node) {
            if (($node['id'] ?? null) === 'part_a') {
                $content['components'][$i]['text'] = '운영자 A';
            }
        }

        return $content;
    }

    /**
     * 백업이 마크업 소스이고(짝 JSON 없음), 템플릿 백업은 컴파일 문맥 강제 지정 없이는 컴파일되지 않음을 확인합니다.
     *
     * 템플릿 백업 경로 `extension_backups/templates/{식별자}_{Ymd_His}_…/layouts` 에서 기본 해석기는 대상 템플릿을
     * 디렉토리 이름(`{식별자}_{타임스탬프}`)으로 뽑아 레지스트리를 못 찾는다. 그래서 이 칸이 병합으로 끝나는 것은
     * `BackupLayoutReader` 의 `compileContextOverride` 가 적용됐다는 뜻이다. 모듈·플러그인은 `admin/` 경로 규칙으로
     * 레지스트리를 찾으므로 강제 지정 없이도 컴파일된다(소유 확장 식별자 지정은 컴파일 결과를 바꾸지 않는다).
     *
     * @param  string  $source  출처
     * @param  string  $backup  백업 경로
     * @return void 반환값 없음
     */
    private function assertBackupIsMarkupNeedingOverride(string $source, string $backup): void
    {
        $dir = $this->markupLayoutsDir($source, $backup);
        $this->assertFileExists($dir.'/'.self::SCREEN.'.g7.html', '백업에 v1 마크업 화면이 있어야 한다');
        $this->assertFileExists($dir.'/partials/merge_list/_b.g7.html', '백업에 v1 마크업 조각이 있어야 한다');
        $this->assertFileDoesNotExist($dir.'/'.self::SCREEN.'.json', '짝 JSON 이 없어야 백업 기준이 마크업 컴파일로 나온다');

        if ($source !== 'template') {
            return;
        }

        $resolver = new MarkupCompileContextResolver;
        $markup = $dir.'/'.self::SCREEN.'.g7.html';
        $this->assertNull($resolver->registryFor($markup), '강제 지정 없는 해석기는 백업 디렉토리 이름으로 레지스트리를 못 찾아야 한다');
        $this->assertNotNull($resolver->registryFor($markup, $this->fixtureTemplate), '템플릿 식별자를 강제 지정하면 레지스트리를 찾아야 한다');

        $reader = new class
        {
            use ValidatesLayoutFiles;

            /**
             * 컴파일 문맥 강제 지정 없이 레이아웃 디렉토리를 컴파일합니다.
             *
             * @param  string  $path  layouts 경로
             * @param  string  $identifier  식별자
             * @return array<int, array<string, mixed>> 검증 결과
             */
            public function read(string $path, string $identifier): array
            {
                return $this->validateLayoutFiles($path, $identifier, 'template', true);
            }
        };

        // 마크업 컴파일은 템플릿과 무관하다 — 레지스트리 없이도 백업이 컴파일된다.
        // 강제 지정이 남기는 몫은 경로에서 뽑을 수 없는 소유 식별자(`{id}_{Ymd_His}`)뿐이고, 병합 결과는 위 단언이 잰다.
        $this->assertNotEmpty($reader->read($dir, $this->fixtureTemplate), '템플릿 백업 마크업은 강제 지정 없이도 컴파일돼야 한다(템플릿 무관 컴파일)');
    }

    /**
     * 출처 파일을 명세의 판으로 바꿉니다.
     *
     * @param  string  $source  출처
     * @param  array<string, mixed>  $spec  판 명세
     * @return void 반환값 없음
     */
    private function writeVersion(string $source, array $spec): void
    {
        if ($source === 'template') {
            $this->writeTemplateLayouts($this->hostLayout() + $this->screenFiles('', $spec));
            $this->writeTemplateExtensionOverrides(self::OVERRIDE_TARGET, $this->fragmentFiles($spec));
        } elseif ($source === 'module') {
            $this->writeModuleFiles($this->screenFiles('admin/', $spec), $this->fragmentFiles($spec));
        } else {
            $this->writePluginFiles($this->screenFiles('admin/', $spec), $this->fragmentFiles($spec));
        }
    }

    /**
     * 출처 디렉토리를 업데이트 백업 모양으로 복사합니다.
     *
     * @param  string  $source  출처
     * @return string 백업 경로
     */
    private function backup(string $source): string
    {
        return match ($source) {
            'template' => $this->backupFixtureTemplate(),
            'module' => $this->backupFixtureModule(),
            'plugin' => $this->backupFixturePlugin(),
        };
    }

    /**
     * 백업의 v1 파일 문구를 바꿔 백업 컴파일 해시가 행의 원본 해시와 어긋나게 합니다(컴파일러가 달라진 경우와 같은 효과).
     *
     * @param  string  $source  출처
     * @param  string  $backup  백업 경로
     * @return void 반환값 없음
     */
    private function tamperBackup(string $source, string $backup): void
    {
        $spec = $this->v1Spec();
        $spec['a'] = 'A0';
        $spec['fa'] = 'FA0';

        if ($source === 'template') {
            $files = $this->screenFiles('', $spec);
            $layouts = $backup.'/layouts';
            $fragments = $backup.'/extensions/'.self::OVERRIDE_TARGET;
        } else {
            $files = $this->screenFiles('admin/', $spec);
            $layouts = $backup.'/resources/layouts';
            $fragments = $backup.'/resources/extensions';
        }

        foreach ($files as $relative => $json) {
            File::put($layouts.'/'.$relative, json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        foreach ($this->fragmentFiles($spec) as $relative => $json) {
            File::put($fragments.'/'.$relative, json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }

    /**
     * 업데이트 경로의 레이아웃 갱신을 실행합니다.
     *
     * @param  string  $source  출처
     * @param  bool  $keep  수정 유지 여부
     * @param  string|null  $backup  업데이트 백업 경로
     * @return array<string, mixed> 갱신 결과
     */
    private function refresh(string $source, bool $keep, ?string $backup): array
    {
        return match ($source) {
            'template' => app(TemplateManager::class)->refreshTemplateLayouts($this->fixtureTemplate, $keep, $backup),
            'module' => app(ModuleManager::class)->refreshModuleLayouts($this->fixtureModule, $keep, $backup),
            'plugin' => app(PluginManager::class)->refreshPluginLayouts($this->fixturePlugin, $keep, $backup),
        };
    }

    /**
     * 모듈·플러그인 활성화의 레이아웃 단계를 같은 순서로 실행합니다 (`activate{Module,Plugin}()` 참고).
     *
     * 설치 준비(`$withReregister = false`)는 전 활성 확장 조각 재등록을 건너뛴다 — 실제 활성 모듈 전부의 조각을 다시 컴파일해
     * 느리고, 설치 상태를 만드는 데는 필요 없다. 활성화 칸을 재는 테스트는 끝 단계까지 실행한다.
     *
     * @param  string  $source  출처 (`module`·`plugin`)
     * @param  bool  $withReregister  전 활성 확장 조각 재등록까지 실행할지
     * @return void 반환값 없음
     */
    private function activateExtension(string $source, bool $withReregister = true): void
    {
        $instance = $source === 'module'
            ? app(ModuleManager::class)->getModule($this->fixtureModule)
            : app(PluginManager::class)->getPlugin($this->fixturePlugin);
        $this->assertNotNull($instance, "{$source} 픽스처가 관리자에 로드돼야 한다");

        $this->invokeManager($source, $source === 'module' ? 'registerModuleLayouts' : 'registerPluginLayouts', $this->identifierOf($source));
        $this->invokeManager($source, 'restoreLayoutExtensions', $instance);
        $this->invokeManager($source, 'registerLayoutExtensions', $instance);

        if ($withReregister) {
            $this->invokeManager($source, 'reregisterActiveExtensionsToAllTemplates');
        }
    }

    /**
     * 모듈·플러그인 관리자의 protected 메서드를 실행합니다.
     *
     * @param  string  $source  출처 (`module`·`plugin`)
     * @param  string  $method  메서드명
     * @param  mixed  ...$args  인자
     * @return mixed 반환값
     */
    private function invokeManager(string $source, string $method, mixed ...$args): mixed
    {
        $class = $source === 'module' ? ModuleManager::class : PluginManager::class;
        $ref = new ReflectionMethod($class, $method);
        $ref->setAccessible(true);

        return $ref->invoke(app($class), ...$args);
    }

    /**
     * 확장 상태를 바꿉니다 (꺼 둔 채 업데이트 → 켜기).
     *
     * @param  string  $source  출처
     * @param  string  $status  상태
     * @return void 반환값 없음
     */
    private function setStatus(string $source, string $status): void
    {
        match ($source) {
            'template' => $this->fixtureTemplateModel->update(['status' => $status]),
            'module' => Module::where('identifier', $this->fixtureModule)->update(['status' => $status]),
            'plugin' => Plugin::where('identifier', $this->fixturePlugin)->update(['status' => $status]),
        };

        ModuleManager::invalidateModuleStatusCache();
        PluginManager::invalidatePluginStatusCache();
    }

    /**
     * 출처 식별자.
     *
     * @param  string  $source  출처
     * @return string 식별자
     */
    private function identifierOf(string $source): string
    {
        return match ($source) {
            'template' => $this->fixtureTemplate,
            'module' => $this->fixtureModule,
            'plugin' => $this->fixturePlugin,
        };
    }

    // ──────────────────────────────────────────────────────────────────────
    // 픽스처 — 행·운영자 편집
    // ──────────────────────────────────────────────────────────────────────

    /**
     * 출처의 레이아웃 행을 찾습니다 (지운 행 제외).
     *
     * @param  string  $source  출처
     * @param  string  $baseName  접두어 없는 레이아웃 이름
     * @return TemplateLayout|null 행
     */
    private function layoutRow(string $source, string $baseName): ?TemplateLayout
    {
        $name = $source === 'template' ? $baseName : $this->identifierOf($source).'.'.$baseName;

        return TemplateLayout::where('template_id', $this->fixtureTemplateModel->id)->where('name', $name)->first();
    }

    /**
     * 출처의 확장 조각 행을 찾습니다 — 템플릿은 조각 오버라이드 행.
     *
     * @param  string  $source  출처
     * @param  string  $key  조각 식별자
     * @param  bool  $withTrashed  지운 행 포함 여부
     * @return LayoutExtension 행
     */
    private function fragmentRow(string $source, string $key, bool $withTrashed = false): LayoutExtension
    {
        $query = $withTrashed ? LayoutExtension::withTrashed() : LayoutExtension::query();

        return $query->where('template_id', $this->fixtureTemplateModel->id)
            ->where('source_identifier', $this->identifierOf($source))
            ->where('fragment_key', $key)
            ->firstOrFail();
    }

    /**
     * 단위에 해당하는 행을 운영자가 편집기로 고친 상태로 만듭니다.
     *
     * @param  string  $source  출처
     * @param  string  $unit  병합 단위
     * @return TemplateLayout|LayoutExtension 고친 행
     */
    private function operatorEdit(string $source, string $unit): TemplateLayout|LayoutExtension
    {
        $row = $unit === 'extension_fragment' ? $this->fragmentRow($source, 'frag_a') : $this->layoutRow($source, self::SCREEN);
        $this->assertNotNull($row, '설치 뒤 대상 행이 있어야 한다');

        $this->operatorEdits($row, fn (array $content) => $this->applyOperatorEdit($unit, $content));

        return $row->fresh();
    }

    /**
     * 운영자 편집을 본문에 적용합니다 — 기대 병합 결과를 만들 때도 같은 함수를 쓴다.
     *
     * @param  string  $unit  병합 단위
     * @param  array<string, mixed>  $content  본문
     * @return array<string, mixed> 고친 본문
     */
    private function applyOperatorEdit(string $unit, array $content): array
    {
        switch ($unit) {
            case 'partial':
                foreach ($content['slots']['content'] as $i => $node) {
                    if (($node['id'] ?? null) === 'part_a') {
                        $content['slots']['content'][$i]['text'] = '운영자 A';
                    }
                }
                break;
            case 'top_level_key':
                $content['meta']['title'] = '운영자 제목';
                break;
            case 'extension_fragment':
                $content['injections'][0]['components'][0]['text'] = '운영자 조각';
                break;
        }

        return $content;
    }

    /**
     * 지금 디스크의 새 판(서비스 본문)을 읽습니다 — 레이아웃은 설치 경로와 같은 컴파일, 조각은 파일 JSON.
     *
     * @param  string  $source  출처
     * @param  string  $unit  병합 단위
     * @return array<string, mixed> 새 판
     */
    private function theirs(string $source, string $unit): array
    {
        if ($unit === 'extension_fragment') {
            $dir = $source === 'template'
                ? base_path("templates/{$this->fixtureTemplate}/extensions/".self::OVERRIDE_TARGET)
                : base_path(($source === 'module' ? 'modules/' : 'plugins/').$this->identifierOf($source).'/resources/extensions');

            return json_decode(File::get($dir.'/frag_a.json'), true);
        }

        $reader = new class
        {
            use ValidatesLayoutFiles;

            /**
             * 레이아웃 디렉토리를 설치 경로와 같이 검증·컴파일합니다.
             *
             * @param  string  $path  layouts 경로
             * @param  string  $identifier  식별자
             * @param  string  $type  출처 유형
             * @return array<int, array<string, mixed>> 검증 결과
             */
            public function read(string $path, string $identifier, string $type): array
            {
                return $this->validateLayoutFiles($path, $identifier, $type, true);
            }
        };

        $path = $source === 'template'
            ? base_path("templates/{$this->fixtureTemplate}/layouts")
            : base_path(($source === 'module' ? 'modules/' : 'plugins/').$this->identifierOf($source).'/resources/layouts');

        foreach ($reader->read($path, $this->identifierOf($source), $source) as $layout) {
            if ($layout['layout_name'] === self::SCREEN) {
                return $layout['data'];
            }
        }

        $this->fail('새 판 화면을 컴파일하지 못했다');
    }

    // ──────────────────────────────────────────────────────────────────────
    // 공통 단언 (A-41)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * 병합 쓰기 공통 단언 — 정규화 비교·잠금 +1·원본 본문/해시가 새 판으로 옮겨감·버전 이력 `source=extension_update`.
     *
     * @param  TemplateLayout|LayoutExtension  $row  대상 행 (병합 전)
     * @param  int  $lockBefore  병합 전 잠금 버전
     * @param  array<string, mixed>  $expected  기대 병합 본문
     * @param  array<string, mixed>  $theirs  새 판 (서비스 본문)
     * @return void 반환값 없음
     */
    private function assertMergeWrite(TemplateLayout|LayoutExtension $row, int $lockBefore, array $expected, array $theirs): void
    {
        $fresh = $row->fresh();

        $this->assertTrue(
            LayoutContentComparator::sameDocument($fresh->getRawOriginal('content'), $expected),
            "병합 결과가 기대(운영자 편집 + 새 판)와 정규화 비교로 같아야 한다:\n".$fresh->getRawOriginal('content')
        );
        $this->assertSame($lockBefore + 1, (int) $fresh->lock_version, '내용이 바뀐 쓰기는 잠금 버전을 올려야 한다');
        $this->assertSame($this->computeContentHash($theirs), $fresh->original_content_hash, '원본 해시가 새 판으로 옮겨가야 한다');

        $original = json_decode((string) $fresh->getRawOriginal('original_content'), true);
        $this->assertIsArray($original, '원본 본문이 채워져야 한다');
        $this->assertTrue(LayoutContentComparator::sameDocument(LayoutPartialOrigin::strip($original), $theirs), '원본 본문이 새 판이어야 한다');

        $latest = $row instanceof TemplateLayout
            ? TemplateLayoutVersion::where('layout_id', $row->id)->orderByDesc('version')->first()
            : TemplateLayoutExtensionVersion::where('extension_id', $row->id)->orderByDesc('version')->first();
        $this->assertSame('extension_update', $latest?->changes_summary['source'] ?? null, '버전 이력에 확장 업데이트 병합판이 남아야 한다');
        $this->assertNotEmpty($latest->changes_summary['applied'] ?? [], '버전 이력 요약에 반영 단위가 실려야 한다');
    }

    /**
     * 덮어쓰기 공통 단언 — 새 판 그대로·잠금 +1·원본 해시 = 새 판·확장 업데이트 버전 이력 없음.
     *
     * @param  TemplateLayout|LayoutExtension  $row  대상 행
     * @param  int  $lockBefore  덮어쓰기 전 잠금 버전
     * @param  array<string, mixed>  $theirs  새 판
     * @return void 반환값 없음
     */
    private function assertOverwritten(TemplateLayout|LayoutExtension $row, int $lockBefore, array $theirs): void
    {
        $fresh = $row->fresh();

        $this->assertTrue(LayoutContentComparator::sameDocument($fresh->getRawOriginal('content'), $theirs), '운영자 수정이 새 판으로 덮여야 한다');
        $this->assertSame($lockBefore + 1, (int) $fresh->lock_version, '덮어쓰기도 잠금 버전을 올려야 한다(편집기 낙관적 잠금)');
        $this->assertSame($this->computeContentHash($theirs), $fresh->original_content_hash);
        $this->assertFalse(app(LayoutRowSynchronizer::class)->isOperatorModified($fresh), '덮어쓴 뒤에는 미수정이어야 한다');
    }

    /**
     * 보존 공통 단언 — 내용·잠금 그대로, 여전히 운영자 수정본, 확장 업데이트 버전 이력 없음.
     *
     * @param  TemplateLayout|LayoutExtension  $row  운영자가 고친 행
     * @return void 반환값 없음
     */
    private function assertPreserved(TemplateLayout|LayoutExtension $row): void
    {
        $fresh = $row->fresh();

        $this->assertTrue(LayoutContentComparator::sameDocument($fresh->getRawOriginal('content'), $row->getRawOriginal('content')), '운영자 수정이 지워졌다');
        $this->assertSame((int) $row->lock_version, (int) $fresh->lock_version, '내용이 그대로면 잠금도 그대로');
        $this->assertTrue(app(LayoutRowSynchronizer::class)->isOperatorModified($fresh), '여전히 운영자 수정본이어야 한다');

        $versions = $row instanceof TemplateLayout
            ? TemplateLayoutVersion::where('layout_id', $row->id)->get()
            : TemplateLayoutExtensionVersion::where('extension_id', $row->id)->get();
        $this->assertFalse($versions->contains(fn ($v) => ($v->changes_summary['source'] ?? null) === 'extension_update'), '변화가 없으면 병합 버전 이력을 남기지 않는다');
    }
}
