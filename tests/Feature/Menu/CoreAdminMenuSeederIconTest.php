<?php

namespace Tests\Feature\Menu;

use App\Enums\ExtensionOwnerType;
use App\Extension\Support\LifecycleWarnings;
use App\Models\Attachment;
use App\Models\Menu;
use App\Services\MenuIconService;
use App\Services\MenuService;
use App\Support\MenuIcon\MenuIconDeclaration;
use Database\Seeders\CoreAdminMenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 코어 관리자 메뉴 시더의 아이콘 선언 정규화 테스트 (#381).
 *
 * `config/core.php` menus 의 아이콘 선언(문자열·class·image 상대경로·svg path)이 확장 동기화와 같은 정규화기를 거쳐
 * 저장 정규형으로 시드되고, 잘못된 선언(사이트 절대경로)은 시드를 막지 않고 아이콘만 비는지 검증한다.
 */
class CoreAdminMenuSeederIconTest extends TestCase
{
    use RefreshDatabase;

    /** @var string 테스트용 currentColor SVG */
    private const SAFE_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2 2 22h20z"/></svg>';

    /** @var string 코어 패키지 루트 대역(public/ 대신) */
    private string $root;

    /**
     * 코어 패키지 루트 픽스처(이미지·SVG)를 만들고 루트 해석기를 그곳으로 돌린다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('framework/testing/menu-icon-core-seed');
        File::deleteDirectory($this->root);
        File::ensureDirectoryExists($this->root.'/icons');
        File::put($this->root.'/icons/menu.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
        File::put($this->root.'/icons/menu.svg', self::SAFE_SVG);
        $root = $this->root;
        MenuIconDeclaration::resolveRootUsing(static fn (ExtensionOwnerType $type): string => $type === ExtensionOwnerType::Core ? $root : base_path());
        LifecycleWarnings::current()->clear();
    }

    /**
     * 루트 해석기를 되돌리고 픽스처를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        MenuIconDeclaration::resolveRootUsing(null);
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    /**
     * 메뉴 선언 하나를 만듭니다.
     *
     * @param  string  $slug  슬러그
     * @param  mixed  $icon  선언 아이콘
     * @param  array<int, array<string, mixed>>  $children  자식 선언
     * @return array<string, mixed> 메뉴 선언
     */
    private function declaration(string $slug, mixed $icon, array $children = []): array
    {
        return [
            'name' => ['ko' => $slug, 'en' => $slug],
            'slug' => $slug,
            'url' => '/admin/'.$slug,
            'icon' => $icon,
            'order' => 1,
            'is_active' => true,
            'children' => $children,
        ];
    }

    /**
     * 시드된 코어 메뉴의 저장 원문 icon 을 돌려줍니다.
     *
     * @param  string  $slug  슬러그
     * @return string|null 저장 원문
     */
    private function storedIcon(string $slug): ?string
    {
        return Menu::where('slug', $slug)->where('extension_type', ExtensionOwnerType::Core)->firstOrFail()->getRawOriginal('icon');
    }

    /**
     * 코어 시더가 다섯 형의 선언을 정규형으로 시드하고, 사이트 절대경로 이미지는 아이콘만 비운 채 메뉴를 만든다.
     *
     * @scenario icon_type=legacy_string, icon_type=class, icon_type=svg, icon_type=image_package, icon_type=image_site, entry=core_seed
     *
     * @effects canonical_storage, invalid_declaration_ignored_with_warning
     *
     * @return void 반환값 없음
     */
    public function test_core_seed_normalizes_declared_icons(): void
    {
        config(['core.menus' => [
            $this->declaration('seed-legacy', ' fas  fa-users ', [
                $this->declaration('seed-class', ['type' => 'class', 'value' => 'fa-solid  fa-star']),
            ]),
            $this->declaration('seed-svg', ['type' => 'svg', 'path' => 'icons/menu.svg']),
            $this->declaration('seed-image', ['type' => 'image', 'path' => 'icons/menu.png']),
            $this->declaration('seed-site', ['type' => 'image', 'path' => '/icons/menu.png']),
        ]]);

        $this->seed(CoreAdminMenuSeeder::class);

        $this->assertSame('fas fa-users', $this->storedIcon('seed-legacy'));
        $this->assertSame('fa-solid fa-star', $this->storedIcon('seed-class'));
        $this->assertSame(['type' => 'svg', 'value' => self::SAFE_SVG], json_decode((string) $this->storedIcon('seed-svg'), true));
        $this->assertSame('{"path":"icons/menu.png","source":"package","type":"image"}', $this->storedIcon('seed-image'));
        $this->assertNull($this->storedIcon('seed-site'));

        $warnings = array_values(array_filter(LifecycleWarnings::current()->all(), static fn (array $item): bool => $item['step'] === 'menu_icon'));
        $this->assertCount(1, $warnings);
        $this->assertSame('core', $warnings[0]['target']);
    }

    /**
     * 실제 코어 메뉴 선언은 경고 없이 시드되고 모든 아이콘이 class 정규형이다.
     *
     * @scenario icon_type=legacy_string, entry=core_seed
     *
     * @effects canonical_storage, legacy_icon_exposed
     *
     * @return void 반환값 없음
     */
    public function test_shipped_core_menus_seed_without_icon_warnings(): void
    {
        MenuIconDeclaration::resolveRootUsing(null);

        $this->seed(CoreAdminMenuSeeder::class);

        $menus = Menu::where('extension_type', ExtensionOwnerType::Core)->get();
        $this->assertGreaterThan(5, $menus->count());
        foreach ($menus as $menu) {
            $spec = $menu->iconSpec();
            if ($spec !== null) {
                $this->assertSame('class', $spec->type, $menu->slug);
                $this->assertSame($spec->value, $menu->getRawOriginal('icon'), $menu->slug);
            }
        }
        $this->assertSame([], array_values(array_filter(LifecycleWarnings::current()->all(), static fn (array $item): bool => $item['step'] === 'menu_icon')));
    }

    /**
     * 회귀(#381 validate-plan 5차 2회차 ③): 코어 메뉴를 다시 시드하면 코어 메뉴 아래 운영자가 만든 메뉴가 FK 연쇄로 함께
     * 지워지는데, 첨부 정리는 코어 메뉴 ID 만 예약해 그 하위 메뉴의 업로드 아이콘 첨부·파일이 영구히 남았다.
     *
     * @scenario icon_type=image_attachment, entry=core_seed
     *
     * @effects menu_delete_purges_attachment
     *
     * @return void 반환값 없음
     */
    public function test_core_reseed_purges_attachments_of_cascaded_child_menus(): void
    {
        Storage::fake(config('attachment.disk', 'attachments'));
        config(['core.menus' => [$this->declaration('seed-parent', 'fas fa-users')]]);
        $this->seed(CoreAdminMenuSeeder::class);

        $parent = Menu::where('slug', 'seed-parent')->firstOrFail();
        $spec = app(MenuIconService::class)->upload(UploadedFile::fake()->image('child.png', 16, 16));
        $child = app(MenuService::class)->createMenu([
            'name' => ['ko' => '운영자 하위', 'en' => 'Operator child'],
            'slug' => 'operator-child',
            'url' => '/admin/operator-child',
            'parent_id' => $parent->id,
            'icon_spec' => ['type' => 'image', 'source' => 'attachment', 'attachment_id' => $spec['attachment_id']],
        ]);
        $attachment = Attachment::findOrFail($spec['attachment_id']);
        $this->assertSame($child->id, (int) $attachment->attachmentable_id);

        $this->seed(CoreAdminMenuSeeder::class);

        $this->assertFalse(Menu::whereKey($child->id)->exists());
        $this->assertNull(Attachment::find($attachment->id));
        Storage::disk($attachment->disk)->assertMissing($attachment->path);
    }

    /**
     * 회귀(#381 validate-plan 6차 D-6c): 시더는 트랜잭션 밖에서 돌아 커밋 뒤 예약이 곧바로 실행된다. 첨부 정리를 메뉴
     * 삭제보다 먼저 예약하면, 코어 메뉴 삭제가 실패해 메뉴는 남아도 하위 메뉴의 업로드 아이콘 첨부·파일이 먼저 사라졌다.
     *
     * @scenario icon_type=image_attachment, entry=core_seed
     *
     * @effects menu_delete_purges_attachment
     *
     * @return void 반환값 없음
     */
    public function test_core_reseed_keeps_attachment_when_menu_delete_fails(): void
    {
        Storage::fake(config('attachment.disk', 'attachments'));
        config(['core.menus' => [$this->declaration('seed-parent', 'fas fa-users')]]);
        $this->seed(CoreAdminMenuSeeder::class);

        $parent = Menu::where('slug', 'seed-parent')->firstOrFail();
        $spec = app(MenuIconService::class)->upload(UploadedFile::fake()->image('child.png', 16, 16));
        $child = app(MenuService::class)->createMenu([
            'name' => ['ko' => '운영자 하위', 'en' => 'Operator child'],
            'slug' => 'operator-child-kept',
            'url' => '/admin/operator-child-kept',
            'parent_id' => $parent->id,
            'icon_spec' => ['type' => 'image', 'source' => 'attachment', 'attachment_id' => $spec['attachment_id']],
        ]);
        $attachment = Attachment::findOrFail($spec['attachment_id']);

        $failMenuDelete = true;
        DB::beforeExecuting(function (string $query) use (&$failMenuDelete): void {
            // 테이블 접두(DB_PREFIX)가 붙은 이름으로 나간다
            if ($failMenuDelete && preg_match('/^delete from [`"]?'.preg_quote(DB::getTablePrefix(), '/').'menus[`"]?\s/i', $query)) {
                throw new \RuntimeException('메뉴 삭제 실패(시험)');
            }
        });

        $thrown = null;
        try {
            $this->seed(CoreAdminMenuSeeder::class);
        } catch (\RuntimeException $e) {
            $thrown = $e->getMessage();
        } finally {
            $failMenuDelete = false;
        }
        $this->assertSame('메뉴 삭제 실패(시험)', $thrown, '메뉴 삭제 실패가 전파되지 않았다');

        $this->assertTrue(Menu::whereKey($child->id)->exists());
        $this->assertNotNull(Attachment::find($attachment->id), '메뉴는 남았는데 아이콘 첨부가 먼저 지워졌다');
        Storage::disk($attachment->disk)->assertExists($attachment->path);
    }
}
