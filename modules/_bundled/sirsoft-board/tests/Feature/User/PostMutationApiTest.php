<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

// 테스트 베이스 클래스 수동 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 게시글 생성/수정/삭제 API 테스트
 *
 * 게시판 설정에 따른 게시글 CRUD 동작 검증:
 * - 비회원 권한 (role/permission 시스템)
 * - 파일 업로드 허용/불허 (use_file_upload)
 * - 비밀글 모드 (secret_mode: disabled, enabled, always)
 * - 금지 키워드 검증 (blocked_keywords)
 * - 수정/삭제 권한 (작성자, 비밀번호)
 */
class PostMutationApiTest extends BoardTestCase
{
    private User $memberUser;

    private User $otherUser;

    /**
     * 테스트 게시판 slug
     */
    protected function getTestBoardSlug(): string
    {
        return 'mutation-test';
    }

    /**
     * 기본 게시판 속성
     */
    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '뮤테이션 테스트 게시판', 'en' => 'Mutation Test Board'],
            'is_active' => true,
            'use_file_upload' => true,
            'secret_mode' => 'enabled',
            'blocked_keywords' => [],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // user 역할에 게시판 권한 부여
        $this->grantUserRolePermissions();

        // 테스트 사용자 생성 (parent::setUp()에서 users 테이블 초기화됨)
        $this->memberUser = User::factory()->create(['email' => 'member@test.com']);
        $this->otherUser = User::factory()->create(['email' => 'other@test.com']);

        // member 사용자에 user 역할 부여
        $userRole = \App\Models\Role::where('identifier', 'user')->first();
        $this->memberUser->roles()->attach($userRole->id);
        $this->otherUser->roles()->attach($userRole->id);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * 테스트 게시글을 생성합니다.
     */
    protected function createTestPost(array $attributes = []): int
    {
        $defaults = [
            'board_id' => $this->board->id,
            'title' => '테스트 게시글',
            'content' => '테스트 내용입니다.',
            'user_id' => null,
            'author_name' => '테스트',
            'password' => null,
            'ip_address' => '127.0.0.1',
            'is_notice' => false,
            'is_secret' => false,
            'status' => 'published',
            'trigger_type' => 'admin',
            'view_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        return DB::table('board_posts')->insertGetId(array_merge($defaults, $attributes));
    }

    // ==========================================
    // 게시글 생성 테스트 (POST /boards/{slug}/posts)
    // ==========================================

    /**
     * 회원이 게시글을 생성할 수 있다
     */
    public function test_member_can_create_post(): void
    {
        // When: 회원이 게시글 생성 요청
        $response = $this->actingAs($this->memberUser)
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts", [
                'title' => '회원 게시글',
                'content' => '회원이 작성한 내용입니다.',
                'author_name' => '회원작성자',
            ]);

        // Then: 201 성공
        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', '회원 게시글');

        // 데이터베이스 확인
        $this->assertDatabaseHas('board_posts', [
            'board_id' => $this->board->id,
            'title' => '회원 게시글',
            'user_id' => $this->memberUser->id,
        ]);
    }

    /**
     * 비회원이 글쓰기 허용된 게시판에서 게시글을 생성할 수 있다
     */
    public function test_guest_can_create_post_when_allowed(): void
    {
        // Given: 비회원 글쓰기 허용 (기본 설정)

        // When: 비회원이 게시글 생성 요청
        $response = $this->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts", [
            'title' => '비회원 게시글',
            'content' => '비회원이 작성한 내용입니다.',
            'author_name' => '비회원',
            'password' => 'guest1234',
        ]);

        // Then: 201 성공
        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', '비회원 게시글');

        // 비밀번호가 해시되어 저장되었는지 확인
        $post = DB::table('board_posts')->where('board_id', $this->board->id)->first();
        $this->assertTrue(Hash::check('guest1234', $post->password));
    }

    /**
     * 비회원이 글쓰기 불허 게시판에서 게시글 생성 시 401 반환
     */
    public function test_guest_cannot_create_post_when_not_allowed(): void
    {
        // Given: 비회원 글쓰기 불허 (posts.write 권한 제거)
        $this->setGuestPermissions(['posts.read']);

        // When: 비회원이 게시글 생성 요청
        $response = $this->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts", [
            'title' => '비회원 게시글',
            'content' => '비회원이 작성한 내용입니다.',
            'author_name' => '비회원',
            'password' => 'guest1234',
        ]);

        // Then: 401 에러 (PermissionMiddleware는 guest 권한 부족 시 401 반환)
        $response->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    /**
     * 파일 업로드가 불허된 게시판에서 파일 첨부 시 403 반환
     */
    public function test_file_upload_blocked_when_not_allowed(): void
    {
        // Given: 파일 업로드 불허
        $this->updateBoardSettings(['use_file_upload' => false]);

        // 테스트용 가짜 파일 생성
        $file = \Illuminate\Http\UploadedFile::fake()->create('test.pdf', 100);

        // When: 파일과 함께 게시글 생성 요청
        $response = $this->actingAs($this->memberUser)
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts", [
                'title' => '파일 첨부 게시글',
                'content' => '충분한 길이의 테스트 내용입니다.',
                'author_name' => '작성자',
                'files' => [$file],
            ]);

        // Then: 403 에러
        $response->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    /**
     * 비회원이 파일 업로드 권한 없이 파일 첨부 시 403 반환
     */
    public function test_guest_file_upload_blocked_when_no_permission(): void
    {
        // Given: 비회원 파일 업로드 권한 없음 (attachments.upload 권한 제거)
        $this->updateBoardSettings(['use_file_upload' => true]);
        $this->setGuestPermissions(['posts.read', 'posts.write']); // attachments.upload 없음

        // 테스트용 가짜 파일 생성
        $file = \Illuminate\Http\UploadedFile::fake()->create('test.pdf', 100);

        // When: 비회원이 파일과 함께 게시글 생성 요청
        $response = $this->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts", [
            'title' => '파일 첨부 게시글',
            'content' => '충분한 길이의 테스트 내용입니다.',
            'author_name' => '비회원',
            'password' => 'guest1234',
            'files' => [$file],
        ]);

        // Then: 403 에러
        $response->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    /**
     * 비회원이 파일 업로드 권한 있을 때 파일 첨부 가능
     */
    public function test_guest_can_upload_file_with_permission(): void
    {
        // Given: 비회원 파일 업로드 권한 있음
        // 기본 설정에 attachments.upload 포함되어 있음

        // 테스트용 가짜 파일 생성
        $file = \Illuminate\Http\UploadedFile::fake()->create('test.pdf', 100);

        // When: 비회원이 파일과 함께 게시글 생성 요청
        $response = $this->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts", [
            'title' => '파일 첨부 게시글',
            'content' => '충분한 길이의 테스트 내용입니다.',
            'author_name' => '비회원',
            'password' => 'guest1234',
            'files' => [$file],
        ]);

        // Then: 201 성공
        $response->assertStatus(201)
            ->assertJsonPath('success', true);
    }

    /**
     * secret_mode가 always일 때 is_secret이 자동으로 true 설정
     */
    public function test_secret_mode_always_auto_sets_is_secret(): void
    {
        // Given: secret_mode = always
        $this->updateBoardSettings(['secret_mode' => 'always']);

        // When: is_secret을 명시하지 않고 게시글 생성
        $response = $this->actingAs($this->memberUser)
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts", [
                'title' => '일반 게시글',
                'content' => '충분한 길이의 테스트 내용입니다.',
                'author_name' => '작성자',
                // is_secret 명시하지 않음
            ]);

        // Then: 성공하고 is_secret이 true로 설정됨
        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_secret', true);
    }

    /**
     * secret_mode가 always일 때 is_secret=false로 요청해도 true로 강제 설정
     */
    public function test_secret_mode_always_forces_is_secret_even_when_false(): void
    {
        // Given: secret_mode = always
        $this->updateBoardSettings(['secret_mode' => 'always']);

        // When: is_secret을 false로 명시하고 게시글 생성
        $response = $this->actingAs($this->memberUser)
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts", [
                'title' => '일반 게시글',
                'content' => '충분한 길이의 테스트 내용입니다.',
                'author_name' => '작성자',
                'is_secret' => false,
            ]);

        // Then: 성공하고 is_secret이 true로 강제 설정됨
        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_secret', true);
    }

    /**
     * secret_mode가 enabled일 때 사용자가 is_secret 선택 가능
     */
    public function test_secret_mode_enabled_allows_user_choice(): void
    {
        // Given: secret_mode = enabled (기본 설정)

        // When: is_secret=true로 게시글 생성
        $response = $this->actingAs($this->memberUser)
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts", [
                'title' => '비밀 게시글',
                'content' => '충분한 길이의 비밀 내용입니다.',
                'author_name' => '작성자',
                'is_secret' => true,
            ]);

        // Then: 성공하고 is_secret이 true
        $response->assertStatus(201)
            ->assertJsonPath('data.is_secret', true);
    }

    /**
     * secret_mode가 disabled일 때 is_secret=true 요청 거부
     */
    public function test_secret_mode_disabled_rejects_is_secret(): void
    {
        // Given: secret_mode = disabled
        $this->updateBoardSettings(['secret_mode' => 'disabled']);

        // When: is_secret=true로 게시글 생성 시도
        $response = $this->actingAs($this->memberUser)
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts", [
                'title' => '게시글',
                'content' => '충분한 길이의 테스트 내용입니다.',
                'author_name' => '작성자',
                'is_secret' => true,
            ]);

        // Then: 비밀글 기능 비활성화로 403 반환
        $response->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    /**
     * 금지 키워드가 제목에 포함된 경우 422 반환
     */
    public function test_blocked_keyword_in_title_returns_422(): void
    {
        // Given: 금지 키워드 설정
        $this->updateBoardSettings(['blocked_keywords' => ['광고', '스팸']]);

        // When: 금지 키워드가 포함된 제목으로 게시글 생성
        $response = $this->actingAs($this->memberUser)
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts", [
                'title' => '이것은 광고입니다',
                'content' => '충분한 길이의 정상 내용입니다.',
                'author_name' => '작성자',
            ]);

        // Then: 422 에러
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['title']);
    }

    /**
     * 금지 키워드가 내용에 포함된 경우 422 반환
     */
    public function test_blocked_keyword_in_content_returns_422(): void
    {
        // Given: 금지 키워드 설정
        $this->updateBoardSettings(['blocked_keywords' => ['광고', '스팸']]);

        // When: 금지 키워드가 포함된 내용으로 게시글 생성
        $response = $this->actingAs($this->memberUser)
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts", [
                'title' => '정상 제목',
                'content' => '이것은 스팸 내용입니다.',
                'author_name' => '작성자',
            ]);

        // Then: 422 에러
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['content']);
    }

    /**
     * 필수 필드 누락 시 422 반환
     */
    public function test_missing_required_fields_returns_422(): void
    {
        // When: 필수 필드 없이 요청
        $response = $this->actingAs($this->memberUser)
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts", [
                // title, content, author_name 누락
            ]);

        // Then: 422 에러 (인증된 사용자에게 author_name은 필수 아님)
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'content']);
    }

    /**
     * 비활성화된 게시판에 게시글 생성 시 404 반환
     */
    public function test_create_post_on_inactive_board_returns_404(): void
    {
        // Given: 비활성화된 게시판
        $this->updateBoardSettings(['is_active' => false]);

        // When: 게시글 생성 요청
        $response = $this->actingAs($this->memberUser)
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts", [
                'title' => '비활성 게시판 테스트',
                'content' => '충분한 길이의 테스트 내용입니다.',
            ]);

        // Then: 404 에러
        $response->assertStatus(404);
    }

    // ==========================================
    // 게시글 수정 테스트 (PUT /boards/{slug}/posts/{id})
    // ==========================================

    /**
     * 작성자 본인이 회원 게시글을 수정할 수 있다
     */
    public function test_author_can_update_own_member_post(): void
    {
        // Given: 회원 게시글 생성
        $postId = $this->createTestPost([
            'title' => '원본 제목',
            'content' => '원본 내용',
            'user_id' => $this->memberUser->id,
            'author_name' => $this->memberUser->name,
        ]);

        // When: 작성자가 수정 요청
        $response = $this->actingAs($this->memberUser)
            ->putJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}", [
                'title' => '수정된 제목',
                'content' => '충분한 길이의 수정된 내용입니다.',
            ]);

        // Then: 성공
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', '수정된 제목');
    }

    /**
     * 다른 사용자가 회원 게시글 수정 시 403 반환
     */
    public function test_other_user_cannot_update_member_post(): void
    {
        // Given: 회원 게시글 생성
        $postId = $this->createTestPost([
            'user_id' => $this->memberUser->id,
            'author_name' => $this->memberUser->name,
        ]);

        // When: 다른 사용자가 수정 요청
        $response = $this->actingAs($this->otherUser)
            ->putJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}", [
                'title' => '수정된 제목',
            ]);

        // Then: 403 에러
        $response->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    /**
     * 비회원이 올바른 비밀번호로 비회원 게시글을 수정할 수 있다
     */
    public function test_guest_can_update_own_post_with_correct_password(): void
    {
        // Given: 비회원 게시글 생성
        $password = 'guest1234';
        $postId = $this->createTestPost([
            'title' => '비회원 게시글',
            'user_id' => null,
            'author_name' => '비회원',
            'password' => Hash::make($password),
        ]);

        // When: 올바른 비밀번호로 수정 요청
        $response = $this->putJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}", [
            'title' => '수정된 비회원 게시글',
            'password' => $password,
        ]);

        // Then: 성공
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', '수정된 비회원 게시글');
    }

    /**
     * 비회원이 잘못된 비밀번호로 수정 시 403 반환
     */
    public function test_guest_cannot_update_post_with_wrong_password(): void
    {
        // Given: 비회원 게시글 생성
        $postId = $this->createTestPost([
            'user_id' => null,
            'password' => Hash::make('correct_password'),
        ]);

        // When: 잘못된 비밀번호로 수정 요청
        $response = $this->putJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}", [
            'title' => '수정된 제목',
            'password' => 'wrong_password',
        ]);

        // Then: 403 에러
        $response->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    /**
     * 존재하지 않는 게시글 수정 시 404 반환
     */
    public function test_update_non_existent_post_returns_404(): void
    {
        // When: 존재하지 않는 게시글 수정 요청
        $response = $this->actingAs($this->memberUser)
            ->putJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/99999", [
                'title' => '수정된 제목',
            ]);

        // Then: 404 에러
        $response->assertStatus(404);
    }

    /**
     * 수정 시 금지 키워드가 포함되면 422 반환
     */
    public function test_update_with_blocked_keyword_returns_422(): void
    {
        // Given: 금지 키워드 설정 및 게시글 생성
        $this->updateBoardSettings(['blocked_keywords' => ['광고']]);
        $postId = $this->createTestPost([
            'user_id' => $this->memberUser->id,
            'author_name' => $this->memberUser->name,
        ]);

        // When: 금지 키워드가 포함된 내용으로 수정
        $response = $this->actingAs($this->memberUser)
            ->putJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}", [
                'title' => '광고 제목',
            ]);

        // Then: 422 에러
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['title']);
    }

    // ==========================================
    // 게시글 삭제 테스트 (DELETE /boards/{slug}/posts/{id})
    // ==========================================

    /**
     * 작성자 본인이 회원 게시글을 삭제할 수 있다
     */
    public function test_author_can_delete_own_member_post(): void
    {
        // Given: 회원 게시글 생성
        $postId = $this->createTestPost([
            'user_id' => $this->memberUser->id,
            'author_name' => $this->memberUser->name,
        ]);

        // When: 작성자가 삭제 요청
        $response = $this->actingAs($this->memberUser)
            ->deleteJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}");

        // Then: 성공
        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        // 소프트 삭제 확인
        $post = DB::table('board_posts')->where('id', $postId)->first();
        $this->assertNotNull($post->deleted_at);
    }

    /**
     * 다른 사용자가 회원 게시글 삭제 시 403 반환
     */
    public function test_other_user_cannot_delete_member_post(): void
    {
        // Given: 회원 게시글 생성
        $postId = $this->createTestPost([
            'user_id' => $this->memberUser->id,
            'author_name' => $this->memberUser->name,
        ]);

        // When: 다른 사용자가 삭제 요청
        $response = $this->actingAs($this->otherUser)
            ->deleteJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}");

        // Then: 403 에러
        $response->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    /**
     * 비회원이 올바른 비밀번호로 비회원 게시글을 삭제할 수 있다
     */
    public function test_guest_can_delete_own_post_with_correct_password(): void
    {
        // Given: 비회원 게시글 생성
        $password = 'guest1234';
        $postId = $this->createTestPost([
            'user_id' => null,
            'author_name' => '비회원',
            'password' => Hash::make($password),
        ]);

        // When: 올바른 비밀번호로 삭제 요청
        $response = $this->deleteJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}", [
            'password' => $password,
        ]);

        // Then: 성공
        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    /**
     * 비회원이 잘못된 비밀번호로 삭제 시 403 반환
     */
    public function test_guest_cannot_delete_post_with_wrong_password(): void
    {
        // Given: 비회원 게시글 생성
        $postId = $this->createTestPost([
            'user_id' => null,
            'password' => Hash::make('correct_password'),
        ]);

        // When: 잘못된 비밀번호로 삭제 요청
        $response = $this->deleteJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}", [
            'password' => 'wrong_password',
        ]);

        // Then: 403 에러
        $response->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    /**
     * 존재하지 않는 게시글 삭제 시 404 반환
     */
    public function test_delete_non_existent_post_returns_404(): void
    {
        // When: 존재하지 않는 게시글 삭제 요청
        $response = $this->actingAs($this->memberUser)
            ->deleteJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/99999");

        // Then: 404 에러
        $response->assertStatus(404);
    }

    /**
     * 비로그인 사용자가 비밀번호 없이 삭제 시 403 반환
     */
    public function test_guest_without_password_cannot_delete_post(): void
    {
        // Given: 비회원 게시글 생성
        $postId = $this->createTestPost([
            'user_id' => null,
            'password' => Hash::make('secret'),
        ]);

        // When: 비밀번호 없이 삭제 요청
        $response = $this->deleteJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}");

        // Then: 403 에러
        $response->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    /**
     * 게시글 소프트삭제 시 댓글은 삭제되지 않고 유지됨
     */
    public function test_soft_delete_post_preserves_comments(): void
    {
        // Given: 게시글 + 댓글 생성
        $postId = $this->createTestPost(['user_id' => $this->memberUser->id]);
        $commentId = $this->createTestComment($postId);

        // When: 게시글 삭제
        $this->actingAs($this->memberUser)
            ->deleteJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}")
            ->assertStatus(200);

        // Then: 댓글은 deleted_at 없이 유지
        $comment = DB::table('board_comments')->where('id', $commentId)->first();
        $this->assertNotNull($comment, '댓글이 DB에 존재해야 합니다');
        $this->assertNull($comment->deleted_at, '댓글이 소프트삭제되지 않아야 합니다');
    }

    /**
     * 게시글 소프트삭제 시 첨부파일 레코드는 삭제되지 않고 유지됨
     */
    public function test_soft_delete_post_preserves_attachments(): void
    {
        // Given: 게시글 + 첨부파일 레코드 직접 삽입
        $postId = $this->createTestPost(['user_id' => $this->memberUser->id]);
        $attachmentId = DB::table('board_attachments')->insertGetId([
            'board_id' => $this->board->id,
            'post_id' => $postId,
            'hash' => substr(md5(uniqid()), 0, 12),
            'original_filename' => 'test.txt',
            'stored_filename' => 'test_stored.txt',
            'mime_type' => 'text/plain',
            'size' => 100,
            'disk' => 'local',
            'path' => 'board/test_stored.txt',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // When: 게시글 삭제
        $this->actingAs($this->memberUser)
            ->deleteJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}")
            ->assertStatus(200);

        // Then: 첨부파일 레코드는 deleted_at 없이 유지
        $attachment = DB::table('board_attachments')->where('id', $attachmentId)->first();
        $this->assertNotNull($attachment, '첨부파일 레코드가 DB에 존재해야 합니다');
        $this->assertNull($attachment->deleted_at, '첨부파일이 소프트삭제되지 않아야 합니다');
    }
}
