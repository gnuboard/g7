<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

// 테스트 베이스 클래스 수동 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 비밀글 첨부파일 접근 차단 테스트 (KVE-2026-1914 A-3)
 *
 * 첨부는 본문 게이팅(getFilteredContent)과 별개 경로라, 본문이 비밀 마스킹되어도
 * 첨부 다운로드/미리보기는 게시글 비밀 상태를 검사하지 않아 해시/ID 만으로 원문
 * 파일이 노출되던 결함을 검증합니다.
 *
 * 정책: 비밀글 첨부는 작성자 본인 또는 게시판 manager/posts.read-secret 보유자만 접근.
 *
 * 실제 공격자 프로필은 대개 미인증(게스트)이다. preview 라우트는 permission
 * 미들웨어 없이 optional.sanctum 만 걸려(공개 썸네일 정책) 컨트롤러/서비스의 비밀
 * 게이트만이 게스트를 막는다 — 그래서 게스트 축을 명시 고정한다. download 라우트는
 * permission 미들웨어가 게스트를 401 로 선차단한다.
 *
 * 시나리오 축(viewer)·효과는 매니페스트 tests/scenarios/board-secret-content-gate.yaml 참조.
 * 각 test 메서드의 `@scenario viewer=…` 마커가 축 조합을 커버한다(메서드당 단일 값).
 *
 * 효과 목록을 클래스 레벨에 몰아 적지 않는다 — 커버리지 룰은 마커 레벨을 구분하지 않으므로,
 * 클래스 레벨 목록이 있으면 그 메서드를 지워도 효과가 "언급됨" 으로 집계돼 삭제가 무증상
 * green 이 된다. 마커는 메서드에만 둔다.
 */
class SecretPostAttachmentAccessTest extends BoardTestCase
{
    private User $regularUser;

    private User $ownerUser;

    private User $managerUser;

    /**
     * 테스트 게시판 슬러그
     *
     * @return string 게시판 슬러그
     */
    protected function getTestBoardSlug(): string
    {
        return 'secret-attach';
    }

