<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Upgrade;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use Modules\Sirsoft\Board\Upgrades\Upgrade_1_2_0;
use Psr\Log\LoggerInterface;

/**
 * 1.2.0 업그레이드 — 저장된 레이아웃의 예전 임시 키 생성식을 `$uuid()` 로 바꾸는지 검증 (KVE-2026-2349)
 *
 * 1.2.0 부터 temp_key 는 UUID 만 받으므로, 편집기로 고쳐 둔 화면이나 「편집본 유지」 업데이트 화면에 남은
 * `'temp_' + Date.now() …` 생성식은 첨부 업로드를 422 로 막는다. 스텝이 그 값만 바꾸고, 편집 여부
 * (original_content_hash 관계)를 보존하는지 실제 행으로 확인한다.
 */
class TempKeyGeneratorLayoutUpgradeTest extends BoardTestCase
{
    /** 괄호 형태의 예전 생성식 */
    private const LEGACY = "{{('temp_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9))}}";

    /** 괄호 없는 예전 생성식 */
    private const LEGACY_BARE = "{{'temp_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9)}}";

    /**
     * 테스트용 템플릿 ID
     */
    private int $templateId;

    /**
     * 테스트 게시판 슬러그를 반환합니다.
     *
     * @return string 게시판 슬러그
     */
    protected function getTestBoardSlug(): string
    {
        return 'tempkey-layout-upgrade';
    }

    /**
     * 격리 템플릿을 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->templateId = DB::table('templates')->insertGetId([
            'identifier' => 'test-tempkey_template_'.uniqid(),
            'vendor' => 'test',
            'name' => json_encode(['ko' => '임시 키 업그레이드 테스트', 'en' => 'Temp key upgrade test']),
            'version' => '1.0.0',
            'type' => 'user',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * 사용자가 편집한 모듈 레이아웃: 생성식만 바뀌고 편집 표시(해시)는 그대로다.
     *
     * @return void 반환값 없음
     *
     * @scenario row_state=edited,owner=module_layout
     *
     * @effects legacy_generator_replaced_with_uuid, edited_row_keeps_original_hash, changed_row_lock_version_bumped
     */
    public function test_edited_module_layout_is_patched_and_stays_edited(): void
    {
        $id = $this->insertLayout('sirsoft-board.admin_board_post_form', $this->boardLayout(self::LEGACY), 'module', edited: true);
        $before = DB::table('template_layouts')->find($id);

        $this->runUpgrade();

        $after = DB::table('template_layouts')->find($id);
        $this->assertStringContainsString('{{$uuid()}}', $after->content);
        $this->assertStringNotContainsString("'temp_' + Date.now()", $after->content);
        $this->assertSame($before->original_content_hash, $after->original_content_hash);
        $this->assertNotSame($after->original_content_hash, $this->hash($after->content), '편집본이 원본처럼 위장됐다');
        $this->assertSame((int) $before->lock_version + 1, (int) $after->lock_version);
    }

    /**
     * 사용자가 편집한 템플릿 레이아웃도 생성식만 바뀌고 편집 표시는 그대로다.
     *
     * @return void 반환값 없음
     *
     * @scenario row_state=edited,owner=template_layout
     *
     * @effects legacy_generator_replaced_with_uuid, edited_row_keeps_original_hash
     */
    public function test_edited_template_layout_is_patched_and_stays_edited(): void
    {
        $id = $this->insertLayout('board/form', $this->boardLayout(self::LEGACY), 'template', edited: true);
        $hashBefore = DB::table('template_layouts')->where('id', $id)->value('original_content_hash');

        $this->runUpgrade();

        $after = DB::table('template_layouts')->find($id);
        $this->assertStringContainsString('{{$uuid()}}', $after->content);
        $this->assertSame($hashBefore, $after->original_content_hash);
    }

