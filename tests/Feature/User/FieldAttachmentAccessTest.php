<?php

namespace Tests\Feature\User;

use App\Contracts\Repositories\FieldDefinitionRepositoryInterface;
use App\Models\Attachment;
use App\Models\FieldDefinition;
use App\Models\Role;
use App\Models\User;
use App\Services\UserService;
use Database\Seeders\CoreFieldDefinitionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 회원의 파일·이미지 항목 — 올리기, 지우기, 열람 권한, 탈퇴와 삭제 때의 파일 정리.
 *
 * 파일 항목은 폼 저장이 아니라 전용 API 로 오간다. 올린 파일은 주소를 안다고 누구나 받을 수 없어야 한다.
 */
class FieldAttachmentAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private User $other;

    private User $admin;

    /**
     * 역할과 코어 회원 항목을 시딩하고 회원 둘과 최고관리자를 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(CoreFieldDefinitionSeeder::class);
        Storage::fake('attachments');

        $this->member = User::factory()->create();
        $this->other = User::factory()->create();
        $this->admin = User::factory()->create(['is_super' => true]);
        $adminRole = Role::where('identifier', 'admin')->first();
        if ($adminRole) {
            $this->admin->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        }
        $this->admin = $this->admin->fresh();
    }

    /**
     * 운영자 추가 항목을 만듭니다.
     *
     * @param  string  $key  항목 키
     * @param  string  $type  유형 키
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @return FieldDefinition 만든 항목
     */
    private function custom(string $key, string $type = 'file', array $attributes = []): FieldDefinition
    {
        $definition = FieldDefinition::create(array_merge([
            'field_set' => 'core.user', 'key' => $key, 'type' => $type, 'source_type' => 'custom',
            'source_identifier' => 'custom', 'storage' => 'value', 'name' => ['ko' => '시험 '.$key], 'sort_order' => 500,
            'enabled' => true,
        ], $attributes));
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();

        return $definition->refresh();
    }

    /**
     * 사용자의 토큰으로 요청을 보냅니다 (한 테스트에서 사용자를 바꿔 가며 부른다).
     *
     * @param  User|null  $user  요청자 (null 이면 비로그인)
     * @return static 테스트 케이스
     */
    private function as(?User $user): static
    {
        // 실제 화면처럼 Bearer 토큰으로 보낸다 — actingAs 는 공개 라우트(첨부 내려받기)에서도 사용자를 꽂아 줘서
        // 「토큰을 실어도 서버가 본인을 알아보지 못하는」 결함을 가린다.
        $this->app['auth']->forgetGuards();
        // 앞 요청의 인증 미들웨어가 바꿔 둔 기본 가드를 되돌린다 — 실제 요청은 매번 기본 가드(web)에서 시작한다.
        // 인증 미들웨어는 설정값(auth.defaults.guard)까지 sanctum 으로 바꾸므로 설정에서 읽지 않고 적는다.
        $this->app['auth']->shouldUse('web');
        $this->flushHeaders();

        return $user === null ? $this : $this->withHeader('Authorization', 'Bearer '.$user->createToken('t490')->plainTextToken);
    }

    /**
     * 본인 경로로 파일을 올립니다.
     *
     * @param  string  $key  항목 키
     * @param  UploadedFile  $file  올릴 파일
     * @param  User|null  $user  요청자 (기본은 회원)
     * @return TestResponse 응답
     */
    private function upload(string $key, UploadedFile $file, ?User $user = null): TestResponse
    {
        return $this->as($user ?? $this->member)->post("/api/me/fields/{$key}/file", ['file' => $file], ['Accept' => 'application/json']);
    }

    /**
     * 본인이 파일 항목에 파일을 올리면 내 정보 항목 조회와 내 정보 응답에 파일 목록으로 나타나고, 지우면 사라진다.
     *
     * @scenario case=self_upload_and_delete
     *
     * @effects file_field_round_trips_through_dedicated_api
     *
     * @return void 반환값 없음
     */
    public function test_member_uploads_and_deletes_own_file(): void
    {
        $this->custom('t490_doc', 'file', ['constraints' => ['max_count' => 2]]);

        $uploaded = $this->upload('t490_doc', UploadedFile::fake()->create('biz.pdf', 120, 'application/pdf'))->assertCreated();
        $attachmentId = $uploaded->json('data.file.id');
        $this->assertSame('biz.pdf', $uploaded->json('data.file.name'));
        $this->assertCount(1, $uploaded->json('data.files'));
        $this->assertDatabaseHas('attachments', ['id' => $attachmentId, 'attachmentable_id' => $this->member->id, 'collection' => 'field:t490_doc']);

        $fields = array_column($this->as($this->member)->getJson('/api/me/fields')->assertOk()->json('data.fields'), null, 'key');
        $this->assertSame('file', $fields['t490_doc']['renderer']);
        $this->assertSame([$attachmentId], array_column($fields['t490_doc']['files'], 'id'));
        $this->assertSame(2, $fields['t490_doc']['file_rules']['max_count']);
        $this->assertSame('biz.pdf', $fields['t490_doc']['display_value']);

        $profile = $this->as($this->member)->getJson('/api/me')->assertOk()->json('data.custom_fields.t490_doc');
        $this->assertSame([$attachmentId], array_column($profile, 'id'));

        $this->as($this->member)->deleteJson('/api/me/fields/t490_doc/file', ['attachment_id' => $attachmentId])
            ->assertOk()->assertJsonPath('data.files', []);
        $this->assertSame(0, Attachment::where('collection', 'field:t490_doc')->count());
    }

    /**
     * 항목이 정한 확장자, 크기, 개수를 넘는 파일은 422 로 거부한다. 이미지 항목은 이미지만 받는다.
     *
     * @scenario case=upload_limits
     *
     * @effects file_field_enforces_extension_size_and_count
     *
     * @return void 반환값 없음
     */
    public function test_upload_limits_are_enforced(): void
    {
        $this->custom('t490_doc', 'file', ['constraints' => ['extensions' => ['pdf'], 'max_size_mb' => 1, 'max_count' => 1]]);
        $this->custom('t490_photo', 'image');

        $this->upload('t490_doc', UploadedFile::fake()->create('memo.txt', 10, 'text/plain'))->assertStatus(422)->assertJsonValidationErrors(['file']);
        $this->upload('t490_doc', UploadedFile::fake()->create('big.pdf', 2048, 'application/pdf'))->assertStatus(422)->assertJsonValidationErrors(['file']);
        $this->upload('t490_photo', UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'))->assertStatus(422)->assertJsonValidationErrors(['file']);

        $this->upload('t490_doc', UploadedFile::fake()->create('one.pdf', 10, 'application/pdf'))->assertCreated();
        $this->upload('t490_doc', UploadedFile::fake()->create('two.pdf', 10, 'application/pdf'))->assertStatus(422)->assertJsonValidationErrors(['file']);
        $this->upload('t490_photo', UploadedFile::fake()->image('face.png', 80, 80))->assertCreated();
    }

    /**
     * 본인 경로는 본인 수정 범위의 파일 항목만 다룬다 — 관리자 범위 항목, 파일이 아닌 항목, 없는 항목, 꺼진 항목은 404 다.
     *
     * @scenario case=self_scope
     *
     * @effects self_route_rejects_fields_outside_self_scope
     *
     * @return void 반환값 없음
     */
    public function test_self_route_only_accepts_self_scoped_file_fields(): void
    {
        $this->custom('t490_admin_doc', 'file', ['edit_scope' => 'admin_edit']);
        $this->custom('t490_text', 'text');
        $this->custom('t490_off', 'file', ['enabled' => false]);
        $file = fn () => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf');

        $this->upload('t490_admin_doc', $file())->assertNotFound();
        $this->upload('t490_text', $file())->assertNotFound();
        $this->upload('t490_off', $file())->assertNotFound();
        $this->upload('t490_none', $file())->assertNotFound();
        $this->as(null)->post('/api/me/fields/t490_admin_doc/file', ['file' => $file()], ['Accept' => 'application/json'])->assertUnauthorized();
    }

    /**
     * 남의 파일은 지울 수 없다 — 다른 회원이 올린 첨부 ID 를 보내면 404 이고 파일은 남는다.
     *
     * @scenario case=delete_others
     *
     * @effects member_cannot_delete_another_members_file
     *
     * @return void 반환값 없음
     */
    public function test_member_cannot_delete_another_members_file(): void
    {
        $this->custom('t490_doc');
        $attachmentId = $this->upload('t490_doc', UploadedFile::fake()->create('mine.pdf', 10, 'application/pdf'))->assertCreated()->json('data.file.id');

        $this->as($this->other)->deleteJson('/api/me/fields/t490_doc/file', ['attachment_id' => $attachmentId])->assertNotFound();
        $this->assertDatabaseHas('attachments', ['id' => $attachmentId]);
    }

    /**
     * 올린 파일은 본인과 회원 조회 권한이 있는 관리자만 받는다. 다른 회원과 비로그인은 거부된다.
     *
     * @scenario case=download_access
     *
     * @effects field_file_download_limited_to_owner_and_admin
     *
     * @return void 반환값 없음
     */
    public function test_download_is_limited_to_owner_and_admin(): void
    {
        $this->custom('t490_doc');
        $hash = $this->upload('t490_doc', UploadedFile::fake()->create('mine.pdf', 10, 'application/pdf'))->assertCreated()->json('data.file.hash');
        $url = "/api/attachment/{$hash}";

        $this->as($this->member)->get($url)->assertOk();
        $this->as($this->admin)->get($url)->assertOk();
        $this->as($this->other)->get($url)->assertForbidden();
        $this->as(null)->get($url)->assertForbidden();
    }

    /**
     * 공개 프로필에 노출하는 이미지 항목은 누구나 받는다. 관리자만 열람 항목의 파일은 본인도 받지 못한다.
     *
     * @scenario case=download_public_and_admin_only
     *
     * @effects public_image_is_open_and_admin_only_file_is_closed_to_owner
     *
     * @return void 반환값 없음
     */
    public function test_public_profile_image_is_open_and_admin_only_file_is_closed(): void
    {
        $this->custom('t490_photo', 'image', ['public_profile' => true]);
        $this->custom('t490_secret', 'file', ['edit_scope' => 'admin_only']);

        $photo = $this->upload('t490_photo', UploadedFile::fake()->image('face.png', 80, 80))->assertCreated()->json('data.file.hash');
        $secret = $this->as($this->admin)->post("/api/admin/users/{$this->member->uuid}/fields/t490_secret/file", [
            'file' => UploadedFile::fake()->create('review.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.file.hash');

        $this->as(null)->get("/api/attachment/{$photo}")->assertOk();
        $this->as($this->other)->get("/api/attachment/{$photo}")->assertOk();

        $this->as($this->member)->get("/api/attachment/{$secret}")->assertForbidden();
        $this->as($this->admin)->get("/api/attachment/{$secret}")->assertOk();

        // 본인 응답에는 관리자만 열람 항목이 실리지 않는다
        $this->assertArrayNotHasKey('t490_secret', $this->as($this->member)->getJson('/api/me')->assertOk()->json('data.custom_fields'));
    }

    /**
     * 관리자는 회원의 어떤 범위의 파일 항목에도 올리고 지울 수 있고, 회원 수정 권한이 없으면 403 이다.
     *
     * @scenario case=admin_route
     *
     * @effects admin_manages_member_files_with_update_permission
     *
     * @return void 반환값 없음
     */
    public function test_admin_uploads_and_deletes_member_file(): void
    {
        $this->custom('t490_admin_doc', 'file', ['edit_scope' => 'admin_edit']);
        $base = "/api/admin/users/{$this->member->uuid}/fields/t490_admin_doc/file";
        $file = fn () => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf');

        $this->as($this->other)->post($base, ['file' => $file()], ['Accept' => 'application/json'])->assertForbidden();

        $attachmentId = $this->as($this->admin)->post($base, ['file' => $file()], ['Accept' => 'application/json'])->assertCreated()->json('data.file.id');
        $this->assertDatabaseHas('attachments', ['id' => $attachmentId, 'attachmentable_id' => $this->member->id, 'collection' => 'field:t490_admin_doc']);

        $detail = $this->as($this->admin)->getJson("/api/admin/users/{$this->member->uuid}")->assertOk()->json('data.custom_fields.t490_admin_doc');
        $this->assertSame([$attachmentId], array_column($detail, 'id'));

        $this->as($this->admin)->deleteJson($base, ['attachment_id' => $attachmentId])->assertOk()->assertJsonPath('data.files', []);
    }

    /**
     * 탈퇴하면 회원 항목에 올린 파일이 바로 지워진다. 회원을 삭제해도 지워진다.
     *
     * @scenario case=withdraw_and_delete
     *
     * @effects field_files_are_removed_on_withdraw_and_delete
     *
     * @return void 반환값 없음
     */
    public function test_files_are_removed_on_withdraw_and_delete(): void
    {
        $this->custom('t490_doc');
        $this->upload('t490_doc', UploadedFile::fake()->create('mine.pdf', 10, 'application/pdf'))->assertCreated();
        $this->upload('t490_doc', UploadedFile::fake()->create('theirs.pdf', 10, 'application/pdf'), $this->other)->assertCreated();
        $this->assertSame(2, Attachment::where('collection', 'field:t490_doc')->count());

        app(UserService::class)->withdrawUser($this->member->fresh());
        $this->assertSame(0, Attachment::where('collection', 'field:t490_doc')->where('attachmentable_id', $this->member->id)->count(), '탈퇴한 회원의 파일이 남았다');
        $this->assertSame(1, Attachment::where('collection', 'field:t490_doc')->count());

        // 회원 삭제는 관리자가 한다(본인 계정은 관리자 화면에서 지울 수 없다)
        $this->actingAs($this->admin);
        app(UserService::class)->deleteUser($this->other->fresh());
        $this->assertSame(0, Attachment::where('collection', 'field:t490_doc')->count(), '삭제한 회원의 파일이 남았다');
    }
}
