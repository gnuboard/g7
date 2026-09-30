<?php

namespace Tests\Feature\Upgrade;

use App\Enums\ExtensionStatus;
use App\Extension\UpgradeContext;
use App\Models\Template;
use App\Upgrades\Data\V7_1_0\Migrations\RehashLayoutOriginalContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 7.1.0 업그레이드 — 빈 객체 때문에 거짓 "수정됨"이 된 레이아웃 원본 해시를 새 정규화로 다시 적는다 (#138 실측 ⑥)
 *
 * 7.0.x 설치본은 빈 객체(`{}`)를 객체로 둔 채 원본 해시를 저장했다. 7.1.0 은 DB 에서 읽은 모양(빈 배열)과 같게
 * 정규화하므로, 저장된 해시를 그대로 두면 빈 객체가 있는 레이아웃이 계속 "수정됨"으로 남는다. 이 마이그레이션은
 * DB 본문이 설치 때 그대로인 행만(옛 방식 해시가 일치) 새 방식으로 다시 적고, 운영자가 실제로 고친 행은 두어
 * 계속 보존되게 한다.
 */
class RehashLayoutOriginalContentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 데이터 마이그레이션 클래스를 불러옵니다(업그레이드 스텝이 동적으로 require 하는 파일).
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        require_once base_path('upgrades/data/7.1.0/migrations/02_RehashLayoutOriginalContent.php');
    }

    /**
     * 7.0.x 방식(빈 객체 유지)으로 원본 해시를 계산합니다.
     *
     * @param  mixed  $content  본문
     * @return string 해시
     */
    private function legacyHash(mixed $content): string
    {
        return hash('sha256', json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * 7.1.0 방식(빈 객체 = 빈 배열)으로 원본 해시를 계산합니다.
     *
     * @param  mixed  $content  본문
     * @return string 해시
     */
    private function newHash(mixed $content): string
    {
        return hash('sha256', json_encode(json_decode(json_encode($content), true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * 레이아웃 행을 넣습니다.
     *
     * @param  string  $table  테이블
     * @param  array<string, mixed>  $row  행 값
     * @return int 행 ID
     */
    private function insert(string $table, array $row): int
    {
        return (int) DB::table($table)->insertGetId($row + ['created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * 설치 그대로인 행은 새 해시로 바뀌고, 고친 행·해시 없는 행·이미 맞는 행은 그대로이며, 두 번 돌려도 같다.
     *
     * @scenario case=rehash_untouched_rows
     *
     * @effects legacy_empty_object_hash_is_rewritten_only_for_untouched_rows
     *
     * @return void 반환값 없음
     */
    public function test_rewrites_only_rows_untouched_since_install(): void
    {
        $template = Template::create([
            'identifier' => 'test-rehash-admin',
            'vendor' => 'test',
            'name' => ['ko' => '재해시', 'en' => 'Rehash'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '테스트용', 'en' => 'For testing'],
        ]);

        $original = ['params' => ['query' => new \stdClass], 'components' => [], 'title' => '목록'];
        $edited = ['params' => ['query' => new \stdClass], 'components' => [], 'title' => '운영자가 고친 제목'];
        $plain = ['components' => [['name' => 'Div']]];

        $base = ['template_id' => $template->id, 'source_type' => 'template', 'source_identifier' => null];
        $untouched = $this->insert('template_layouts', $base + ['name' => 'untouched', 'content' => json_encode($original), 'original_content_hash' => $this->legacyHash($original), 'original_content_size' => 1]);
        $modified = $this->insert('template_layouts', $base + ['name' => 'modified', 'content' => json_encode($edited), 'original_content_hash' => $this->legacyHash($original), 'original_content_size' => 1]);
        $noHash = $this->insert('template_layouts', $base + ['name' => 'nohash', 'content' => json_encode($original), 'original_content_hash' => null, 'original_content_size' => null]);
        $already = $this->insert('template_layouts', $base + ['name' => 'plain', 'content' => json_encode($plain), 'original_content_hash' => $this->legacyHash($plain), 'original_content_size' => 7]);

        $ext = $this->insert('template_layout_extensions', [
            'template_id' => $template->id,
            'extension_type' => 'overlay',
            'target_name' => 'untouched',
            'source_type' => 'module',
            'source_identifier' => 'acme-mod',
            'content' => json_encode($original),
            'original_content_hash' => $this->legacyHash($original),
            'original_content_size' => 1,
            'priority' => 100,
            'is_active' => true,
        ]);

        $migration = new RehashLayoutOriginalContent;
        $context = new UpgradeContext('7.0.11', '7.1.0', '7.1.0');
        $migration->run($context);

        $hash = fn (string $table, int $id) => DB::table($table)->where('id', $id)->value('original_content_hash');

        $this->assertSame($this->newHash($original), $hash('template_layouts', $untouched), '설치 그대로인 레이아웃의 해시가 새 방식으로 바뀌어야 한다');
        $this->assertSame($this->legacyHash($original), $hash('template_layouts', $modified), '운영자가 고친 레이아웃은 수정본으로 남아야 한다');
        $this->assertNull($hash('template_layouts', $noHash));
        $this->assertSame($this->legacyHash($plain), $hash('template_layouts', $already), '빈 객체가 없는 행은 바뀔 이유가 없다');
        $this->assertSame($this->newHash($original), $hash('template_layout_extensions', $ext), '확장 조각도 같은 규칙으로 다시 적는다');

        // 멱등
        $migration->run($context);
        $this->assertSame($this->newHash($original), $hash('template_layouts', $untouched));
        $this->assertSame($this->legacyHash($original), $hash('template_layouts', $modified));
    }
}
