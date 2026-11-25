<?php

namespace Tests\Feature\Menu;

use App\Contracts\Repositories\AttachmentRepositoryInterface;
use App\Contracts\Repositories\MenuRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Extension\Helpers\ExtensionMenuSyncHelper;
use App\Http\Resources\MenuResource;
use App\Models\Attachment;
use App\Models\Menu;
use App\Services\MenuIconService;
use App\Services\MenuService;
use App\Support\MenuIcon\MenuIconDeclaration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

/**
 * 메뉴 아이콘 첨부 수명·확장 선언 이미지 표시 통합 테스트 (#381).
 *
 * 업로드 첨부는 저장 전에는 미바인딩(고아 후보)이고, 메뉴에 저장되면 묶이며, 메뉴가 지워지면(서비스·확장 정리 경로)
 * 커밋 뒤에 함께 지워진다. 롤백되면 파일이 남는다. 확장 선언 이미지는 확장 자산 URL 로 표시된다.
 */
class MenuIconAttachmentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    /** @var string 확장 식별자 */
    private const EXTENSION = 'vendor-probe';

    /** @var string 픽스처 패키지 루트 */
    private string $root;

    /**
     * 첨부 디스크를 가짜로 바꾸고 확장 패키지 픽스처 루트를 준비한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('attachment.disk', 'attachments'));

        $this->root = storage_path('framework/testing/menu-icon-lifecycle');
        File::deleteDirectory($this->root);
        File::ensureDirectoryExists($this->root.'/assets');
        File::put($this->root.'/assets/menu.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
        $root = $this->root;
        MenuIconDeclaration::resolveRootUsing(static fn (): string => $root);
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
     * 래스터 아이콘을 업로드해 icon_spec 을 받습니다.
     *
     * @param  string  $name  파일 이름
     * @return array<string, mixed> 업로드 응답 icon_spec
     */
    private function uploadIcon(string $name = 'menu.png'): array
    {
        return app(MenuIconService::class)->upload(UploadedFile::fake()->image($name, 16, 16));
    }

    /**
     * 확장 메뉴 하나를 동기화로 만듭니다.
     *
     * @param  string  $slug  슬러그
     * @param  mixed  $icon  선언 아이콘
     * @return Menu 메뉴
     */
    private function extensionMenu(string $slug, mixed $icon = 'fas fa-circle'): Menu
    {
        return app(ExtensionMenuSyncHelper::class)->syncMenu(
            $slug,
            ExtensionOwnerType::Module,
            self::EXTENSION,
            ['name' => ['ko' => $slug, 'en' => $slug], 'url' => '/admin/'.$slug, 'icon' => $icon, 'order' => 1, 'is_active' => true],
        );
    }

    /**
     * 메뉴에 업로드 아이콘을 저장(바인딩)하고 첨부를 돌려줍니다.
     *
     * @param  Menu  $menu  대상 메뉴
     * @return Attachment 바인딩된 첨부
     */
    private function bindUploadedIcon(Menu $menu): Attachment
    {
        $spec = $this->uploadIcon();
        app(MenuService::class)->updateMenu($menu->fresh(), [
            'icon_spec' => ['type' => 'image', 'source' => 'attachment', 'attachment_id' => $spec['attachment_id']],
        ]);

        return Attachment::findOrFail($spec['attachment_id']);
    }

    /**
     * 저장하지 않은 업로드는 미바인딩 첨부(고아 후보)로 남고, 응답은 서버 해시 url 을 싣는다.
     *
     * @scenario icon_type=image_attachment, entry=upload
     *
     * @effects unsaved_upload_orphan, url_presented
     *
     * @return void 반환값 없음
     */
    public function test_unsaved_upload_stays_unbound(): void
    {
        $spec = $this->uploadIcon();
        $attachment = Attachment::findOrFail($spec['attachment_id']);

        $this->assertSame('menu_icons', $attachment->collection);
        $this->assertNull($attachment->attachmentable_type);
        $this->assertNull($attachment->attachmentable_id);
        $this->assertSame($attachment->hash, $spec['hash']);
        $this->assertStringEndsWith('/api/attachment/'.$attachment->hash, $spec['url']);
        Storage::disk($attachment->disk)->assertExists($attachment->path);
    }

    /**
     * 메뉴에 저장한 업로드 첨부는 그 메뉴에 묶여 고아 후보가 아니다.
     *
     * @scenario icon_type=image_attachment, entry=api_create
     *
     * @effects attachment_bound
     *
     * @return void 반환값 없음
     */
    public function test_saved_upload_is_bound_and_not_orphan(): void
    {
        $spec = $this->uploadIcon();
        $menu = app(MenuService::class)->createMenu([
            'name' => ['ko' => '업로드', 'en' => 'Upload'],
            'slug' => 'uploaded-icon',
            'url' => '/admin/uploaded-icon',
            'icon_spec' => ['type' => 'image', 'source' => 'attachment', 'attachment_id' => $spec['attachment_id'], 'hash' => 'forged'],
        ]);

        $attachment = Attachment::findOrFail($spec['attachment_id']);
        $this->assertSame(Menu::class, $attachment->attachmentable_type);
        $this->assertSame($menu->id, (int) $attachment->attachmentable_id);
        $this->assertSame($attachment->hash, $menu->fresh()->iconSpec()?->hash);
        $this->assertSame(0, Attachment::where('collection', 'menu_icons')->whereNull('attachmentable_id')->count());
    }

    /**
     * 확장 정리 경로(deleteByExtension)로 메뉴가 지워지면 묶인 아이콘 첨부도 지워진다.
     *
     * @effects menu_delete_purges_attachment
     *
     * @return void 반환값 없음
     */
    public function test_delete_by_extension_purges_bound_attachment(): void
    {
        $menu = $this->extensionMenu('purge-by-extension');
        $attachment = $this->bindUploadedIcon($menu);

        app(MenuRepositoryInterface::class)->deleteByExtension(ExtensionOwnerType::Module, self::EXTENSION);

        $this->assertNull(Menu::find($menu->id));
        $this->assertNull(Attachment::find($attachment->id), '확장 제거로 메뉴가 지워졌는데 아이콘 첨부가 남았다');
    }

    /**
     * 회귀(#381 validate-plan 6차 D-6c): 확장 정리 경로(deleteByExtension)가 트랜잭션 밖에서 불리면 커밋 뒤 예약이 곧바로
     * 실행된다. 첨부 정리를 메뉴 삭제보다 먼저 예약하면, 메뉴 삭제가 실패해 메뉴는 남아도 아이콘 첨부·파일이 먼저 사라졌다.
     *
     * @scenario icon_type=image_attachment, entry=extension_sync
     *
     * @effects menu_delete_purges_attachment
     *
     * @return void 반환값 없음
     */
    public function test_delete_by_extension_keeps_attachment_when_menu_delete_fails(): void
    {
        $menu = $this->extensionMenu('purge-fails');
        $attachment = $this->bindUploadedIcon($menu);

        $failMenuDelete = true;
        DB::beforeExecuting(function (string $query) use (&$failMenuDelete): void {
            // 테이블 접두(DB_PREFIX)가 붙은 이름으로 나간다
            if ($failMenuDelete && preg_match('/^delete from [`"]?'.preg_quote(DB::getTablePrefix(), '/').'menus[`"]?\s/i', $query)) {
                throw new RuntimeException('메뉴 삭제 실패(시험)');
            }
        });

        $thrown = null;
        try {
            app(MenuRepositoryInterface::class)->deleteByExtension(ExtensionOwnerType::Module, self::EXTENSION);
        } catch (RuntimeException $e) {
            $thrown = $e->getMessage();
        } finally {
            $failMenuDelete = false;
        }
        $this->assertSame('메뉴 삭제 실패(시험)', $thrown, '메뉴 삭제 실패가 전파되지 않았다');

        $this->assertNotNull(Menu::find($menu->id));
        $this->assertNotNull(Attachment::find($attachment->id), '메뉴는 남았는데 아이콘 첨부가 먼저 지워졌다');
        Storage::disk($attachment->disk)->assertExists($attachment->path);
    }

    /**
     * 선언에서 빠진 확장 메뉴 정리(cleanupStaleMenus)도 묶인 아이콘 첨부를 지운다.
     *
     * @effects menu_delete_purges_attachment
     *
     * @return void 반환값 없음
     */
    public function test_stale_cleanup_purges_bound_attachment(): void
    {
        $menu = $this->extensionMenu('purge-stale');
        $attachment = $this->bindUploadedIcon($menu);

        $deleted = app(ExtensionMenuSyncHelper::class)->cleanupStaleMenus(ExtensionOwnerType::Module, self::EXTENSION, []);

        $this->assertSame(1, $deleted);
        $this->assertNull(Attachment::find($attachment->id));
        Storage::disk($attachment->disk)->assertMissing($attachment->path);
    }

    /**
     * 메뉴 삭제 트랜잭션이 롤백되면 아이콘 첨부와 파일은 남는다(커밋 뒤 삭제).
     *
     * @effects menu_delete_purges_attachment
     *
     * @return void 반환값 없음
     */
    public function test_rolled_back_delete_keeps_attachment(): void
    {
        $menu = $this->extensionMenu('rollback');
        $attachment = $this->bindUploadedIcon($menu);

        try {
            DB::transaction(function () use ($menu): void {
                $menu->fresh()->delete();

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
            // 롤백 유도
        }

        $this->assertNotNull(Menu::find($menu->id));
        $this->assertNotNull(Attachment::find($attachment->id));
        Storage::disk($attachment->disk)->assertExists($attachment->path);

        // 커밋되는 삭제는 첨부까지 지운다
        app(MenuService::class)->deleteMenu($menu->fresh());
        $this->assertNull(Attachment::find($attachment->id));
    }

    /**
     * 확장 선언 이미지는 package 출처로 저장되고 응답 url 은 모듈 자산 URL 이다.
     *
     * @scenario icon_type=image_package, entry=extension_sync
     *
     * @effects url_presented, canonical_storage
     *
     * @return void 반환값 없음
     */
    public function test_extension_image_is_presented_with_module_asset_url(): void
    {
        $menu = $this->extensionMenu('declared-image', ['type' => 'image', 'path' => 'assets/menu.png']);

        $this->assertSame('{"path":"assets/menu.png","source":"package","type":"image"}', $menu->fresh()->getRawOriginal('icon'));

        $payload = (new MenuResource($menu->fresh()))->toArray(Request::create('/'));
        $this->assertNull($payload['icon']);
        $this->assertSame('package', $payload['icon_spec']['source']);
        $this->assertStringContainsString('/api/modules/assets/'.self::EXTENSION, $payload['icon_spec']['url']);
        $this->assertTrue(
            str_contains($payload['icon_spec']['url'], 'assets/menu.png') || str_contains($payload['icon_spec']['url'], rawurlencode('assets/menu.png')),
            $payload['icon_spec']['url']
        );
    }

    /**
     * 서비스 수정으로 사이트 이미지 경로를 저장하면 정규 JSON 으로 남고 url 이 표시된다.
     *
     * @scenario icon_type=image_site, entry=api_update
     *
     * @effects canonical_storage, url_presented, change_marked
     *
     * @return void 반환값 없음
     */
    public function test_service_update_with_site_image(): void
    {
        $menu = $this->extensionMenu('site-image');

        app(MenuService::class)->updateMenu($menu->fresh(), [
            'icon_spec' => ['type' => 'image', 'source' => 'site', 'path' => '/qa/a.png', 'url' => 'https://evil.example/x.png'],
        ]);

        $fresh = $menu->fresh();
        $this->assertSame('{"path":"/qa/a.png","source":"site","type":"image"}', $fresh->getRawOriginal('icon'));
        $this->assertContains('icon', $fresh->user_overrides ?? []);
        $payload = (new MenuResource($fresh))->toArray(Request::create('/'));
        $this->assertStringEndsWith('/qa/a.png', $payload['icon_spec']['url']);
        $this->assertStringNotContainsString('evil', $payload['icon_spec']['url']);
    }

    /**
     * 게시판 모듈처럼 서비스 생성에 FA 클래스 문자열을 넘기면 그대로 class 로 저장된다.
     *
     * @scenario icon_type=legacy_string, entry=api_create
     *
     * @effects canonical_storage, legacy_icon_exposed
     *
     * @return void 반환값 없음
     */
    public function test_service_create_with_class_string_keeps_class(): void
    {
        $menu = app(MenuService::class)->createMenu([
            'name' => ['ko' => '게시판', 'en' => 'Board'],
            'slug' => 'board-like',
            'url' => '/admin/board/notice',
            'icon' => 'fas fa-clipboard-list',
        ]);

        $fresh = $menu->fresh();
        $this->assertSame('fas fa-clipboard-list', $fresh->getRawOriginal('icon'));
        $payload = (new MenuResource($fresh))->toArray(Request::create('/'));
        $this->assertSame('fas fa-clipboard-list', $payload['icon']);
        $this->assertSame(['type' => 'class', 'value' => 'fas fa-clipboard-list'], $payload['icon_spec']);
    }

    /**
     * 회귀(#381 validate-plan 5차 2회차 ①): 같은 미바인딩 업로드로 메뉴 두 개를 동시에 저장하면 둘 다 규칙을 통과하고
     * 묶기는 먼저 온 쪽만 되어, 나중 메뉴는 남의 첨부를 아이콘으로 쥔 채 저장됐다(먼저 메뉴가 아이콘을 바꾸면 깨짐).
     * 규칙 통과 뒤 묶기에서 진 쪽은 422(icon_spec)로 거절되고 그 저장은 통째로 되돌린다.
     *
     * @scenario icon_type=image_attachment, entry=api_create, entry=api_update
     *
     * @effects attachment_bound
     *
     * @return void 반환값 없음
     */
    public function test_attachment_bound_to_another_menu_is_not_shared(): void
    {
        $spec = $this->uploadIcon();
        $iconSpec = ['type' => 'image', 'source' => 'attachment', 'attachment_id' => $spec['attachment_id']];
        $first = app(MenuService::class)->createMenu(['name' => ['ko' => '먼저', 'en' => 'First'], 'slug' => 'race-first', 'url' => '/admin/race-first', 'icon_spec' => $iconSpec]);

        // 동시 요청에서 규칙은 이미 통과했다 — 서비스 단계의 묶기만 재현한다
        try {
            app(MenuService::class)->createMenu(['name' => ['ko' => '나중', 'en' => 'Second'], 'slug' => 'race-second', 'url' => '/admin/race-second', 'icon_spec' => $iconSpec]);
            $this->fail('남의 메뉴에 묶인 첨부로 생성이 통과했다');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('icon_spec', $e->errors());
        }
        $this->assertFalse(Menu::where('slug', 'race-second')->exists());

        $third = app(MenuService::class)->createMenu(['name' => ['ko' => '셋째', 'en' => 'Third'], 'slug' => 'race-third', 'url' => '/admin/race-third', 'icon' => 'fas fa-users']);
        $overridesBefore = $third->fresh()->user_overrides;
        try {
            app(MenuService::class)->updateMenu($third->fresh(), ['icon_spec' => $iconSpec]);
            $this->fail('남의 메뉴에 묶인 첨부로 수정이 통과했다');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('icon_spec', $e->errors());
        }
        $this->assertSame('fas fa-users', $third->fresh()->getRawOriginal('icon'));
        // 6차 D-6b: 되돌린 수정이 「운영자가 고친 아이콘」 표식만 남기면 확장 업데이트가 그 메뉴 아이콘을 다시 넣지 않는다
        $this->assertSame($overridesBefore, $third->fresh()->user_overrides);
        $this->assertNotContains('icon', $third->fresh()->user_overrides ?? []);

        $attachment = Attachment::findOrFail($spec['attachment_id']);
        $this->assertSame($first->id, (int) $attachment->attachmentable_id);
    }

    /**
     * 회귀(#381 validate-plan 5차 2회차 ②): 운영자 수정 표식이 없는 확장 메뉴의 업로드 아이콘을 확장 업데이트가 선언값으로
     * 바꾸면, 예전 업로드 첨부가 메뉴에 묶인 채 아무도 쓰지 않는 파일로 남았다. 동기화도 저장 경로처럼 이전 첨부를 지운다.
     *
     * @scenario icon_type=image_attachment, entry=extension_sync
     *
     * @effects attachment_replaced_deleted
     *
     * @return void 반환값 없음
     */
    public function test_extension_sync_replacing_uploaded_icon_deletes_old_attachment(): void
    {
        $menu = $this->extensionMenu('sync-replace');
        $attachment = $this->bindUploadedIcon($menu);
        // 표식 없는 상태(예: 옛 판에서 들어온 값) — 동기화가 아이콘을 덮을 수 있다
        Menu::whereKey($menu->id)->toBase()->update(['user_overrides' => null]);

        $this->extensionMenu('sync-replace', 'fas fa-star');

        $this->assertSame('fas fa-star', $menu->fresh()->getRawOriginal('icon'));
        $this->assertNull(Attachment::find($attachment->id));
        Storage::disk($attachment->disk)->assertMissing($attachment->path);
    }

    /**
     * 저장하지 않은 업로드는 실제 고아 정리 판정(findOrphanCandidates)의 후보이고, 메뉴에 묶인 첨부는 후보가 아니다.
     * (validate-plan 5차 2회차 시험 보강 — 미바인딩 여부만 보던 간접 단언을 판정 함수 직접 호출로)
     *
     * @scenario icon_type=image_attachment, entry=upload
     *
     * @effects unsaved_upload_orphan, attachment_bound
     *
     * @return void 반환값 없음
     */
    public function test_orphan_candidates_include_unsaved_upload_only(): void
    {
        $unsaved = $this->uploadIcon('unsaved.png');
        $saved = $this->uploadIcon('saved.png');
        app(MenuService::class)->createMenu([
            'name' => ['ko' => '저장', 'en' => 'Saved'], 'slug' => 'orphan-saved', 'url' => '/admin/orphan-saved',
            'icon_spec' => ['type' => 'image', 'source' => 'attachment', 'attachment_id' => $saved['attachment_id']],
        ]);
        Attachment::query()->whereKey([$unsaved['attachment_id'], $saved['attachment_id']])->toBase()->update(['created_at' => now()->subDays(40)]);

        $candidates = app(AttachmentRepositoryInterface::class)->findOrphanCandidates(now()->subDays(30), 100)->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains((int) $unsaved['attachment_id'], $candidates);
        $this->assertNotContains((int) $saved['attachment_id'], $candidates);
    }
}
