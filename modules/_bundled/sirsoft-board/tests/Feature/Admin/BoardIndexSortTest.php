<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\BoardType;
use Modules\Sirsoft\Board\Services\BoardService;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 관리자 게시판 목록 정렬/페이지 크기 게이트 테스트
 *
 * 검증 목적:
 * - IndexBoardRequest: sort_by 화이트리스트(in:) 밖 컬럼 → 422 (스키마 노출·비인덱스 정렬 DoS 차단)
 * - IndexBoardRequest: per_page 1~999 범위 밖 → 422 (상한은 전체 목록 셀렉트 소비처 수용치)
 * - 화이트리스트 컬럼 정렬은 정상 동작 (sort_by=slug&sort_order=asc)
 * - BoardService::getBoards 직접 호출(게이트 우회 내부 호출)도 미허용 컬럼을
 *   기본 정렬로 fallback — 예외 없이 동작 (ResolvesSortSpec 이중 방어)
 *
 * @group board
 * @group admin
 */
class BoardIndexSortTest extends ModuleTestCase
{
    protected User $adminUser;

    private const INDEX_URL = '/api/modules/sirsoft-board/admin/boards';

    /**
     * 테스트 환경 설정
     */
    protected function setUp(): void
    {
        parent::setUp();

        BoardType::firstOrCreate(
            ['slug' => 'basic'],
            ['name' => ['ko' => '기본', 'en' => 'Basic'], 'is_active' => true, 'is_default' => true]
        );

        $this->adminUser = $this->createAdminUser([
            'sirsoft-board.boards.read',
        ]);
    }

    /**
     * 화이트리스트 밖 정렬 컬럼(sort_by=settings) → 422.
     */
    public function test_rejects_unknown_sort_column(): void
    {
        $this->actingAs($this->adminUser)
            ->getJson(self::INDEX_URL.'?sort_by=settings')
            ->assertStatus(422);
    }

    /**
     * per_page 상한(999) 초과(per_page=1000) → 422.
     */
    public function test_rejects_out_of_range_per_page(): void
    {
        $this->actingAs($this->adminUser)
            ->getJson(self::INDEX_URL.'?per_page=1000')
            ->assertStatus(422);
    }

    /**
     * 전체 목록 셀렉트 소비처의 per_page 를 게이트가 수용한다 (회귀).
     *
     * 게시판은 운영자 등록 설정성 테이블이라 레이아웃이 사실상 전량 조회를 쓴다:
     * - 게시판 설정 화면(admin_board_settings.json): per_page=999
     * - 쇼핑몰 환경설정 문의 카드(admin-ecommerce-inquiry-settings.json): per_page=200
     * 게이트를 100 으로 조였을 때 두 화면 진입이 422 토스트로 깨졌다 (#573 회귀).
     */
    public function test_accepts_full_list_consumer_per_page(): void
    {
        foreach ([200, 999] as $perPage) {
            $this->actingAs($this->adminUser)
                ->getJson(self::INDEX_URL.'?per_page='.$perPage)
                ->assertStatus(200);
        }
    }

    /**
     * 화이트리스트 컬럼 정렬(sort_by=slug&sort_order=asc)은 200 + 순서 보장.
     */
    public function test_sorts_by_whitelisted_column(): void
    {
        $prefix = 'sort-'.uniqid();

        // 생성 순서를 정렬 기대 순서와 다르게 섞는다 (기본 정렬 fallback 오탐 방지)
        foreach (['c', 'a', 'b'] as $suffix) {
            Board::create([
                'name' => ['ko' => "정렬 테스트 {$suffix}"],
                'slug' => "{$prefix}-{$suffix}",
                'type' => 'basic',
                'is_active' => true,
            ]);
        }

        $rows = $this->actingAs($this->adminUser)
            ->getJson(self::INDEX_URL."?search={$prefix}&sort_by=slug&sort_order=asc")
            ->assertStatus(200)
            ->json('data.data');

        $slugs = array_column($rows, 'slug');

        $this->assertSame(
            ["{$prefix}-a", "{$prefix}-b", "{$prefix}-c"],
            $slugs,
            'slug 오름차순 정렬이 적용되어야 합니다.'
        );
    }

    /**
     * BoardService::getBoards 직접 호출(게이트 우회 내부 호출)에 미허용 정렬 컬럼을
     * 넘겨도 예외 없이 기본 정렬로 fallback 한다 (ResolvesSortSpec 이중 방어).
     */
    public function test_service_ignores_unlisted_sort_column(): void
    {
        $this->actingAs($this->adminUser);

        $result = app(BoardService::class)->getBoards([
            'sort_by' => 'evil',
            'sort_order' => 'asc',
        ]);

        $this->assertInstanceOf(
            LengthAwarePaginator::class,
            $result,
            '미허용 정렬 컬럼은 예외 없이 기본 정렬로 fallback 되어야 합니다.'
        );
    }
}
