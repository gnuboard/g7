<?php

namespace Tests\Unit\Rules;

use App\Enums\AttachmentSourceType;
use App\Enums\ExtensionOwnerType;
use App\Models\Attachment;
use App\Models\Menu;
use App\Models\User;
use App\Rules\MenuIconSpecRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * 메뉴 아이콘 구조형 값 검증 규칙(MenuIconSpecRule) 테스트.
 *
 * class·image(site·attachment·package)·svg 세 형의 형식·경로·첨부 소유·확장 이미지 읽기 전용·SVG 안전성을 판정하고,
 * 위반은 사유 문구로 실패시키는지 검증한다.
 */
class MenuIconSpecRuleTest extends TestCase
{
    use RefreshDatabase;

    /** @var string 테스트용 currentColor SVG */
    private const SAFE_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2 2 22h20z"/></svg>';

    /**
     * 규칙으로 값을 검증해 오류 문구 목록을 돌려줍니다.
     *
     * @param  mixed  $value  icon_spec 값
     * @param  Menu|null  $menu  수정 대상 메뉴 (생성이면 null)
     * @return list<string> 오류 문구 (통과면 빈 배열)
     */
    private function errors(mixed $value, ?Menu $menu = null): array
    {
        $validator = Validator::make(['icon_spec' => $value], ['icon_spec' => [new MenuIconSpecRule($menu)]]);

        return $validator->errors()->get('icon_spec');
    }

    /**
     * 메뉴 아이콘 컬렉션 첨부를 만듭니다.
     *
     * @param  array<string, mixed>  $attributes  덮을 속성
     * @return Attachment 첨부
     */
    private function iconAttachment(array $attributes = []): Attachment
    {
        return Attachment::factory()->create(array_merge([
            'collection' => 'menu_icons',
            'mime_type' => 'image/png',
            'source_type' => AttachmentSourceType::Core,
            'source_identifier' => null,
            'created_by' => $this->uploader()->id,
        ], $attributes));
    }

    /**
     * 현재 사용자(업로더)를 만들고 로그인 상태로 둡니다 — 미바인딩 첨부는 올린 사람만 묶을 수 있다.
     *
     * @return User 현재 사용자
     */
    private function uploader(): User
    {
        $this->uploader ??= User::factory()->create();
        $this->actingAs($this->uploader);

        return $this->uploader;
    }

    /** @var User|null 현재 사용자(업로더) */
    private ?User $uploader = null;

    /**
     * 네 형(class·site 이미지·미바인딩 첨부·svg)의 올바른 값은 통과하고, null 도 통과한다.
     *
     * @effects canonical_storage
     *
     * @return void 반환값 없음
     */
    public function test_valid_values_pass(): void
    {
        $attachment = $this->iconAttachment();

        $this->assertSame([], $this->errors(null));
        $this->assertSame([], $this->errors(['type' => 'class', 'value' => 'fas fa-users']));
        $this->assertSame([], $this->errors(['type' => 'image', 'source' => 'site', 'path' => '/qa/a.png', 'url' => '/ignored']));
        $this->assertSame([], $this->errors(['type' => 'image', 'source' => 'attachment', 'attachment_id' => $attachment->id, 'hash' => 'forged']));
        $this->assertSame([], $this->errors(['type' => 'svg', 'value' => self::SAFE_SVG]));
    }

    /**
     * 형식 오류 — 배열 아님·형 없음·class 형식·길이·출처 없음·svg 비어 있음.
     *
     * @effects canonical_storage
     *
     * @return void 반환값 없음
     */
    public function test_format_errors_fail(): void
    {
        $this->assertSame([__('validation.menu.icon_spec.array')], $this->errors('fas fa-users'));
        $this->assertSame([__('validation.menu.icon_spec.invalid_type')], $this->errors(['type' => 'video']));
        $this->assertSame([__('validation.menu.icon_spec.class_invalid')], $this->errors(['type' => 'class', 'value' => '<i>x</i>']));
        $this->assertSame([__('validation.menu.icon_spec.class_invalid')], $this->errors(['type' => 'class']));
        $this->assertSame([__('validation.menu.icon_spec.class_max', ['max' => 100])], $this->errors(['type' => 'class', 'value' => str_repeat('a', 101)]));
        $this->assertSame([__('validation.menu.icon_spec.image_source_invalid')], $this->errors(['type' => 'image', 'path' => '/a.png']));
        $this->assertSame([__('validation.menu.icon_spec.svg_required')], $this->errors(['type' => 'svg', 'value' => '  ']));
        $this->assertSame([__('validation.menu.icon_spec.site_path_invalid')], $this->errors(['type' => 'image', 'source' => 'site', 'path' => '/a/../b.png']));
    }

