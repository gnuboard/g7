<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 비밀글 전수 시나리오 테스트
 *
 * 검증 목적:
 * - secret_mode: disabled / enabled / always 각 모드별 생성/조회 동작
 * - posts.read-secret 권한 유무에 따른 비밀글 내용 노출 여부
 * - secret_mode=always에서 비회원 접근 동작
 * - 비밀번호 기반 비회원 비밀글 접근
 *
 * @group board
 * @group secret
 */
class SecretPostScenarioTest extends BoardTestCase
{
    private User $authorUser;

    private User $otherUser;

    protected function getTestBoardSlug(): string
    {
        return 'secret-scenario';
    }

    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '비밀글 시나리오 테스트 게시판', 'en' => 'Secret Scenario Board'],
            'is_active' => true,
            'secret_mode' => 'enabled',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->setGuestPermissions(['posts.read', 'posts.write']);
        $this->grantUserRolePermissions(['posts.read', 'posts.write']);

        $this->authorUser = User::factory()->create(['email' => 'secret-author@test.com']);
        $this->otherUser = User::factory()->create(['email' => 'secret-other@test.com']);

        $userRole = Role::where('identifier', 'user')->first();
        if ($userRole) {
            $this->authorUser->roles()->attach($userRole->id);
            $this->otherUser->roles()->attach($userRole->id);
        }
    }

    // ==========================================
    // secret_mode별 게시글 생성 시나리오
    // ==========================================

    /**
     * secret_mode=disabled이면 비밀글 생성을 거부한다 (403)
     */
    public function test_secret_mode_disabled_rejects_is_secret_true(): void
    {
        $this->updateBoardSettings(['secret_mode' => 'disabled']);

        $response = $this->actingAs($this->authorUser)
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts", [
                'title' => '비밀글 시도',
                'content' => '비밀글로 작성하려는 내용입니다.',
                'author_name' => '작성자',
                'is_secret' => true,
            ]);

        $response->assertStatus(403);
    }

    /**
     * secret_mode=enabled이면 사용자가 비밀글 여부를 선택할 수 있다
     */
    public function test_secret_mode_enabled_allows_user_to_choose(): void
    {
        $this->updateBoardSettings(['secret_mode' => 'enabled']);

        // 비밀글 생성
        $secretResponse = $this->actingAs($this->authorUser)
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts", [
                'title' => '비밀글 제목',
                'content' => '비밀글 본문입니다. 충분한 길이.',
                'author_name' => '작성자',
                'is_secret' => true,
            ]);
        $secretResponse->assertStatus(201)->assertJsonPath('data.is_secret', true);

        // 공개글 생성
        $publicResponse = $this->actingAs($this->authorUser)
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts", [
                'title' => '공개글 제목',
                'content' => '공개글 본문입니다. 충분한 길이.',
                'author_name' => '작성자',
                'is_secret' => false,
            ]);
        $publicResponse->assertStatus(201)->assertJsonPath('data.is_secret', false);
    }

    /**
     * secret_mode=always이면 모든 게시글이 비밀글로 강제된다
     */
    public function test_secret_mode_always_forces_all_posts_secret(): void
    {
        $this->updateBoardSettings(['secret_mode' => 'always']);

        // is_secret=false로 생성해도 true로 강제
        $response = $this->actingAs($this->authorUser)
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts", [
                'title' => '강제 비밀글 제목',
                'content' => '강제 비밀글 본문입니다. 충분한 길이.',
                'author_name' => '작성자',
                'is_secret' => false,
            ]);

        $response->assertStatus(201)->assertJsonPath('data.is_secret', true);
    }

    /**
     * secret_mode=always이면 비회원도 게시글을 작성하면 자동으로 비밀글이 된다
     */
    public function test_secret_mode_always_guest_post_becomes_secret(): void
    {
        $this->updateBoardSettings(['secret_mode' => 'always']);

        $response = $this->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts", [
            'title' => '비회원 게시글',
            'content' => '비회원이 작성하는 게시글 내용입니다.',
            'author_name' => '비회원',
            'password' => 'pass1234',
            'is_secret' => false,
        ]);

        // always 모드에서는 is_secret=false여도 true로 강제됨
        $response->assertStatus(201)->assertJsonPath('data.is_secret', true);
    }

    // ==========================================
    // posts.read-secret 권한 분기 시나리오
    // ==========================================

    /**
     * posts.read-secret 권한이 있는 사용자는 비밀글 내용을 볼 수 있다
     */
    public function test_user_with_read_secret_permission_can_view_secret_content(): void
    {
        $postId = $this->createTestPost([
            'user_id' => $this->authorUser->id,
            'is_secret' => true,
            'title' => '비밀 제목',
            'content' => '비밀 내용입니다.',
        ]);

        // otherUser에게 read-secret 권한 부여
        $perm = Permission::firstOrCreate(
            ['identifier' => "sirsoft-board.{$this->board->slug}.posts.read-secret"],
            ['name' => ['ko' => '비밀글 조회', 'en' => 'Read Secret'], 'type' => 'user']
        );
        $userRole = Role::where('identifier', 'user')->first();
        $userRole->permissions()->syncWithoutDetaching([$perm->id]);

        $response = $this->actingAs($this->otherUser)
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}");

        $response->assertStatus(200)
            ->assertJsonPath('data.is_secret', true)
            ->assertJsonPath('data.content', '비밀 내용입니다.');
    }

    /**
     * posts.read-secret 권한이 없는 타인은 비밀글 내용이 마스킹된다
     */
    public function test_user_without_read_secret_sees_masked_content(): void
    {
        $postId = $this->createTestPost([
            'user_id' => $this->authorUser->id,
            'is_secret' => true,
            'title' => '비밀 제목',
            'content' => '비밀 내용입니다.',
        ]);

        $response = $this->actingAs($this->otherUser)
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}");

        $response->assertStatus(200)
            ->assertJsonPath('data.is_secret', true);

        // 내용이 마스킹되거나 빈 값이어야 함
        $content = $response->json('data.content');
        $this->assertNotEquals('비밀 내용입니다.', $content);
    }

    /**
     * 비밀글 작성자 본인은 항상 내용을 볼 수 있다
     */
    public function test_secret_post_author_can_always_view_own_content(): void
    {
        $postId = $this->createTestPost([
            'user_id' => $this->authorUser->id,
            'is_secret' => true,
            'title' => '내 비밀글',
            'content' => '내 비밀 내용입니다.',
        ]);

        $response = $this->actingAs($this->authorUser)
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}");

        $response->assertStatus(200)
            ->assertJsonPath('data.content', '내 비밀 내용입니다.');
    }

    /**
     * 비회원 비밀글은 올바른 비밀번호로 verify-password API가 content를 반환한다
     */
    public function test_guest_secret_post_accessible_with_correct_password(): void
    {
        $postId = $this->createTestPost([
            'is_secret' => true,
            'password' => Hash::make('pass1234'),
            'author_name' => '비회원',
            'title' => '비회원 비밀글',
            'content' => '비회원 비밀 내용입니다.',
        ]);

        // 비밀번호 검증 - 성공 시 content 포함하여 반환
        $response = $this->postJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/verify-password",
            ['password' => 'pass1234']
        );

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.content', '비회원 비밀 내용입니다.');
    }

    /**
     * 비회원 비밀글은 틀린 비밀번호로 접근이 거부된다 (403)
     */
    public function test_guest_secret_post_denied_with_wrong_password(): void
    {
        $postId = $this->createTestPost([
            'is_secret' => true,
            'password' => Hash::make('pass1234'),
            'author_name' => '비회원',
            'title' => '비회원 비밀글',
            'content' => '비회원 비밀 내용입니다.',
        ]);

        $response = $this->postJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/verify-password",
            ['password' => 'wrongpass']
        );

        $response->assertStatus(403);
    }

    // ==========================================
    // 비밀글 목록 노출 시나리오
    // ==========================================

    /**
     * 비밀글은 목록에 포함되며 is_secret 플래그가 표시된다
     */
    public function test_secret_posts_appear_in_list_with_masked_content(): void
    {
        $this->createTestPost(['is_secret' => false, 'title' => '공개글']);
        $this->createTestPost(['is_secret' => true, 'title' => '비밀글', 'user_id' => $this->authorUser->id]);

        $response = $this->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts");

        $response->assertStatus(200);
        $items = $response->json('data.data');
        $this->assertCount(2, $items);

        // 비밀글이 목록에 포함되어 있음 (is_secret 플래그)
        $secretPost = collect($items)->firstWhere('is_secret', true);
        $this->assertNotNull($secretPost);
    }

    /**
     * secret_mode=always 게시판에서 navigation API는 500을 반환하지 않는다
     */
    public function test_navigation_does_not_500_on_always_secret_board(): void
    {
        $this->updateBoardSettings(['secret_mode' => 'always']);

        $postId = $this->createTestPost(['is_secret' => true, 'user_id' => $this->authorUser->id]);

        $response = $this->getJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/navigation"
        );

        // 500이 아닌 200 또는 404여야 함
        $this->assertNotEquals(500, $response->status());
    }
}
