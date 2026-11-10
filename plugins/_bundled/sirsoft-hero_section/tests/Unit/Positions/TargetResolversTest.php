<?php

namespace Plugins\Sirsoft\HeroSection\Tests\Unit\Positions;

use App\Enums\TotalRelation;
use App\Support\Query\BoundedPage;
use Illuminate\Database\Eloquent\Collection;
use Mockery;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CategoryRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductRepositoryInterface;
use Plugins\Sirsoft\HeroSection\Support\Targets\BoardTargetResolver;
use Plugins\Sirsoft\HeroSection\Support\Targets\CategoryTargetResolver;
use Plugins\Sirsoft\HeroSection\Support\Targets\ProductTargetResolver;
use Plugins\Sirsoft\HeroSection\Tests\PluginTestCase;

/**
 * 내장 대상 해석기 — 화면 값(slug·상품 코드·id)을 저장 키(id)로 풀고, 이름·검색·사이트 주소를 준다(매니페스트 position-registry.yaml).
 *
 * 모듈 저장소 계약만 쓴다(모듈 코드는 고치지 않는다). 시험은 계약을 목으로 바꿔 시험 DB 의 모듈 설치 여부와 무관하게 돈다.
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
     * 게시판 모델을 만듭니다.
     *
     * @param  int  $id  게시판 id
     * @param  string  $slug  슬러그
     * @param  string  $name  한국어 이름
     * @return Board 게시판
     */
    private function board(int $id, string $slug, string $name): Board
    {
        return (new Board)->forceFill(['id' => $id, 'slug' => $slug, 'name' => ['ko' => $name, 'en' => $name.' EN']]);
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
        $repo = Mockery::mock(BoardRepositoryInterface::class);
        $repo->shouldReceive('findBySlug')->with('free')->andReturn($free);
        $repo->shouldReceive('findBySlug')->with('nope')->andReturnNull();
        $repo->shouldReceive('findBySlug')->with('2')->andReturnNull();
        $repo->shouldReceive('find')->with(2)->andReturn($free);
        $repo->shouldReceive('find')->with(9)->andReturnNull();
        $repo->shouldReceive('search')->with('자유')->andReturn(new Collection([$free]));
        // 이름을 여러 건 풀 때는 게시판마다 묻지 않고 한 번에 읽는다
        $repo->shouldReceive('all')->once()->andReturn(new Collection([$free, $this->board(3, 'gallery', '갤러리')]));
        $resolver = new BoardTargetResolver($repo);
        app()->setLocale('ko');

        $this->assertSame('2', $resolver->resolve('free'));
        $this->assertSame('2', $resolver->resolve('2'));
        $this->assertNull($resolver->resolve('nope'));
        $this->assertNull($resolver->resolve(''));
        $this->assertTrue($resolver->exists('2'));
        $this->assertFalse($resolver->exists('9'));
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
        $product = (new Product)->forceFill(['id' => 31, 'product_code' => 'P-31', 'name' => ['ko' => '무선 마우스']]);
        $repo = Mockery::mock(ProductRepositoryInterface::class);
        $repo->shouldReceive('findByProductCode')->with('P-31')->andReturn($product);
        $repo->shouldReceive('findByProductCode')->with('31')->andReturnNull();
        $repo->shouldReceive('findByProductCode')->with('none')->andReturnNull();
        $repo->shouldReceive('find')->with(31)->andReturn($product);
        $repo->shouldReceive('findByIdsKeyed')->with([31, 99])->andReturn(new Collection([31 => $product]));
        $repo->shouldReceive('searchByKeyword')->andReturn(new BoundedPage(new Collection([$product]), 1, 20, 1, TotalRelation::Exact, null, false));
        $resolver = new ProductTargetResolver($repo);
        app()->setLocale('ko');

        $this->assertSame('31', $resolver->resolve('P-31'));
        $this->assertSame('31', $resolver->resolve('31'));
        $this->assertNull($resolver->resolve('none'));
        $this->assertSame(['31' => '무선 마우스'], $resolver->labels(['31', '99']));
        $this->assertSame([['key' => '31', 'label' => '무선 마우스', 'hint' => 'P-31']], $resolver->search('마우스', 20));
        $this->assertStringEndsWith('/products/P-31', (string) $resolver->siteUrl('31'));
    }

    /**
     * 카테고리는 slug 와 id 를 받아 id 로 풀고, 이름은 상위 경로를 이어 보인다(의류 › 남성).
     *
     * @scenario case=builtin_target_resolvers
     *
     * @return void 반환값 없음
     */
    public function test_category_resolver(): void
    {
        $clothing = (new Category)->forceFill(['id' => 1, 'path' => '1', 'slug' => 'clothing', 'name' => ['ko' => '의류']]);
        $men = (new Category)->forceFill(['id' => 2, 'path' => '1/2', 'slug' => 'men', 'name' => ['ko' => '남성']]);
        $repo = Mockery::mock(CategoryRepositoryInterface::class);
        $repo->shouldReceive('findBySlug')->with('men')->andReturn($men);
        $repo->shouldReceive('findBySlug')->with('2')->andReturnNull();
        $repo->shouldReceive('findById')->with(2)->andReturn($men);
        $repo->shouldReceive('findByIdsKeyed')->andReturn(new Collection([1 => $clothing, 2 => $men]));
        $repo->shouldReceive('getFlatList')->andReturn(new Collection([$clothing, $men]));
        $resolver = new CategoryTargetResolver($repo);
        app()->setLocale('ko');

        $this->assertSame('2', $resolver->resolve('men'));
        $this->assertSame('2', $resolver->resolve('2'));
        $this->assertSame(['2' => '의류 › 남성'], $resolver->labels(['2']));
        $this->assertSame([['key' => '2', 'label' => '의류 › 남성', 'hint' => 'men']], $resolver->search('남성', 20));
        $this->assertStringEndsWith('/category/men', (string) $resolver->siteUrl('2'));
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
        $boards = Mockery::mock(BoardRepositoryInterface::class);
        $boards->shouldReceive('findBySlug')->with('5')->andReturn($this->board(7, '5', '숫자 게시판'));
        $boards->shouldReceive('find')->with(5)->andReturn($this->board(5, 'notice', '공지'));
        $this->assertSame('7', (new BoardTargetResolver($boards))->resolve('5'));

        // 상품: 상품 코드 '12' 인 상품(id 40)과 id 12 인 상품
        $products = Mockery::mock(ProductRepositoryInterface::class);
        $products->shouldReceive('findByProductCode')->with('12')->andReturn((new Product)->forceFill(['id' => 40, 'product_code' => '12']));
        $products->shouldReceive('find')->with(12)->andReturn((new Product)->forceFill(['id' => 12, 'product_code' => 'P-12']));
        $this->assertSame('40', (new ProductTargetResolver($products))->resolve('12'));

        // 카테고리: slug '3' 인 카테고리(id 8)와 id 3 인 카테고리
        $categories = Mockery::mock(CategoryRepositoryInterface::class);
        $categories->shouldReceive('findBySlug')->with('3')->andReturn((new Category)->forceFill(['id' => 8, 'path' => '8', 'slug' => '3']));
        $categories->shouldReceive('findById')->with(3)->andReturn((new Category)->forceFill(['id' => 3, 'path' => '3', 'slug' => 'kids']));
        $this->assertSame('8', (new CategoryTargetResolver($categories))->resolve('3'));
    }
}
