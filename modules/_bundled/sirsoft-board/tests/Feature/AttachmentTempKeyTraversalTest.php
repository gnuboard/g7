<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

require_once __DIR__.'/../ModuleTestCase.php';

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 게시판 temp_key 경로 순회 방어 테스트 (KVE-2026-2349)
 *
 * 첨부 없이 글 작성 요청의 temp_key 에 `../../../..` 를 넣으면 임시 디렉토리 정리가
 * modules 디스크 루트를 지워 전 모듈 저장소가 사라졌다(HTTP 201). 요청 계층이 UUID 가 아닌
 * temp_key 를 422 로 거부하는지, 임시 첨부가 없으면 서비스가 아무 디렉토리도 지우지 않는지,
 * 남이 올린 임시 첨부는 잇지도 지우지도 않는지, 정상 흐름(UUID 키 업로드 → 글 저장)은
 * 그대로인지 실제 저장소 파일로 확인한다. 파괴 페이로드가 통과하더라도 테스트 디스크
 * (storage/framework/testing) 안에서만 일어난다.
 */
class AttachmentTempKeyTraversalTest extends BoardTestCase
{
    /**
     * 경로 순회·인코딩 temp_key 변종
     *
     * @var array<int, string>
     */
    private const TRAVERSAL_KEYS = [
        '../../../..',
        '..%2f..%2f..%2f..',
        'a/../../../../..',
        '../../../../',
        '\\',
        '.',
    ];

    /**
     * 글 작성·업로드 권한이 있는 회원
     */
    private User $memberUser;

    /**
     * 가드가 뚫리면 함께 사라질 형제 모듈 canary 경로 (modules 디스크 기준)
     */
    private string $canaryPath = 'canary-module/settings/keep.json';

    /**
     * 테스트 게시판 슬러그를 반환합니다.
     *
     * @return string 게시판 슬러그
     */
    protected function getTestBoardSlug(): string
    {
        return 'temp-key-traversal';
    }

