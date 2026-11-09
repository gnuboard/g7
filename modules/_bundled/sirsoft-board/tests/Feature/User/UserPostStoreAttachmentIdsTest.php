<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 사용자 글 작성 API 가 첨부 번호(attachment_ids)로 첨부를 잇는다 (#883 후속)
 *
 * 종전에는 사용자 글 작성(`POST boards/{slug}/posts`)이 `attachment_ids` 를 검증만 하고 서비스로 넘기지 않아, 201 이 나도 첨부가
 * 글에 이어지지 않았다(관리자 경로·사용자 수정 경로는 넘김). 번호로 잇는 경로도 소유 규칙(본인 업로드만)을 그대로 탄다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-board/tests/scenarios/user-post-store-attachment-ids.yaml
 */
class UserPostStoreAttachmentIdsTest extends BoardTestCase
{
    /**
     * 테스트 게시판 슬러그를 반환합니다.
     *
     * @return string 게시판 슬러그
     */
    protected function getTestBoardSlug(): string
    {
        return 'user-store-attachment-ids';
    }

    /**
     * 테스트 게시판 기본 속성을 반환합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @return array<string, mixed> 게시판 속성
     */
    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '첨부 번호 연결 게시판', 'en' => 'Attachment Ids Board'],
            'is_active' => true,
            'use_file_upload' => true,
            'max_file_count' => 5,
        ];
    }

    /**
     * 회원 권한을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->grantUserRolePermissions(['posts.read', 'posts.write', 'attachments.upload']);
    }

    /**
     * 테스트가 만든 저장소 파일을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Storage::disk('modules')->deleteDirectory('sirsoft-board/attachments/'.$this->getTestBoardSlug());

        parent::tearDown();
    }

    /**
     * 회원이 올린 첨부의 번호를 글 작성에 실으면 그 글에 이어진다. 남이 올린 번호는 잇지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=own_and_foreign_ids
     *
     * @effects own_attachment_ids_linked, foreign_attachment_ids_ignored
     */
    public function test_user_store_links_own_attachment_ids_and_ignores_foreign(): void
    {
        $member = $this->member('ids-member@test.com');
        $other = $this->member('ids-other@test.com');

        $mine = (int) $this->actingAs($member, 'sanctum')->postJson($this->attachmentsUrl(), [
            'file' => UploadedFile::fake()->image('mine.png'),
        ])->assertStatus(201)->json('data.data.id');

        $foreign = (int) $this->actingAs($other, 'sanctum')->postJson($this->attachmentsUrl(), [
            'file' => UploadedFile::fake()->image('foreign.png'),
        ])->assertStatus(201)->json('data.data.id');

        $postId = (int) $this->actingAs($member, 'sanctum')->postJson($this->postsUrl(), [
            'title' => '첨부 번호로 잇기',
            'content' => '본문은 최소 길이를 넘겨야 검증을 통과합니다.',
            'attachment_ids' => [$mine, $foreign],
        ])->assertStatus(201)->json('data.id');

        $this->assertSame($postId, (int) Attachment::query()->findOrFail($mine)->post_id, '본인 첨부는 번호로 이어진다');
        $this->assertNull(Attachment::query()->findOrFail($foreign)->post_id, '남이 올린 첨부는 잇지 않는다');
    }

    /**
     * 사용자 역할을 가진 회원을 만듭니다.
     *
     * @param  string  $email  이메일
     * @return User 회원
     */
    private function member(string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $role = Role::where('identifier', 'user')->first();
        if ($role) {
            $user->roles()->attach($role->id);
        }

        return $user;
    }

    /**
     * 사용자 첨부 업로드 API 경로.
     *
     * @return string 경로
     */
    private function attachmentsUrl(): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->getTestBoardSlug()}/attachments";
    }

    /**
     * 사용자 글 작성 API 경로.
     *
     * @return string 경로
     */
    private function postsUrl(): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->getTestBoardSlug()}/posts";
    }
}
