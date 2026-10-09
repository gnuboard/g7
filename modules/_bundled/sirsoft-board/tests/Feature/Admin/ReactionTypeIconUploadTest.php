<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

use App\Models\Attachment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Database\Seeders\BoardReactionTypeSeeder;
use Modules\Sirsoft\Board\Models\ReactionType;
use Modules\Sirsoft\Board\Services\ReactionTypeService;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 반응 유형 아이콘 이미지 업로드 Feature 테스트.
 *
 * 아이콘은 이미지 업로드만 지원하며(결정 R4), SVG 는 제외한다(결정 R13 —
 * 첨부 다운로드가 인증 없이 인라인 스트리밍이라 스크립트 실행 위험).
 * 업로드 응답은 코어 <file-uploader> 계약을 따라야 컴포넌트가 그대로 붙는다.
 */
class ReactionTypeIconUploadTest extends BoardTestCase
{
    private const UPLOAD_ENDPOINT = '/api/modules/sirsoft-board/admin/reaction-types/icons';

    private User $admin;

    /**
     * 각 테스트 전에 공통 픽스처를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdminUser([
            'sirsoft-board.settings.read',
            'sirsoft-board.settings.update',
        ]);
        // 반응 유형 이름은 사이트 기본 언어 값이 필수다 — 픽스처는 한국어 이름을 싣는다
        $this->admin->forceFill(['language' => 'ko'])->save();

        DB::table('board_reactions')->delete();
        ReactionType::query()->delete();
        $this->seed(BoardReactionTypeSeeder::class);
    }

    /**
     * settings.update 권한이 없으면 업로드가 막힌다.
     *
     * @scenario case=icon_upload_without_permission_forbidden
     *
     * @effects icon_upload_requires_permission
     *
     * @return void 반환값 없음
     */
    public function test_upload_requires_settings_update_permission(): void
    {
        // createAdminUser 는 공용 admin 역할에 권한을 붙이므로 "read 만" 을 만들 수 없다.
        // 전용 역할을 쓰면 admin 미들웨어는 통과하면서 settings.update 만 빠진 상태가 된다.
        $viewer = $this->createSettingsViewer();

        $this->actingAs($viewer)
            ->postJson(self::UPLOAD_ENDPOINT, [
                'file' => UploadedFile::fake()->image('icon.png', 32, 32),
            ])
            ->assertForbidden();
    }