    /**
     * 편집하지 않은 템플릿 레이아웃: 바꾼 뒤 해시를 새 내용에 맞춰 여전히 "원본" 으로 보인다.
     *
     * 템플릿이 함께 업데이트되지 않는 사이트에서도 글쓰기가 막히지 않아야 하고, 보정이 "편집" 으로 보여
     * 다음 「편집본 유지」 업데이트에서 새 번들 파일 반영이 빠지면 안 된다.
     *
     * @return void 반환값 없음
     *
     * @scenario row_state=unedited,owner=template_layout
     *
     * @effects legacy_generator_replaced_with_uuid, unedited_row_hash_resynced
     */
    public function test_unedited_template_layout_is_patched_and_stays_unedited(): void
    {
        $id = $this->insertLayout('board/form', $this->boardLayout(self::LEGACY_BARE), 'template', edited: false);

        $this->runUpgrade();

        $after = DB::table('template_layouts')->find($id);
        $this->assertStringContainsString('{{$uuid()}}', $after->content);
        $this->assertSame($this->hash($after->content), $after->original_content_hash, '보정이 편집으로 보인다');
        $this->assertSame(strlen($this->normalize($after->content)), (int) $after->original_content_size);
    }

    /**
     * 편집하지 않은 모듈 레이아웃도 해시를 맞춘다.
     *
     * @return void 반환값 없음
     *
     * @scenario row_state=unedited,owner=module_layout
     *
     * @effects legacy_generator_replaced_with_uuid, unedited_row_hash_resynced
     */
    public function test_unedited_module_layout_is_patched_and_stays_unedited(): void
    {
        $id = $this->insertLayout('sirsoft-board.admin_board_post_form', $this->boardLayout(self::LEGACY), 'module', edited: false);

        $this->runUpgrade();

        $after = DB::table('template_layouts')->find($id);
        $this->assertStringContainsString('{{$uuid()}}', $after->content);
        $this->assertSame($this->hash($after->content), $after->original_content_hash);
    }