    /**
     * 테스트 게시판 기본 속성(비밀글 사용)
     *
     * @param  string  $slug  게시판 슬러그
     * @return array<string, mixed> 게시판 속성
     */
    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '비밀 첨부 테스트 게시판', 'en' => 'Secret Attachment Test Board'],
            'is_active' => true,
            'use_comment' => true,
            'secret_mode' => 'enabled',
            'blocked_keywords' => [],
        ];
    }

    /**
     * 일반 회원·작성자·게시판 관리자 계정과 권한을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $slug = $this->board->slug;

        // 일반 사용자 (posts.read + attachments.download, 비밀 열람 권한 없음)
        $this->regularUser = User::factory()->create();
        $this->ownerUser = User::factory()->create();
        $userRole = Role::where('identifier', 'user')->first();
        if ($userRole) {
            foreach (['posts.read', 'attachments.download'] as $key) {
                $perm = Permission::firstOrCreate(
                    ['identifier' => "sirsoft-board.{$slug}.{$key}"],
                    ['name' => ['ko' => $key, 'en' => $key], 'type' => 'user']
                );
                $userRole->permissions()->syncWithoutDetaching([$perm->id]);
            }
            $this->regularUser->roles()->attach($userRole->id);
            $this->ownerUser->roles()->attach($userRole->id);
        }

        // manager 권한 사용자
        $this->managerUser = User::factory()->create();
        $managerRole = Role::firstOrCreate(
            ['identifier' => "{$slug}-manager"],
            ['name' => ['ko' => '게시판 매니저', 'en' => 'Board Manager']]
        );
        foreach (['posts.read', 'attachments.download', 'manager'] as $key) {
            $perm = Permission::firstOrCreate(
                ['identifier' => "sirsoft-board.{$slug}.{$key}"],
                ['name' => ['ko' => $key, 'en' => $key], 'type' => 'user']
            );
            $managerRole->permissions()->syncWithoutDetaching([$perm->id]);
        }
        $this->managerUser->roles()->attach($managerRole->id);
    }

    /**
     * 게시글에 첨부 행을 만듭니다(파일 없이 DB 행만).
     *
     * @param  int  $postId  게시글 ID
     * @param  string  $hash  첨부 해시
     * @param  bool  $image  이미지 첨부 여부
     * @return int 첨부 ID
     */
    private function createAttachment(int $postId, string $hash, bool $image = false): int
    {
        $ext = $image ? 'jpg' : 'pdf';
        $mime = $image ? 'image/jpeg' : 'application/pdf';

        return DB::table('board_attachments')->insertGetId([
            'board_id' => $this->board->id,
            'post_id' => $postId,
            'original_filename' => "doc.{$ext}",
            'stored_filename' => "{$hash}.{$ext}",
            'hash' => $hash,
            'mime_type' => $mime,
            'size' => 1024,
            'path' => "attachments/doc.{$ext}",
            'collection' => 'attachments',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * 첨부 내려받기 주소
     *
     * @param  string  $hash  첨부 해시
     * @return string 상대 주소
     */
    private function downloadUrl(string $hash): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->board->slug}/attachment/{$hash}";
    }

    /**
     * 첨부 미리보기 주소(무서명)
     *
     * @param  string  $hash  첨부 해시
     * @return string 상대 주소
     */
    private function previewUrl(string $hash): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->board->slug}/attachment/{$hash}/preview";
    }

    /**
     * 작성자 소유 비밀글을 만듭니다.
     *
     * @return int 게시글 ID
     */
    private function secretPost(): int
    {
        return $this->createTestPost([
            'title' => '비밀글 첨부',
            'status' => 'published',
            'is_secret' => true,
            'user_id' => $this->ownerUser->id,
            'author_name' => 'owner',
        ]);
    }

    // ==========================================
    // 비밀글 첨부 차단
    // ==========================================

    /**
     * 일반 회원은 비밀글 첨부를 내려받을 수 없다(403).
     *
     * @scenario viewer=regular
     *
     * @effects regular_user_cannot_download_secret_post_attachment
     *
     * @return void 반환값 없음
     */
    public function test_regular_user_cannot_download_secret_post_attachment(): void
    {
        $postId = $this->secretPost();
        $this->createAttachment($postId, 'secdlregAAAA');

        $response = $this->actingAs($this->regularUser, 'sanctum')
            ->get($this->downloadUrl('secdlregAAAA'));

        // attachments.download 권한은 있으나 비밀 게이트로 차단(403)
        $response->assertStatus(403);
    }

    /**
     * 일반 회원은 비밀글 첨부 미리보기를 볼 수 없다(403).
     *
     * @scenario viewer=regular
     *
     * @effects regular_user_cannot_preview_secret_post_attachment
     *
     * @return void 반환값 없음
     */
    public function test_regular_user_cannot_preview_secret_post_attachment(): void
    {
        $postId = $this->secretPost();
        $this->createAttachment($postId, 'secprevregAA', image: true);

        $response = $this->actingAs($this->regularUser, 'sanctum')
            ->get($this->previewUrl('secprevregAA'));

        $response->assertStatus(403);
    }

    // ==========================================
    // 블라인드 글 첨부 차단 (2026-10-07 실측 K4-D5)
    // ==========================================

    /**
     * 블라인드된 글의 첨부는 작성자·게시판 관리자가 아니면 목록에도, 내려받기·미리보기에도 나가지 않는다.
     *
     * 화면(sirsoft-basic)은 블라인드 글의 첨부 영역을 숨기지만, 응답의 `attachments` 와 첨부 서빙
     * 경로가 블라인드를 보지 않아 해시만으로 파일을 받을 수 있었다. 본문은 이미 가리고 있었다.
     *
     * @scenario viewer=regular
     *
     * @effects regular_user_cannot_access_blinded_post_attachment
     *
     * @return void 반환값 없음
     */
    public function test_regular_user_cannot_access_blinded_post_attachment(): void
    {
        $postId = $this->createTestPost([
            'title' => '블라인드 글 첨부',
            'status' => 'blinded',
            'user_id' => $this->ownerUser->id,
            'author_name' => 'owner',
        ]);
        $this->createAttachment($postId, 'blinddlregAA');
        $this->createAttachment($postId, 'blindprvregA', image: true);

        $this->actingAs($this->regularUser, 'sanctum')->get($this->downloadUrl('blinddlregAA'))->assertStatus(403);
        $this->actingAs($this->regularUser, 'sanctum')->get($this->previewUrl('blindprvregA'))->assertStatus(403);

        $detail = $this->actingAs($this->regularUser, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}")
            ->assertOk();
        $this->assertSame([], $detail->json('data.attachments') ?? [], '블라인드 글 응답에 첨부 목록이 실렸다');
    }

    /**
     * 블라인드된 글의 첨부도 작성자 본인과 게시판 관리자는 그대로 받는다(회귀 방지).
     *
     * @scenario viewer=owner
     *
     * @effects owner_and_manager_can_access_blinded_post_attachment
     *
     * @return void 반환값 없음
     */
    public function test_owner_and_manager_can_access_blinded_post_attachment(): void
    {
        $postId = $this->createTestPost([
            'title' => '블라인드 글 첨부(작성자)',
            'status' => 'blinded',
            'user_id' => $this->ownerUser->id,
            'author_name' => 'owner',
        ]);
        $this->createAttachment($postId, 'blinddlownAA');

        foreach ([$this->ownerUser, $this->managerUser] as $viewer) {
            $status = $this->actingAs($viewer, 'sanctum')->get($this->downloadUrl('blinddlownAA'))->getStatusCode();
            $this->assertNotSame(403, $status, '작성자·관리자는 블라인드 글 첨부에 접근할 수 있어야 합니다');
            $this->assertLessThan(500, $status);
        }

        $detail = $this->actingAs($this->ownerUser, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}")
            ->assertOk();
        $this->assertCount(1, $detail->json('data.attachments') ?? []);
    }

    /**
     * 블라인드 글의 목록 썸네일 주소도 작성자·게시판 관리자에게만 싣는다.
     *
     * 2026-10-07 실측(K4, D5 후속): 블라인드 글 첨부 서빙을 막은 뒤에도 카드형·갤러리형 목록이 블라인드 글의
     * 썸네일 주소를 그대로 받아 이미지가 깨지고(미리보기 403) 콘솔 오류가 쌓였다. 막기 전에는 그 주소로
     * 블라인드 글 이미지가 누구에게나 나갔다.
     *
     * @scenario viewer=regular
     *
     * @effects blinded_post_thumbnail_hidden_in_list
     *
     * @return void 반환값 없음
     */
    public function test_blinded_post_thumbnail_is_hidden_in_list_for_regular_user(): void
    {
        $postId = $this->createTestPost([
            'title' => '블라인드 글 썸네일',
            'status' => 'blinded',
            'user_id' => $this->ownerUser->id,
            'author_name' => 'owner',
        ]);
        $this->createAttachment($postId, 'blindthumbAA', image: true);

        $thumbnailFor = function (?User $viewer) use ($postId) {
            $request = $viewer ? $this->actingAs($viewer, 'sanctum') : $this;
            $rows = $request->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts")
                ->assertOk()
                ->json('data.data') ?? [];
            $row = collect($rows)->firstWhere('id', $postId);
            $this->assertNotNull($row, '목록에 블라인드 글 행이 없다');

            return $row['thumbnail'] ?? null;
        };

        $this->assertNull($thumbnailFor($this->regularUser), '일반 회원 목록에 블라인드 글 썸네일 주소가 실렸다');
        $ownerThumbnail = $thumbnailFor($this->ownerUser);
        $this->assertNotNull($ownerThumbnail, '작성자 목록에는 썸네일이 남아야 한다');
        // <img> 요청에는 토큰이 실리지 않으므로 서명 주소여야 작성자 화면에서도 이미지가 열린다
        $this->assertStringContainsString('signature=', (string) $ownerThumbnail);
        $this->assertNotNull($thumbnailFor($this->managerUser), '게시판 관리자 목록에는 썸네일이 남아야 한다');
    }

    /**
     * 게시글의 첨부 개수 캐시 컬럼(attachments_count)을 실제 첨부 행 수로 맞춥니다.
     *
     * createAttachment 는 DB 행만 넣어 업로드 리스너가 세는 캐시 컬럼이 0 으로 남는다 — 목록·상세의
     * attachment_count·has_attachment 는 이 컬럼을 읽으므로 그 값을 재는 테스트는 먼저 맞춰 둔다.
     *
     * @param  int  $postId  게시글 ID
     * @return void 반환값 없음
     */
    private function syncAttachmentCount(int $postId): void
    {
        DB::table('board_posts')->where('id', $postId)->update([
            'attachments_count' => DB::table('board_attachments')->where('post_id', $postId)->whereNull('deleted_at')->count(),
        ]);
    }

    /**
     * 사용자 목록에서 한 글의 행을 받아 옵니다.
     *
     * @param  User|null  $viewer  보는 사람(null 이면 비로그인)
     * @param  int  $postId  게시글 ID
     * @return array<string, mixed> 목록 행
     */
    private function listRowFor(?User $viewer, int $postId): array
    {
        $request = $viewer ? $this->actingAs($viewer, 'sanctum') : $this;
        $rows = $request->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts")
            ->assertOk()
            ->json('data.data') ?? [];
        $row = collect($rows)->firstWhere('id', $postId);
        $this->assertNotNull($row, '목록에 글 행이 없다');

        return $row;
    }

    /**
     * 비밀글 목록 썸네일도 작성자·게시판 관리자에게는 서명 주소로 싣고, 다른 사람에게는 싣지 않는다.
     *
     * 2026-10-08 실측(O-K4-10): 비밀글 첨부 서빙은 무서명 미리보기를 막는데(작성자·관리자도 <img> 요청에는
     * 토큰이 실리지 않는다) 목록 썸네일만 무서명 주소로 나가, 작성자·관리자 카드형·갤러리형 목록 이미지가 403 으로 깨졌다.
     *
     * @scenario viewer=owner
     *
     * @effects secret_post_thumbnail_signed_for_owner_and_manager_in_list
     *
     * @return void 반환값 없음
     */
    public function test_secret_post_thumbnail_is_signed_for_owner_and_manager_in_list(): void
    {
        $postId = $this->secretPost();
        $this->createAttachment($postId, 'secthumbAAAA', image: true);

        $this->assertNull($this->listRowFor(null, $postId)['thumbnail'] ?? null, '비로그인 목록에 비밀글 썸네일이 실렸다');
        $this->assertNull($this->listRowFor($this->regularUser, $postId)['thumbnail'] ?? null, '일반 회원 목록에 비밀글 썸네일이 실렸다');

        foreach (['작성자' => $this->ownerUser, '게시판 관리자' => $this->managerUser] as $label => $viewer) {
            $thumbnail = $this->listRowFor($viewer, $postId)['thumbnail'] ?? null;
            $this->assertIsString($thumbnail, "{$label} 목록에는 비밀글 썸네일이 남아야 한다");
            $this->assertStringContainsString('signature=', $thumbnail, "{$label} 목록의 비밀글 썸네일이 무서명 주소다");

            // 무인증 <img> 요청으로도 서빙 게이트를 통과해야 화면에 이미지가 보인다
            $status = $this->get($thumbnail)->getStatusCode();
            $this->assertNotSame(403, $status, "{$label} 목록의 비밀글 썸네일 주소가 서빙 게이트에 막혔다");
            $this->assertLessThan(500, $status);
        }
    }

    /**
     * 공개 글 목록 썸네일은 종전대로 무서명 공개 주소다(비밀글 서명이 공개 글로 번지는 회귀 방지).
     *
     * @scenario viewer=owner
     *
     * @effects normal_post_thumbnail_stays_plain_in_list
     *
     * @return void 반환값 없음
     */
    public function test_normal_post_thumbnail_stays_plain_in_list(): void
    {
        $postId = $this->createTestPost([
            'title' => '공개 글 썸네일',
            'status' => 'published',
            'is_secret' => false,
            'user_id' => $this->ownerUser->id,
            'author_name' => 'owner',
        ]);
        $this->createAttachment($postId, 'normthumbAAA', image: true);

        $this->assertSame($this->previewUrl('normthumbAAA'), $this->listRowFor($this->ownerUser, $postId)['thumbnail'] ?? null);
        $this->assertSame($this->previewUrl('normthumbAAA'), $this->listRowFor(null, $postId)['thumbnail'] ?? null);
    }

    /**
     * 블라인드 글의 첨부 표시(attachment_count·has_attachment)는 작성자·게시판 관리자에게만 싣는다.
     *
     * 2026-10-08 실측(O-K4-9): 블라인드 글 첨부 목록·서빙은 막았지만 목록·상세 응답의 첨부 개수가 남아
     * 기본형·카드형·갤러리형 목록에 클립 아이콘이 보였다(파일은 받을 수 없는데 첨부가 있다는 표시만 남음).
     *
     * @scenario viewer=regular
     *
     * @effects blinded_post_attachment_indicator_hidden_for_others
     *
     * @return void 반환값 없음
     */
    public function test_blinded_post_attachment_indicator_is_hidden_for_others(): void
    {
        $postId = $this->createTestPost([
            'title' => '블라인드 글 첨부 표시',
            'status' => 'blinded',
            'user_id' => $this->ownerUser->id,
            'author_name' => 'owner',
        ]);
        $this->createAttachment($postId, 'blindcntAAAA');
        $this->createAttachment($postId, 'blindcntBBBB', image: true);
        $this->syncAttachmentCount($postId);

        foreach (['비로그인' => null, '일반 회원' => $this->regularUser] as $label => $viewer) {
            $row = $this->listRowFor($viewer, $postId);
            $this->assertSame(0, $row['attachment_count'], "{$label} 목록에 블라인드 글 첨부 개수가 실렸다");
            $this->assertFalse($row['has_attachment'], "{$label} 목록에 블라인드 글 첨부 표시가 실렸다");
        }

        $detail = $this->actingAs($this->regularUser, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}")
            ->assertOk();
        $this->assertSame(0, $detail->json('data.attachment_count'), '일반 회원 상세에 블라인드 글 첨부 개수가 실렸다');
        $this->assertFalse($detail->json('data.has_attachment'));

        foreach (['작성자' => $this->ownerUser, '게시판 관리자' => $this->managerUser] as $label => $viewer) {
            $row = $this->listRowFor($viewer, $postId);
            $this->assertSame(2, $row['attachment_count'], "{$label} 목록에는 블라인드 글 첨부 개수가 남아야 한다");
            $this->assertTrue($row['has_attachment']);
        }
    }

    /**
     * 관리자 목록도 같은 기준이다 — 게시판 관리 권한(admin.manage)이 있으면 블라인드 글 첨부 표시가 남고,
     * 읽기 권한만 있는 관리자에게는 원문처럼 감춘다. 공개 글 첨부 표시는 그대로 남는다.
     *
     * @scenario viewer=manager
     *
     * @effects blinded_post_attachment_indicator_follows_admin_manage
     *
     * @return void 반환값 없음
     */
    public function test_admin_list_blinded_attachment_indicator_follows_manage_permission(): void
    {
        $blindedId = $this->createTestPost([
            'title' => '관리자 목록 블라인드 첨부',
            'status' => 'blinded',
            'user_id' => $this->ownerUser->id,
            'author_name' => 'owner',
        ]);
        $this->createAttachment($blindedId, 'admblindAAAA');
        $normalId = $this->createTestPost(['title' => '관리자 목록 공개 첨부', 'status' => 'published']);
        $this->createAttachment($normalId, 'admnormAAAAA');
        $this->syncAttachmentCount($blindedId);
        $this->syncAttachmentCount($normalId);

        $slug = $this->board->slug;
        // createAdminUser 는 공용 admin 역할에 권한을 더하므로 두 관리자의 권한이 섞인다 — 관리자마다 역할을 따로 둔다
        $rowsFor = function (string $roleKey, array $actions) use ($slug) {
            $role = Role::firstOrCreate(
                ['identifier' => "{$slug}-{$roleKey}"],
                ['name' => ['ko' => $roleKey, 'en' => $roleKey]]
            );
            foreach ($actions as $action) {
                $perm = Permission::firstOrCreate(
                    ['identifier' => "sirsoft-board.{$slug}.{$action}"],
                    ['name' => ['ko' => $action, 'en' => $action], 'type' => 'admin']
                );
                $role->permissions()->syncWithoutDetaching([$perm->id]);
            }
            $admin = User::factory()->create();
            $admin->roles()->attach($role->id);

            return collect($this->actingAs($admin)
                ->getJson("/api/modules/sirsoft-board/admin/board/{$slug}/posts")
                ->assertOk()
                ->json('data.data') ?? [])->keyBy('id');
        };

        $manageRows = $rowsFor('adm-manage', ['admin.posts.read', 'admin.manage']);
        $this->assertTrue($manageRows[$blindedId]['has_attachment'], '게시판 관리 권한자 목록에서 블라인드 글 첨부 표시가 사라졌다');
        $this->assertSame(1, $manageRows[$blindedId]['attachment_count']);

        $readRows = $rowsFor('adm-read', ['admin.posts.read']);
        $this->assertFalse($readRows[$blindedId]['has_attachment'], '읽기 권한만 있는 관리자 목록에 블라인드 글 첨부 표시가 실렸다');
        $this->assertTrue($readRows[$normalId]['has_attachment'], '공개 글 첨부 표시는 남아야 한다');
    }

    // ==========================================
    // 미인증(게스트) 차단 — 실제 공격자 프로필
    // ==========================================

    /**
     * 미인증(게스트)은 비밀글 첨부 미리보기를 볼 수 없다 (403).
     *
     * preview 라우트는 permission 미들웨어가 없어(optional.sanctum 만) 컨트롤러/서비스의
     * 비밀 게이트만이 게스트를 막는다 — 해시만 쥔 미인증 공격자가 실제로 차단되는지 고정한다.
     *
     * @scenario viewer=guest
     *
     * @effects guest_cannot_preview_secret_post_attachment
     *
     * @return void 반환값 없음
     */
    public function test_guest_cannot_preview_secret_post_attachment(): void
    {
        $postId = $this->secretPost();
        $this->createAttachment($postId, 'secprevgstAA', image: true);

        // actingAs 없음 = 미인증 게스트
        $response = $this->get($this->previewUrl('secprevgstAA'));

        $response->assertStatus(403);
    }

    /**
     * 미인증(게스트)은 비밀글 첨부 다운로드를 할 수 없다 (401).
     *
     * download 라우트는 attachments.download permission 미들웨어가 걸려 있어 게스트를
     * 컨트롤러 도달 전에 401(guest_permission_denied)로 선차단한다.
     *
     * @scenario viewer=guest
     *
     * @effects guest_cannot_download_secret_post_attachment
     *
     * @return void 반환값 없음
     */
    public function test_guest_cannot_download_secret_post_attachment(): void
    {
        $postId = $this->secretPost();
        $this->createAttachment($postId, 'secdlgstAAAA');

        $response = $this->get($this->downloadUrl('secdlgstAAAA'));

        $response->assertStatus(401);
    }

    /**
     * 미인증(게스트)도 정상글(비밀 아님) 첨부 미리보기는 차단되지 않는다 (게이트 과차단 회귀 방지).
     *
     * 공개 썸네일 정책상 preview 는 공개다 — 비밀 게이트가 정상글 게스트 미리보기까지
     * 막으면 안 된다. 실제 파일이 없어 404 여도 403(비밀 차단)은 아니어야 한다.
     *
     * @scenario viewer=guest
     *
     * @effects guest_can_preview_normal_post_attachment
     *
     * @return void 반환값 없음
     */
    public function test_guest_can_preview_normal_post_attachment(): void
    {
        $postId = $this->createTestPost([
            'title' => '정상글 첨부(게스트 미리보기)',
            'status' => 'published',
            'is_secret' => false,
        ]);
        $this->createAttachment($postId, 'normgstAAAAA', image: true);

        $response = $this->get($this->previewUrl('normgstAAAAA'));

        $this->assertNotSame(403, $response->getStatusCode(), '정상글 첨부 미리보기는 게스트에게 비밀 차단되면 안 됩니다');
        $this->assertLessThan(500, $response->getStatusCode(), '게이트 통과 시 서버 오류가 아니어야 합니다');
    }

    // ==========================================
    // 작성자/manager 는 접근 가능 (회귀 방지)
    // ==========================================

    /**
     * 작성자 본인은 비밀글 첨부에 접근할 수 있다(회귀 방지).
     *
     * @scenario viewer=owner
     *
     * @effects owner_can_access_secret_post_attachment
     *
     * @return void 반환값 없음
     */
    public function test_owner_can_access_secret_post_attachment(): void
    {
        $postId = $this->secretPost();
        $this->createAttachment($postId, 'secdlownerAA');

        $response = $this->actingAs($this->ownerUser, 'sanctum')
            ->get($this->downloadUrl('secdlownerAA'));

        // 작성자 본인은 비밀 게이트 통과 (실제 파일 없어 404 여도 403 은 아님)
        $this->assertNotSame(403, $response->getStatusCode(), '작성자 본인은 비밀글 첨부에 접근 가능해야 합니다');
        $this->assertLessThan(500, $response->getStatusCode(), '게이트 통과 시 서버 오류가 아니어야 합니다');
    }

    /**
     * 게시판 관리자는 비밀글 첨부에 접근할 수 있다(회귀 방지).
     *
     * @scenario viewer=manager
     *
     * @effects manager_can_access_secret_post_attachment
     *
     * @return void 반환값 없음
     */
    public function test_manager_can_access_secret_post_attachment(): void
    {
        $postId = $this->secretPost();
        $this->createAttachment($postId, 'secdlmgrAAAA');

        $response = $this->actingAs($this->managerUser, 'sanctum')
            ->get($this->downloadUrl('secdlmgrAAAA'));

        $this->assertNotSame(403, $response->getStatusCode(), 'manager 는 비밀글 첨부에 접근 가능해야 합니다');
        $this->assertLessThan(500, $response->getStatusCode(), '게이트 통과 시 서버 오류가 아니어야 합니다');
    }

    // ==========================================
    // 정상글 첨부는 그대로 (회귀 방지)
    // ==========================================

    /**
     * 비밀글이 아닌 글의 첨부는 그대로 받을 수 있다(회귀 방지).
     *
     * @scenario viewer=regular
     *
     * @effects normal_post_attachment_still_public
     *
     * @return void 반환값 없음
     */
    public function test_normal_post_attachment_still_public(): void
    {
        $postId = $this->createTestPost([
            'title' => '정상글 첨부',
            'status' => 'published',
            'is_secret' => false,
        ]);
        $this->createAttachment($postId, 'normsecAAAAA');

        $response = $this->actingAs($this->regularUser, 'sanctum')
            ->get($this->downloadUrl('normsecAAAAA'));

        $this->assertNotSame(403, $response->getStatusCode(), '정상글 첨부는 권한 차단되면 안 됩니다');
        $this->assertLessThan(500, $response->getStatusCode(), '게이트 통과 시 서버 오류가 아니어야 합니다');
    }

    // ==========================================
    // 서명 preview URL (비밀글 <img> 썸네일 렌더 경로)
    //
    // 브라우저 <img src> 는 Authorization 헤더를 실을 수 없어, 비밀 게이트 도입 후
    // 열람 권한자(작성자·manager) 화면의 비밀글 첨부 썸네일이 무인증 요청 → 403 으로
    // 깨졌다. 게이트를 통과한 응답 직렬화(PostResource)가 한시 서명 URL 을 발급하고,
    // 서빙 엔드포인트가 유효 서명을 허용한다 — 무서명 게이트는 종전과 동일하다.
    // ==========================================

    /**
     * 서명 preview URL 을 만든다.
     *
     * @param  string  $hash  첨부 해시
     * @param  int  $minutes  유효 시간(분, 음수면 만료된 URL)
     * @return string 상대경로 서명 URL
     */
    private function signedPreviewUrl(string $hash, int $minutes = 30): string
    {
        return URL::temporarySignedRoute(
            'api.modules.sirsoft-board.boards.attachment.preview',
            now()->addMinutes($minutes),
            ['slug' => $this->board->slug, 'hash' => $hash],
            absolute: false
        );
    }

    /**
     * 유효한 한시 서명 preview URL 은 무인증(게스트) 요청도 비밀 게이트를 통과한다.
     *
     * @scenario viewer=guest
     *
     * @effects guest_with_valid_signature_passes_secret_preview_gate
     *
     * @return void 반환값 없음
     */
    public function test_guest_with_valid_signed_url_passes_secret_preview_gate(): void
    {
        $postId = $this->secretPost();
        $this->createAttachment($postId, 'secsignedAAA', image: true);

        $response = $this->get($this->signedPreviewUrl('secsignedAAA'));

        // 실제 파일이 없어 404 여도 403(비밀 차단)은 아니어야 한다
        $this->assertNotSame(403, $response->getStatusCode(), '유효 서명 URL 은 비밀 게이트를 통과해야 합니다');
        $this->assertLessThan(500, $response->getStatusCode(), '게이트 통과 시 서버 오류가 아니어야 합니다');
    }

    /**
     * 변조된 서명 preview URL 은 종전과 동일하게 비밀 게이트에 차단된다 (403).
     *
     * @scenario viewer=guest
     *
     * @effects guest_with_tampered_signature_still_blocked
     *
     * @return void 반환값 없음
     */
    public function test_guest_with_tampered_signature_still_blocked(): void
    {
        $postId = $this->secretPost();
        $this->createAttachment($postId, 'sectamperAAA', image: true);

        $tampered = preg_replace_callback(
            '/(signature=)([0-9a-f]+)/',
            fn ($m) => $m[1].substr($m[2], 0, -8).strrev(substr($m[2], -8)),
            $this->signedPreviewUrl('sectamperAAA')
        );

        $this->get($tampered)->assertStatus(403);
    }

    /**
     * 만료된 서명 preview URL 은 비밀 게이트에 차단된다 (403, 한시성 보장).
     *
     * @scenario viewer=guest
     *
     * @effects guest_with_expired_signature_still_blocked
     *
     * @return void 반환값 없음
     */
    public function test_guest_with_expired_signature_still_blocked(): void
    {
        $postId = $this->secretPost();
        $this->createAttachment($postId, 'secexpireAAA', image: true);

        $this->get($this->signedPreviewUrl('secexpireAAA', minutes: -1))->assertStatus(403);
    }

    /**
     * 열람 권한자(작성자)에게 직렬화되는 비밀글 상세 응답의 첨부 preview_url 은
     * 한시 서명 URL 이고, 그 URL 은 무인증 <img> 요청으로도 게이트를 통과한다
     * (렌더 계약의 양끝 검증).
     *
     * @scenario viewer=owner
     *
     * @effects secret_post_detail_serializes_signed_preview_url
     *
     * @return void 반환값 없음
     */
    public function test_secret_post_detail_serializes_signed_preview_url_for_owner(): void
    {
        $postId = $this->secretPost();
        $this->createAttachment($postId, 'secserialAAA', image: true);

        $previewUrl = $this->actingAs($this->ownerUser, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}")
            ->assertStatus(200)
            ->json('data.attachments.0.preview_url');

        $this->assertIsString($previewUrl);
        $this->assertStringContainsString('signature=', $previewUrl);

        $response = $this->get($previewUrl);
        $this->assertNotSame(403, $response->getStatusCode(), '직렬화된 서명 URL 은 무인증 게이트를 통과해야 합니다');
        $this->assertLessThan(500, $response->getStatusCode(), '게이트 통과 시 서버 오류가 아니어야 합니다');
    }

    /**
     * 정상글(비밀 아님) 상세 응답의 첨부 preview_url 은 종전과 동일한 무서명
     * 공개 hash 경로다 (공개 콘텐츠에 만료성 URL 이 섞이는 회귀 방지).
     *
     * @scenario viewer=regular
     *
     * @effects normal_post_detail_serializes_plain_preview_url
     *
     * @return void 반환값 없음
     */
    public function test_normal_post_detail_serializes_plain_preview_url(): void
    {
        $postId = $this->createTestPost([
            'title' => '정상글 첨부 직렬화',
            'status' => 'published',
            'is_secret' => false,
        ]);
        $this->createAttachment($postId, 'normserialAA', image: true);

        $previewUrl = $this->actingAs($this->regularUser, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}")
            ->assertStatus(200)
            ->json('data.attachments.0.preview_url');

        $this->assertSame($this->previewUrl('normserialAA'), $previewUrl);
    }
}
