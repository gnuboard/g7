<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

require_once __DIR__.'/../ModuleTestCase.php';

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Services\PostService;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 임시 첨부 연결·이동의 롤백 — 파일도 제자리로 (#883 3단계)
 *
 * 글 저장이 임시 첨부를 본경로로 옮긴 뒤 바깥에서 실패해 DB 가 되돌아가면, 종전에는 파일만 본경로에 남아 첨부 행(임시 경로)과
 * 어긋났다. 특히 상품문의처럼 **바깥 트랜잭션 안에서 글 저장이 먼저 커밋**되고 바깥이 롤백되는 경우는 `DB::afterRollBack()` 으로
 * 잡히지 않는다 — StorageTransaction 의 등록 깊이 비교가 보상한다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-board/tests/scenarios/post-save-transaction-hooks.yaml
 */
class TempAttachmentLinkRollbackTest extends BoardTestCase
{
    private User $member;

    /**
     * 테스트 게시판 슬러그를 반환합니다.
     *
     * @return string 게시판 슬러그
     */
    protected function getTestBoardSlug(): string
    {
        return 'temp-link-rollback';
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
            'name' => ['ko' => '연결 롤백 게시판', 'en' => 'Link Rollback Board'],
            'is_active' => true,
            'use_file_upload' => true,
            'max_file_count' => 5,
        ];
    }

    /**
     * 권한·회원을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->grantUserRolePermissions(['posts.read', 'posts.write', 'attachments.upload']);
        $this->member = User::factory()->create(['email' => 'link-rollback@test.com']);
        $role = Role::where('identifier', 'user')->first();
        if ($role) {
            $this->member->roles()->attach($role->id);
        }
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
     * 바깥 트랜잭션 안에서 글 저장이 커밋된 뒤 바깥이 롤백되면, 첨부 행과 파일이 모두 임시 자리로 돌아간다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=listener_throws_on_create
     *
     * @effects listener_exception_rolls_back_post_and_files
     */
    public function test_outer_rollback_after_inner_post_commit_restores_temp_file(): void
    {
        $slug = $this->getTestBoardSlug();
        $tempKey = (string) Str::uuid();
        $this->actingAs($this->member, 'sanctum')
            ->postJson("/api/modules/sirsoft-board/boards/{$slug}/attachments", [
                'file' => UploadedFile::fake()->image('nested.png'),
                'temp_key' => $tempKey,
            ])->assertStatus(201);
        $attachment = Attachment::query()->where('temp_key', $tempKey)->firstOrFail();
        $tempPath = $attachment->path;
        $postsBefore = Post::query()->count();

        $this->actingAs($this->member);
        try {
            DB::transaction(function () use ($slug, $tempKey) {
                app(PostService::class)->createPost($slug, [
                    'title' => '상품문의 모양',
                    'content' => '바깥 트랜잭션 안에서 글 저장이 먼저 커밋된다.',
                    'user_id' => $this->member->id,
                    'temp_key' => $tempKey,
                ]);

                throw new \RuntimeException('outer failure after inner commit');
            });
            $this->fail('바깥 예외가 올라와야 한다');
        } catch (\RuntimeException $e) {
            $this->assertSame('outer failure after inner commit', $e->getMessage());
        }

        $this->assertSame($postsBefore, Post::query()->count());
        $attachment->refresh();
        $this->assertNull($attachment->post_id);
        $this->assertSame($tempPath, $attachment->path);
        $this->assertTrue(Storage::disk('modules')->exists('sirsoft-board/attachments/'.$tempPath));
        $this->assertSame([], Storage::disk('modules')->allFiles('sirsoft-board/attachments/'.$slug.'/'.date('Y')));
    }
}
