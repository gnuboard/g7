<?php

namespace Tests\Feature\Menu;

use App\Enums\AttachmentSourceType;
use App\Http\Requests\Menu\UploadMenuIconRequest;
use App\Models\Attachment;
use App\Models\Menu;
use App\Models\User;
use App\Services\MenuIconService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 메뉴 아이콘 서비스(MenuIconService) 계약 테스트 (#381).
 *
 * 업로드 분기(SVG 는 파일 없이 코드, 래스터는 첨부), 저장 변환(첨부 hash 를 DB 에서 다시 읽음·확장 이미지는 현재값),
 * 첨부 사용 가능 판정(컬렉션·출처·MIME·업로더·바인딩)을 서비스 단위로 잠근다.
 */
class MenuIconServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @var string 테스트용 currentColor SVG */
    private const SAFE_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2 2 22h20z"/></svg>';

    /**
     * 서비스 인스턴스를 꺼냅니다.
     *
     * @return MenuIconService 서비스
     */
    private function service(): MenuIconService
    {
        return app(MenuIconService::class);
    }

    /**
     * 현재 사용자가 올린 미바인딩 아이콘 첨부를 만듭니다.
     *
     * @param  array<string, mixed>  $attributes  덮을 속성
     * @return Attachment 첨부
     */
    private function ownAttachment(array $attributes = []): Attachment
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return Attachment::factory()->create(array_merge([
            'collection' => 'menu_icons',
            'mime_type' => 'image/png',
            'source_type' => AttachmentSourceType::Core,
            'source_identifier' => null,
            'created_by' => $user->id,
        ], $attributes));
    }

    /**
     * SVG 파일 업로드는 첨부를 만들지 않고 정규화한 인라인 코드를, 래스터는 첨부 spec 을 돌려준다.
     *
     * @scenario icon_type=svg, entry=upload
     *
     * @effects svg_upload_not_file, unsaved_upload_orphan
     *
     * @return void 반환값 없음
     */
    public function test_upload_branches_svg_inline_and_raster_attachment(): void
    {
        Storage::fake(config('attachment.disk', 'attachments'));
        $this->actingAs(User::factory()->create());

        $svg = $this->service()->upload(UploadedFile::fake()->createWithContent('i.svg', "\xEF\xBB\xBF".self::SAFE_SVG));
        $this->assertSame(['type' => 'svg', 'value' => self::SAFE_SVG], $svg);
        $this->assertSame(0, Attachment::where('collection', 'menu_icons')->count());

        $raster = $this->service()->upload(UploadedFile::fake()->image('i.png', 16, 16));
        $this->assertSame('attachment', $raster['source']);
        $attachment = Attachment::find($raster['attachment_id']);
        $this->assertNull($attachment->attachmentable_id);
        $this->assertStringEndsWith('/api/attachment/'.$attachment->hash, $raster['url']);
    }

    /**
     * 저장 변환은 클라이언트가 보낸 hash 를 버리고 DB 값을 쓰며, 배열이 아니거나 형식을 알 수 없으면 null 이다.
     *
     * @scenario icon_type=image_attachment, entry=api_create
     *
     * @effects canonical_storage, attachment_bound
     *
     * @return void 반환값 없음
     */
    public function test_to_storage_rereads_attachment_hash(): void
    {
        $attachment = $this->ownAttachment();

        $stored = $this->service()->toStorage(['type' => 'image', 'source' => 'attachment', 'attachment_id' => $attachment->id, 'hash' => 'forged'], null);

        $this->assertSame($attachment->hash, json_decode((string) $stored, true)['hash']);
        $this->assertNull($this->service()->toStorage(null, null));
        $this->assertNull($this->service()->toStorage(['type' => 'unknown'], null));
    }

    /**
     * 확장 이미지(package) 입력은 현재 저장값을 그대로 돌려준다(경로를 바꿔 보내도 현재값).
     *
     * @scenario icon_type=image_package, entry=api_update
     *
     * @effects package_image_read_only
     *
     * @return void 반환값 없음
     */
    public function test_to_storage_keeps_current_package_image(): void
    {
        $stored = '{"path":"assets/menu.png","source":"package","type":"image"}';
        $menu = Menu::factory()->create(['icon' => $stored]);

        $this->assertSame($stored, $this->service()->toStorage(['type' => 'image', 'source' => 'package', 'path' => 'assets/other.png'], $menu));
    }

    /**
     * 사용 가능 판정 — 남이 올린 미바인딩 첨부·다른 MIME·다른 컬렉션은 거부, 내 미바인딩·같은 메뉴 바인딩은 허용.
     *
     * @scenario icon_type=image_attachment, entry=api_update
     *
     * @effects attachment_bound
     *
     * @return void 반환값 없음
     */
    public function test_is_usable_attachment_conditions(): void
    {
        $mine = $this->ownAttachment();
        $this->assertTrue($this->service()->isUsableAttachment($mine->id, null));

        $svgMime = $this->ownAttachment(['mime_type' => 'image/svg+xml']);
        $this->assertFalse($this->service()->isUsableAttachment($svgMime->id, null));

        $otherCollection = $this->ownAttachment(['collection' => 'default']);
        $this->assertFalse($this->service()->isUsableAttachment($otherCollection->id, null));

        $current = auth()->id();
        $theirs = Attachment::factory()->create([
            'collection' => 'menu_icons', 'mime_type' => 'image/png', 'source_type' => AttachmentSourceType::Core,
            'source_identifier' => null, 'created_by' => User::factory()->create()->id,
        ]);
        $this->assertSame($current, auth()->id());
        $this->assertFalse($this->service()->isUsableAttachment($theirs->id, null));

        $menu = Menu::factory()->create();
        $bound = $this->ownAttachment(['attachmentable_type' => Menu::class, 'attachmentable_id' => $menu->id]);
        $this->assertTrue($this->service()->isUsableAttachment($bound->id, $menu->id));
        $this->assertFalse($this->service()->isUsableAttachment($bound->id, null));
        $this->assertFalse($this->service()->isUsableAttachment(999999, null));
    }

    /**
     * 회귀(#381 validate-plan 5차 2회차 ④): 일반 첨부 올리기(collection 을 menu_icons 로 지정)로 올린 1MB 넘는 이미지도
     * 다른 조건이 맞으면 메뉴 아이콘으로 쓸 수 있어, 아이콘 업로드의 1MB 상한을 우회했다. 쓸 수 있는 첨부에도 같은 상한을 건다.
     *
     * @scenario icon_type=image_attachment, entry=api_update
     *
     * @effects attachment_bound
     *
     * @return void 반환값 없음
     */
    public function test_attachment_over_upload_limit_is_not_usable(): void
    {
        $limit = MenuIconService::UPLOAD_MAX_KB * 1024;

        $atLimit = $this->ownAttachment(['size' => $limit]);
        $this->assertTrue($this->service()->isUsableAttachment($atLimit->id, null));

        $over = $this->ownAttachment(['size' => $limit + 1]);
        $this->assertFalse($this->service()->isUsableAttachment($over->id, null));
    }

    /**
     * 회귀(#381 validate-plan 6차 E-6a): 업로드 용량 오류 문구의 한도가 숫자(1024)로 고정돼, 공용 한도 상수를 바꾸면 규칙은
     * 바뀌는데 문구는 옛 숫자로 남았다. 문구의 한도도 규칙과 같은 상수에서 나온다.
     *
     * @scenario icon_type=image_attachment, entry=upload
     *
     * @effects upload_file_type_enforced
     *
     * @return void 반환값 없음
     */
    public function test_upload_size_message_uses_shared_limit(): void
    {
        $request = new UploadMenuIconRequest;
        $limit = MenuIconService::UPLOAD_MAX_KB;

        $this->assertContains('max:'.$limit, $request->rules()['file']);
        $this->assertSame(__('validation.menu.icon_upload.file_max', ['max' => $limit]), $request->messages()['file.max']);
        $this->assertStringContainsString((string) $limit, $request->messages()['file.max']);
    }
}
