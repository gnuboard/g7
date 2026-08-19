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

    protected function getTestBoardSlug(): string
    {
        return 'secret-attach';
    }

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

    private function downloadUrl(string $hash): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->board->slug}/attachment/{$hash}";
    }

    private function previewUrl(string $hash): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->board->slug}/attachment/{$hash}/preview";
    }

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
     * @scenario viewer=regular
     *
     * @effects regular_user_cannot_download_secret_post_attachment
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
     * @scenario viewer=regular
     *
     * @effects regular_user_cannot_preview_secret_post_attachment
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
     * @scenario viewer=owner
     *
     * @effects owner_can_access_secret_post_attachment
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
     * @scenario viewer=manager
     *
     * @effects manager_can_access_secret_post_attachment
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
     * @scenario viewer=regular
     *
     * @effects normal_post_attachment_still_public
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
