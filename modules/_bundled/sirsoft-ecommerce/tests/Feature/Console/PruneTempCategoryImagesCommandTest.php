<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Console;

use App\Extension\Storage\ModuleStorageDriver;
use App\Thumbnails\ThumbnailStore;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\CategoryImage;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 미연결 임시 카테고리 이미지 정리 커맨드 테스트
 *
 * 새 카테고리 폼에서 올리고 저장하지 않고 닫은 업로드(`temp_key` 있음, `category_id` 없음)만
 * 대상이어야 합니다 — 저장된 카테고리의 이미지가 섞이면 노출 중인 카테고리 배너가 사라집니다.
 * 파일은 저장소 드라이버로 지우므로 그 원본의 썸네일 파생본도 함께 사라져야 합니다.
 */
class PruneTempCategoryImagesCommandTest extends ModuleTestCase
{
    /**
     * 모듈·썸네일 디스크를 가짜로 바꾸고 썸네일 기능을 켭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('modules');
        Storage::fake('thumbnails');

        Config::set('core.thumbnail.enabled', true);
        Config::set('core.thumbnail.disk_public', 'thumbnails');
        Config::set('core.thumbnail.disk_gated', 'thumbnails');
    }

    /**
     * 모듈 저장소 드라이버를 만듭니다.
     *
     * @return ModuleStorageDriver 이커머스 모듈 저장소
     */
    private function storage(): ModuleStorageDriver
    {
        return new ModuleStorageDriver('sirsoft-ecommerce', 'modules');
    }

    /**
     * 카테고리 이미지 파일과 기록을 함께 만듭니다 (기본은 저장하지 않은 임시 업로드).
     *
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @param  int  $ageDays  업로드 후 지난 일수
     * @return CategoryImage 생성된 이미지
     */
    private function makeImage(array $attributes = [], int $ageDays = 5): CategoryImage
    {
        $filename = uniqid('temp-category-').'.png';
        $path = $attributes['path'] ?? 'category/2026/11/10/'.$filename;

        $this->storage()->put('images', $path, 'bytes');

        $image = CategoryImage::create(array_merge([
            'category_id' => null,
            'temp_key' => 'b0b8d3f2-6a8e-4c1e-9f55-0b8f1d2a3c4e',
            'original_filename' => $filename,
            'stored_filename' => $filename,
            'disk' => 'modules',
            'path' => $path,
            'mime_type' => 'image/png',
            'file_size' => 5,
            'collection' => 'main',
            'sort_order' => 1,
        ], $attributes));

        $image->forceFill(['created_at' => now()->subDays($ageDays)])->saveQuietly();

        return $image;
    }

    /**
     * 원본 하나의 썸네일 파생본을 썸네일 디스크에 심습니다.
     *
     * @param  CategoryImage  $image  원본 이미지 행
     * @return string 심은 파생본 키
     */
    private function plantThumbnail(CategoryImage $image): string
    {
        $key = ThumbnailStore::ROOT.'/modules/sirsoft-ecommerce/images/'.$image->path.'/sm_0123abcd.png';
        Storage::disk('thumbnails')->put($key, 'thumb');

        return $key;
    }

    /**
     * 저장된 카테고리를 만듭니다.
     *
     * @return Category 카테고리
     */
    private function makeCategory(): Category
    {
        return Category::create([
            'name' => ['ko' => '정리 대상 아님', 'en' => 'Not a prune target'],
            'slug' => uniqid('prune-temp-category-'),
            'is_active' => true,
            'depth' => 0,
            'path' => '',
            'sort_order' => 0,
        ]);
    }

