<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

// 테스트 베이스 클래스 수동 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 게시글 접근 및 비밀번호 검증 테스트
 *
 * 비밀글 접근 권한 로직:
 * - 작성자 본인 (로그인 사용자)
 * - 비회원 비밀번호 검증 성공
 * - 게시판 관리자 권한 (admin.manage, admin.posts.read)
 * - 시스템 관리자 (Super Admin)
 */
class PostAccessTest extends BoardTestCase
{
    private User $authorUser;

    private User $otherUser;

    private User $boardAdminUser;

    /**
     * 테스트 게시판 slug
     */
    protected function getTestBoardSlug(): string
    {
        return 'post-access';
    }

    /**
     * 기본 게시판 속성
     */
    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '접근 테스트 게시판', 'en' => 'Access Test Board'],
            'is_active' => true,
            'secret_mode' => 'enabled',
            'blocked_keywords' => [],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // user 역할에 게시판 권한 부여 (라우트 미들웨어 통과용)
        $this->grantUserRolePermissions();

        // 테스트 사용자 생성
        $this->createTestUsers();

        // 게시판 관리자 권한 설정
        $this->setupBoardAdminPermissions();
    }

    /**
     * 테스트 사용자 생성
     */
    private function createTestUsers(): void
    {
        $this->authorUser = User::factory()->create();
        $this->otherUser = User::factory()->create();
        $this->boardAdminUser = User::factory()->create();

        // 일반 사용자에게 user 역할 부여
        $userRole = Role::where('identifier', 'user')->first();
        if ($userRole) {
            $this->authorUser->roles()->attach($userRole->id);
            $this->otherUser->roles()->attach($userRole->id);
        }
    }

    /**
     * 게시판 관리자 권한 설정
     *
     * User 페이지 라우트는 permission:user 타입 미들웨어를 사용하므로
     * user 타입 권한(posts.read, manager)도 함께 부여합니다.
     */
    private function setupBoardAdminPermissions(): void
    {
        $slug = $this->board->slug;

        // 게시판 관리자 역할 생성
        $boardAdminRole = Role::firstOrCreate(
            ['identifier' => "{$slug}-board-admin"],
            ['name' => ['ko' => '게시판 관리자', 'en' => 'Board Admin']]
        );

        // user 타입 권한 부여 (라우트 미들웨어 + 비밀글 열람)
        $userPermissions = ['posts.read', 'posts.write', 'manager'];
        foreach ($userPermissions as $key) {
            $perm = Permission::firstOrCreate(
                ['identifier' => "sirsoft-board.{$slug}.{$key}"],
                ['name' => ['ko' => $key, 'en' => $key], 'type' => 'user']
            );
            $boardAdminRole->permissions()->syncWithoutDetaching([$perm->id]);
        }

        // admin 타입 관리 권한 부여
        $managePermission = Permission::firstOrCreate(
            ['identifier' => "sirsoft-board.{$slug}.admin.manage"],
            ['name' => ['ko' => '관리', 'en' => 'Manage'], 'type' => 'admin']
        );
        $boardAdminRole->permissions()->syncWithoutDetaching([$managePermission->id]);

        $this->boardAdminUser->roles()->attach($boardAdminRole->id);
    }

    // ==========================================
    // 비밀번호 검증 API 테스트
    // ==========================================

    /**
     * 비회원 게시글에 올바른 비밀번호로 검증 성공
     */
    public function test_verifies_correct_password_for_guest_post(): void
    {
        // Given: 비회원 비밀글 생성
        $password = 'secret123';
        $postId = $this->createTestPost([
            'title' => '비회원 비밀글',
            'content' => '비밀 내용입니다.',
            'user_id' => null,
            'author_name' => '비회원',
            'password' => Hash::make($password),
            'is_secret' => true,
        ]);

        // When: 올바른 비밀번호로 검증 요청
        $response = $this->postJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/verify-password",
            ['password' => $password]
        );

        // Then: 성공 응답 및 내용 반환
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'title',
                    'content',
                ],
            ]);

        // 비밀글 내용이 정상적으로 반환되는지 확인
        $this->assertEquals('비밀 내용입니다.', $response->json('data.content'));
    }

    /**
     * 비회원 게시글에 잘못된 비밀번호로 검증 실패
     */
    public function test_rejects_wrong_password_for_guest_post(): void
    {
        // Given: 비회원 비밀글 생성
        $postId = $this->createTestPost([
            'title' => '비회원 비밀글',
            'content' => '비밀 내용입니다.',
            'user_id' => null,
            'password' => Hash::make('correct_password'),
            'is_secret' => true,
        ]);

        // When: 잘못된 비밀번호로 검증 요청
        $response = $this->postJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/verify-password",
            ['password' => 'wrong_password']
        );

        // Then: 403 응답
        $response->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    /**
     * 회원 게시글은 비밀번호 검증 불가 (400 에러)
     */
    public function test_rejects_password_verification_for_member_post(): void
    {
        // Given: 회원 게시글 생성 (user_id 있음)
        $postId = $this->createTestPost([
            'title' => '회원 게시글',
            'content' => '회원이 작성한 내용입니다.',
            'user_id' => $this->authorUser->id,
            'author_name' => $this->authorUser->name,
            'password' => null,
            'is_secret' => true,
        ]);

        // When: 비밀번호 검증 시도
        $response = $this->postJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/verify-password",
            ['password' => 'any_password']
        );

        // Then: 400 에러 (회원 게시글은 비밀번호 검증 불가)
        $response->assertStatus(400)
            ->assertJsonPath('success', false);
    }

    /**
     * 비밀번호 없이 요청 시 422 에러
     */
    public function test_requires_password_for_verification(): void
    {
        // Given: 비회원 게시글 생성
        $postId = $this->createTestPost([
            'password' => Hash::make('secret'),
            'is_secret' => true,
        ]);

        // When: 비밀번호 없이 요청
        $response = $this->postJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/verify-password",
            []
        );

        // Then: 422 에러
        $response->assertStatus(422);
    }

    /**
     * 비밀번호가 설정되지 않은 비회원 게시글은 검증 불가 (400 에러)
     */
    public function test_rejects_verification_for_post_without_password(): void
    {
        // Given: 비밀번호 없는 비회원 게시글 생성
        $postId = $this->createTestPost([
            'user_id' => null,
            'password' => null,
            'is_secret' => false,
        ]);

        // When: 비밀번호 검증 시도
        $response = $this->postJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/verify-password",
            ['password' => 'any_password']
        );

        // Then: 400 에러 (비밀번호 없음)
        $response->assertStatus(400)
            ->assertJsonPath('success', false);
    }

    // ==========================================
    // 비밀글 조회 권한 테스트
    // ==========================================

    /**
     * 작성자 본인은 비밀글 내용 조회 가능
     */
    public function test_allows_author_to_view_secret_post(): void
    {
        // Given: 회원 비밀글 생성
        $postId = $this->createTestPost([
            'title' => '회원 비밀글',
            'content' => '비밀 내용입니다.',
            'user_id' => $this->authorUser->id,
            'author_name' => $this->authorUser->name,
            'is_secret' => true,
        ]);

        // When: 작성자 본인이 조회
        $response = $this->actingAs($this->authorUser)
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}");

        // Then: 성공, 내용 반환
        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertEquals('비밀 내용입니다.', $response->json('data.content'));
    }

    /**
     * 다른 사용자는 비밀글 내용 조회 불가 (content=null)
     */
    public function test_denies_other_user_from_viewing_secret_post(): void
    {
        // Given: 회원 비밀글 생성
        $postId = $this->createTestPost([
            'title' => '회원 비밀글',
            'content' => '비밀 내용입니다.',
            'user_id' => $this->authorUser->id,
            'author_name' => $this->authorUser->name,
            'is_secret' => true,
        ]);

        // When: 다른 사용자가 조회 시도
        $response = $this->actingAs($this->otherUser)
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}");

        // Then: 200 반환하되 content가 null (PostResource에서 필터링)
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_secret', true)
            ->assertJsonPath('data.content', null);
    }

    /**
     * 게시판 관리자는 비밀글 내용 조회 가능
     */
    public function test_allows_board_admin_to_view_secret_post(): void
    {
        // Given: 회원 비밀글 생성
        $postId = $this->createTestPost([
            'title' => '회원 비밀글',
            'content' => '비밀 내용입니다.',
            'user_id' => $this->authorUser->id,
            'author_name' => $this->authorUser->name,
            'is_secret' => true,
        ]);

        // When: 게시판 관리자가 조회
        $response = $this->actingAs($this->boardAdminUser)
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}");

        // Then: 성공, 내용 반환
        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertEquals('비밀 내용입니다.', $response->json('data.content'));
    }

    /**
     * 비로그인 사용자는 비밀글 내용 조회 불가 (content=null)
     */
    public function test_denies_guest_from_viewing_secret_post(): void
    {
        // Given: 비회원 비밀글 생성
        $postId = $this->createTestPost([
            'title' => '비회원 비밀글',
            'content' => '비밀 내용입니다.',
            'user_id' => null,
            'password' => Hash::make('secret'),
            'is_secret' => true,
        ]);

        // When: 비로그인 상태에서 조회 (비밀번호 없이)
        $response = $this->getJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}"
        );

        // Then: 200 반환하되 content가 null (PostResource에서 필터링)
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_secret', true)
            ->assertJsonPath('data.content', null);
    }

    /**
     * 일반 게시글은 누구나 조회 가능
     */
    public function test_allows_anyone_to_view_normal_post(): void
    {
        // Given: 일반 게시글 생성
        $postId = $this->createTestPost([
            'title' => '일반 게시글',
            'content' => '일반 내용입니다.',
            'user_id' => $this->authorUser->id,
            'is_secret' => false,
        ]);

        // When: 비로그인 상태에서 조회
        $response = $this->getJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}"
        );

        // Then: 성공, 내용 반환
        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertEquals('일반 내용입니다.', $response->json('data.content'));
    }

    /**
     * 존재하지 않는 게시글 조회 시 404 반환
     */
    public function test_returns_404_for_non_existent_post(): void
    {
        // When: 존재하지 않는 게시글 조회
        $response = $this->getJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/99999"
        );

        // Then: 404 응답
        $response->assertStatus(404);
    }
}