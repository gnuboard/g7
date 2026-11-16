<?php

namespace Plugins\Sirsoft\HeroSection\Tests\Unit\Positions;

use Mockery;
use Modules\Sirsoft\Board\Contracts\BoardDirectory;
use Modules\Sirsoft\Board\Contracts\Data\BoardSummary;
use Modules\Sirsoft\Ecommerce\Contracts\Catalog\CategoryDirectory;
use Modules\Sirsoft\Ecommerce\Contracts\Catalog\Data\CategorySummary;
use Modules\Sirsoft\Ecommerce\Contracts\Catalog\Data\ProductSummary;
use Modules\Sirsoft\Ecommerce\Contracts\Catalog\ProductDirectory;
use Plugins\Sirsoft\HeroSection\Support\Targets\BoardTargetResolver;
use Plugins\Sirsoft\HeroSection\Support\Targets\CategoryTargetResolver;
use Plugins\Sirsoft\HeroSection\Support\Targets\ProductTargetResolver;
use Plugins\Sirsoft\HeroSection\Tests\PluginTestCase;

/**
 * 내장 대상 해석기 — 화면 값(slug·상품 코드·id)을 저장 키(id)로 풀고, 이름·검색·사이트 주소를 준다(매니페스트 position-registry.yaml).
 *
 * 모듈이 공개한 조회 계약만 쓴다(#916 — 모듈 Model·저장소는 모듈 내부). 시험은 계약을 목으로 바꿔 시험 DB 의 모듈 설치 여부와 무관하게 돈다.
 * 경로 이름(「의류 › 남성」) 조립은 이커머스 계약 구현의 몫이라 이커머스 `CategoryDirectoryTest` 가 잠근다.
 */
class TargetResolversTest extends PluginTestCase
{
    /**
     * 테스트 뒤 목 객체를 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * 게시판 요약을 만듭니다.
     *
     * @param  int  $id  게시판 id
     * @param  string  $slug  슬러그
     * @param  string  $name  이름
     * @return BoardSummary 게시판 요약
     */
    private function board(int $id, string $slug, string $name): BoardSummary
    {
        return new BoardSummary($id, $slug, $name, '/board/'.$slug);
    }

    /**
     * 상품 요약을 만듭니다.
     *
     * @param  int  $id  상품 id
     * @param  string  $code  상품 코드
     * @param  string  $name  이름
     * @return ProductSummary 상품 요약
     */
    private function product(int $id, string $code, string $name = ''): ProductSummary
    {
        return new ProductSummary($id, $code, $name, '/shop/products/'.$code);
    }

    /**
     * 카테고리 요약을 만듭니다.
     *
     * @param  int  $id  카테고리 id
     * @param  string  $slug  슬러그
     * @param  string  $pathLabel  경로 이름
     * @return CategorySummary 카테고리 요약
     */
    private function category(int $id, string $slug, string $pathLabel = ''): CategorySummary
    {
        return new CategorySummary($id, $slug, $pathLabel, $pathLabel, '/shop/category/'.$slug);
    }

    /**
     * 게시판은 slug 와 id 를 모두 받아 id 로 풀고, 없는 값은 null 이다. 이름·검색·사이트 주소를 준다.
     *
     * @scenario case=builtin_target_resolvers
     *
     * @return void 반환값 없음
     */
    public function test_board_resolver(): void
    {
        $free = $this->board(2, 'free', '자유게시판');
        $boards = Mockery::mock(BoardDirectory::class);
        $boards->shouldReceive('findBySlug')->with('free')->andReturn($free);
        $boards->shouldReceive('findBySlug')->with('nope')->andReturnNull();
        $boards->shouldReceive('findBySlug')->with('2')->andReturnNull();
        $boards->shouldReceive('findById')->with(2)->andReturn($free);
        $boards->shouldReceive('findById')->with(9)->andReturnNull();
        $boards->shouldReceive('search')->with('자유', 20)->andReturn([$free]);
        // 이름을 여러 건 풀 때는 게시판마다 묻지 않고 id 묶음 한 번으로 묻는다
        $boards->shouldReceive('findByIds')->once()->with([2, 9])->andReturn([2 => $free]);
        $resolver = new BoardTargetResolver($boards);

        $this->assertSame('2', $resolver->resolve('free'));
        $this->assertSame('2', $resolver->resolve('2'));
        $this->assertNull($resolver->resolve('nope'));
        $this->assertNull($resolver->resolve(''));
        $this->assertTrue($resolver->exists('2'));
        $this->assertFalse($resolver->exists('9'));
        $this->assertFalse($resolver->exists('free'));
        $this->assertSame(['2' => '자유게시판'], $resolver->labels(['2', '9']));
        $this->assertSame([['key' => '2', 'label' => '자유게시판', 'hint' => 'free']], $resolver->search('자유', 20));
        $this->assertSame('/board/free', $resolver->siteUrl('2'));
        $this->assertNull($resolver->siteUrl('9'));
    }

