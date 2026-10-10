<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

require_once __DIR__.'/../ModuleTestCase.php';

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * 게시판 API 검증 문구의 한국어 조사 자리표시 회귀 테스트 (7.1.0 전수 실측 O-K4-2)
 *
 * 게시판 요청 규칙 중 자기 문구가 없는 것(in·exists·required_with 등)은 코어 기본 문구
 * 「선택한 :attribute이(가) 올바르지 않습니다.」로 떨어져, 회원이 댓글 상태를 조작하면
 * 「선택한 상태이(가) 올바르지 않습니다.」처럼 조사 자리표시가 그대로 응답에 실렸다.
 * 댓글 상태는 게시글과 같은 전용 문구를 쓰고, 나머지는 게시판 요청 공용 대체 문구
 * (「선택한 :attribute 값이 올바르지 않습니다.」)로 받침과 무관하게 읽히게 한다.
 *
 * 시나리오 축·효과는 매니페스트 tests/scenarios/validation-message-particle.yaml 참조.
 */
#[Group('board')]
class ValidationMessageParticleTest extends BoardTestCase
{
    /** 조사 자리표시 — 받침에 따라 고를 조사를 괄호로 남긴 꼴 */
    private const PARTICLE_PLACEHOLDER = '/이\(가\)|을\(를\)|은\(는\)|와\(과\)|\(으\)로/u';

    private User $member;

    /**
     * 회원 역할에 글·댓글 권한을 주고 기본 회원을 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->grantUserRolePermissions(['posts.read', 'posts.write', 'comments.read', 'comments.write']);
        $this->member = User::factory()->create(['email' => 'particle-member@test.com', 'language' => 'ko']);
        $this->member->roles()->attach(Role::where('identifier', 'user')->value('id'));
    }

    /**
     * 응답의 한 칸 오류 문구를 돌려줍니다.
     *
     * @param  TestResponse  $response  422 응답
     * @param  string  $field  칸 이름
     * @return string 첫 오류 문구
     */
    private function firstError(TestResponse $response, string $field): string
    {
        $response->assertStatus(422)->assertJsonValidationErrors([$field]);

        return (string) $response->json("errors.{$field}.0");
    }

    /**
     * 회원이 댓글 작성·수정에서 상태를 조작하면 게시글과 같은 꼴의 댓글 상태 문구가 나오고
     * 조사 자리표시가 없다.
     *
     * @scenario case=comment_status_message
     *
     * @effects comment_status_message_natural
     *
     * @return void 반환값 없음
     */
    public function test_comment_status_rejection_reads_naturally(): void
    {
        app()->setLocale('ko');
        $postId = $this->createTestPost();
        $commentId = $this->createTestComment($postId, ['user_id' => $this->member->id, 'author_name' => null]);
        $base = "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/comments";

        $store = $this->firstError(
            $this->actingAs($this->member)->withHeader('Accept-Language', 'ko')
                ->postJson($base, ['content' => '상태를 바꿔 쓰는 댓글', 'status' => 'blinded']),
            'status'
        );
        $update = $this->firstError(
            $this->actingAs($this->member)->withHeader('Accept-Language', 'ko')
                ->putJson("{$base}/{$commentId}", ['content' => '상태를 바꿔 고친 댓글', 'status' => 'deleted']),
            'status'
        );

        foreach ([$store, $update] as $message) {
            $this->assertSame('유효하지 않은 댓글 상태입니다.', $message);
            $this->assertDoesNotMatchRegularExpression(self::PARTICLE_PLACEHOLDER, $message);
        }
    }

    /**
     * 자기 문구가 없는 규칙(정렬 방향 in)도 게시판 공용 대체 문구로 받침과 무관하게 읽힌다 —
     * 회원 경로(내 반응 정렬)와 관리자 경로(게시판 목록 정렬) 모두.
     *
     * @scenario case=fallback_rule_message
     *
     * @effects fallback_rule_message_natural
     *
     * @return void 반환값 없음
     */
    public function test_rules_without_own_message_use_board_fallback_wording(): void
    {
        app()->setLocale('ko');

        $member = $this->firstError(
            $this->actingAs($this->member)->withHeader('Accept-Language', 'ko')
                ->getJson('/api/modules/sirsoft-board/me/reactions?sort=sideways'),
            'sort'
        );

        $admin = User::factory()->create(['is_super' => true, 'status' => 'active', 'language' => 'ko']);
        $adminRole = Role::where('identifier', 'admin')->firstOrFail();
        $admin->roles()->attach($adminRole->id);
        $permission = Permission::firstOrCreate(
            ['identifier' => 'sirsoft-board.boards.read'],
            ['name' => ['ko' => 'sirsoft-board.boards.read', 'en' => 'sirsoft-board.boards.read'], 'type' => 'admin']
        );
        $adminRole->permissions()->syncWithoutDetaching([$permission->id]);
        $this->resetPermissionMiddlewareCache();

        $adminMessage = $this->firstError(
            $this->actingAs($admin->fresh())->withHeader('Accept-Language', 'ko')
                ->getJson('/api/modules/sirsoft-board/admin/boards?sort_order=sideways'),
            'sort_order'
        );

        foreach ([$member, $adminMessage] as $message) {
            $this->assertDoesNotMatchRegularExpression(self::PARTICLE_PLACEHOLDER, $message);
            $this->assertStringContainsString('값이 올바르지 않습니다.', $message);
        }
    }
}
