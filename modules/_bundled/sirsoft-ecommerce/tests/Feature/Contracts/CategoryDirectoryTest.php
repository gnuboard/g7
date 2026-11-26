<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Contracts;

use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Contracts\Catalog\CategoryDirectory;
use Modules\Sirsoft\Ecommerce\Contracts\Catalog\Data\CategorySummary;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 상품 카테고리 조회 공개 계약 
 *
 * 다른 확장(히어로섹션 카테고리 위치)은 카테고리 Model·저장소 대신 이 계약으로 카테고리를 찾는다. 경로 이름(「의류 › 남성」)·사이트 경로·
 * id 묶음 조회·검색이 전환 전 히어로 해석기가 직접 만들던 값과 같아야 한다.
 */
class CategoryDirectoryTest extends ModuleTestCase
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
     * 카테고리를 만들고 경로를 채웁니다.
     *
     * @param  string  $slug  카테고리 슬러그
     * @param  string  $name  한국어 이름
     * @param  Category|null  $parent  상위 카테고리
     * @return Category 카테고리
     */
    private function makeCategory(string $slug, string $name, ?Category $parent = null): Category
    {
        $category = Category::create([
            'name' => ['ko' => $name, 'en' => $name.' EN'],
            'slug' => $slug,
            'parent_id' => $parent?->id,
            'path' => '',
            'depth' => $parent === null ? 0 : $parent->depth + 1,
            'is_active' => true,
            'sort_order' => 0,
        ]);
        $category->path = $parent === null ? (string) $category->id : $parent->path.'/'.$category->id;
        $category->save();

        return $category;
    }

    /**
     * 계약이 컨테이너에 바인딩되고, slug·id·id 묶음으로 찾은 요약에 경로 이름과 사이트 경로가 담긴다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=category_directory_find
     *
     * @effects category_directory_finds_with_path_label_and_url
     */
    public function test_finds_by_slug_id_and_ids_with_path_label_and_url(): void
    {
        app()->setLocale('ko');
        $clothing = $this->makeCategory('fx916-clothing', '의류');
        $men = $this->makeCategory('fx916-men', '남성', $clothing);
        $directory = app(CategoryDirectory::class);

        $bySlug = $directory->findBySlug('fx916-men');
        $this->assertInstanceOf(CategorySummary::class, $bySlug);
        $this->assertSame($men->id, $bySlug->id);
        $this->assertSame('남성', $bySlug->label);
        $this->assertSame('의류 › 남성', $bySlug->pathLabel);
        $this->assertStringEndsWith('/category/fx916-men', $bySlug->url);

        $this->assertSame('의류 › 남성', $directory->findById($men->id)?->pathLabel);
        $this->assertNull($directory->findById(0));
        $this->assertNull($directory->findBySlug('fx916-none'));
        $this->assertNull($directory->findBySlug('  '));

        $many = $directory->findByIds([$men->id, $clothing->id, 999999]);
        $this->assertSame([$men->id, $clothing->id], array_keys($many));
        $this->assertSame('의류', $many[$clothing->id]->pathLabel);

        $this->assertSame(['fx916-men' => '의류 › 남성'], $directory->pathLabelsBySlugs(['fx916-men', 'fx916-none']));
    }

    /**
     * 경로 이름 일괄 조회는 slug 수와 무관하게 카테고리 한 번, 조상 한 번만 읽고, 결과는 요청 순서대로 slug 마다 찾던 값과 같다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=category_directory_path_labels_batch
     *
     * @effects category_directory_path_labels_read_in_fixed_queries
     */
    public function test_path_labels_by_slugs_read_in_fixed_queries_and_match_single_lookups(): void
    {
        app()->setLocale('ko');
        $clothing = $this->makeCategory('fx916-b-clothing', '의류');
        $this->makeCategory('fx916-b-men', '남성', $clothing);
        $women = $this->makeCategory('fx916-b-women', '여성', $clothing);
        $this->makeCategory('fx916-b-shoes', '신발', $women);
        $directory = app(CategoryDirectory::class);
        $slugs = ['fx916-b-shoes', 'fx916-b-none', ' fx916-b-men ', 'fx916-b-clothing', 'fx916-b-shoes'];

        $expected = [];
        foreach (['fx916-b-shoes', 'fx916-b-men', 'fx916-b-clothing'] as $slug) {
            $expected[$slug] = $directory->findBySlug($slug)?->pathLabel;
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $labels = $directory->pathLabelsBySlugs($slugs);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($expected, $labels);
        $this->assertSame('의류 › 여성 › 신발', $labels['fx916-b-shoes']);
        $this->assertSame(2, $queries, '카테고리 한 번, 조상 한 번만 읽어야 합니다');
    }

    /**
     * 검색은 현재 언어 이름·slug 부분 일치(대소문자 무시)이고 건수를 지킨다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=category_directory_search
     *
     * @effects category_directory_search_matches_name_or_slug_within_limit
     */
    public function test_search_matches_name_or_slug_and_respects_limit(): void
    {
        app()->setLocale('ko');
        $clothing = $this->makeCategory('fx916-clothing', '의류');
        $this->makeCategory('fx916-men', '남성', $clothing);
        $directory = app(CategoryDirectory::class);

        $byName = $directory->search('남성', 20);
        $this->assertSame(['의류 › 남성'], array_map(fn (CategorySummary $c) => $c->pathLabel, $byName));

        $bySlug = array_map(fn (CategorySummary $c) => $c->slug, $directory->search('FX916-', 20));
        $this->assertContains('fx916-clothing', $bySlug);
        $this->assertContains('fx916-men', $bySlug);

        $this->assertCount(1, $directory->search('fx916-', 1));
        $this->assertSame([], $directory->search('fx916-', 0));
    }
}
