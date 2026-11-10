<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 회귀: 토큰이 만료된 회원이 본인 글을 다루면 「권한 없음」(403) 대신 세션 만료(401)를 받는다 (#883 후속)
 *
 * 결함: `optional.sanctum` 은 만료 토큰을 비회원으로 통과시킨다(로그인 화면 등 공개 접근 유지). 비회원도 업로드 권한이 있는
 * 게시판에서 만료 토큰 회원이 본인 글에 첨부하면, 본인 확인이 비회원 기준으로 실패해 「게시글 수정 권한이 없습니다」(403)가
 * 나왔다 — 다시 로그인하면 되는 상황인데 권한이 없다고 안내했다. 이제 만료 토큰을 실은 요청의 본인 확인 실패는 401
 * (`auth.token_expired`)로 알려 화면이 세션 만료 흐름(토큰 갱신 → 재로그인)을 탄다. 토큰 없는 비회원·유효 토큰의 남은 403 은 그대로.
 *
 * @scenario-manifest modules/_bundled/sirsoft-board/tests/scenarios/expired-token-ownership-denial.yaml
 */
class ExpiredTokenOwnershipDenialTest extends BoardTestCase
{
    /**
     * 이 시험이 쓰는 게시판 슬러그를 돌려줍니다.
     *
     * @return string 게시판 슬러그
     */
    protected function getTestBoardSlug(): string
    {
        return 'expired-token-denial';
    }

    /**
     * 이 시험 게시판의 기본 속성을 돌려줍니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @return array<string, mixed> 게시판 속성
     */
    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '만료 토큰 게시판', 'en' => 'Expired Token Board'],
            'is_active' => true,
            'use_file_upload' => true,
            'max_file_count' => 5,
        ];
    }

    /**
     * 비회원·회원 모두 업로드 권한을 줍니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->setGuestPermissions(['posts.read', 'posts.write', 'attachments.upload']);
        $this->grantUserRolePermissions(['posts.read', 'posts.write', 'attachments.upload']);
    }

    /**
     * 시험이 만든 저장소 파일을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Storage::disk('modules')->deleteDirectory('sirsoft-board/attachments/'.$this->getTestBoardSlug());

        parent::tearDown();
    }

    /**
     * 만료 토큰으로 본인 글에 첨부·수정하면 401(토큰 만료), 토큰 없이 남의 글에 첨부하면 종전대로 403.
     *
     * @return void 반환값 없음
     *
     * @scenario case=expired_token_own_post
     *
     * @effects expired_token_ownership_denial_is_401, tokenless_denial_stays_403
     */
    public function test_expired_token_owner_gets_401_not_403(): void
    {
        $member = User::factory()->create(['email' => 'expired-owner@test.com']);
        $role = Role::where('identifier', 'user')->first();
        if ($role) {
            $member->roles()->attach($role->id);
        }
        $postId = $this->createTestPost(['user_id' => $member->id, 'author_name' => 'owner']);

        $expired = $member->createToken('test', ['*'], now()->subMinute())->plainTextToken;

        $upload = $this->withHeader('Authorization', 'Bearer '.$expired)
            ->postJson("/api/modules/sirsoft-board/boards/{$this->getTestBoardSlug()}/attachments", [
                'file' => UploadedFile::fake()->image('a.png'),
                'post_id' => $postId,
            ]);
        $upload->assertStatus(401);

        $update = $this->withHeader('Authorization', 'Bearer '.$expired)
            ->putJson("/api/modules/sirsoft-board/boards/{$this->getTestBoardSlug()}/posts/{$postId}", [
                'title' => '수정',
                'content' => '본문은 최소 길이를 넘겨야 검증을 통과합니다.',
            ]);
        $update->assertStatus(401);

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->postJson("/api/modules/sirsoft-board/boards/{$this->getTestBoardSlug()}/attachments", [
            'file' => UploadedFile::fake()->image('a.png'),
            'post_id' => $postId,
        ])->assertStatus(403);
    }
}
