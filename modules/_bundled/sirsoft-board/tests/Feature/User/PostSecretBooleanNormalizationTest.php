<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

// 테스트 베이스 클래스 수동 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 게시글 is_secret boolean 정규화 — 공개 이슈 #97 잠재 위험 방어
 *
 * 비밀글 여부를 문자열 "true"/"false" 로 보내는 클라이언트(레이아웃 hidden 시드 등)가
 * 나타나도 `boolean` 규칙에서 422 로 깨지지 않도록 Store/UpdatePostRequest 의
 * prepareForValidation 이 문자열 표기를 boolean 으로 정규화함을 고정한다.
 * 해석 불가값은 건드리지 않아 boolean 규칙이 422 로 처리한다.
 */
class PostSecretBooleanNormalizationTest extends BoardTestCase
{
    private User $memberUser;

    /**
     * 테스트 게시판 slug
     */
    protected function getTestBoardSlug(): string
    {
        return 'secret-boolean-test';
    }

    /**
     * 기본 게시판 속성 (비밀글 허용)
     */
    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '비밀글 boolean 테스트 게시판', 'en' => 'Secret Boolean Test Board'],
            'is_active' => true,
            'secret_mode' => 'enabled',
            'blocked_keywords' => [],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->grantUserRolePermissions();

        $this->memberUser = User::factory()->create(['email' => 'secret-bool@test.com']);
        $userRole = Role::where('identifier', 'user')->first();
        $this->memberUser->roles()->attach($userRole->id);
    }

    /**
     * 수용해야 하는 boolean 표기 4종(문자열)과 기대 저장값
     *
     * @return array<string, array{mixed, bool}> [입력값, 기대 boolean]
     */
    private function acceptedNotations(): array
    {
        return [
            "문자열 'true'" => ['true', true],
            "문자열 'false'" => ['false', false],
            "문자열 '1'" => ['1', true],
            "문자열 '0'" => ['0', false],
        ];
    }

    /**
     * 작성: 문자열 boolean 표기 4종 모두 201 + DB boolean 저장
     */
    public function test_store_accepts_string_boolean_notations(): void
    {
        foreach ($this->acceptedNotations() as $label => [$input, $expected]) {
            $response = $this->actingAs($this->memberUser)
                ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts", [
                    'title' => "비밀글 표기 테스트 {$label}",
                    'content' => '비밀글 boolean 정규화 검증용 내용입니다.',
                    'is_secret' => $input,
                ]);

            $response->assertStatus(201);

            $postId = $response->json('data.id');
            $stored = (bool) DB::table('board_posts')->where('id', $postId)->value('is_secret');
            $this->assertSame(
                $expected,
                $stored,
                "작성 {$label} 입력이 boolean 으로 저장되어야 합니다."
            );
        }
    }

    /**
     * 수정: 문자열 boolean 표기 4종 모두 200 + DB boolean 저장
     */
    public function test_update_accepts_string_boolean_notations(): void
    {
        $createResponse = $this->actingAs($this->memberUser)
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts", [
                'title' => '비밀글 수정 표기 테스트',
                'content' => '비밀글 boolean 정규화 수정 검증용 내용입니다.',
                'is_secret' => false,
            ]);
        $createResponse->assertStatus(201);
        $postId = $createResponse->json('data.id');

        foreach ($this->acceptedNotations() as $label => [$input, $expected]) {
            $response = $this->actingAs($this->memberUser)
                ->putJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}", [
                    'is_secret' => $input,
                ]);

            $response->assertStatus(200);

            $stored = (bool) DB::table('board_posts')->where('id', $postId)->value('is_secret');
            $this->assertSame(
                $expected,
                $stored,
                "수정 {$label} 입력이 boolean 으로 저장되어야 합니다."
            );
        }
    }

    /**
     * 작성: 해석 불가값('abc')은 정규화하지 않고 boolean 규칙이 422 처리
     */
    public function test_store_rejects_unparsable_value_with_422(): void
    {
        $response = $this->actingAs($this->memberUser)
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts", [
                'title' => '비밀글 해석불가 테스트',
                'content' => '비밀글 boolean 해석 불가값 검증용 내용입니다.',
                'is_secret' => 'abc',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['is_secret']);
    }

    /**
     * 수정: 해석 불가값('abc')은 정규화하지 않고 boolean 규칙이 422 처리
     */
    public function test_update_rejects_unparsable_value_with_422(): void
    {
        $createResponse = $this->actingAs($this->memberUser)
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts", [
                'title' => '비밀글 수정 해석불가 테스트',
                'content' => '비밀글 boolean 수정 해석 불가값 검증용 내용입니다.',
                'is_secret' => true,
            ]);
        $createResponse->assertStatus(201);
        $postId = $createResponse->json('data.id');

        $response = $this->actingAs($this->memberUser)
            ->putJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}", [
                'is_secret' => 'abc',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['is_secret']);

        // 실패한 요청이 저장값을 건드리지 않아야 한다
        $this->assertSame(1, (int) DB::table('board_posts')->where('id', $postId)->value('is_secret'));
    }

    /**
     * 작성: is_secret 미전송이면 정규화가 개입하지 않고 그대로 통과 (has 가드)
     */
    public function test_store_without_is_secret_passes(): void
    {
        $response = $this->actingAs($this->memberUser)
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts", [
                'title' => '비밀글 미전송 테스트',
                'content' => '비밀글 필드 미전송 검증용 내용입니다.',
            ]);

        $response->assertStatus(201);

        $postId = $response->json('data.id');
        $this->assertSame(0, (int) DB::table('board_posts')->where('id', $postId)->value('is_secret'));
    }
}
