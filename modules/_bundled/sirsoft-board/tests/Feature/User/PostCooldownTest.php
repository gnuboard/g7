<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

require_once __DIR__.'/../../ModuleTestCase.php';

use Illuminate\Support\Facades\Cache;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 게시글 작성 쿨다운 API 통합 시나리오 테스트
 *
 * 검증 목적:
 * - 쿨다운 비활성(0초): 연속 작성 허용
 * - 쿨다운 활성: 첫 작성 성공 후 두 번째 작성 422
 * - 쿨다운 활성: 캐시 만료 후 재작성 허용 (단위 테스트에서 검증)
 * - 회원은 user_id로, 비회원은 IP로 격리
 *
 * @group board
 * @group feature
 * @group cooldown
 */
class PostCooldownTest extends BoardTestCase
{
    private array $mockSettings = [];

    protected function getTestBoardSlug(): string
    {
        return 'post-cooldown-test';
    }

    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '쿨다운 테스트 게시판', 'en' => 'Cooldown Test Board'],
            'is_active' => true,
            'secret_mode' => 'disabled',
            'blocked_keywords' => [],
            'min_title_length' => 1,
            'max_title_length' => 200,
            'min_content_length' => 1,
            'max_content_length' => 10000,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->grantUserRolePermissions(['posts.read', 'posts.write']);
        $this->setGuestPermissions(['posts.read', 'posts.write']);

        $this->mockSettings = [
            'spam_security' => [
                'post_cooldown_seconds' => 0,
                'comment_cooldown_seconds' => 0,
                'report_cooldown_seconds' => 0,
                'view_count_cache_ttl' => 86400,
            ],
        ];
    }

    private function applySettings(): void
    {
        // g7_module_settings()는 Config::get("g7_settings.modules.sirsoft-board.*")를 읽음
        foreach ($this->mockSettings as $category => $values) {
            config(["g7_settings.modules.sirsoft-board.{$category}" => $values]);
        }
    }

    private function postUrl(): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts";
    }

    private function validPostData(): array
    {
        return [
            'title' => '쿨다운 테스트 제목',
            'content' => '쿨다운 테스트 내용입니다.',
        ];
    }

    // ==========================================
    // 쿨다운 비활성 (0초)
    // ==========================================

    /**
     * 쿨다운 0초: 연속 작성 허용
     */
    public function test_consecutive_posts_allowed_when_cooldown_disabled(): void
    {
        $this->mockSettings['spam_security']['post_cooldown_seconds'] = 0;
        $this->applySettings();

        $user = $this->createUser();

        $this->actingAs($user)->postJson($this->postUrl(), $this->validPostData())
            ->assertStatus(201);

        $this->actingAs($user)->postJson($this->postUrl(), $this->validPostData())
            ->assertStatus(201);
    }

    // ==========================================
    // 쿨다운 활성
    // ==========================================

    /**
     * 쿨다운 활성: 첫 번째 작성 성공
     */
    public function test_first_post_succeeds_when_cooldown_active(): void
    {
        $this->mockSettings['spam_security']['post_cooldown_seconds'] = 60;
        $this->applySettings();

        $user = $this->createUser();

        $this->actingAs($user)->postJson($this->postUrl(), $this->validPostData())
            ->assertStatus(201);
    }

    /**
     * 쿨다운 활성: 첫 작성 직후 두 번째 작성 422
     */
    public function test_second_post_rejected_when_cooldown_active(): void
    {
        $this->mockSettings['spam_security']['post_cooldown_seconds'] = 60;
        $this->applySettings();

        $user = $this->createUser();

        // 첫 번째 작성 성공 → 쿨다운 캐시 등록
        $this->actingAs($user)->postJson($this->postUrl(), $this->validPostData())
            ->assertStatus(201);

        // 두 번째 작성 시 422
        $this->actingAs($user)->postJson($this->postUrl(), $this->validPostData())
            ->assertStatus(422);
    }

    /**
     * 쿨다운 활성: 다른 사용자는 영향 없음 (user_id 격리)
     */
    public function test_cooldown_is_isolated_per_user(): void
    {
        $this->mockSettings['spam_security']['post_cooldown_seconds'] = 60;
        $this->applySettings();

        $userA = $this->createUser();
        $userB = $this->createUser();

        // userA 작성 → 쿨다운 등록
        $this->actingAs($userA)->postJson($this->postUrl(), $this->validPostData())
            ->assertStatus(201);

        // userB는 쿨다운 영향 없이 작성 가능
        $this->actingAs($userB)->postJson($this->postUrl(), $this->validPostData())
            ->assertStatus(201);
    }

    /**
     * 쿨다운 오류 응답에 422 상태코드와 errors 필드가 있다
     */
    public function test_cooldown_rejection_returns_422_with_errors(): void
    {
        $this->mockSettings['spam_security']['post_cooldown_seconds'] = 60;
        $this->applySettings();

        $user = $this->createUser();

        $this->actingAs($user)->postJson($this->postUrl(), $this->validPostData())
            ->assertStatus(201);

        $this->actingAs($user)->postJson($this->postUrl(), $this->validPostData())
            ->assertStatus(422)
            ->assertJsonStructure(['errors']);
    }
}