    /**
     * 보존기간이 지난 임시 업로드는 파일과 행이 함께 사라진다 (행은 소프트 삭제가 아니라 영구 삭제).
     *
     * @scenario age=past_retention, image_state=temp_unlinked
     *
     * @effects category_temp_prune_deletes_file_and_record
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function stale_temp_images_lose_both_file_and_record(): void
    {
        $image = $this->makeImage();

        $this->artisan('sirsoft-ecommerce:prune-temp-category-images')->assertSuccessful();

        $this->assertFalse($this->storage()->exists('images', $image->path));
        $this->assertDatabaseMissing('ecommerce_category_images', ['id' => $image->id]);
    }

    /**
     * 「등록 시 미리 생성」으로 만들어진 임시 업로드의 썸네일도 함께 사라진다.
     *
     * @scenario age=past_retention, image_state=temp_unlinked
     *
     * @effects category_temp_prune_deletes_thumbnails
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function thumbnails_of_a_pruned_temp_image_are_deleted_too(): void
    {
        $image = $this->makeImage();
        $thumbnail = $this->plantThumbnail($image);

        // 대조군 — 보존기간 안의 임시 업로드 썸네일은 남아야 한다 (썸네일 디스크째 비우는 것이 아님)
        $recent = $this->makeImage(ageDays: 0);
        $recentThumbnail = $this->plantThumbnail($recent);

        Storage::disk('thumbnails')->assertExists($thumbnail);

        $this->artisan('sirsoft-ecommerce:prune-temp-category-images')->assertSuccessful();

        Storage::disk('thumbnails')->assertMissing($thumbnail);
        Storage::disk('thumbnails')->assertExists($recentThumbnail);
    }

    /**
     * 보존기간 안의 임시 업로드는 지금 폼을 쓰는 중일 수 있으므로 남긴다.
     *
     * @scenario age=within_retention, image_state=temp_unlinked
     *
     * @effects category_temp_prune_keeps_within_retention
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function temp_image_within_retention_is_kept(): void
    {
        $image = $this->makeImage(ageDays: 0);

        $this->artisan('sirsoft-ecommerce:prune-temp-category-images')->assertSuccessful();

        $this->assertTrue($this->storage()->exists('images', $image->path));
        $this->assertDatabaseHas('ecommerce_category_images', ['id' => $image->id]);
    }

    /**
     * 저장된 카테고리의 이미지는 아무리 오래되어도 대상이 아니다 (파일·썸네일·행 모두 남는다).
     *
     * @scenario age=past_retention, image_state=linked_to_category
     *
     * @effects category_temp_prune_skips_linked
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function image_linked_to_a_category_is_never_pruned(): void
    {
        $category = $this->makeCategory();
        $image = $this->makeImage(['category_id' => $category->id, 'temp_key' => null], ageDays: 90);
        $thumbnail = $this->plantThumbnail($image);

        $this->artisan('sirsoft-ecommerce:prune-temp-category-images')->assertSuccessful();

        $this->assertTrue($this->storage()->exists('images', $image->path));
        Storage::disk('thumbnails')->assertExists($thumbnail);
        $this->assertDatabaseHas('ecommerce_category_images', ['id' => $image->id, 'deleted_at' => null]);
    }

    /**
     * 폼에서 뺀 임시 이미지(소프트 삭제, 파일은 이미 지워짐)는 행이 영구히 남지 않는다.
     *
     * @effects category_temp_prune_removes_soft_deleted_temp_rows
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function temp_image_removed_in_the_form_loses_its_row(): void
    {
        $image = $this->makeImage();
        $this->storage()->delete('images', $image->path);
        $image->delete();

        $this->assertSoftDeleted('ecommerce_category_images', ['id' => $image->id]);

        $this->artisan('sirsoft-ecommerce:prune-temp-category-images')->assertSuccessful();

        $this->assertDatabaseMissing('ecommerce_category_images', ['id' => $image->id]);
    }

    /**
     * 저장된 카테고리 이미지를 지운 소프트 삭제 행은 임시 업로드가 아니므로 건드리지 않는다.
     *
     * @effects category_temp_prune_skips_linked
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function soft_deleted_linked_image_is_not_touched(): void
    {
        $category = $this->makeCategory();
        $image = $this->makeImage(['category_id' => $category->id, 'temp_key' => null], ageDays: 90);
        $image->delete();

        $this->artisan('sirsoft-ecommerce:prune-temp-category-images')->assertSuccessful();

        $this->assertSoftDeleted('ecommerce_category_images', ['id' => $image->id]);
    }

    /**
     * 미리보기는 대상 건수만 알리고 파일·행을 건드리지 않는다.
     *
     * @effects category_temp_prune_dry_run
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function dry_run_reports_targets_without_deleting(): void
    {
        $image = $this->makeImage();

        $this->artisan('sirsoft-ecommerce:prune-temp-category-images --dry-run')
            ->expectsOutputToContain('[DRY RUN]')
            ->assertSuccessful();

        $this->assertTrue($this->storage()->exists('images', $image->path));
        $this->assertDatabaseHas('ecommerce_category_images', ['id' => $image->id]);
    }

    /**
     * 보존기간 1일 미만은 지금 올리는 중인 업로드까지 지우므로 정리하지 않는다.
     *
     * @effects category_temp_prune_days_guard
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function retention_below_one_day_performs_no_cleanup(): void
    {
        $image = $this->makeImage(ageDays: 90);

        $this->artisan('sirsoft-ecommerce:prune-temp-category-images --days=0')->assertSuccessful();

        $this->assertDatabaseHas('ecommerce_category_images', ['id' => $image->id]);
    }

    /**
     * 한 번에 처리하는 건수는 --limit 을 넘지 않고, 오래된 것부터 지운다 (쌓인 행은 다음 회차가 이어 처리).
     *
     * @effects category_temp_prune_respects_limit_oldest_first
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function limit_caps_one_run_and_takes_the_oldest_first(): void
    {
        $oldest = $this->makeImage(ageDays: 30);
        $newer = $this->makeImage(ageDays: 5);

        $this->artisan('sirsoft-ecommerce:prune-temp-category-images --limit=1')->assertSuccessful();

        $this->assertDatabaseMissing('ecommerce_category_images', ['id' => $oldest->id]);
        $this->assertDatabaseHas('ecommerce_category_images', ['id' => $newer->id]);

        $this->artisan('sirsoft-ecommerce:prune-temp-category-images --limit=1')->assertSuccessful();

        $this->assertDatabaseMissing('ecommerce_category_images', ['id' => $newer->id]);
    }

    /**
     * `temp_key` 가 남아 있어도 카테고리에 연결된 행은 대상이 아니다 (「미연결」 조건 단독 고정).
     *
     * 지금은 두 값이 함께 채워진 행을 만드는 경로가 없지만, 연결 처리가 `temp_key` 를 비우지 못한 행이
     * 생기면 이 조건 하나가 노출 중인 카테고리 이미지를 지키는 유일한 장치가 된다.
     *
     * @effects category_temp_prune_requires_both_conditions
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function linked_row_that_still_has_a_temp_key_is_kept(): void
    {
        $category = $this->makeCategory();
        $image = $this->makeImage(['category_id' => $category->id], ageDays: 90);

        $this->artisan('sirsoft-ecommerce:prune-temp-category-images')->assertSuccessful();

        $this->assertTrue($this->storage()->exists('images', $image->path));
        $this->assertDatabaseHas('ecommerce_category_images', ['id' => $image->id, 'deleted_at' => null]);
    }

    /**
     * `temp_key` 가 비어 있으면 카테고리 연결이 없어도 임시 업로드가 아니므로 대상이 아니다 (「임시」 조건 단독 고정).
     *
     * @effects category_temp_prune_requires_both_conditions
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function row_without_a_temp_key_is_kept_even_when_unlinked(): void
    {
        $image = $this->makeImage(['temp_key' => null], ageDays: 90);

        $this->artisan('sirsoft-ecommerce:prune-temp-category-images')->assertSuccessful();

        $this->assertTrue($this->storage()->exists('images', $image->path));
        $this->assertDatabaseHas('ecommerce_category_images', ['id' => $image->id]);
    }

    /**
     * 콘솔 출력은 ko·en 모두 번역된 문구로 나온다 (키가 빠지면 키 문자열이 그대로 찍힌다).
     *
     * @effects category_temp_prune_console_messages_localized
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function console_output_is_translated_in_ko_and_en(): void
    {
        $this->makeImage();

        $expected = [
            'ko' => ['dry' => '미연결 임시 카테고리 이미지: 1건', 'done' => '임시 카테고리 이미지 0건 중 0건을 삭제했습니다', 'off' => '임시 이미지 보존기간이 1일 미만'],
            'en' => ['dry' => 'Unlinked temporary category images past retention (days: 2): 1', 'done' => 'Deleted 0 of 0 temporary category images', 'off' => 'retention period is less than 1 day'],
        ];

        foreach ($expected as $locale => $text) {
            app()->setLocale($locale);

            $this->artisan('sirsoft-ecommerce:prune-temp-category-images --dry-run')
                ->expectsOutputToContain($text['dry'])
                ->assertSuccessful();

            $this->artisan('sirsoft-ecommerce:prune-temp-category-images --days=999')
                ->expectsOutputToContain($text['done'])
                ->assertSuccessful();

            $this->artisan('sirsoft-ecommerce:prune-temp-category-images --days=0')
                ->expectsOutputToContain($text['off'])
                ->assertSuccessful();
        }
    }

    /**
     * 같은 날짜 디렉토리는 저장된 카테고리 이미지와 함께 쓰므로 정리 후에도 그 이미지 파일이 남는다.
     *
     * @effects category_temp_prune_keeps_shared_date_directory
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function shared_date_directory_keeps_saved_category_images(): void
    {
        $category = $this->makeCategory();
        $temp = $this->makeImage(['path' => 'category/2026/11/11/'.uniqid('t-').'.png']);
        $saved = $this->makeImage([
            'category_id' => $category->id,
            'temp_key' => null,
            'path' => 'category/2026/11/11/'.uniqid('s-').'.png',
        ]);

        $this->artisan('sirsoft-ecommerce:prune-temp-category-images')->assertSuccessful();

        $this->assertFalse($this->storage()->exists('images', $temp->path));
        $this->assertTrue($this->storage()->exists('images', $saved->path));
    }
}