    /**
     * 상품은 상품 코드와 id 를 받아 id 로 푼다. 이름은 여러 개를 한 번에 조회한다.
     *
     * @scenario case=builtin_target_resolvers
     *
     * @return void 반환값 없음
     */
    public function test_product_resolver(): void
    {
        $mouse = $this->product(31, 'P-31', '무선 마우스');
        $products = Mockery::mock(ProductDirectory::class);
        $products->shouldReceive('findByCode')->with('P-31')->andReturn($mouse);
        $products->shouldReceive('findByCode')->with('31')->andReturnNull();
        $products->shouldReceive('findByCode')->with('none')->andReturnNull();
        $products->shouldReceive('findById')->with(31)->andReturn($mouse);
        $products->shouldReceive('findByIds')->with([31, 99])->andReturn([31 => $mouse]);
        $products->shouldReceive('search')->with('마우스', 20)->andReturn([$mouse]);
        $resolver = new ProductTargetResolver($products);

        $this->assertSame('31', $resolver->resolve('P-31'));
        $this->assertSame('31', $resolver->resolve('31'));
        $this->assertNull($resolver->resolve('none'));
        $this->assertSame(['31' => '무선 마우스'], $resolver->labels(['31', '99']));
        $this->assertSame([['key' => '31', 'label' => '무선 마우스', 'hint' => 'P-31']], $resolver->search('마우스', 20));
        $this->assertSame('/shop/products/P-31', $resolver->siteUrl('31'));
    }

    /**
     * 카테고리는 slug 와 id 를 받아 id 로 풀고, 이름은 계약이 준 경로 이름(의류 › 남성)을 보인다.
     *
     * @scenario case=builtin_target_resolvers
     *
     * @return void 반환값 없음
     */
    public function test_category_resolver(): void
    {
        $men = $this->category(2, 'men', '의류 › 남성');
        $categories = Mockery::mock(CategoryDirectory::class);
        $categories->shouldReceive('findBySlug')->with('men')->andReturn($men);
        $categories->shouldReceive('findBySlug')->with('2')->andReturnNull();
        $categories->shouldReceive('findById')->with(2)->andReturn($men);
        $categories->shouldReceive('findByIds')->with([2])->andReturn([2 => $men]);
        $categories->shouldReceive('search')->with('남성', 20)->andReturn([$men]);
        $resolver = new CategoryTargetResolver($categories);

        $this->assertSame('2', $resolver->resolve('men'));
        $this->assertSame('2', $resolver->resolve('2'));
        $this->assertSame(['2' => '의류 › 남성'], $resolver->labels(['2']));
        $this->assertSame([['key' => '2', 'label' => '의류 › 남성', 'hint' => 'men']], $resolver->search('남성', 20));
        $this->assertSame('/shop/category/men', $resolver->siteUrl('2'));
    }

    /**
     * 화면 값은 slug(상품은 상품 코드)를 먼저 찾고, 없을 때만 숫자 id 로 찾는다 — 숫자 slug 가 다른 대상의 id 와 겹치면 slug 쪽 대상이다.
     * 모듈 화면도 주소 값을 slug·상품 코드로 찾으므로, 그 화면에 보이는 대상과 배너 대상이 어긋나지 않는다.
     *
     * @scenario case=target_value_prefers_slug
     *
     * @return void 반환값 없음
     */
    public function test_screen_value_prefers_slug_over_id(): void
    {
        // 게시판: slug '5' 인 게시판(id 7)과 id 5 인 게시판이 함께 있다
        $boards = Mockery::mock(BoardDirectory::class);
        $boards->shouldReceive('findBySlug')->with('5')->andReturn($this->board(7, '5', '숫자 게시판'));
        $boards->shouldReceive('findById')->with(5)->andReturn($this->board(5, 'notice', '공지'));
        $this->assertSame('7', (new BoardTargetResolver($boards))->resolve('5'));

        // 상품: 상품 코드 '12' 인 상품(id 40)과 id 12 인 상품
        $products = Mockery::mock(ProductDirectory::class);
        $products->shouldReceive('findByCode')->with('12')->andReturn($this->product(40, '12'));
        $products->shouldReceive('findById')->with(12)->andReturn($this->product(12, 'P-12'));
        $this->assertSame('40', (new ProductTargetResolver($products))->resolve('12'));

        // 카테고리: slug '3' 인 카테고리(id 8)와 id 3 인 카테고리
        $categories = Mockery::mock(CategoryDirectory::class);
        $categories->shouldReceive('findBySlug')->with('3')->andReturn($this->category(8, '3'));
        $categories->shouldReceive('findById')->with(3)->andReturn($this->category(3, 'kids'));
        $this->assertSame('8', (new CategoryTargetResolver($categories))->resolve('3'));
    }

    /**
     * 대상 목록 화면의 사이트 주소는 한 번에 묻는다 — id 묶음 한 번. 없는 대상은 빠진다.
     *
     * @scenario case=admin_placement_list
     *
     * @return void 반환값 없음
     */
    public function test_site_urls_in_one_call(): void
    {
        $boards = Mockery::mock(BoardDirectory::class);
        $boards->shouldReceive('findByIds')->once()->with([2, 3, 9])->andReturn([2 => $this->board(2, 'free', '자유'), 3 => $this->board(3, 'gallery', '갤러리')]);
        $this->assertSame(['2' => '/board/free', '3' => '/board/gallery'], (new BoardTargetResolver($boards))->siteUrls(['2', '3', '9']));

        $products = Mockery::mock(ProductDirectory::class);
        $products->shouldReceive('findByIds')->once()->with([31, 99])->andReturn([31 => $this->product(31, 'P-31')]);
        $this->assertSame(['31' => '/shop/products/P-31'], (new ProductTargetResolver($products))->siteUrls(['31', '99']));

        $categories = Mockery::mock(CategoryDirectory::class);
        $categories->shouldReceive('findByIds')->once()->with([2])->andReturn([2 => $this->category(2, 'men')]);
        $this->assertSame(['2' => '/shop/category/men'], (new CategoryTargetResolver($categories))->siteUrls(['2']));
    }
}
