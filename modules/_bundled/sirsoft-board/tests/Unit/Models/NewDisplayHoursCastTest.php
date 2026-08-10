<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Models;

use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * new_display_hours 정수 캐스트 회귀 테스트
 *
 * Post::isNew() 는 게시판의 new_display_hours 를 Carbon subHours() 에 넘긴다.
 * subHours 는 값을 음수화하며 넘기므로 숫자 문자열은 우연히 통과하지만(비숫자 문자열은
 * "Unsupported operand types" 로 실패), 같은 값이 addHours 계열에 닿는 순간 TypeError 가 된다.
 *
 * 더 직접적인 결함은 타입 불일치다 — DB 왕복 후에는 드라이버가 INT 컬럼을 정수로 반환하지만,
 * 요청 입력으로 모델에 대입된 직후(재조회 전)에는 문자열이 그대로 남아 API 응답/비교 연산의
 * 타입이 조회 시점에 따라 달라진다. 형제 숫자 컬럼(max_reply_depth/posts_count 등)과
 * 동일하게 integer 캐스트로 통일한다.
 *
 * @effects board_new_display_hours_is_integer
 */
class NewDisplayHoursCastTest extends BoardTestCase
{
    #[Test]
    public function new_display_hours_is_integer_right_after_mass_assignment(): void
    {
        // 요청 입력(HTML number → 문자열)으로 모델을 만든 직후, 재조회 없이 읽는 경로
        $board = new Board(['new_display_hours' => '48']);

        $this->assertSame(48, $board->new_display_hours);
    }

    #[Test]
    public function new_display_hours_is_integer_after_create_without_refresh(): void
    {
        $board = Board::create([
            'slug' => 'cast-new-display-hours',
            'name' => ['ko' => '캐스트 테스트', 'en' => 'Cast Test'],
            'is_active' => true,
            'new_display_hours' => '48',
        ]);

        $this->assertSame(48, $board->new_display_hours);
    }

    #[Test]
    public function new_display_hours_is_cast_to_integer_when_loaded_from_db(): void
    {
        DB::table('boards')->where('id', $this->board->id)->update(['new_display_hours' => 12]);

        $board = Board::findOrFail($this->board->id);

        $this->assertSame(12, $board->new_display_hours);
    }

    #[Test]
    public function post_is_new_does_not_crash_when_board_attribute_is_string(): void
    {
        $postId = $this->createTestPost();
        $post = Post::with('board')->findOrFail($postId);

        // 문자열 유입 재현 (캐스트 도입 후 정수로 해석되어야 함 — 비회귀 가드)
        $post->board->new_display_hours = '24';

        $this->assertTrue($post->isNew());
    }

    #[Test]
    public function post_is_new_returns_false_for_old_post(): void
    {
        $postId = $this->createTestPost();
        DB::table('board_posts')->where('id', $postId)->update(['created_at' => now()->subHours(5)]);

        $post = Post::with('board')->findOrFail($postId);
        $post->board->new_display_hours = '1';

        $this->assertFalse($post->isNew());
    }
}