    /**
     * 테스트 게시판 기본 속성을 반환합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @return array<string, mixed> 게시판 속성
     */
    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '임시 키 순회 방어 게시판', 'en' => 'Temp Key Traversal Board'],
            'is_active' => true,
            'use_file_upload' => true,
            'max_file_count' => 5,
        ];
    }

    /**
     * 권한·회원·canary 파일을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->setGuestPermissions(['posts.read']);
        $this->grantUserRolePermissions(['posts.read', 'posts.write', 'attachments.upload']);
        $this->memberUser = $this->createMember('temp-key-member@test.com');

        Storage::disk('modules')->put($this->canaryPath, '{}');
    }

    /**
     * 테스트가 만든 저장소 파일을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Storage::disk('modules')->deleteDirectory('canary-module');
        Storage::disk('modules')->deleteDirectory('sirsoft-board/attachments/'.$this->getTestBoardSlug());

        parent::tearDown();
    }

    /**
     * 글 작성 요청의 순회·인코딩 temp_key 는 422 로 거부되고 글도 저장소도 그대로다.
     *
     * @return void 반환값 없음
     *
     * @scenario entry_point=post_store,temp_key_shape=path_traversal
     *
     * @effects traversal_temp_key_rejected_with_422, sibling_module_storage_survives
     */
    public function test_post_store_rejects_traversal_temp_keys(): void
    {
        foreach (self::TRAVERSAL_KEYS as $tempKey) {
            $this->actingAs($this->memberUser, 'sanctum')
                ->postJson($this->postsUrl(), $this->postPayload(['temp_key' => $tempKey]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('temp_key');
        }

        $this->assertSame(0, DB::table('board_posts')->where('board_id', $this->board->id)->count());
        $this->assertTrue(Storage::disk('modules')->exists($this->canaryPath));
    }

    /**
     * 첨부 업로드 요청의 순회·인코딩 temp_key 는 422 로 거부되고 파일이 저장되지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario entry_point=upload,temp_key_shape=path_traversal
     *
     * @effects traversal_temp_key_rejected_with_422, sibling_module_storage_survives
     */
    public function test_upload_rejects_traversal_temp_keys(): void
    {
        $before = Attachment::query()->count();

        foreach (self::TRAVERSAL_KEYS as $tempKey) {
            $this->actingAs($this->memberUser, 'sanctum')
                ->postJson($this->attachmentsUrl(), [
                    'file' => UploadedFile::fake()->image('probe.png'),
                    'temp_key' => $tempKey,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('temp_key');
        }

        $this->assertSame($before, Attachment::query()->count());
        $this->assertTrue(Storage::disk('modules')->exists($this->canaryPath));
    }

    /**
     * 글 수정 요청(사용자·관리자 경로)의 순회·인코딩 temp_key 도 422 로 거부된다.
     *
     * 수정 경로도 같은 임시 첨부 연결(linkTempAttachmentsWithMove)을 타므로 작성 경로와 같은 규칙이어야 한다.
     *
     * @return void 반환값 없음
     *
     * @effects traversal_temp_key_rejected_with_422, sibling_module_storage_survives
     */
    public function test_post_update_rejects_traversal_temp_keys(): void
    {
        $slug = $this->getTestBoardSlug();
        $memberPostId = $this->createTestPost(['user_id' => $this->memberUser->id]);

        $admin = $this->createAdminUser([
            "sirsoft-board.{$slug}.admin.posts.write",
            "sirsoft-board.{$slug}.admin.manage",
        ]);
        $adminPostId = $this->createTestPost(['user_id' => $admin->id]);

        foreach (self::TRAVERSAL_KEYS as $tempKey) {
            $this->actingAs($this->memberUser, 'sanctum')
                ->putJson("{$this->postsUrl()}/{$memberPostId}", $this->postPayload(['temp_key' => $tempKey]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('temp_key');

            $this->actingAs($admin)
                ->putJson("/api/modules/sirsoft-board/admin/board/{$slug}/posts/{$adminPostId}", $this->postPayload(['temp_key' => $tempKey]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('temp_key');
        }

        $this->assertTrue(Storage::disk('modules')->exists($this->canaryPath));
    }

    /**
     * 첨부 없이 UUID temp_key 로 글을 저장하면 201 이고 어떤 디렉토리도 지우지 않는다.
     *
     * 업로드를 끈 게시판에서도 화면은 temp_key 를 늘 보낸다(수정 전 공격의 전제). 같은 키의
     * 임시 디렉토리에 DB 행 없는 파일을 미리 두고, 저장 뒤에도 남는지로 정리 미실행을 확인한다.
     *
     * @return void 반환값 없음
     *
     * @scenario entry_point=post_store,temp_key_shape=uuid
     *
     * @effects uuid_temp_key_accepted, empty_temp_link_deletes_nothing
     */
    public function test_post_store_with_uuid_key_and_no_temp_attachments_deletes_nothing(): void
    {
        $this->updateBoardSettings(['use_file_upload' => false]);

        $tempKey = (string) Str::uuid();
        $stray = "sirsoft-board/attachments/{$this->getTestBoardSlug()}/temp/{$tempKey}/stray.txt";
        Storage::disk('modules')->put($stray, 'x');

        $this->actingAs($this->memberUser, 'sanctum')
            ->postJson($this->postsUrl(), $this->postPayload(['temp_key' => $tempKey]))
            ->assertStatus(201);

        $this->assertTrue(Storage::disk('modules')->exists($stray));
        $this->assertTrue(Storage::disk('modules')->exists($this->canaryPath));
    }

    /**
     * UUID temp_key 로 올린 임시 첨부는 글 저장 시 연결·이동되고 임시 디렉토리가 정리된다.
     *
     * @return void 반환값 없음
     *
     * @scenario entry_point=upload,temp_key_shape=uuid
     *
     * @effects uuid_temp_key_accepted, owned_temp_attachments_moved_and_temp_dir_cleaned
     */
    public function test_uuid_temp_upload_is_linked_and_temp_dir_cleaned_on_store(): void
    {
        $tempKey = (string) Str::uuid();

        $this->actingAs($this->memberUser, 'sanctum')
            ->postJson($this->attachmentsUrl(), [
                'file' => UploadedFile::fake()->image('linked.png'),
                'temp_key' => $tempKey,
            ])
            ->assertStatus(201);

        $tempDir = "sirsoft-board/attachments/{$this->getTestBoardSlug()}/temp/{$tempKey}";
        $this->assertCount(1, Storage::disk('modules')->files($tempDir));

        $response = $this->actingAs($this->memberUser, 'sanctum')
            ->postJson($this->postsUrl(), $this->postPayload(['temp_key' => $tempKey]))
            ->assertStatus(201);

        $attachment = Attachment::query()->where('post_id', (int) $response->json('data.id'))->firstOrFail();
        $this->assertNull($attachment->temp_key);
        $this->assertStringNotContainsString('/temp/', $attachment->path);
        $this->assertTrue(Storage::disk('modules')->exists('sirsoft-board/attachments/'.$attachment->path));
        $this->assertFalse(Storage::disk('modules')->exists($tempDir));
    }

    /**
     * 다른 회원이 올린 임시 첨부의 키로 글을 저장해도 그 첨부를 잇지도 지우지도 않는다.
     *
     * @return void 반환값 없음
     *
     * @effects foreign_temp_attachments_not_linked_or_deleted
     */
    public function test_foreign_temp_attachments_are_not_linked_or_deleted(): void
    {
        $owner = $this->createMember('temp-key-owner@test.com');
        $tempKey = (string) Str::uuid();

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->attachmentsUrl(), [
                'file' => UploadedFile::fake()->image('owner.png'),
                'temp_key' => $tempKey,
            ])
            ->assertStatus(201);

        $this->actingAs($this->memberUser, 'sanctum')
            ->postJson($this->postsUrl(), $this->postPayload(['temp_key' => $tempKey]))
            ->assertStatus(201);

        $attachment = Attachment::query()->where('temp_key', $tempKey)->firstOrFail();
        $this->assertNull($attachment->post_id);
        $this->assertTrue(Storage::disk('modules')->exists('sirsoft-board/attachments/'.$attachment->path));
    }

    /**
     * 다른 회원이 올린 임시 첨부를 `attachment_ids` 로 자기 글(수정)에 이을 수 없다.
     *
     * 2026-10-07 실측(K4-D1): temp_key 경로만 소유자를 확인하고 `attachment_ids` 경로는 확인하지
     * 않아, 첨부 번호(순번)만 알면 남이 올려 둔 임시 첨부를 자기 글에 붙일 수 있었다.
     *
     * @return void 반환값 없음
     *
     * @effects foreign_temp_attachments_not_linked_or_deleted
     */
    public function test_foreign_temp_attachment_ids_are_not_linked_on_member_update(): void
    {
        $owner = $this->createMember('temp-ids-owner@test.com');
        $uploaded = $this->actingAs($owner, 'sanctum')
            ->postJson($this->attachmentsUrl(), ['file' => UploadedFile::fake()->image('owner-ids.png')])
            ->assertStatus(201);
        $foreignId = (int) $uploaded->json('data.data.id');

        $memberPostId = $this->createTestPost(['user_id' => $this->memberUser->id]);
        $this->actingAs($this->memberUser, 'sanctum')
            ->putJson("{$this->postsUrl()}/{$memberPostId}", $this->postPayload(['attachment_ids' => [$foreignId]]))
            ->assertStatus(200);

        $attachment = Attachment::query()->findOrFail($foreignId);
        $this->assertNull($attachment->post_id);
        $this->assertSame(0, (int) $attachment->board_id);
        $this->assertNotNull($attachment->temp_key);
    }

    /**
     * 관리자 경로의 `attachment_ids` 도 남이 올린 임시 첨부를 잇지 않고, 자기 임시 첨부는 그대로 잇는다.
     *
     * @return void 반환값 없음
     *
     * @effects foreign_temp_attachments_not_linked_or_deleted, owned_attachment_ids_still_linked
     */
    public function test_admin_attachment_ids_link_only_own_temp_attachments(): void
    {
        $slug = $this->getTestBoardSlug();
        $admin = $this->createAdminUser([
            "sirsoft-board.{$slug}.admin.posts.write",
            "sirsoft-board.{$slug}.admin.attachments.upload",
            "sirsoft-board.{$slug}.admin.manage",
        ]);
        $foreignId = (int) $this->actingAs($this->memberUser, 'sanctum')
            ->postJson($this->attachmentsUrl(), ['file' => UploadedFile::fake()->image('member-ids.png')])
            ->assertStatus(201)
            ->json('data.data.id');
        $ownId = (int) $this->actingAs($admin)
            ->postJson("/api/modules/sirsoft-board/admin/board/{$slug}/attachments", [
                'file' => UploadedFile::fake()->image('admin-ids.png'),
            ])
            ->assertStatus(201)
            ->json('data.data.id');

        $response = $this->actingAs($admin)
            ->postJson("/api/modules/sirsoft-board/admin/board/{$slug}/posts", $this->postPayload([
                'attachment_ids' => [$foreignId, $ownId],
            ]))
            ->assertStatus(201);
        $postId = (int) $response->json('data.id');

        $this->assertNull(Attachment::query()->findOrFail($foreignId)->post_id);
        $this->assertSame($postId, (int) Attachment::query()->findOrFail($ownId)->post_id);
    }

    /**
     * 글도 temp_key 도 없이 올린 첨부는 서버가 만든 UUID 키의 임시 경로에 저장된다.
     *
     * 업로드 후 attachment_ids 로 잇는 흐름이다. 키를 비워 두면 경로가 `{slug}/temp//파일` 이
     * 되어 저장소 경로 가드가 거부하므로 서버가 키를 채운다.
     *
     * @return void 반환값 없음
     *
     * @effects keyless_upload_gets_server_generated_temp_key
     */
    public function test_keyless_upload_gets_server_generated_temp_key(): void
    {
        $response = $this->actingAs($this->memberUser, 'sanctum')
            ->postJson($this->attachmentsUrl(), ['file' => UploadedFile::fake()->image('keyless.png')])
            ->assertStatus(201);

        $attachment = Attachment::query()->findOrFail((int) $response->json('data.data.id'));
        $this->assertTrue(Str::isUuid((string) $attachment->temp_key));
        $this->assertStringStartsWith("{$this->getTestBoardSlug()}/temp/{$attachment->temp_key}/", $attachment->path);
        $this->assertStringNotContainsString('//', $attachment->path);
    }

    /**
     * 글 작성·업로드 권한 역할을 가진 회원을 만듭니다.
     *
     * @param  string  $email  이메일
     * @return User 생성된 회원
     */
    private function createMember(string $email): User
    {
        $user = User::factory()->create(['email' => $email]);

        $userRole = Role::where('identifier', 'user')->first();
        if ($userRole) {
            $user->roles()->attach($userRole->id);
        }

        return $user;
    }

    /**
     * 사용자 글 작성 API 경로를 반환합니다.
     *
     * @return string API 경로
     */
    private function postsUrl(): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->getTestBoardSlug()}/posts";
    }

    /**
     * 사용자 첨부 업로드 API 경로를 반환합니다.
     *
     * @return string API 경로
     */
    private function attachmentsUrl(): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->getTestBoardSlug()}/attachments";
    }

    /**
     * 글 작성 요청 본문을 만듭니다.
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 필드
     * @return array<string, mixed> 요청 본문
     */
    private function postPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => '임시 키 순회 방어',
            'content' => '본문은 최소 길이를 넘겨야 검증을 통과합니다.',
        ], $overrides);
    }
}
