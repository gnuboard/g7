<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

// 테스트 베이스 클래스 수동 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 비회원 게시글 전체 흐름 테스트
 *
 * 비회원(Guest)의 게시글 생성, 수정, 삭제 흐름을 종합적으로 테스트합니다.
 * - 비회원 글쓰기 권한 검증 (guest_permissions)
 * - 비밀번호 해싱 및 검증
 * - 비회원 게시글 수정/삭제 시 비밀번호 확인
 * - 비회원 파일 업로드 권한 검증
 */
class PostGuestTest extends BoardTestCase
{
    private User $boardAdminUser;

    /**
     * 테스트 게시판 slug
     */
    protected function getTestBoardSlug(): string
    {
        return 'post-guest';
    }

    /**
     * 기본 게시판 속성 (비회원 글쓰기 허용)
     */
    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '비회원 테스트 게시판', 'en' => 'Guest Test Board'],
            'is_active' => true,
            'use_file_upload' => true,
            'secret_mode' => 'enabled',
            'blocked_keywords' => [],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // 게시판 설정이 올바른지 확인 (firstOrCreate는 기존 Board를 업데이트하지 않음)
        $this->board->update([
            'use_file_upload' => true,
            'secret_mode' => 'enabled',
        ]);
        $this->board->refresh();

        // 게시판 관리자 생성
        $this->createBoardAdminUser();
    }

    /**
     * 게시판 관리자 사용자 생성
     *
     * User 페이지 라우트는 permission:user 타입 미들웨어를 사용하므로
     * admin 타입 권한만으로는 접근 불가. user 타입 권한도 함께 부여합니다.
     */
    private function createBoardAdminUser(): void
    {
        $this->boardAdminUser = User::factory()->create();

        $slug = $this->board->slug;

        // 게시판 관리자 역할 생성
        $boardAdminRole = Role::firstOrCreate(
            ['identifier' => "{$slug}-board-admin"],
            ['name' => ['ko' => '게시판 관리자', 'en' => 'Board Admin']]
        );

        // user 타입 권한 부여 (라우트 미들웨어 통과용)
        $userPermissions = ['posts.read', 'posts.write', 'manager'];
        foreach ($userPermissions as $key) {
            $perm = Permission::firstOrCreate(
                ['identifier' => "sirsoft-board.{$slug}.{$key}"],
                ['name' => ['ko' => $key, 'en' => $key], 'type' => 'user']
            );
            $boardAdminRole->permissions()->syncWithoutDetaching([$perm->id]);
        }

        // admin 타입 관리 권한 부여 (관리자 기능용)
        $managePermission = Permission::firstOrCreate(
            ['identifier' => "sirsoft-board.{$slug}.admin.manage"],
            ['name' => ['ko' => '관리', 'en' => 'Manage'], 'type' => 'admin']
        );
        $boardAdminRole->permissions()->syncWithoutDetaching([$managePermission->id]);

        $this->boardAdminUser->roles()->attach($boardAdminRole->id);
    }

    // ==========================================
    // 비회원 게시글 생성 테스트
    // ==========================================

    /**
     * 비회원이 권한이 있을 때 게시글을 생성할 수 있다
     */
    public function test_guest_can_create_post_with_permission(): void
    {
        // Given: 비회원 글쓰기 권한 있음 (기본 설정)

        // When: 비회원이 게시글 생성
        $response = $this->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts", [
            'title' => '비회원 테스트 게시글',
            'content' => '비회원이 작성한 내용입니다.',
            'author_name' => '비회원작성자',
            'password' => 'test1234',
        ]);

        // Then: 201 성공
        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', '비회원 테스트 게시글')
            ->assertJsonPath('data.author.name', '비회원작성자');

        // 비밀번호가 해시되어 저장되었는지 확인
        $post = DB::table('board_posts')->where('board_id', $this->board->id)->orderBy('id', 'desc')->first();
        $this->assertTrue(Hash::check('test1234', $post->password));
        $this->assertNull($post->user_id);
    }

    /**
     * 비회원 게시글 생성 시 비밀번호 필수
     */
    public function test_guest_post_requires_password(): void
    {
        // When: 비밀번호 없이 게시글 생성 시도
        $response = $this->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts", [
            'title' => '비회원 게시글',
            'content' => '내용입니다. 최소 10자 이상.',
            'author_name' => '비회원',
            // password 누락
        ]);

        // Then: 422 에러 (비밀번호 필수)
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    /**
     * 비회원이 권한이 없을 때 게시글 생성 불가
     */
    public function test_guest_cannot_create_post_without_permission(): void
    {
        // Given: 비회원 글쓰기 권한 제거 (posts.write 제외)
        $this->setGuestPermissions(['posts.read']);

        // When: 비회원이 게시글 생성 시도
        $response = $this->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts", [
            'title' => '비회원 게시글',
            'content' => '내용입니다. 최소 10자 이상.',
            'author_name' => '비회원',
            'password' => 'test1234',
        ]);

        // Then: 401 에러 (PermissionMiddleware는 guest에 권한 없을 때 401 반환)
        $response->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    /**
     * 비회원이 비밀글을 작성할 수 있다 (secret_mode=enabled)
     */
    public function test_guest_can_create_secret_post(): void
    {
        // Given: secret_mode=enabled (기본 설정)

        // When: 비회원이 비밀글 작성
        $response = $this->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts", [
            'title' => '비회원 비밀글',
            'content' => '비밀 내용입니다. 최소 10자 이상.',
            'author_name' => '비회원',
            'password' => 'secret123',
            'is_secret' => true,
        ]);

        // Then: 201 성공, is_secret=true
        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_secret', true);
    }

    // ==========================================
    // 비회원 게시글 수정 테스트
    // ==========================================

    /**
     * 비회원이 올바른 비밀번호로 본인 게시글을 수정할 수 있다
     */
    public function test_guest_can_update_own_post_with_correct_password(): void
    {
        // Given: 비회원 게시글 생성
        $password = 'original123';
        $postId = $this->createTestPost([
            'title' => '원본 제목',
            'content' => '원본 내용입니다. 최소 10자 이상.',
            'password' => Hash::make($password),
        ]);

        // When: 올바른 비밀번호로 수정 요청
        $response = $this->putJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}", [
            'title' => '수정된 제목',
            'content' => '수정된 내용입니다. 최소 10자 이상.',
            'password' => $password,
        ]);

        // Then: 성공
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', '수정된 제목');
    }

    /**
     * 비회원이 잘못된 비밀번호로 게시글 수정 불가
     */
    public function test_guest_cannot_update_post_with_wrong_password(): void
    {
        // Given: 비회원 게시글 생성
        $postId = $this->createTestPost([
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
     * 비회원이 비밀번호 없이 게시글 수정 불가
     */
    public function test_guest_cannot_update_post_without_password(): void
    {
        // Given: 비회원 게시글 생성
        $postId = $this->createTestPost([
            'password' => Hash::make('secret'),
        ]);

        // When: 비밀번호 없이 수정 요청
        $response = $this->putJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}", [
            'title' => '수정된 제목',
            // password 누락
        ]);

        // Then: 403 에러
        $response->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    // ==========================================
    // 비회원 게시글 삭제 테스트
    // ==========================================

    /**
     * 비회원이 올바른 비밀번호로 본인 게시글을 삭제할 수 있다
     */
    public function test_guest_can_delete_own_post_with_correct_password(): void
    {
        // Given: 비회원 게시글 생성
        $password = 'delete123';
        $postId = $this->createTestPost([
            'title' => '삭제할 게시글',
            'password' => Hash::make($password),
        ]);

        // When: 올바른 비밀번호로 삭제 요청
        $response = $this->deleteJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}", [
            'password' => $password,
        ]);

        // Then: 성공
        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        // 소프트 삭제 확인
        $post = DB::table('board_posts')->where('board_id', $this->board->id)->where('id', $postId)->first();
        $this->assertNotNull($post->deleted_at);
    }

    /**
     * 비회원이 잘못된 비밀번호로 게시글 삭제 불가
     */
    public function test_guest_cannot_delete_post_with_wrong_password(): void
    {
        // Given: 비회원 게시글 생성
        $postId = $this->createTestPost([
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
     * 비회원이 비밀번호 없이 게시글 삭제 불가
     */
    public function test_guest_cannot_delete_post_without_password(): void
    {
        // Given: 비회원 게시글 생성
        $postId = $this->createTestPost([
            'password' => Hash::make('secret'),
        ]);

        // When: 비밀번호 없이 삭제 요청
        $response = $this->deleteJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}");

        // Then: 403 에러
        $response->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    // ==========================================
    // 비회원 게시글 조회 테스트
    // ==========================================

    /**
     * 비회원이 일반 게시글 목록을 조회할 수 있다
     */
    public function test_guest_can_view_post_list(): void
    {
        // Given: 게시글 생성
        $this->createTestPost(['title' => '첫 번째 게시글']);
        $this->createTestPost(['title' => '두 번째 게시글']);

        // When: 비회원이 목록 조회
        $response = $this->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts");

        // Then: 성공, 게시글 2개 반환
        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        // 페이지네이션된 응답 구조인 경우 data.data를 확인
        $data = $response->json('data');
        if (isset($data['data'])) {
            $this->assertCount(2, $data['data']);
        } else {
            $this->assertCount(2, $data);
        }
    }

    /**
     * 비회원이 일반 게시글 상세를 조회할 수 있다
     */
    public function test_guest_can_view_normal_post_detail(): void
    {
        // Given: 일반 게시글 생성
        $postId = $this->createTestPost([
            'title' => '일반 게시글',
            'content' => '일반 내용입니다.',
            'is_secret' => false,
        ]);

        // When: 비회원이 상세 조회
        $response = $this->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}");

        // Then: 성공, 내용 반환
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', '일반 게시글')
            ->assertJsonPath('data.content', '일반 내용입니다.');
    }

    /**
     * 비회원이 비밀글 상세 조회 시 content가 null로 반환 (비밀번호 없이)
     */
    public function test_guest_cannot_view_secret_post_without_password(): void
    {
        // Given: 비밀글 생성
        $postId = $this->createTestPost([
            'title' => '비밀글',
            'content' => '비밀 내용입니다.',
            'is_secret' => true,
            'password' => Hash::make('secret'),
        ]);

        // When: 비회원이 비밀번호 없이 상세 조회
        $response = $this->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}");

        // Then: 200 반환하되 content가 null (PostResource에서 필터링)
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_secret', true)
            ->assertJsonPath('data.content', null);
    }

    /**
     * 비회원이 비밀글 상세 조회 시 비밀번호로 검증 가능
     */
    public function test_guest_can_view_secret_post_with_password_verification(): void
    {
        // Given: 비밀글 생성
        $password = 'viewsecret';
        $postId = $this->createTestPost([
            'title' => '비밀글',
            'content' => '비밀 내용입니다.',
            'is_secret' => true,
            'password' => Hash::make($password),
        ]);

        // When: 비밀번호 검증 API 호출
        $response = $this->postJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/verify-password",
            ['password' => $password]
        );

        // Then: 성공, 내용 반환
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.content', '비밀 내용입니다.');
    }

    // ==========================================
    // 게시판 관리자 권한 테스트
    // ==========================================

    /**
     * 게시판 관리자가 비회원 게시글을 삭제할 수 있다 (비밀번호 없이)
     */
    public function test_board_admin_can_delete_guest_post_without_password(): void
    {
        // Given: 비회원 게시글 생성
        $postId = $this->createTestPost([
            'title' => '비회원 게시글',
            'password' => Hash::make('guest_password'),
        ]);

        // When: 게시판 관리자가 비밀번호 없이 삭제
        $response = $this->actingAs($this->boardAdminUser)
            ->deleteJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}");

        // Then: 성공
        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    /**
     * 게시판 관리자가 비회원 게시글을 수정할 수 있다 (비밀번호 없이)
     */
    public function test_board_admin_can_update_guest_post_without_password(): void
    {
        // Given: 비회원 게시글 생성
        $postId = $this->createTestPost([
            'title' => '비회원 게시글',
            'password' => Hash::make('guest_password'),
        ]);

        // When: 게시판 관리자가 비밀번호 없이 수정
        $response = $this->actingAs($this->boardAdminUser)
            ->putJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}", [
                'title' => '관리자가 수정한 제목',
            ]);

        // Then: 성공
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', '관리자가 수정한 제목');
    }

    /**
     * 게시판 관리자가 비회원 비밀글 내용을 조회할 수 있다
     */
    public function test_board_admin_can_view_guest_secret_post(): void
    {
        // Given: 비회원 비밀글 생성
        $postId = $this->createTestPost([
            'title' => '비회원 비밀글',
            'content' => '비밀 내용입니다.',
            'is_secret' => true,
            'password' => Hash::make('secret'),
        ]);

        // When: 게시판 관리자가 조회
        $response = $this->actingAs($this->boardAdminUser)
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}");

        // Then: 성공, 내용 반환
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.content', '비밀 내용입니다.');
    }

    // ==========================================
    // 비회원 파일 업로드 권한 테스트
    // ==========================================

    /**
     * 비회원이 파일 업로드 권한 없을 때 파일 첨부 불가
     */
    public function test_guest_cannot_upload_file_without_permission(): void
    {
        // Given: 비회원 파일 업로드 권한 없음 (attachments.upload 제외)
        $this->updateBoardSettings(['use_file_upload' => true]);
        $this->setGuestPermissions(['posts.read', 'posts.write']);

        // 테스트용 파일 생성
        $file = \Illuminate\Http\UploadedFile::fake()->create('test.pdf', 100);

        // When: 비회원이 파일과 함께 게시글 생성
        $response = $this->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts", [
            'title' => '파일 첨부 게시글',
            'content' => '내용입니다. 최소 10자 이상.',
            'author_name' => '비회원',
            'password' => 'test1234',
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
        // Given: 비회원 파일 업로드 권한 있음 (기본 설정)

        // 테스트용 파일 생성
        $file = \Illuminate\Http\UploadedFile::fake()->create('test.pdf', 100);

        // When: 비회원이 파일과 함께 게시글 생성
        $response = $this->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts", [
            'title' => '파일 첨부 게시글',
            'content' => '내용입니다. 최소 10자 이상.',
            'author_name' => '비회원',
            'password' => 'test1234',
            'files' => [$file],
        ]);

        // Then: 성공
        $response->assertStatus(201)
            ->assertJsonPath('success', true);
    }

    // ==========================================
    // 전체 흐름 테스트 (E2E)
    // ==========================================

    /**
     * 비회원 게시글 전체 흐름 테스트 (생성 → 조회 → 수정 → 삭제)
     */
    public function test_guest_post_full_lifecycle(): void
    {
        $password = 'lifecycle123';

        // 1. 생성
        $createResponse = $this->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts", [
            'title' => '비회원 전체 흐름 테스트',
            'content' => '초기 내용입니다. 최소 10자 이상.',
            'author_name' => '테스트비회원',
            'password' => $password,
        ]);

        $createResponse->assertStatus(201);
        $postId = $createResponse->json('data.id');

        // 2. 조회 (일반 게시글이므로 내용 표시)
        $showResponse = $this->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}");
        $showResponse->assertStatus(200)
            ->assertJsonPath('data.title', '비회원 전체 흐름 테스트');

        // 3. 수정
        $updateResponse = $this->putJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}", [
            'title' => '수정된 전체 흐름 테스트',
            'content' => '수정된 내용입니다.',
            'password' => $password,
        ]);
        $updateResponse->assertStatus(200)
            ->assertJsonPath('data.title', '수정된 전체 흐름 테스트');

        // 4. 삭제
        $deleteResponse = $this->deleteJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}", [
            'password' => $password,
        ]);
        $deleteResponse->assertStatus(200);

        // 5. 삭제 후 조회 시 404 또는 삭제된 게시글 (목록에서 제외 확인)
        $listResponse = $this->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts");
        $listResponse->assertStatus(200);

        // 삭제된 게시글이 목록에 없는지 확인 (페이지네이션 응답: data.data)
        $posts = $listResponse->json('data.data');
        $this->assertIsArray($posts);
        $postIds = array_column($posts, 'id');
        $this->assertNotContains($postId, $postIds);
    }
}