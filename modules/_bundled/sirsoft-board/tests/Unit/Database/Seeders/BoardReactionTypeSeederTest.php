<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Database\Seeders;

use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Database\Seeders\BoardReactionTypeSeeder;
use Modules\Sirsoft\Board\Models\ReactionType;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * BoardReactionTypeSeeder 검증.
 *
 * 시더 실행 후 추천(like)/비추천(dislike) 2건이 정확한 code·아이콘·ko/en/ja
 * 라벨로 등록되는지 확인한다 (이슈 #525 1단계 시더 작성 검증).
 */
class BoardReactionTypeSeederTest extends ModuleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // FK(restrictOnDelete) 순서: 이력 먼저 정리 (타 테스트 잔여 데이터 대비)
        DB::table('board_reactions')->delete();
    }

    /**
     * 시더가 추천/비추천 2건을 정확한 값으로 등록한다.
     *
     * @scenario case=reaction_type_label_localized
     * @effects reaction_type_name_localized
     */
    public function test_seeder_creates_like_and_dislike_types(): void
    {
        ReactionType::query()->delete();

        $this->seed(BoardReactionTypeSeeder::class);

        $this->assertSame(2, ReactionType::query()->count());

        $like = ReactionType::query()->where('code', 'like')->first();
        $this->assertNotNull($like);
        $this->assertSame('fas fa-thumbs-up', $like->icon);
        $this->assertSame(1, $like->display_order);
        $this->assertTrue($like->is_active);
        $this->assertSame(
            ['ko' => '추천', 'en' => 'Recommend', 'ja' => 'おすすめ'],
            $like->name
        );

        $dislike = ReactionType::query()->where('code', 'dislike')->first();
        $this->assertNotNull($dislike);
        $this->assertSame('fas fa-thumbs-down', $dislike->icon);
        $this->assertSame(2, $dislike->display_order);
        $this->assertTrue($dislike->is_active);
        $this->assertSame(
            ['ko' => '비추천', 'en' => 'Not Recommend', 'ja' => 'ひどい'],
            $dislike->name
        );
    }

    /**
     * 시더는 code로 매칭하는 upsert라 재실행해도 중복 생성되지 않는다.
     */
    public function test_seeder_is_idempotent(): void
    {
        ReactionType::query()->delete();

        $this->seed(BoardReactionTypeSeeder::class);
        $this->seed(BoardReactionTypeSeeder::class);

        $this->assertSame(2, ReactionType::query()->count());
    }

    /**
     * 한글이 유니코드 이스케이프 없이 저장된다 (AsUnicodeJson 캐스트).
     */
    public function test_name_stored_as_unicode_json(): void
    {
        ReactionType::query()->delete();

        $this->seed(BoardReactionTypeSeeder::class);

        $raw = \Illuminate\Support\Facades\DB::table('board_reaction_types')
            ->where('code', 'like')
            ->value('name');

        $this->assertStringContainsString('추천', $raw);
        $this->assertStringNotContainsString('\\u', $raw);
    }
}
