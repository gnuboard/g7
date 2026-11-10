<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Services\PostService;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 게시판 첨부 소유 빈틈 (#883)
 *
 * 비회원 임시 첨부는 임시 키 소지로 소유를 판단한다(템플릿 무변경 — basic 복제 템플릿 호환). 그 위에서 남아 있던 빈틈을 막는다.
 * - 회원이 비회원의 임시 키로, 비회원이 회원 업로드를 잇던 교차
 * - 비회원끼리 첨부 번호만으로 남의 임시 첨부를 잇던 것(이제 임시 키도 맞아야 한다)
 * - 다른 게시판 임시 키·첨부 번호로 잇던 것
 * - 남의 글·다른 게시판 글 번호(`post_id`)로 파일을 붙이던 것
 * - 예약 컬렉션(본문 미디어) 이름으로 일반 첨부를 올려 개수 상한을 피하던 것
 *
 * @scenario-manifest modules/_bundled/sirsoft-board/tests/scenarios/attachment-ownership.yaml
 */
class AttachmentOwnershipGapsTest extends BoardTestCase
{
    private User $memberA;

    private User $memberB;

    /**
     * 테스트 게시판 슬러그를 반환합니다.
     *
     * @return string 게시판 슬러그
     */
    protected function getTestBoardSlug(): string
    {
        return 'attach-ownership';
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
            'name' => ['ko' => '첨부 소유 빈틈 게시판', 'en' => 'Attachment Ownership Board'],
            'is_active' => true,
            'use_file_upload' => true,
            'max_file_count' => 5,
        ];
    }

    /**
     * 비회원·회원 권한과 회원 둘을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->setGuestPermissions(['posts.read', 'posts.write', 'attachments.upload']);
        $this->grantUserRolePermissions(['posts.read', 'posts.write', 'attachments.upload']);
        $this->memberA = $this->createMember('owner-a@test.com');
        $this->memberB = $this->createMember('owner-b@test.com');
    }

    /**
     * 테스트가 만든 저장소 파일을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Storage::disk('modules')->deleteDirectory('sirsoft-board/attachments/'.$this->getTestBoardSlug());
        Storage::disk('modules')->deleteDirectory('sirsoft-board/attachments/other-board');

        parent::tearDown();
    }

    /**
     * 회원은 비회원의 임시 키로 그 비회원 임시 첨부를 자기 글에 잇지 못한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=member_links_guest_temp_key
     *
     * @effects cross_owner_temp_attachment_not_linked
     */
    public function test_member_cannot_link_guest_temp_attachment_by_temp_key(): void
    {
        $tempKey = (string) Str::uuid();
        $guestUpload = $this->postJson($this->attachmentsUrl(), [
            'file' => UploadedFile::fake()->image('guest.png'),
            'temp_key' => $tempKey,
        ])->assertStatus(201);

        $this->actingAs($this->memberA, 'sanctum')
            ->postJson($this->postsUrl(), $this->postPayload(['temp_key' => $tempKey]))
            ->assertStatus(201);

        $this->assertNull(Attachment::query()->findOrFail((int) $guestUpload->json('data.data.id'))->post_id);
    }

    /**
     * 비회원은 회원이 올린 임시 첨부를 같은 임시 키로 잇지 못한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=guest_links_member_upload
     *
     * @effects cross_owner_temp_attachment_not_linked
     */
    public function test_guest_cannot_link_member_temp_attachment(): void
    {
        $tempKey = (string) Str::uuid();
        $memberUploadId = (int) $this->actingAs($this->memberA, 'sanctum')
            ->postJson($this->attachmentsUrl(), ['file' => UploadedFile::fake()->image('member.png'), 'temp_key' => $tempKey])
            ->assertStatus(201)
            ->json('data.data.id');
        $this->app['auth']->forgetGuards();

        $this->postJson($this->postsUrl(), $this->guestPostPayload([
            'temp_key' => $tempKey,
            'attachment_ids' => [$memberUploadId],
        ]))->assertStatus(201);

        $this->assertNull(Attachment::query()->findOrFail($memberUploadId)->post_id);
    }

    /**
     * 비회원끼리는 첨부 번호만으로 남의 임시 첨부를 잇지 못하고, 그 첨부의 임시 키를 함께 보내면 잇는다.
     *
     * 번호 연결은 글 수정(`attachment_ids`) 경로가 탄다 — 사용자 글 작성은 임시 키 경로만 쓴다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=guest_links_by_id_only
     *
     * @effects guest_id_link_requires_matching_temp_key
     */
    public function test_guest_id_link_requires_matching_temp_key(): void
    {
        $ownerKey = (string) Str::uuid();
        $uploadId = (int) $this->postJson($this->attachmentsUrl(), [
            'file' => UploadedFile::fake()->image('guest-a.png'),
            'temp_key' => $ownerKey,
        ])->assertStatus(201)->json('data.data.id');
        $guestPostId = $this->createTestPost(['user_id' => null, 'password' => Hash::make('guest1234')]);

        $this->putJson("{$this->postsUrl()}/{$guestPostId}", $this->guestPostPayload([
            'attachment_ids' => [$uploadId],
        ]))->assertStatus(200);
        $this->assertNull(Attachment::query()->findOrFail($uploadId)->post_id);

        $this->putJson("{$this->postsUrl()}/{$guestPostId}", $this->guestPostPayload([
            'attachment_ids' => [$uploadId],
            'temp_key' => $ownerKey,
        ]))->assertStatus(200);
        $this->assertSame($guestPostId, (int) Attachment::query()->findOrFail($uploadId)->post_id);
    }

    /**
     * 다른 게시판 임시 키·첨부 번호로는 이 게시판 글에 잇지 못하고, 파일도 옮기지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=other_board_temp
     *
     * @effects other_board_temp_attachment_not_linked
     */
    public function test_other_board_temp_attachments_are_not_linked(): void
    {
        $tempKey = (string) Str::uuid();
        $path = "other-board/temp/{$tempKey}/x.png";
        Storage::disk('modules')->put('sirsoft-board/attachments/'.$path, 'png');
        $foreign = Attachment::create([
            'board_id' => 0,
            'post_id' => null,
            'temp_key' => $tempKey,
            'original_filename' => 'x.png',
            'stored_filename' => 'x.png',
            'disk' => 'modules',
            'path' => $path,
            'mime_type' => 'image/png',
            'size' => 3,
            'collection' => 'attachments',
            'order' => 1,
            'created_by' => $this->memberA->id,
        ]);

        $this->actingAs($this->memberA, 'sanctum')
            ->postJson($this->postsUrl(), $this->postPayload([
                'temp_key' => $tempKey,
                'attachment_ids' => [$foreign->id],
            ]))
            ->assertStatus(201);

        $foreign->refresh();
        $this->assertNull($foreign->post_id);
        $this->assertSame($path, $foreign->path);
        $this->assertTrue(Storage::disk('modules')->exists('sirsoft-board/attachments/'.$path));
    }

    /**
     * 다른 게시판 글 번호로 업로드하면 404, 이 게시판 남의 글이면 403 이다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=post_id_other_board_or_owner
     *
     * @effects post_id_upload_requires_modify_permission
     */
    public function test_post_id_upload_rejects_other_board_and_other_owner(): void
    {
        $other = Board::create([
            'slug' => 'other-board',
            'name' => ['ko' => '다른 게시판', 'en' => 'Other'],
            'is_active' => true,
            'use_file_upload' => true,
        ]);
        $otherBoardPostId = $this->createTestPost(['board_id' => $other->id, 'user_id' => $this->memberA->id]);
        $memberBPostId = $this->createTestPost(['user_id' => $this->memberB->id]);

        $this->actingAs($this->memberA, 'sanctum')
            ->postJson($this->attachmentsUrl(), ['file' => UploadedFile::fake()->image('a.png'), 'post_id' => $otherBoardPostId])
            ->assertStatus(404);

        $this->actingAs($this->memberA, 'sanctum')
            ->postJson($this->attachmentsUrl(), ['file' => UploadedFile::fake()->image('b.png'), 'post_id' => $memberBPostId])
            ->assertStatus(403);

        $this->assertSame(0, Attachment::query()->whereIn('post_id', [$otherBoardPostId, $memberBPostId])->count());

        $ownPostId = $this->createTestPost(['user_id' => $this->memberA->id]);
        $this->actingAs($this->memberA, 'sanctum')
            ->postJson($this->attachmentsUrl(), ['file' => UploadedFile::fake()->image('c.png'), 'post_id' => $ownPostId])
            ->assertStatus(201);
    }

    /**
     * 비회원 글은 비밀번호 확인 뒤 받은 검증 토큰으로 업로드할 수 있고, 업로드가 토큰을 쓰지 않는다(저장에서 다시 쓴다).
     *
     * @return void 반환값 없음
     *
     * @scenario case=guest_post_with_verify_token
     *
     * @effects post_id_upload_requires_modify_permission, verify_token_not_consumed_by_upload
     */
    public function test_guest_post_upload_with_verify_token_does_not_consume_it(): void
    {
        $slug = $this->getTestBoardSlug();
        $postId = $this->createTestPost(['user_id' => null, 'password' => Hash::make('guest1234')]);
        $token = Str::random(40);
        app(PostService::class)->storeDeleteVerifyToken($slug, $postId, $token);

        $this->postJson($this->attachmentsUrl(), ['file' => UploadedFile::fake()->image('g.png'), 'post_id' => $postId])
            ->assertStatus(403);

        $this->withHeader(PostService::VERIFY_TOKEN_HEADER, $token)
            ->postJson($this->attachmentsUrl(), ['file' => UploadedFile::fake()->image('g.png'), 'post_id' => $postId])
            ->assertStatus(201);

        $this->assertTrue(app(PostService::class)->hasValidDeleteVerifyToken($slug, $postId, $token));
    }

    /**
     * 예약 컬렉션(본문 미디어) 이름으로는 일반 첨부를 올릴 수 없다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=reserved_collection
     *
     * @effects reserved_collection_rejected
     */
    public function test_reserved_collection_upload_is_rejected(): void
    {
        $this->actingAs($this->memberA, 'sanctum')
            ->postJson($this->attachmentsUrl(), [
                'file' => UploadedFile::fake()->image('cm.png'),
                'collection' => Attachment::COLLECTION_CONTENT_MEDIA,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['collection']);
    }

    /**
     * 업로드 응답에 올린 본인의 임시 키가 실린다(키 없이 올렸으면 서버가 만든 키).
     *
     * @return void 반환값 없음
     *
     * @scenario case=guest_links_by_id_only
     *
     * @effects guest_id_link_requires_matching_temp_key
     */
    public function test_upload_response_carries_temp_key(): void
    {
        $response = $this->postJson($this->attachmentsUrl(), ['file' => UploadedFile::fake()->image('k.png')])
            ->assertStatus(201);

        $this->assertTrue(Str::isUuid((string) $response->json('data.data.temp_key')));
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
     * 회원 글 작성 요청 본문을 만듭니다.
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 필드
     * @return array<string, mixed> 요청 본문
     */
    private function postPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => '첨부 소유 빈틈',
            'content' => '본문은 최소 길이를 넘겨야 검증을 통과합니다.',
        ], $overrides);
    }

    /**
     * 비회원 글 작성 요청 본문을 만듭니다.
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 필드
     * @return array<string, mixed> 요청 본문
     */
    private function guestPostPayload(array $overrides = []): array
    {
        return $this->postPayload(array_merge([
            'author_name' => '비회원',
            'password' => 'guest1234',
        ], $overrides));
    }
}
