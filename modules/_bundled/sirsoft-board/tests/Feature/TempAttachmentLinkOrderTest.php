<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

require_once __DIR__.'/../ModuleTestCase.php';

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 임시 첨부를 글에 이을 때의 순서 테스트
 *
 * 글쓰기 화면은 고른 순서대로 임시 첨부를 올리고(서버는 임시 키 안에서 도착 순서로 1, 2, … 를 매김) 글을 저장할 때
 * 잇는다. 이미 첨부가 있는 글의 수정 화면에서 더한 파일도 1 부터 번호를 받아 기존 첨부와 같은 번호가 됐고,
 * 목록에서 기존 첨부 사이에 끼었다(7.1.0 전수 실측 O-F1-3 서버 정합 확인 — 페이지 모듈 O-L1 (2) 와 같은 결함).
 * 새 첨부는 기존 첨부의 마지막 번호 뒤에 붙는다. 새 글은 기존 첨부가 없어 1..N 그대로다.
 *
 * @scenario-manifest tests/scenarios/board-temp-attachment-link-order.yaml
 */
class TempAttachmentLinkOrderTest extends BoardTestCase
{
    /**
     * 글 작성·업로드 권한이 있는 회원
     */
    private User $member;

    /**
     * 테스트 게시판 슬러그를 반환합니다.
     *
     * @return string 게시판 슬러그
     */
    protected function getTestBoardSlug(): string
    {
        return 'temp-attachment-link-order';
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
            'name' => ['ko' => '임시 첨부 연결 순서 게시판', 'en' => 'Temp Attachment Link Order Board'],
            'is_active' => true,
            'use_file_upload' => true,
            'max_file_count' => 10,
            'secret_mode' => 'disabled',
            'blocked_keywords' => [],
        ];
    }

    /**
     * 권한과 회원을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->grantUserRolePermissions(['posts.read', 'posts.write', 'attachments.upload']);
        $this->member = User::factory()->create(['email' => 'link-order-member@test.com']);
        $userRole = Role::where('identifier', 'user')->first();
        if ($userRole) {
            $this->member->roles()->attach($userRole->id);
        }
    }

    /**
     * 테스트가 만든 임시 첨부 행과 저장소 파일을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Attachment::withTrashed()->where('board_id', 0)->where('path', 'like', $this->getTestBoardSlug().'/temp/%')->forceDelete();
        Storage::disk('modules')->deleteDirectory('sirsoft-board/attachments/'.$this->getTestBoardSlug());

        parent::tearDown();
    }

    /**
     * 새 글: 고른 순서대로 올린 임시 첨부가 1..N 으로 이어진다.
     *
     * @return void 반환값 없음
     *
     * @scenario screen=create
     *
     * @effects new_post_attachments_keep_upload_order
     */
    public function test_new_post_links_temp_attachments_in_upload_order(): void
    {
        $tempKey = (string) Str::uuid();
        foreach (['n1.png', 'n2.png', 'n3.png'] as $name) {
            $this->upload($name, $tempKey);
        }

        $postId = (int) $this->actingAs($this->member, 'sanctum')
            ->postJson($this->postsUrl(), $this->payload(['temp_key' => $tempKey]))
            ->assertStatus(201)
            ->json('data.id');

        $this->assertSame(
            ['n1.png' => 1, 'n2.png' => 2, 'n3.png' => 3],
            $this->ordersOf($postId)
        );
    }

    /**
     * 수정 화면: 기존 첨부가 있는 글에 더한 임시 첨부는 기존 첨부 뒤 번호를 받는다.
     *
     * @return void 반환값 없음
     *
     * @scenario screen=edit
     *
     * @effects edit_added_attachments_follow_existing_ones
     */
    public function test_edit_appends_temp_attachments_after_existing_ones(): void
    {
        $firstKey = (string) Str::uuid();
        foreach (['old1.png', 'old2.png'] as $name) {
            $this->upload($name, $firstKey);
        }
        $postId = (int) $this->actingAs($this->member, 'sanctum')
            ->postJson($this->postsUrl(), $this->payload(['temp_key' => $firstKey]))
            ->assertStatus(201)
            ->json('data.id');

        $secondKey = (string) Str::uuid();
        foreach (['add1.png', 'add2.png'] as $name) {
            $this->upload($name, $secondKey);
        }
        $this->actingAs($this->member, 'sanctum')
            ->putJson($this->postsUrl()."/{$postId}", $this->payload(['temp_key' => $secondKey]))
            ->assertStatus(200);

        $this->assertSame(
            ['old1.png' => 1, 'old2.png' => 2, 'add1.png' => 3, 'add2.png' => 4],
            $this->ordersOf($postId)
        );
    }

    /**
     * 임시 첨부를 올립니다.
     *
     * @param  string  $name  파일명
     * @param  string  $tempKey  임시 키
     * @return void 반환값 없음
     */
    private function upload(string $name, string $tempKey): void
    {
        $this->actingAs($this->member, 'sanctum')
            ->postJson($this->postsUrl(false), [
                'file' => UploadedFile::fake()->image($name),
                'temp_key' => $tempKey,
                'collection' => 'post_attachments',
            ])
            ->assertStatus(201);
    }

    /**
     * 글 첨부의 파일명 => 순서 맵을 순서대로 돌려줍니다.
     *
     * @param  int  $postId  글 ID
     * @return array<string, int> 파일명 => 순서
     */
    private function ordersOf(int $postId): array
    {
        return Attachment::query()
            ->where('post_id', $postId)
            ->orderBy('order')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (Attachment $a) => [$a->original_filename => (int) $a->order])
            ->all();
    }

    /**
     * 글 본문 요청 값을 만듭니다.
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 값
     * @return array<string, mixed> 요청 값
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => '첨부 연결 순서',
            'content' => '본문은 최소 길이를 넘겨야 검증을 통과합니다.',
        ], $overrides);
    }

    /**
     * 사용자 글(또는 첨부) API 경로를 반환합니다.
     *
     * @param  bool  $posts  true 면 글 경로, false 면 첨부 경로
     * @return string API 경로
     */
    private function postsUrl(bool $posts = true): string
    {
        $base = "/api/modules/sirsoft-board/boards/{$this->getTestBoardSlug()}";

        return $posts ? "{$base}/posts" : "{$base}/attachments";
    }
}
