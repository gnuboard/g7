<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Contracts;

use Modules\Sirsoft\Board\Contracts\BoardDirectory;
use Modules\Sirsoft\Board\Contracts\Data\BoardSummary;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 게시판 조회 공개 계약 
 *
 * 다른 확장(히어로섹션 게시판 위치)은 게시판 Model·저장소 대신 이 계약으로 게시판을 찾는다. 이름·사이트 경로·id 묶음 조회·검색이
 * 전환 전 히어로 해석기가 직접 만들던 값과 같아야 한다.
 */
class BoardDirectoryTest extends ModuleTestCase
{
    /**
     * 스키마를 다시 만들지 않고 트랜잭션으로 격리합니다.
     *
     * @return void 반환값 없음
     */
    public function refreshDatabase(): void
    {
        $this->beginDatabaseTransaction();
    }

    /**
     * 계약이 컨테이너에 바인딩되고, slug·id·id 묶음으로 찾은 요약에 현재 언어 이름과 목록 경로가 담긴다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=find
     *
     * @effects board_directory_finds_by_slug_id_and_ids_with_label_and_path
     */
    public function test_finds_by_slug_id_and_ids(): void
    {
        app()->setLocale('ko');
        $free = Board::factory()->create(['slug' => 'fx916free', 'name' => ['ko' => '자유게시판', 'en' => 'Free']]);
        $gallery = Board::factory()->create(['slug' => 'fx916gallery', 'name' => ['ko' => '갤러리', 'en' => 'Gallery']]);
        $directory = app(BoardDirectory::class);

        $bySlug = $directory->findBySlug('fx916free');
        $this->assertInstanceOf(BoardSummary::class, $bySlug);
        $this->assertSame([$free->id, 'fx916free', '자유게시판', '/board/fx916free'], [$bySlug->id, $bySlug->slug, $bySlug->label, $bySlug->url]);
        $this->assertSame('갤러리', $directory->findById($gallery->id)?->label);
        $this->assertNull($directory->findById(0));
        $this->assertNull($directory->findBySlug(''));

        $many = $directory->findByIds([$gallery->id, $free->id, 999999]);
        $this->assertSame([$gallery->id, $free->id], array_keys($many));
        $this->assertSame([$free->id], array_keys($directory->findByIds([$free->id])));

        $this->assertSame(['fx916free' => '자유게시판'], $directory->labelsBySlugs(['fx916free', 'fx916none']));
    }

    /**
     * 검색은 slug·이름 부분 일치이고 건수를 지킨다. 빈 검색어는 앞에서부터, 0 건 요청은 빈 배열.
     *
     * @return void 반환값 없음
     *
     * @scenario case=search
     *
     * @effects board_directory_search_matches_keyword_and_respects_limit
     */
    public function test_search_respects_keyword_and_limit(): void
    {
        app()->setLocale('ko');
        Board::factory()->create(['slug' => 'fx916free', 'name' => ['ko' => '자유게시판', 'en' => 'Free']]);
        $directory = app(BoardDirectory::class);

        $found = $directory->search('fx916', 20);
        $this->assertSame(['fx916free'], array_map(fn (BoardSummary $b) => $b->slug, $found));
        $this->assertCount(1, $directory->search('', 1));
        $this->assertSame([], $directory->search('fx916', 0));
    }
}