    /**
     * 외부 주소(http·https·//·data:)는 external_url 문구로 거부되고, SVG 확장자 이미지는 확장자 문구로 거부된다.
     *
     * @effects external_url_rejected
     *
     * @return void 반환값 없음
     */
    public function test_external_url_and_svg_image_fail(): void
    {
        foreach (['http://example.com/a.png', 'https://example.com/a.png', '//example.com/a.png', 'data:image/png;base64,AAA'] as $path) {
            $this->assertSame([__('validation.menu.icon_spec.external_url')], $this->errors(['type' => 'image', 'source' => 'site', 'path' => $path]), $path);
        }

        $errors = $this->errors(['type' => 'image', 'source' => 'site', 'path' => '/qa/a.svg']);
        $this->assertSame([__('validation.menu.icon_spec.image_extension_invalid', ['extensions' => 'png, webp, jpg, jpeg, gif'])], $errors);
    }

    /**
     * 공백이 든 사이트 안 경로는 「외부 주소」가 아니라 경로 형식 문구로 거부된다.
     *
     * @effects external_url_rejected
     *
     * @return void 반환값 없음
     */
    public function test_site_path_with_whitespace_fails_with_path_message(): void
    {
        $errors = $this->errors(['type' => 'image', 'source' => 'site', 'path' => '/qa/my icon.png']);

        $this->assertSame([__('validation.menu.icon_spec.site_path_invalid')], $errors);
    }

    /**
     * XML 선언만 있어 정규화하면 비는 SVG 코드는 「안전하지 않은 SVG」가 아니라 입력 요구 문구로 거부된다.
     *
     * @effects unsafe_svg_rejected
     *
     * @return void 반환값 없음
     */
    public function test_svg_with_only_xml_declaration_requires_code(): void
    {
        foreach (['<?xml version="1.0" encoding="UTF-8"?>', "\u{FEFF}<?xml version=\"1.0\"?>\n  "] as $svg) {
            $this->assertSame([__('validation.menu.icon_spec.svg_required')], $this->errors(['type' => 'svg', 'value' => $svg]));
        }
    }

    /**
     * 다른 컬렉션·확장 출처·비이미지·없는 첨부는 쓸 수 없다.
     *
     * @effects attachment_bound
     *
     * @return void 반환값 없음
     */
    public function test_attachment_of_other_collection_or_source_fails(): void
    {
        $otherCollection = $this->iconAttachment(['collection' => 'default']);
        $extensionOwned = $this->iconAttachment(['source_type' => AttachmentSourceType::Module, 'source_identifier' => 'sirsoft-board']);
        $notImage = $this->iconAttachment(['mime_type' => 'image/svg+xml']);

        foreach ([$otherCollection->id, $extensionOwned->id, $notImage->id, 999999, 'abc'] as $id) {
            $this->assertSame(
                [__('validation.menu.icon_spec.attachment_invalid')],
                $this->errors(['type' => 'image', 'source' => 'attachment', 'attachment_id' => $id]),
                (string) $id
            );
        }
    }

    /**
     * 다른 메뉴에 묶인 첨부는 거부되고, 같은 메뉴에 묶인 첨부는 통과한다.
     *
     * @effects attachment_bound
     *
     * @return void 반환값 없음
     */
    public function test_attachment_bound_to_another_menu_fails(): void
    {
        $owner = Menu::factory()->create();
        $other = Menu::factory()->create();
        $attachment = $this->iconAttachment(['attachmentable_type' => Menu::class, 'attachmentable_id' => $owner->id]);
        $value = ['type' => 'image', 'source' => 'attachment', 'attachment_id' => $attachment->id];

        $this->assertSame([__('validation.menu.icon_spec.attachment_invalid')], $this->errors($value, $other));
        $this->assertSame([__('validation.menu.icon_spec.attachment_invalid')], $this->errors($value));
        $this->assertSame([], $this->errors($value, $owner));
    }

