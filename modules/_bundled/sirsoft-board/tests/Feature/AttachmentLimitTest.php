<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

require_once __DIR__.'/../ModuleTestCase.php';

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Mockery;
use Modules\Sirsoft\Board\Exceptions\AttachmentLimitExceededException;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Services\AttachmentService;
use Modules\Sirsoft\Board\Services\PostService;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 첨부파일 개수 상한 서버측 불변조건 테스트 (B3)
 *
 * 수정 전에는 `files[]` 직접 업로드만 FormRequest 에서 막고, `attachment_ids` · `temp_key` ·
 * 단일 업로드 반복 경로에는 개수 검사가 없었습니다. 같은 8개 파일을 `temp_key` 로 보내면
 * 201 로 전부 연결되어 사용자 페이지에 그대로 노출됐습니다.
 *
 * @group board
 * @group attachment
 */
class AttachmentLimitTest extends BoardTestCase
{
    private User $memberUser;

    protected function getTestBoardSlug(): string
    {
        return 'attachment-limit';
    }

    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '첨부 상한 게시판', 'en' => 'Attachment Limit Board'],
            'is_active' => true,
            'use_file_upload' => true,
            'max_file_count' => 5,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->setGuestPermissions(['posts.read', 'posts.write']);
        $this->grantUserRolePermissions(['posts.read', 'posts.write', 'attachments.upload']);

        $this->memberUser = User::factory()->create(['email' => 'attachment-limit@test.com']);

        $userRole = Role::where('identifier', 'user')->first();
        if ($userRole) {
            $this->memberUser->roles()->attach($userRole->id);
        }
    }

    /**
     * 임시 첨부(temp_key) 를 N 개 만듭니다.
     *
     * @param  string  $tempKey  임시 업로드 키
     * @param  int  $count  생성 개수
     */
    private function seedTempAttachments(string $tempKey, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Attachment::create([
                'board_id' => 0,
                'post_id' => null,
                'temp_key' => $tempKey,
                'collection' => 'attachments',
                'original_filename' => "temp-{$i}.png",
                'stored_filename' => "temp-{$i}.png",
                'path' => "{$this->getTestBoardSlug()}/temp/{$tempKey}/temp-{$i}.png",
                'mime_type' => 'image/png',
                'size' => 100,
                'order' => $i,
            ]);
        }
    }

    /**
     * 게시글에 연결된 첨부를 N 개 만듭니다.
     *
     * @param  int  $postId  게시글 ID
     * @param  int  $count  생성 개수
     */
    private function seedLinkedAttachments(int $postId, int $count): void
    {
        $board = $this->board;

        for ($i = 0; $i < $count; $i++) {
            Attachment::create([
                'board_id' => $board->id,
                'post_id' => $postId,
                'collection' => 'attachments',
                'original_filename' => "linked-{$i}.png",
                'stored_filename' => "linked-{$i}.png",
                'path' => "{$this->getTestBoardSlug()}/2026/07/27/linked-{$i}.png",
                'mime_type' => 'image/png',
                'size' => 100,
                'order' => $i,
            ]);
        }
    }

    /**
     * temp_key 경로로 상한을 초과하면 차단된다 (실측 재현된 우회 경로).
     */
    public function test_temp_key_link_over_limit_is_blocked(): void
    {
        $postId = $this->createTestPost();
        $tempKey = 'tk_'.str_repeat('a', 20);
        $this->seedTempAttachments($tempKey, 8);

        $this->expectException(AttachmentLimitExceededException::class);

        app(AttachmentService::class)->linkTempAttachmentsWithMove(
            $this->getTestBoardSlug(),
            $tempKey,
            $postId
        );
    }

    /**
     * 상한과 정확히 같은 개수는 통과한다 (경계).
     */
    public function test_temp_key_link_at_limit_passes(): void
    {
        $postId = $this->createTestPost();
        $tempKey = 'tk_'.str_repeat('b', 20);
        $this->seedTempAttachments($tempKey, 5);

        $linked = app(AttachmentService::class)->linkTempAttachmentsWithMove(
            $this->getTestBoardSlug(),
            $tempKey,
            $postId
        );

        $this->assertSame(5, $linked);
    }

    /**
     * 기존 첨부와 합산해 상한을 넘으면 차단된다 (수정 경로).
     */
    public function test_existing_plus_new_over_limit_is_blocked(): void
    {
        $postId = $this->createTestPost();
        $this->seedLinkedAttachments($postId, 4);

        $tempKey = 'tk_'.str_repeat('c', 20);
        $this->seedTempAttachments($tempKey, 2);

        $this->expectException(AttachmentLimitExceededException::class);

        app(AttachmentService::class)->linkTempAttachmentsWithMove(
            $this->getTestBoardSlug(),
            $tempKey,
            $postId
        );
    }

    /**
     * 기존 4 + 신규 1 = 5 는 통과한다 (경계).
     */
    public function test_existing_plus_new_at_limit_passes(): void
    {
        $postId = $this->createTestPost();
        $this->seedLinkedAttachments($postId, 4);

        $tempKey = 'tk_'.str_repeat('d', 20);
        $this->seedTempAttachments($tempKey, 1);

        $linked = app(AttachmentService::class)->linkTempAttachmentsWithMove(
            $this->getTestBoardSlug(),
            $tempKey,
            $postId
        );

        $this->assertSame(1, $linked);
    }

    /**
     * attachment_ids 경로도 합산 판정된다.
     */
    public function test_attachment_ids_over_limit_is_blocked(): void
    {
        $postId = $this->createTestPost();
        $this->seedLinkedAttachments($postId, 5);

        $this->expectException(AttachmentLimitExceededException::class);

        app(AttachmentService::class)->assertAttachmentCountWithin(
            $this->getTestBoardSlug(),
            $postId,
            1
        );
    }

    /**
     * 관리자 수정 경로에서 상한 초과가 500 이 아닌 422 로 응답되는지 검증합니다.
     *
     * FormRequest 선차단은 UX 용이고 최종 불변조건은 Service 예외다. 그런데 관리자 수정
     * 컨트롤러의 catch 가 게시글 *조회* try 에 붙어 있어, 예외를 실제로 던지는 저장 try 는
     * 이 예외를 잡지 못하고 generic 500 으로 떨어졌다. 조회 mock 이 정상 반환하고 저장 mock 이
     * 예외를 던지는 구성으로 그 매핑만 격리해 검증한다.
     */
    public function test_admin_update_maps_attachment_limit_to_422(): void
    {
        $slug = $this->getTestBoardSlug();
        $admin = $this->createAdminUser([
            "sirsoft-board.{$slug}.admin.posts.write",
            "sirsoft-board.{$slug}.admin.manage",
        ]);

        $postId = $this->createTestPost(['user_id' => $admin->id]);
        $post = Post::find($postId);

        $mock = Mockery::mock(PostService::class);
        $mock->shouldReceive('getPost')->andReturn($post);
        $mock->shouldReceive('updatePost')
            ->andThrow(new AttachmentLimitExceededException(5, 6));
        $this->app->instance(PostService::class, $mock);

        $this->actingAs($admin)
            ->putJson("/api/modules/sirsoft-board/admin/board/{$slug}/posts/{$postId}", [
                'title' => '상한 초과 수정 시도',
                'content' => '본문은 최소 길이를 넘겨야 검증을 통과합니다.',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'attachment_limit_exceeded');
    }

    /**
     * 직접 업로드와 `attachment_ids` 를 섞어 보내도 합산 판정된다.
     *
     * 소스가 나뉘면 각 소스는 상한 이하인데 총합만 초과하는 조합이 만들어진다. 어느 한 소스만
     * 세는 구현은 이 조합을 그대로 통과시킨다.
     */
    public function test_mixed_files_and_attachment_ids_over_limit_is_blocked(): void
    {
        $postId = $this->createTestPost(['user_id' => $this->memberUser->id]);

        // 기존 첨부 3 개 — 이 중 3 개를 attachment_ids 로 유지하면서 새 파일 3 개를 더 보낸다
        $this->seedLinkedAttachments($postId, 3);
        $keepIds = Attachment::where('post_id', $postId)->pluck('id')->all();

        $response = $this->actingAs($this->memberUser)->putJson(
            "/api/modules/sirsoft-board/boards/{$this->getTestBoardSlug()}/posts/{$postId}",
            [
                'title' => '혼합 소스 상한 초과',
                'content' => '본문은 최소 길이를 넘겨야 검증을 통과합니다.',
                'attachment_ids' => $keepIds,
                'files' => [
                    UploadedFile::fake()->image('mix-1.png'),
                    UploadedFile::fake()->image('mix-2.png'),
                    UploadedFile::fake()->image('mix-3.png'),
                ],
            ]
        );

        $response->assertStatus(422);
        $this->assertArrayHasKey('files', $response->json('errors') ?? []);
    }

    /**
     * 작성 시 직접 업로드가 상한과 정확히 같은 개수면 통과하고 실제로 저장된다 (경계).
     *
     * 합산 판정이 들어간 뒤로 `files[]` 경로는 초과 케이스만 고정돼 있었다. 판정식이
     * `>=` 로 어긋나거나 기존 첨부 0 건을 1 건으로 세면 상한 동수가 조용히 막히는데,
     * 초과 케이스만 보는 테스트는 그 회귀를 통과시킨다. 저장 건수까지 단언해 "차단은
     * 안 했지만 첨부도 안 됐다" 는 절반짜리 통과를 배제한다.
     */
    public function test_files_at_limit_on_create_passes_and_persists(): void
    {
        $response = $this->actingAs($this->memberUser, 'sanctum')->postJson(
            "/api/modules/sirsoft-board/boards/{$this->getTestBoardSlug()}/posts",
            [
                'title' => '상한 동수 첨부',
                'content' => '본문은 최소 길이를 넘겨야 검증을 통과합니다.',
                'files' => [
                    UploadedFile::fake()->image('at-1.png'),
                    UploadedFile::fake()->image('at-2.png'),
                    UploadedFile::fake()->image('at-3.png'),
                    UploadedFile::fake()->image('at-4.png'),
                    UploadedFile::fake()->image('at-5.png'),
                ],
            ]
        );

        $response->assertStatus(201);

        $postId = (int) $response->json('data.id');
        $this->assertSame(5, Attachment::where('post_id', $postId)->count());
    }

    /**
     * 작성 시 상한을 1 건 넘기면 차단된다 (위 경계의 대조군).
     */
    public function test_files_over_limit_on_create_is_blocked(): void
    {
        $response = $this->actingAs($this->memberUser, 'sanctum')->postJson(
            "/api/modules/sirsoft-board/boards/{$this->getTestBoardSlug()}/posts",
            [
                'title' => '상한 초과 첨부',
                'content' => '본문은 최소 길이를 넘겨야 검증을 통과합니다.',
                'files' => [
                    UploadedFile::fake()->image('over-1.png'),
                    UploadedFile::fake()->image('over-2.png'),
                    UploadedFile::fake()->image('over-3.png'),
                    UploadedFile::fake()->image('over-4.png'),
                    UploadedFile::fake()->image('over-5.png'),
                    UploadedFile::fake()->image('over-6.png'),
                ],
            ]
        );

        $response->assertStatus(422);
        $this->assertArrayHasKey('files', $response->json('errors') ?? []);
    }

    /**
     * 단일 업로드를 반복해도 상한을 넘는 순간 차단된다.
     *
     * 한 번에 여러 개를 보내는 경로만 막으면, 한 개씩 N 번 부르는 경로로 그대로 우회된다.
     */
    public function test_repeated_single_upload_is_blocked_at_the_limit(): void
    {
        $postId = $this->createTestPost();
        $service = app(AttachmentService::class);

        for ($i = 0; $i < 5; $i++) {
            $service->upload(
                $this->getTestBoardSlug(),
                UploadedFile::fake()->image("single-{$i}.png"),
                $postId
            );
        }

        $this->assertSame(5, Attachment::where('post_id', $postId)->count());

        $this->expectException(AttachmentLimitExceededException::class);

        $service->upload(
            $this->getTestBoardSlug(),
            UploadedFile::fake()->image('single-6.png'),
            $postId
        );
    }

    /**
     * 단일 업로드 엔드포인트가 상한 초과를 422 로 돌려주는지 검증합니다.
     *
     * 이 엔드포인트는 게시글 작성 Request 들과 마찬가지로 요청 계층에서 선차단하고,
     * `AttachmentService::upload()` 가 최종 불변조건으로 남는다(훅·Service 직접 호출 방어).
     *
     * 두 계층이 같은 응답을 내야 소비자가 분기하지 않는다 — 선차단은 필드 검증 오류가 아니라
     * `AttachmentLimitExceededException` 을 던지고, 그 `render()` 가 컨트롤러 catch 와
     * 동일한 `errors.code=attachment_limit_exceeded` 를 만든다. 이 테스트가 그 동일성을 고정한다.
     */
    public function test_single_upload_endpoint_blocks_over_limit_with_422(): void
    {
        $slug = $this->getTestBoardSlug();
        $postId = $this->createTestPost(['user_id' => $this->memberUser->id]);

        $this->seedLinkedAttachments($postId, 5);

        $this->actingAs($this->memberUser, 'sanctum')
            ->postJson("/api/modules/sirsoft-board/boards/{$slug}/attachments", [
                'file' => UploadedFile::fake()->image('over-limit.png'),
                'post_id' => $postId,
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'attachment_limit_exceeded');
    }

    /**
     * 단일 업로드 상한 초과는 Service 에 닿기 전 요청 계층에서 차단된다.
     *
     * `AttachmentService` 를 통째로 mock 해 `upload()` 가 아예 호출되지 않음을 단언한다 —
     * Service 가 최종 관문으로 남아 있어도 요청 계층이 먼저 막아야 선차단이라 부를 수 있다.
     */
    public function test_single_upload_over_limit_is_blocked_before_reaching_service(): void
    {
        $slug = $this->getTestBoardSlug();
        $postId = $this->createTestPost(['user_id' => $this->memberUser->id]);

        $this->seedLinkedAttachments($postId, 5);

        $service = Mockery::mock(AttachmentService::class);
        $service->shouldNotReceive('upload');
        $this->app->instance(AttachmentService::class, $service);

        $this->actingAs($this->memberUser, 'sanctum')
            ->postJson("/api/modules/sirsoft-board/boards/{$slug}/attachments", [
                'file' => UploadedFile::fake()->image('blocked-before-service.png'),
                'post_id' => $postId,
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'attachment_limit_exceeded');
    }

    /**
     * temp_key 임시 업로드도 상한을 넘는 순간 요청 계층에서 차단된다.
     */
    public function test_single_upload_with_temp_key_over_limit_is_blocked(): void
    {
        $slug = $this->getTestBoardSlug();
        $tempKey = 'temp-single-upload-limit';

        $this->seedTempAttachments($tempKey, 5);

        $this->actingAs($this->memberUser, 'sanctum')
            ->postJson("/api/modules/sirsoft-board/boards/{$slug}/attachments", [
                'file' => UploadedFile::fake()->image('temp-over-limit.png'),
                'temp_key' => $tempKey,
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'attachment_limit_exceeded');
    }

    /**
     * 상한 이내 단일 업로드는 그대로 통과한다 (경계).
     */
    public function test_single_upload_endpoint_allows_within_limit(): void
    {
        $slug = $this->getTestBoardSlug();
        $postId = $this->createTestPost(['user_id' => $this->memberUser->id]);

        $this->seedLinkedAttachments($postId, 4);

        $this->actingAs($this->memberUser, 'sanctum')
            ->postJson("/api/modules/sirsoft-board/boards/{$slug}/attachments", [
                'file' => UploadedFile::fake()->image('within-limit.png'),
                'post_id' => $postId,
            ])
            ->assertSuccessful();
    }

    /**
     * 수정 시 `attachment_ids` 로 보낸 첨부가 사용자 경로에서도 실제로 연결된다.
     *
     * 결함: 관리자 컨트롤러는 `attachment_ids` 를 뽑아 Service 로 넘기는데 사용자 컨트롤러는
     *   넘기지 않았다. 그래서 같은 요청이 검증(형식·개수 상한 합산)은 통과하고 200 을 받는데
     *   첨부만 조용히 연결되지 않았다 — 화면에는 성공으로 보이고 첨부만 사라진다.
     *   작성 경로의 `files[]` 유실과 같은 계열이며, 두 경로가 같은 강도여야 한다.
     */
    public function test_update_links_attachment_ids_on_user_path(): void
    {
        $postId = $this->createTestPost(['user_id' => $this->memberUser->id]);

        $tempKey = 'tk_'.str_repeat('u', 20);
        $this->seedTempAttachments($tempKey, 2);
        $attachmentIds = Attachment::where('temp_key', $tempKey)->pluck('id')->all();

        $response = $this->actingAs($this->memberUser, 'sanctum')->putJson(
            "/api/modules/sirsoft-board/boards/{$this->getTestBoardSlug()}/posts/{$postId}",
            [
                'title' => '첨부 연결 수정',
                'content' => '본문은 최소 길이를 넘겨야 검증을 통과합니다.',
                'attachment_ids' => $attachmentIds,
            ]
        );

        $response->assertStatus(200);

        $this->assertSame(
            2,
            Attachment::where('post_id', $postId)->count(),
            'attachment_ids 로 보낸 첨부가 게시글에 연결되지 않았습니다 — 응답은 성공인데 첨부만 사라집니다.'
        );
    }

    /**
     * 수정 경로의 `attachment_ids` 도 개수 상한을 넘기면 차단된다 (위 경계의 대조군).
     */
    public function test_update_attachment_ids_over_limit_is_blocked(): void
    {
        $postId = $this->createTestPost(['user_id' => $this->memberUser->id]);

        $tempKey = 'tk_'.str_repeat('o', 20);
        $this->seedTempAttachments($tempKey, 6);
        $attachmentIds = Attachment::where('temp_key', $tempKey)->pluck('id')->all();

        $response = $this->actingAs($this->memberUser, 'sanctum')->putJson(
            "/api/modules/sirsoft-board/boards/{$this->getTestBoardSlug()}/posts/{$postId}",
            [
                'title' => '상한 초과 첨부 수정',
                'content' => '본문은 최소 길이를 넘겨야 검증을 통과합니다.',
                'attachment_ids' => $attachmentIds,
            ]
        );

        $response->assertStatus(422);
        $this->assertSame(0, Attachment::where('post_id', $postId)->count());
    }

    /**
     * 상한 미설정(0) 게시판은 제한하지 않는다 (기존 동작 유지).
     */
    public function test_board_without_limit_is_not_restricted(): void
    {
        $this->board->update(['max_file_count' => 0]);

        $postId = $this->createTestPost();
        $tempKey = 'tk_'.str_repeat('e', 20);
        $this->seedTempAttachments($tempKey, 8);

        $linked = app(AttachmentService::class)->linkTempAttachmentsWithMove(
            $this->getTestBoardSlug(),
            $tempKey,
            $postId
        );

        $this->assertSame(8, $linked);
    }
}
