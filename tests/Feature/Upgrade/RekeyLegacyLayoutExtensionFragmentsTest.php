<?php

namespace Tests\Feature\Upgrade;

use App\Extension\UpgradeContext;
use App\Models\Template;
use App\Upgrades\Data\V7_1_0\Migrations\RekeyLegacyLayoutExtensionFragments;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Mockery;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * 7.1.0 데이터 마이그레이션 05 — 대상 이름으로 백필된 옛 조각 행을 파일 키로 옮긴다 (#712 W5 · F5).
 *
 * 등록 경로는 대상당 조각 1개인 모듈·플러그인 옛 행만 이어 붙인다(#703 런타임 승계). 템플릿 오버라이드 옛 행은 소스에
 * 없는 키로 보여 정리되고(운영자 수정 소실), 같은 대상에 조각이 여럿인 확장의 옛 행은 어느 조각인지 몰라 남거나
 * 지워진다. 이 스텝이 업그레이드 시점에 같은 규칙으로 정리한다.
 */
class RekeyLegacyLayoutExtensionFragmentsTest extends TestCase
{
    use DatabaseTransactions;

    private const MODULE = 'probe-rekey_module';

    private const TEMPLATE = 'probe-rekey_tpl';

    private string $moduleDir;

    private string $overrideDir;

    private int $templateId;

    private RekeyLegacyLayoutExtensionFragments $migration;

    /**
     * 임시 모듈 조각 디렉토리·템플릿 오버라이드 디렉토리와 템플릿 행을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        require_once base_path('upgrades/data/7.1.0/migrations/05_RekeyLegacyLayoutExtensionFragments.php');

        $this->moduleDir = base_path('modules/'.self::MODULE.'/resources/extensions');
        $this->overrideDir = base_path('templates/'.self::TEMPLATE.'/extensions/sirsoft-probetarget');
        File::ensureDirectoryExists($this->moduleDir);
        File::ensureDirectoryExists($this->overrideDir);

        $this->templateId = Template::factory()->create(['identifier' => self::TEMPLATE, 'type' => 'user'])->id;
        $this->migration = new RekeyLegacyLayoutExtensionFragments;
    }

    /**
     * 임시 디렉토리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory(base_path('modules/'.self::MODULE));
        File::deleteDirectory(base_path('templates/'.self::TEMPLATE));

        parent::tearDown();
    }

    /**
     * 후보 1개면 키를 옮기고, 운영자 수정본은 그대로 남는다. 두 번 돌려도 바뀌는 것이 없다.
     *
     * @scenario case=rekey_single
     *
     * @effects legacy_row_rekeyed_to_file_key_and_edit_kept
     *
     * @return void 반환값 없음
     */
    public function test_single_candidate_is_rekeyed_and_rerun_is_noop(): void
    {
        $this->writeFragment($this->moduleDir, 'user-probe', ['extension_point' => 'probe_slot', 'components' => [['type' => 'basic', 'name' => 'Div']]]);
        $legacy = $this->legacyRow('module', self::MODULE, 'extension_point', 'probe_slot', ['extension_point' => 'probe_slot', 'components' => [], 'comment' => 'EDIT'], modified: true);

        $this->run710();

        $this->assertSame('user-probe', $this->row($legacy)->fragment_key);
        $this->assertStringContainsString('EDIT', $this->row($legacy)->content);

        $snapshot = DB::table('template_layout_extensions')->where('template_id', $this->templateId)->orderBy('id')->get()->toJson();
        $this->run710();
        $this->assertSame($snapshot, DB::table('template_layout_extensions')->where('template_id', $this->templateId)->orderBy('id')->get()->toJson(), '두 번째 실행은 아무것도 바꾸지 않는다');
    }

    /**
     * 새 키 행이 이미 있으면 병합 — 옛 행이 수정본이면 옛 행이 키를 맡고 새 키 행은 지운다, 아니면 옛 행을 지운다.
     *
     * @scenario case=rekey_merge
     *
     * @effects legacy_and_new_key_rows_merge_by_operator_edit
     *
     * @return void 반환값 없음
     */
    public function test_merge_with_existing_new_key_row(): void
    {
        $this->writeFragment($this->moduleDir, 'user-a', ['extension_point' => 'slot_a', 'components' => []]);
        $this->writeFragment($this->moduleDir, 'user-b', ['extension_point' => 'slot_b', 'components' => []]);

        $editedLegacy = $this->legacyRow('module', self::MODULE, 'extension_point', 'slot_a', ['extension_point' => 'slot_a', 'comment' => 'EDIT'], modified: true);
        $fileRowA = $this->legacyRow('module', self::MODULE, 'extension_point', 'slot_a', ['extension_point' => 'slot_a'], modified: false, key: 'user-a');
        $plainLegacy = $this->legacyRow('module', self::MODULE, 'extension_point', 'slot_b', ['extension_point' => 'slot_b'], modified: false);
        $fileRowB = $this->legacyRow('module', self::MODULE, 'extension_point', 'slot_b', ['extension_point' => 'slot_b'], modified: false, key: 'user-b');

        $this->run710();

        $this->assertSame('user-a', $this->row($editedLegacy)->fragment_key, '수정본인 옛 행이 새 키를 맡는다');
        $this->assertNull($this->row($fileRowA), '파일본 새 키 행은 지운다 (고유 키 충돌 회피)');
        $this->assertNotNull($this->row($plainLegacy)->deleted_at, '무수정 옛 행은 지운다');
        $this->assertNull($this->row($fileRowB)->deleted_at);
        $this->assertSame(1, $this->live('slot_a'));
        $this->assertSame(1, $this->live('slot_b'), '같은 대상의 살아 있는 행은 하나 — 두 번 주입되지 않는다');
    }

    /**
     * 옛 행과 새 키 행이 둘 다 운영자 수정본이면 어느 쪽도 지우지 않고 보류한다.
     *
     * 첫 업그레이드에는 새 키 행이 없지만, 업데이트 뒤 스텝을 `--force` 로 다시 돌리면 새 키 행이 이미 운영자 손을 탔을
     * 수 있다. 그때 병합이 새 키 행을 영구 삭제하면 그 수정본이 사라진다(검증 검토 A3).
     *
     * @scenario case=rekey_merge
     *
     * @effects legacy_and_new_key_rows_merge_by_operator_edit
     *
     * @return void 반환값 없음
     */
    public function test_merge_holds_when_both_rows_are_operator_edits(): void
    {
        $this->writeFragment($this->moduleDir, 'user-a', ['extension_point' => 'slot_a', 'components' => []]);

        $editedLegacy = $this->legacyRow('module', self::MODULE, 'extension_point', 'slot_a', ['extension_point' => 'slot_a', 'comment' => 'OLD EDIT'], modified: true);
        $editedNew = $this->legacyRow('module', self::MODULE, 'extension_point', 'slot_a', ['extension_point' => 'slot_a', 'comment' => 'NEW EDIT'], modified: true, key: 'user-a');

        $logger = Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')->with('upgrade')->andReturn($logger);

        $this->run710();

        $this->assertNotNull($this->row($editedNew), '새 키 행의 운영자 수정본이 지워졌습니다.');
        $this->assertNull($this->row($editedNew)->deleted_at);
        $this->assertSame('slot_a', $this->row($editedLegacy)->fragment_key, '옛 행은 키를 옮기지 않는다');
        $this->assertNull($this->row($editedLegacy)->deleted_at);

        $logger->shouldHaveReceived('warning')->once();
        $logger->shouldHaveReceived('info')->withArgs(fn ($message) => is_string($message)
            && str_contains($message, '병합 0') && str_contains($message, '보류 1'));
    }

    /**
     * 후보가 여럿이면 원본 해시가 맞는 파일 키로 옮기고, 맞는 게 없으면 수정본은 보류·무수정본은 지운다.
     *
     * @scenario case=rekey_multiple
     *
     * @effects multi_candidate_legacy_row_resolved_by_hash_or_held
     *
     * @return void 반환값 없음
     */
    public function test_multiple_candidates_by_original_hash(): void
    {
        $first = ['target_layout' => 'probe_form', 'injections' => [['target_id' => 'a', 'position' => 'append', 'components' => []]]];
        $second = ['target_layout' => 'probe_form', 'injections' => [['target_id' => 'b', 'position' => 'append', 'components' => []]]];
        $this->writeFragment($this->moduleDir, 'form-first', $first);
        $this->writeFragment($this->moduleDir, 'form-second', $second);

        // ⓐ 원본 해시가 두 번째 파일과 같다 → 그 키로 (운영자 수정 내용은 유지)
        $matched = $this->legacyRow('module', self::MODULE, 'overlay', 'probe_form', $second + ['comment' => 'EDIT'], modified: true, originalOf: $second);

        $this->run710();
        $this->assertSame('form-second', $this->row($matched)->fragment_key);

        // ⓑ 어느 파일과도 해시가 안 맞는 수정본 → 보류(그대로), 무수정본 → 삭제
        DB::table('template_layout_extensions')->where('id', $matched)->delete();
        $held = $this->legacyRow('module', self::MODULE, 'overlay', 'probe_form', ['target_layout' => 'probe_form', 'comment' => 'EDIT'], modified: true);
        $this->run710();
        $this->assertSame('probe_form', $this->row($held)->fragment_key, '어느 조각인지 모르는 수정본은 손대지 않는다');
        $this->assertNull($this->row($held)->deleted_at);

        DB::table('template_layout_extensions')->where('id', $held)->delete();
        $plain = $this->legacyRow('module', self::MODULE, 'overlay', 'probe_form', ['target_layout' => 'probe_form'], modified: false);
        $this->run710();
        $this->assertNotNull($this->row($plain)->deleted_at, '무수정본은 지운다 — 다음 등록이 파일별 행을 만든다');
    }

    /**
     * 템플릿 오버라이드 옛 행도 오버라이드 디렉토리의 파일 키로 옮긴다 (런타임 승계가 없는 갈래).
     *
     * @scenario case=rekey_template_override
     *
     * @effects template_override_legacy_row_rekeyed
     *
     * @return void 반환값 없음
     */
    public function test_template_override_legacy_row_is_rekeyed(): void
    {
        File::put($this->overrideDir.'/user-address-search.g7.html',
            "<!-- target-layout=\"decoy\" 주석 속 속성은 무시한다 -->\n<extension extension-point=\"address_search_slot\" priority=\"10\">\n  <div/>\n</extension>\n");
        $legacy = $this->legacyRow('template', self::TEMPLATE, 'extension_point', 'address_search_slot', ['extension_point' => 'address_search_slot', 'comment' => 'EDIT'], modified: true, overrideTarget: 'sirsoft-probetarget');

        $this->run710();

        $this->assertSame('user-address-search', $this->row($legacy)->fragment_key);
    }

    /**
     * 출처 디렉토리가 없거나(삭제된 확장) 파일 이름이 대상 이름과 같으면 손대지 않고, 요약에 「건너뜀」으로 센다.
     *
     * 요약이 바꾼 행만 세면 「대상 행 N개 중 몇 개를 그냥 두었는지」가 로그에 남지 않는다(검증 검토 A2).
     *
     * @scenario case=rekey_untouched
     *
     * @effects legacy_row_untouched_without_candidate_or_without_drift
     *
     * @return void 반환값 없음
     */
    public function test_rows_without_candidates_or_drift_are_untouched(): void
    {
        $orphan = $this->legacyRow('module', 'probe-removed_module', 'extension_point', 'gone_slot', ['extension_point' => 'gone_slot'], modified: false);
        $this->writeFragment($this->moduleDir, 'same_slot', ['extension_point' => 'same_slot', 'components' => []]);
        $sameName = $this->legacyRow('module', self::MODULE, 'extension_point', 'same_slot', ['extension_point' => 'same_slot'], modified: false);

        $logger = Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')->with('upgrade')->andReturn($logger);

        $this->run710();

        $this->assertSame('gone_slot', $this->row($orphan)->fragment_key);
        $this->assertNull($this->row($orphan)->deleted_at);
        $this->assertSame('same_slot', $this->row($sameName)->fragment_key);
        $this->assertNull($this->row($sameName)->deleted_at);

        $logger->shouldHaveReceived('info')->withArgs(fn ($message) => is_string($message)
            && str_contains($message, '재키잉 0') && str_contains($message, '건너뜀 2'));
    }

    /**
     * 이미 지워진 옛 행 옆에 살아 있는 새 키 행이 있으면 정리가 끝난 것이다 — 손대지 않고, 요약에서 「보류」로 세지 않는다.
     *
     * 로컬 실측(2026-10-01): 7.0 → 7.1 을 거친 사이트에서 이 조합이 41행이었고, 요약이 전부 「보류 41」로 찍혀
     * 운영자가 확인할 행이 41개 있는 것처럼 보였다(보류 경고 로그는 0줄).
     *
     * @scenario case=rekey_untouched
     *
     * @effects deleted_legacy_row_beside_live_new_key_row_is_skipped_not_held
     *
     * @return void 반환값 없음
     */
    public function test_deleted_legacy_row_beside_live_new_key_row_is_skipped_not_held(): void
    {
        $this->writeFragment($this->moduleDir, 'probe_card', ['extension_point' => 'card_slot', 'components' => []]);
        $legacy = $this->legacyRow('module', self::MODULE, 'extension_point', 'card_slot', ['extension_point' => 'card_slot', 'comment' => 'EDIT'], modified: true);
        DB::table('template_layout_extensions')->where('id', $legacy)->update(['deleted_at' => now()->subDay()]);
        $live = $this->legacyRow('module', self::MODULE, 'extension_point', 'card_slot', ['extension_point' => 'card_slot'], modified: false, key: 'probe_card');

        $logger = Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')->with('upgrade')->andReturn($logger);

        $this->run710();

        $this->assertSame('card_slot', $this->row($legacy)->fragment_key, '지워진 옛 행은 키를 옮기지 않는다');
        $this->assertNotNull($this->row($legacy)->deleted_at);
        $this->assertNull($this->row($live)->deleted_at, '살아 있는 새 키 행은 그대로');

        $logger->shouldHaveReceived('info')->withArgs(fn ($message) => is_string($message)
            && str_contains($message, '보류 0') && str_contains($message, '정리됨 1'));
        $logger->shouldNotHaveReceived('warning');
    }

    /**
     * 파일 키가 대상 이름과 대소문자만 다르면 옛 행은 자기 자신과 병합되지 않고 그대로 파일 키로 옮겨진다.
     *
     * DB 정렬 규칙이 대소문자를 가리지 않아(`utf8mb4_unicode_ci`) 새 키 행 조회가 옛 행 자신을 찾으면, 운영자 수정본이
     * 「새 키 행을 지우고 옛 행을 옮긴다」 분기에서 자기 자신을 영구 삭제했다(검증 검토 A1).
     *
     * @scenario case=rekey_single
     *
     * @effects legacy_row_rekeyed_to_file_key_and_edit_kept
     *
     * @return void 반환값 없음
     */
    public function test_case_only_key_difference_does_not_merge_row_with_itself(): void
    {
        $this->writeFragment($this->moduleDir, 'Probe_case_slot', ['extension_point' => 'probe_case_slot', 'components' => []]);
        $legacy = $this->legacyRow('module', self::MODULE, 'extension_point', 'probe_case_slot', ['extension_point' => 'probe_case_slot', 'comment' => 'EDIT'], modified: true);

        $this->run710();

        $row = $this->row($legacy);
        $this->assertNotNull($row, '운영자 수정본이 지워졌습니다.');
        $this->assertNull($row->deleted_at);
        $this->assertSame('Probe_case_slot', $row->fragment_key);
        $this->assertStringContainsString('EDIT', (string) $row->content);
    }

    /**
     * 빈 객체(`{}`)를 가진 본문은 그대로 남고, 수정 판정은 런타임 해시 정규화와 같다 (7.1.0 출시 점검 BU-3/U2-3 결함군 대조).
     *
     * 이 스텝은 본문을 다시 쓰지 않는다(키·삭제 표시만 바꾼다) — 그래서 `{}` 가 `[]` 로 바뀌는 소실이 없다. 수정 판정 해시는
     * 런타임(`ComputesLayoutContentHash::normalizeContent`)처럼 배열 디코드 후 재인코드라, `{}` 를 가진 무수정 행이 「수정됨」으로
     * 오판되면 안 된다.
     *
     * @scenario case=rekey_merge
     *
     * @effects empty_object_content_survives_rekey_and_modification_check_matches_runtime
     *
     * @return void 반환값 없음
     */
    public function test_empty_object_content_survives_and_is_judged_like_runtime(): void
    {
        $this->writeFragment($this->moduleDir, 'user-keep', ['extension_point' => 'slot_keep', 'components' => []]);
        $this->writeFragment($this->moduleDir, 'user-dup', ['extension_point' => 'slot_dup', 'components' => []]);

        $raw = '{"extension_point":"slot_keep","components":[{"type":"basic","name":"Form","props":{"body":{}}}]}';
        $kept = $this->legacyRow('module', self::MODULE, 'extension_point', 'slot_keep', json_decode($raw, true), modified: false);
        DB::table('template_layout_extensions')->where('id', $kept)->update(['content' => $raw]);

        $rawDup = '{"extension_point":"slot_dup","params":{}}';
        $plainLegacy = $this->legacyRow('module', self::MODULE, 'extension_point', 'slot_dup', json_decode($rawDup, true), modified: false);
        DB::table('template_layout_extensions')->where('id', $plainLegacy)->update(['content' => $rawDup]);
        $fileRow = $this->legacyRow('module', self::MODULE, 'extension_point', 'slot_dup', ['extension_point' => 'slot_dup'], modified: false, key: 'user-dup');

        $this->run710();

        $this->assertSame('user-keep', $this->row($kept)->fragment_key);
        $this->assertSame($raw, $this->row($kept)->content, '재키잉이 본문을 다시 써 {} 를 잃으면 안 된다');
        $this->assertNotNull($this->row($plainLegacy)->deleted_at, '{} 를 가진 무수정 옛 행을 수정본으로 오판했다');
        $this->assertNull($this->row($fileRow)->deleted_at);
    }

    /**
     * 마이그레이션을 실행합니다.
     *
     * @return void 반환값 없음
     */
    private function run710(): void
    {
        $this->migration->run(new UpgradeContext('7.0.11', '7.1.0', '7.1.0'));
    }

    /**
     * 조각 `.json` 파일을 씁니다.
     *
     * @param  string  $dir  디렉토리
     * @param  string  $key  파일 기본 이름
     * @param  array<string, mixed>  $data  내용
     * @return void 반환값 없음
     */
    private function writeFragment(string $dir, string $key, array $data): void
    {
        File::put("{$dir}/{$key}.json", json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * 7.0.x 에서 올라온(키 = 대상 이름) 조각 행을 만듭니다.
     *
     * @param  string  $sourceType  출처 타입
     * @param  string  $identifier  출처 식별자
     * @param  string  $type  확장 종류
     * @param  string  $target  대상 이름
     * @param  array<string, mixed>  $content  본문
     * @param  bool  $modified  운영자 수정본인지 (원본 해시가 본문과 다르게)
     * @param  string|null  $key  조각 키 (기본: 대상 이름 = 옛 행)
     * @param  array<string, mixed>|null  $originalOf  원본 해시를 이 정의로 계산
     * @param  string|null  $overrideTarget  템플릿 오버라이드 대상
     * @return int 행 id
     */
    private function legacyRow(string $sourceType, string $identifier, string $type, string $target, array $content, bool $modified, ?string $key = null, ?array $originalOf = null, ?string $overrideTarget = null): int
    {
        $hash = fn (array $value): string => hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $original = $originalOf ?? ($modified ? ['__original' => $target] : $content);

        return DB::table('template_layout_extensions')->insertGetId([
            'template_id' => $this->templateId,
            'extension_type' => $type,
            'target_name' => $target,
            'fragment_key' => $key ?? $target,
            'source_type' => $sourceType,
            'source_identifier' => $identifier,
            'override_target' => $overrideTarget,
            'content' => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'original_content_hash' => $hash($original),
            'original_content_size' => 1,
            'priority' => 100,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * 행을 읽습니다 (soft delete 포함).
     *
     * @param  int  $id  행 id
     * @return object|null 행
     */
    private function row(int $id): ?object
    {
        return DB::table('template_layout_extensions')->where('id', $id)->first();
    }

    /**
     * 대상의 살아 있는 행 수를 셉니다.
     *
     * @param  string  $target  대상 이름
     * @return int 행 수
     */
    private function live(string $target): int
    {
        return DB::table('template_layout_extensions')->where('template_id', $this->templateId)->where('target_name', $target)->whereNull('deleted_at')->count();
    }
}