    /**
     * 다른 사용자가 올린 미바인딩 첨부는 거부되고, 업로더가 없는 첨부도 거부된다.
     *
     * @effects attachment_bound
     *
     * @return void 반환값 없음
     */
    public function test_unbound_attachment_of_another_uploader_fails(): void
    {
        $someoneElse = User::factory()->create();
        $theirs = $this->iconAttachment(['created_by' => $someoneElse->id]);
        $orphan = $this->iconAttachment(['created_by' => null]);

        $this->assertSame([__('validation.menu.icon_spec.attachment_invalid')], $this->errors(['type' => 'image', 'source' => 'attachment', 'attachment_id' => $theirs->id]));
        $this->assertSame([__('validation.menu.icon_spec.attachment_invalid')], $this->errors(['type' => 'image', 'source' => 'attachment', 'attachment_id' => $orphan->id]));
    }

    /**
     * 확장 이미지(package)는 생성에서 거부, 수정에서 현재값 그대로면 통과, 바꾸면 거부된다.
     *
     * @effects package_image_read_only
     *
     * @return void 반환값 없음
     */
    public function test_package_image_is_read_only(): void
    {
        $value = ['type' => 'image', 'source' => 'package', 'path' => 'assets/menu.png'];
        $menu = Menu::factory()->create([
            'icon' => $value,
            'extension_type' => ExtensionOwnerType::Module,
            'extension_identifier' => 'vendor-probe',
        ]);
        $plain = Menu::factory()->create(['icon' => 'fas fa-users']);

        $this->assertSame([__('validation.menu.icon_spec.package_readonly')], $this->errors($value));
        $this->assertSame([], $this->errors($value + ['url' => '/x'], $menu));
        $this->assertSame([__('validation.menu.icon_spec.package_readonly')], $this->errors(['type' => 'image', 'source' => 'package', 'path' => 'assets/other.png'], $menu));
        $this->assertSame([__('validation.menu.icon_spec.package_readonly')], $this->errors($value, $plain));
    }

    /**
     * 수정에서 현재 저장값을 그대로 되돌려 보내면 형식 검사 없이 통과한다 — 예전 API 로 저장된 비클래스 문자열
     * (이미지 주소 등)이 남은 메뉴도 이름만 바꿔 저장할 수 있어야 한다. 값을 바꾸거나 생성이면 종전대로 검사한다.
     *
     * @effects unchanged_stored_icon_passes
     *
     * @return void 반환값 없음
     */
    public function test_unchanged_stored_icon_passes_on_update(): void
    {
        $menu = Menu::factory()->create();
        Menu::whereKey($menu->id)->toBase()->update(['icon' => 'https://cdn.example.com/a.png']);
        $menu->refresh();
        $echo = ['type' => 'class', 'value' => 'https://cdn.example.com/a.png'];

        $this->assertSame([], $this->errors($echo, $menu));
        $this->assertSame([], $this->errors($echo + ['url' => '/x'], $menu));
        $this->assertSame([__('validation.menu.icon_spec.class_invalid')], $this->errors($echo));
        $this->assertSame([__('validation.menu.icon_spec.class_invalid')], $this->errors(['type' => 'class', 'value' => 'https://cdn.example.com/b.png'], $menu));
    }

    /**
     * 위험한 SVG 는 사유(요소 이름)를 담은 문구로, 32KB 초과는 크기 문구로 거부된다.
     *
     * @effects unsafe_svg_rejected
     *
     * @return void 반환값 없음
     */
    public function test_unsafe_svg_message_contains_reason(): void
    {
        $errors = $this->errors(['type' => 'svg', 'value' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><script>alert(1)</script><style>a{}</style></svg>']);

        $this->assertCount(1, $errors);
        $this->assertSame(__('validation.menu.icon_spec.svg_unsafe', ['reasons' => 'script, style']), $errors[0]);
        $this->assertStringContainsString('script', $errors[0]);

        $big = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'.str_repeat('<path d="M1 1h2v2H1z"/>', 2000).'</svg>';
        $this->assertSame([__('validation.menu.icon_spec.svg_too_large', ['max' => 32])], $this->errors(['type' => 'svg', 'value' => $big]));
    }
}
