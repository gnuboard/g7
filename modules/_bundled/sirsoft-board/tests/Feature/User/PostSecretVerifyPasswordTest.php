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
 * 비밀글 비밀번호 검증 API 테스트
 *
 * 비밀글(is_secret=true)의 비밀번호 검증 엔드포인트를 테스트합니다.
 * - verify-password: 비밀글 조회용 (posts.read 권한 필요)
 * - verify-password-for-modify: 비밀글 수정용 (posts.write 권한 필요)
 *
 * 테스트 시나리오:
 * - 비회원 비밀글 비밀번호 검증 (읽기/수정)
 * - 회원 비밀글 비밀번호 검증 (작성자/타인)
 * - 관리자 권한 우회
 * - 잘못된 비밀번호 처리
 */
class PostSecretVerifyPasswordTest extends BoardTestCase
{
    private User $boardAdminUser;

    private User $secretPostReadUser;

    private User $regularUser;

    /**
     * 테스트 게시판 slug
     */
    protected function getTestBoardSlug(): string
    {
        return 'post-secret-verify';
    }

    /**
     * 기본 게시판 속성 (비밀글 기능 활성화)
     */
    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '비밀글 검증 테스트 게시판', 'en' => 'Secret Post Verify Test Board'],
            'is_active' => true,
            'use_file_upload' => true,
            'secret_mode' => 'enabled',
            'blocked_keywords' => [],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // 테스트용 사용자 생성
        $this->createTestUsers();
    }

    /**
     * 테스트용 사용자 생성
     */
    private function createTestUsers(): void
    {
        $slug = $this->board->slug;

        // 공통 사용자 권한: posts.read, posts.write (라우트 접근에 필요)
        $postsReadPermission = Permission::firstOrCreate(
            ['identifier' => "sirsoft-board.{$slug}.posts.read"],
            [
                'name' => ['ko' => '게시글 조회', 'en' => 'Read Posts'],
                'slug' => "sirsoft-board.{$slug}.posts.read",
                'type' => 'user',
            ]
        );
        $postsWritePermission = Permission::firstOrCreate(
            ['identifier' => "sirsoft-board.{$slug}.posts.write"],
            [
                'name' => ['ko' => '게시글 작성', 'en' => 'Write Posts'],
                'slug' => "sirsoft-board.{$slug}.posts.write",
                'type' => 'user',
            ]
        );

        $userRole = Role::firstOrCreate(
            ['identifier' => "{$slug}-user"],
            ['name' => ['ko' => '일반 사용자', 'en' => 'Regular User']]
        );
        $userRole->permissions()->syncWithoutDetaching([
            $postsReadPermission->id,
            $postsWritePermission->id,
        ]);

        // 1. 게시판 관리자 (admin.posts.read + admin.manage + user 라우트 접근용 posts.read)
        $this->boardAdminUser = User::factory()->create();
        $adminPostsReadPermission = Permission::firstOrCreate(
            ['identifier' => "sirsoft-board.{$slug}.admin.posts.read"],
            [
                'name' => ['ko' => '관리자 게시글 조회', 'en' => 'Admin Posts Read'],
                'slug' => "sirsoft-board.{$slug}.admin.posts.read",
                'type' => 'admin',
            ]
        );
        $managePermission = Permission::firstOrCreate(
            ['identifier' => "sirsoft-board.{$slug}.admin.manage"],
            [
                'name' => ['ko' => '관리', 'en' => 'Manage'],
                'slug' => "sirsoft-board.{$slug}.admin.manage",
                'type' => 'admin',
            ]
        );
        $boardAdminRole = Role::firstOrCreate(
            ['identifier' => "{$slug}-board-admin"],
            ['name' => ['ko' => '게시판 관리자', 'en' => 'Board Admin']]
        );
        $boardAdminRole->permissions()->syncWithoutDetaching([
            $adminPostsReadPermission->id,
            $managePermission->id,
        ]);
        $this->boardAdminUser->roles()->attach($boardAdminRole->id);
        $this->boardAdminUser->roles()->attach($userRole->id);

        // 2. 비밀글 읽기 권한 사용자 (posts.read-secret + posts.read)
        $this->secretPostReadUser = User::factory()->create();
        $readSecretPermission = Permission::firstOrCreate(
            ['identifier' => "sirsoft-board.{$slug}.posts.read-secret"],
            [
                'name' => ['ko' => '비밀글 읽기', 'en' => 'Read Secret'],
                'slug' => "sirsoft-board.{$slug}.posts.read-secret",
                'type' => 'user',
            ]
        );
        $readSecretRole = Role::firstOrCreate(
            ['identifier' => "{$slug}-secret-reader"],
            ['name' => ['ko' => '비밀글 열람자', 'en' => 'Secret Reader']]
        );
        $readSecretRole->permissions()->syncWithoutDetaching([$readSecretPermission->id]);
        $this->secretPostReadUser->roles()->attach($readSecretRole->id);
        $this->secretPostReadUser->roles()->attach($userRole->id);

        // 3. 일반 사용자
        $this->regularUser = User::factory()->create();
        $this->regularUser->roles()->attach($userRole->id);
    }

    /**
     * 사용자에게 posts.read 권한을 부여합니다.
     */
    private function grantPostsReadPermission(User $user): void
    {
        $slug = $this->board->slug;
        $userRole = Role::where('identifier', "{$slug}-user")->first();

        if ($userRole) {
            $user->roles()->syncWithoutDetaching([$userRole->id]);
        }
    }

    /**
     * 비회원 비밀글을 생성합니다.
     */
    private function createGuestSecretPost(string $password = 'test1234'): int
    {
        return DB::table('board_posts')->insertGetId([
            'board_id' => $this->board->id,
            'title' => '비회원 비밀글',
            'content' => '비회원이 작성한 비밀글 내용입니다.',
            'content_mode' => 'text',
            'author_name' => '비회원작성자',
            'password' => Hash::make($password),
            'user_id' => null,
            'is_secret' => true,
            'is_notice' => false,
            'view_count' => 0,
            'status' => 'published',
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * 회원 비밀글을 생성합니다.
     */
    private function createMemberSecretPost(User $user, ?string $password = null): int
    {
        return DB::table('board_posts')->insertGetId([
            'board_id' => $this->board->id,
            'title' => '회원 비밀글',
            'content' => '회원이 작성한 비밀글 내용입니다.',
            'content_mode' => 'text',
            'user_id' => $user->id,
            'password' => $password ? Hash::make($password) : null,
            'is_secret' => true,
            'is_notice' => false,
            'view_count' => 0,
            'status' => 'published',
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ==========================================
    // 비밀글 조회용 비밀번호 검증 (verify-password)
    // ==========================================

    /**
     * 비회원 비밀글: 올바른 비밀번호로 조회 검증 성공
     */
    public function test_guest_secret_post_verify_password_success(): void
    {
        // Given: 비회원 비밀글 생성
        $postId = $this->createGuestSecretPost('test1234');

        // When: 올바른 비밀번호로 검증
        $response = $this->postJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/verify-password",
            ['password' => 'test1234']
        );

        // Then: 200 성공, content와 attachments 포함
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'title',
                    'content',
                    'attachments',
                ],
            ]);

        // content가 null이 아님
        $this->assertNotNull($response->json('data.content'));
    }

    /**
     * 비회원 비밀글: 잘못된 비밀번호로 조회 검증 실패
     */
    public function test_guest_secret_post_verify_password_wrong_password(): void
    {
        // Given: 비회원 비밀글 생성
        $postId = $this->createGuestSecretPost('test1234');

        // When: 잘못된 비밀번호로 검증
        $response = $this->postJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/verify-password",
            ['password' => 'wrongpassword']
        );

        // Then: 403 실패 (잘못된 비밀번호)
        $response->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    /**
     * 비밀글 조회 검증: 비밀번호 미입력 시 422 에러
     */
    public function test_verify_password_requires_password_field(): void
    {
        // Given: 비회원 비밀글 생성
        $postId = $this->createGuestSecretPost('test1234');

        // When: 비밀번호 없이 요청
        $response = $this->postJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/verify-password",
            []
        );

        // Then: 422 검증 에러
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    // ==========================================
    // 비밀글 수정용 비밀번호 검증 (verify-password-for-modify)
    // ==========================================

    /**
     * 비회원 비밀글: 올바른 비밀번호로 수정 검증 성공
     */
    public function test_guest_secret_post_verify_password_for_modify_success(): void
    {
        // Given: 비회원 비밀글 생성
        $postId = $this->createGuestSecretPost('test1234');

        // When: 올바른 비밀번호로 수정 검증
        $response = $this->postJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/verify-password-for-modify",
            ['password' => 'test1234']
        );

        // Then: 200 성공, verification_token 포함
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'verified',
                    'post_id',
                    'verification_token',
                    'expires_at',
                ],
            ])
            ->assertJsonPath('data.verified', true)
            ->assertJsonPath('data.post_id', $postId);

        // verification_token이 비어있지 않음
        $this->assertNotEmpty($response->json('data.verification_token'));
    }

    /**
     * 비회원 비밀글: 잘못된 비밀번호로 수정 검증 실패
     */
    public function test_guest_secret_post_verify_password_for_modify_wrong_password(): void
    {
        // Given: 비회원 비밀글 생성
        $postId = $this->createGuestSecretPost('test1234');

        // When: 잘못된 비밀번호로 수정 검증
        $response = $this->postJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/verify-password-for-modify",
            ['password' => 'wrongpassword']
        );

        // Then: 403 실패 (잘못된 비밀번호)
        $response->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    // ==========================================
    // 회원 비밀글 검증 테스트
    // ==========================================

    /**
     * 회원 비밀글: 작성자 본인은 비밀번호 없이 조회 가능 (API show 호출)
     */
    public function test_member_author_can_view_own_secret_post_without_password(): void
    {
        // Given: 회원 비밀글 생성
        $postId = $this->createMemberSecretPost($this->regularUser);

        // When: 작성자 본인이 조회
        $response = $this->actingAs($this->regularUser)
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}");

        // Then: 200 성공, content 포함
        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        // content가 null이 아님 (작성자 본인은 열람 가능)
        $this->assertNotNull($response->json('data.content'));
    }

    /**
     * 회원 비밀글: 타인은 content가 null로 반환
     */
    public function test_member_secret_post_content_hidden_for_others(): void
    {
        // Given: regularUser가 작성한 비밀글
        $postId = $this->createMemberSecretPost($this->regularUser);

        // When: 다른 사용자(secretPostReadUser가 아닌 일반 사용자)가 조회
        $anotherUser = User::factory()->create();
        $this->grantPostsReadPermission($anotherUser);
        $response = $this->actingAs($anotherUser)
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}");

        // Then: 200 성공하지만 content는 null
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.content', null);
    }

    /**
     * 회원 비밀글: 비밀번호 검증 대상 아님 (400 에러)
     *
     * 회원 비밀글은 로그인 후 본인/권한자만 열람 가능하므로
     * 비밀번호 검증 엔드포인트 사용 불가
     */
    public function test_member_secret_post_verify_password_not_allowed(): void
    {
        // Given: 비밀번호가 설정된 회원 비밀글
        $postId = $this->createMemberSecretPost($this->regularUser, 'memberpass');

        // When: 다른 사용자가 비밀번호로 검증 시도
        $anotherUser = User::factory()->create();
        $this->grantPostsReadPermission($anotherUser);
        $response = $this->actingAs($anotherUser, 'sanctum')
            ->postJson(
                "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/verify-password",
                ['password' => 'memberpass']
            );

        // Then: 400 에러 (회원 게시글은 비밀번호 검증 대상 아님)
        $response->assertStatus(400)
            ->assertJsonPath('success', false);
    }

    // ==========================================
    // 권한별 우회 테스트
    // ==========================================

    /**
     * 관리자(admin.manage 권한)는 관리자 라우트에서 비밀번호 없이 비밀글 조회 가능
     */
    public function test_admin_can_view_secret_post_without_password(): void
    {
        // Given: 비회원 비밀글 생성
        $postId = $this->createGuestSecretPost('test1234');

        // When: 관리자가 관리자 라우트로 조회 (admin.manage → content 열람 가능)
        $response = $this->actingAs($this->boardAdminUser, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts/{$postId}");

        // Then: 200 성공, content 포함
        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        // 관리자는 비밀번호 없이 content 열람 가능
        $this->assertNotNull($response->json('data.content'));
    }

    /**
     * 비밀글 읽기 권한(posts.read-secret) 사용자는 비밀번호 없이 조회 가능
     */
    public function test_read_secret_permission_user_can_view_without_password(): void
    {
        // Given: 비회원 비밀글 생성
        $postId = $this->createGuestSecretPost('test1234');

        // When: 비밀글 읽기 권한 사용자가 조회
        $response = $this->actingAs($this->secretPostReadUser, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}");

        // Then: 200 성공, content 포함
        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        // posts.read-secret 권한이 있으면 비밀번호 없이 열람 가능
        $this->assertNotNull($response->json('data.content'));
    }

    // ==========================================
    // 존재하지 않는 게시글 테스트
    // ==========================================

    /**
     * 존재하지 않는 게시글 비밀번호 검증 시 404 에러
     */
    public function test_verify_password_for_nonexistent_post_returns_404(): void
    {
        // When: 존재하지 않는 게시글에 대해 검증 시도
        $response = $this->postJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/99999/verify-password",
            ['password' => 'test1234']
        );

        // Then: 404 에러
        $response->assertStatus(404);
    }

    /**
     * 존재하지 않는 게시글 수정 검증 시 404 에러
     */
    public function test_verify_password_for_modify_nonexistent_post_returns_404(): void
    {
        // When: 존재하지 않는 게시글에 대해 수정 검증 시도
        $response = $this->postJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/99999/verify-password-for-modify",
            ['password' => 'test1234']
        );

        // Then: 404 에러
        $response->assertStatus(404);
    }

    // ==========================================
    // 비활성 게시판 테스트
    // ==========================================

    /**
     * 비활성 게시판의 비밀글 검증 시 404 에러
     */
    public function test_verify_password_for_inactive_board_returns_404(): void
    {
        // Given: 비밀글 생성 후 게시판 비활성화
        $postId = $this->createGuestSecretPost('test1234');
        $this->board->update(['is_active' => false]);

        // When: 비밀번호 검증 시도
        $response = $this->postJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/verify-password",
            ['password' => 'test1234']
        );

        // Then: 404 에러
        $response->assertStatus(404);
    }
}