    /**
     * 이미지 업로드는 file-uploader 계약 형태로 201 을 돌려준다.
     *
     * @scenario case=icon_upload_png_returns_uploader_shape
     *
     * @effects icon_upload_uses_module_endpoints, icon_upload_sets_icon_url_and_attachment_id
     *
     * @return void 반환값 없음
     */
    public function test_upload_image_returns_file_uploader_shape(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson(self::UPLOAD_ENDPOINT, [
                'file' => UploadedFile::fake()->image('icon.png', 32, 32),
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.data.is_image', true)
            ->assertJsonPath('data.data.order', 1)
            ->assertJsonStructure([
                'data' => [
                    'data' => ['id', 'hash', 'original_filename', 'mime_type', 'size', 'url', 'download_url'],
                ],
            ]);

        $hash = $response->json('data.data.hash');
        $this->assertSame('/api/attachment/'.$hash, $response->json('data.data.url'));
        $this->assertSame($response->json('data.data.url'), $response->json('data.data.download_url'));

        $attachment = Attachment::find($response->json('data.data.id'));
        $this->assertNotNull($attachment);
        $this->assertSame(ReactionTypeService::ICON_COLLECTION, $attachment->collection);
        $this->assertSame('sirsoft-board', $attachment->source_identifier);
    }

    /**
     * SVG·비이미지·용량 초과는 422 로 거부된다.
     *
     * @scenario case=icon_upload_rejects_svg_wrong_mime_and_oversize
     *
     * @effects icon_upload_rejects_svg_and_wrong_mime_422, icon_upload_rejects_oversize_422
     *
     * @return void 반환값 없음
     */
    public function test_upload_rejects_svg_non_image_and_oversize(): void
    {
        // SVG — 이미지로 통과하더라도 mimes 화이트리스트에 없어 거부된다.
        $this->actingAs($this->admin)
            ->postJson(self::UPLOAD_ENDPOINT, [
                'file' => UploadedFile::fake()->create('icon.svg', 4, 'image/svg+xml'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);

        // 비이미지
        $this->actingAs($this->admin)
            ->postJson(self::UPLOAD_ENDPOINT, [
                'file' => UploadedFile::fake()->create('note.txt', 4, 'text/plain'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);

        // 1MB 초과
        $this->actingAs($this->admin)
            ->postJson(self::UPLOAD_ENDPOINT, [
                'file' => UploadedFile::fake()->create('big.png', 2048, 'image/png'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    /**
     * 업로드한 아이콘은 비로그인 사용자도 내려받을 수 있다 (반응 버튼은 공개 화면에 있다).
     *
     * @scenario case=icon_url_downloadable_by_guest
     *
     * @effects icon_url_public_download
     *
     * @return void 반환값 없음
     */
    public function test_icon_url_is_publicly_downloadable_by_guest(): void
    {
        $hash = $this->actingAs($this->admin)
            ->postJson(self::UPLOAD_ENDPOINT, [
                'file' => UploadedFile::fake()->image('icon.png', 32, 32),
            ])
            ->json('data.data.hash');

        // 인증 헤더 없이 접근
        $this->get('/api/attachment/'.$hash)->assertOk();
    }

    /**
     * 설정 저장이 아이콘을 유형에 연결하고, 교체된 옛 아이콘은 삭제한다.
     *
     * @scenario case=icon_bound_on_save_and_replaced_icon_deleted
     *
     * @effects icon_upload_sets_icon_url_and_attachment_id, icon_remove_clears_icon_fields
     *
     * @return void 반환값 없음
     */
    public function test_sync_binds_icon_attachmentable_and_replacing_icon_deletes_old_file(): void
    {
        $like = ReactionType::where('code', 'like')->firstOrFail();

        $firstId = $this->actingAs($this->admin)
            ->postJson(self::UPLOAD_ENDPOINT, ['file' => UploadedFile::fake()->image('first.png', 32, 32)])
            ->json('data.data.id');

        $this->actingAs($this->admin)->putJson('/api/modules/sirsoft-board/admin/settings', [
            '_tab' => 'basic_defaults',
            'basic_defaults' => [
                'reaction_types' => [
                    ['id' => $like->id, 'code' => 'like', 'name' => ['ko' => '추천'], 'icon_attachment_id' => $firstId, 'is_active' => true],
                ],
            ],
        ])->assertOk();

        $this->assertSame($firstId, $like->fresh()->icon_attachment_id);

        $bound = Attachment::find($firstId);
        $this->assertSame(ReactionType::class, $bound->attachmentable_type, '저장 시 첨부가 유형에 연결되어야 합니다.');
        $this->assertSame($like->id, (int) $bound->attachmentable_id);

        // 아이콘이 있으면 icon_url 이 채워진다 (FA 폴백보다 우선).
        $this->assertSame('/api/attachment/'.$bound->hash, $like->fresh()->getIconUrl());

        // 교체 — 옛 첨부는 고아가 되므로 지워져야 한다.
        $secondId = $this->actingAs($this->admin)
            ->postJson(self::UPLOAD_ENDPOINT, ['file' => UploadedFile::fake()->image('second.png', 32, 32)])
            ->json('data.data.id');

        $this->actingAs($this->admin)->putJson('/api/modules/sirsoft-board/admin/settings', [
            '_tab' => 'basic_defaults',
            'basic_defaults' => [
                'reaction_types' => [
                    ['id' => $like->id, 'code' => 'like', 'name' => ['ko' => '추천'], 'icon_attachment_id' => $secondId, 'is_active' => true],
                ],
            ],
        ])->assertOk();

        $this->assertSame($secondId, $like->fresh()->icon_attachment_id);
        $this->assertNull(Attachment::find($firstId), '교체된 옛 아이콘 첨부는 정리되어야 합니다.');
    }

    /**
     * 아이콘 삭제 엔드포인트는 이 모듈이 만든 첨부만 지운다.
     *
     * @scenario case=icon_delete_foreign_collection_rejected
     *
     * @effects icon_delete_rejects_foreign_collection
     *
     * @return void 반환값 없음
     */
    public function test_destroy_icon_rejects_foreign_collection(): void
    {
        $foreign = Attachment::create([
            'hash' => substr(md5('foreign'.uniqid()), 0, 12),
            'original_filename' => 'foreign.png',
            'stored_filename' => 'foreign.png',
            'path' => 'foreign/foreign.png',
            'mime_type' => 'image/png',
            'size' => 100,
            'collection' => 'some_other_collection',
            'disk' => config('filesystems.default'),
        ]);

        $this->actingAs($this->admin)
            ->deleteJson(self::UPLOAD_ENDPOINT.'/'.$foreign->id)
            ->assertNotFound();

        $this->assertNotNull(Attachment::find($foreign->id), '다른 collection 의 첨부는 지워지지 않아야 합니다.');
    }

    /**
     * 아이콘 첨부를 지우면 유형의 icon_attachment_id 가 NULL 로 풀린다.
     *
     * @scenario case=icon_delete_clears_type_reference
     *
     * @effects icon_remove_clears_icon_fields
     *
     * @return void 반환값 없음
     */
    public function test_deleting_attachment_row_nulls_icon_attachment_id(): void
    {
        $like = ReactionType::where('code', 'like')->firstOrFail();

        $attachmentId = $this->actingAs($this->admin)
            ->postJson(self::UPLOAD_ENDPOINT, ['file' => UploadedFile::fake()->image('icon.png', 32, 32)])
            ->json('data.data.id');

        $this->actingAs($this->admin)->putJson('/api/modules/sirsoft-board/admin/settings', [
            '_tab' => 'basic_defaults',
            'basic_defaults' => [
                'reaction_types' => [
                    ['id' => $like->id, 'code' => 'like', 'name' => ['ko' => '추천'], 'icon_attachment_id' => $attachmentId, 'is_active' => true],
                ],
            ],
        ])->assertOk();

        $this->actingAs($this->admin)
            ->deleteJson(self::UPLOAD_ENDPOINT.'/'.$attachmentId)
            ->assertOk();

        $this->assertNull($like->fresh()->icon_attachment_id, '아이콘을 지우면 유형의 참조도 풀려야 합니다.');
        $this->assertNull($like->fresh()->getIconUrl());
        $this->assertSame('fa-thumbs-up', $like->fresh()->getIconName(), '이미지가 없으면 시드 FA 클래스로 폴백합니다.');
    }

    /**
     * settings.read 만 가진 운영자를 만듭니다.
     *
     * createAdminUser 는 공용 admin 역할에 권한을 붙이므로 "read 만" 을 만들 수 없다.
     * 전용 역할을 쓰면 admin 미들웨어(관리자 타입 권한 보유 여부)는 통과하면서
     * settings.update 만 빠진 상태를 정확히 재현한다.
     *
     * @return User 조회 전용 운영자
     */
    private function createSettingsViewer(): User
    {
        $role = Role::firstOrCreate(
            ['identifier' => 'sirsoft-board.settings-viewer-test'],
            [
                'name' => ['ko' => '환경설정 조회 전용', 'en' => 'Settings Viewer'],
                'description' => ['ko' => '테스트용', 'en' => 'For tests'],
            ]
        );

        $permission = Permission::firstOrCreate(
            ['identifier' => 'sirsoft-board.settings.read'],
            [
                'name' => ['ko' => '환경설정 조회', 'en' => 'View Settings'],
                'type' => 'admin',
            ]
        );

        $role->permissions()->syncWithoutDetaching([$permission->id]);

        $user = User::factory()->create();
        $user->roles()->attach($role->id);

        return $user;
    }
}