    /**
     * 게시판 API 를 부르지 않는 레이아웃과 레이아웃 확장은 범위가 나뉜다 — 남의 모듈 화면은 건드리지 않고,
     * 이 모듈 API 를 부르는 레이아웃 확장은 고친다.
     *
     * @return void 반환값 없음
     *
     * @effects other_module_rows_untouched, extension_rows_patched
     */
    public function test_scope_is_limited_to_board_api_rows_including_extensions(): void
    {
        $foreign = $this->layout(self::LEGACY, '/api/modules/sirsoft-ecommerce/admin/categories/images');
        $foreignId = $this->insertLayout('shop/other', $foreign, 'template', edited: true);

        $extId = DB::table('template_layout_extensions')->insertGetId([
            'template_id' => $this->templateId,
            'extension_type' => 'extension_point',
            'target_name' => 'board_form_extra',
            'source_type' => 'module',
            'source_identifier' => 'sirsoft-board',
            'content' => $this->boardLayout(self::LEGACY),
            'original_content_hash' => 'edited-by-operator',
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runUpgrade();

        $this->assertSame($foreign, DB::table('template_layouts')->where('id', $foreignId)->value('content'));
        $this->assertStringContainsString('{{$uuid()}}', (string) DB::table('template_layout_extensions')->where('id', $extId)->value('content'));
    }

    /**
     * 두 번 돌려도 결과가 같고 두 번째는 아무 행도 바꾸지 않는다.
     *
     * @return void 반환값 없음
     *
     * @effects upgrade_is_idempotent
     */
    public function test_second_run_changes_nothing(): void
    {
        $id = $this->insertLayout('board/form', $this->boardLayout(self::LEGACY), 'template', edited: true);

        $this->runUpgrade();
        $first = DB::table('template_layouts')->find($id);
        $this->runUpgrade();
        $second = DB::table('template_layouts')->find($id);

        $this->assertSame($first->content, $second->content);
        $this->assertSame((int) $first->lock_version, (int) $second->lock_version);
    }

    /**
     * 운영자가 바꾼 변형 생성식은 뜻을 알 수 없어 그대로 두고 경고 로그로 위치를 알린다.
     *
     * @return void 반환값 없음
     *
     * @effects variant_generator_left_and_logged
     */
    public function test_variant_generator_is_left_and_logged(): void
    {
        $variant = "{{'temp_' + Date.now() + '_custom'}}";
        $content = $this->boardLayout($variant);
        $id = $this->insertLayout('board/form', $content, 'template', edited: true);

        $spy = Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')->andReturn($spy);
        $this->runUpgrade();

        $this->assertSame($content, DB::table('template_layouts')->where('id', $id)->value('content'));
        $spy->shouldHaveReceived('warning')->with(Mockery::on(fn ($m) => str_contains($m, "template_layouts#{$id}") && str_contains($m, '$uuid()')));
    }


    /**
     * 대용량 설치본에서도 행 전체를 한 번에 읽지 않는다 — 청크(100행) 순회로 전량을 보정한다.
     *
     * 청크 크기를 넘는 250행을 넣고, 대상 테이블 조회가 전부 `limit 100` 청크이며 모든 행이 바뀌는지 본다.
     * 행 수와 무관하게 메모리가 청크 크기로 묶인다는 성질이라 n=100000 에도 그대로 성립한다.
     *
     * @return void 반환값 없음
     *
     * @scale n=100000 asserts=chunked_scan_bounded_memory,all_rows_patched_across_chunks
     *
     * @effects chunked_scan_covers_all_rows
     */
    public function test_large_install_is_scanned_in_chunks(): void
    {
        $ids = [];
        for ($i = 0; $i < 250; $i++) {
            $ids[] = $this->insertLayout('board/form'.'_'.$i, $this->boardLayout(self::LEGACY), 'template', edited: $i % 2 === 0);
        }

        DB::enableQueryLog();
        $this->runUpgrade();
        $selects = array_filter(DB::getQueryLog(), fn ($q) => preg_match('/^select .* from [`"]?\w*template_layouts[`"]? /i', $q['query']));
        DB::disableQueryLog();

        $this->assertNotEmpty($selects);
        foreach ($selects as $q) {
            $this->assertMatchesRegularExpression('/limit 100\b/i', $q['query'], '청크 없이 전 행을 읽었다');
        }
        $this->assertGreaterThanOrEqual(3, count($selects), '250행인데 청크가 3번 미만 — 청크 순회가 아니다');
        $this->assertSame(0, DB::table('template_layouts')->whereIn('id', $ids)->where('content', 'like', "%'temp_' + Date.now()%")->count());
    }
    /**
     * 본문의 빈 map(`{}`)은 보정 뒤에도 `{}` 로 남고, 편집하지 않은 행의 해시·크기는 코어 표준형과 맞는다.
     *
     * 예전에는 본문을 연관 배열로 읽어 다시 썼으므로 운영자가 고친 행의 `{}`(요청 질의 `params.query`,
     * 폼 초기값 등)가 `[]` 로 굳었다(BU-3).
     *
     * @return void 반환값 없음
     *
     * @scenario row_state=edited,owner=module_layout
     *
     * @effects empty_map_preserved_after_patch, unedited_row_hash_resynced
     */
    public function test_empty_maps_are_preserved_and_hash_follows_core_standard_form(): void
    {
        $content = json_encode([
            'version' => '1.0.0',
            'initLocal' => ['form' => new \stdClass],
            'init_actions' => [
                ['handler' => 'setState', 'params' => ['target' => 'local', 'tempKey' => self::LEGACY]],
                ['handler' => 'apiCall', 'params' => ['endpoint' => '/api/modules/sirsoft-board/boards/{{route.slug}}/attachments', 'query' => new \stdClass]],
            ],
            'components' => [['name' => 'FileUploader', 'props' => ['uploadUrl' => '/api/modules/sirsoft-board/boards/{{route.slug}}/attachments']]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $edited = $this->insertLayout('sirsoft-board.admin_board_post_form_edited', $content, 'module', edited: true);
        $unedited = $this->insertLayout('sirsoft-board.admin_board_post_form_unedited', $content, 'module', edited: false);

        $this->runUpgrade();

        foreach ([$edited, $unedited] as $id) {
            $after = DB::table('template_layouts')->find($id);
            $decoded = json_decode($after->content);

            $this->assertStringContainsString('{{$uuid()}}', $after->content);
            $this->assertInstanceOf(\stdClass::class, $decoded->initLocal->form, '폼 초기값 {} 가 [] 로 바뀌었다');
            $this->assertInstanceOf(\stdClass::class, $decoded->init_actions[1]->params->query, '요청 질의 {} 가 [] 로 바뀌었다');
        }

        $after = DB::table('template_layouts')->find($unedited);
        $this->assertSame($this->hash($after->content), $after->original_content_hash, '보정이 편집으로 보인다');
        $this->assertSame(strlen($this->normalize($after->content)), (int) $after->original_content_size);
    }

    /**
     * 1.2.0 업그레이드 스텝을 실행합니다.
     *
     * @return void 반환값 없음
     */
    private function runUpgrade(): void
    {
        (new Upgrade_1_2_0)->run(new UpgradeContext('1.1.1', '1.2.0', '1.2.0', 'extension-upgrade'));
    }

    /**
     * 게시판 API 를 부르는 글쓰기 화면 레이아웃 JSON 을 만듭니다.
     *
     * @param  string  $generator  tempKey 생성식
     * @return string 레이아웃 JSON
     */
    private function boardLayout(string $generator): string
    {
        return $this->layout($generator, '/api/modules/sirsoft-board/boards/{{route.slug}}/attachments');
    }

    /**
     * tempKey 초기화와 업로드 주소를 가진 최소 레이아웃 JSON 을 만듭니다.
     *
     * @param  string  $generator  tempKey 생성식
     * @param  string  $uploadUrl  업로드 API 주소
     * @return string 레이아웃 JSON
     */
    private function layout(string $generator, string $uploadUrl): string
    {
        return json_encode([
            'version' => '1.0.0',
            'init_actions' => [['handler' => 'setState', 'params' => ['target' => 'local', 'tempKey' => $generator]]],
            'components' => [['name' => 'FileUploader', 'props' => ['uploadUrl' => $uploadUrl]]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 레이아웃 행을 넣습니다.
     *
     * @param  string  $name  레이아웃 이름
     * @param  string  $content  레이아웃 JSON
     * @param  string  $sourceType  module 또는 template
     * @param  bool  $edited  사용자가 편집한 행인지 (해시를 현재 내용과 다르게 둔다)
     * @return int 행 ID
     */
    private function insertLayout(string $name, string $content, string $sourceType, bool $edited): int
    {
        return DB::table('template_layouts')->insertGetId([
            'template_id' => $this->templateId,
            'name' => $name,
            'content' => $content,
            'source_type' => $sourceType,
            'source_identifier' => $sourceType === 'module' ? 'sirsoft-board' : null,
            'original_content_hash' => $edited ? hash('sha256', 'bundled-original') : $this->hash($content),
            'original_content_size' => $edited ? 1 : strlen($this->normalize($content)),
            'lock_version' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * 코어 레이아웃 해시(정규화 JSON 의 SHA-256)를 계산합니다.
     *
     * @param  string  $content  레이아웃 JSON
     * @return string 해시
     */
    private function hash(string $content): string
    {
        return hash('sha256', $this->normalize($content));
    }

    /**
     * 코어 해시가 쓰는 정규화 JSON 을 만듭니다.
     *
     * @param  string  $content  레이아웃 JSON
     * @return string 정규화 JSON
     */
    private function normalize(string $content): string
    {
        return (string) json_encode(json_decode($content, true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
