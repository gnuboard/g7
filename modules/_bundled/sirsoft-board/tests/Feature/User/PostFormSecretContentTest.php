<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

// 테스트 베이스 클래스 수동 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 글쓰기 폼(form-data · form-meta) 비밀 원문 게이트 테스트 (KVE-2026-2375)
 *
 * 폼 응답은 상세 조회의 비밀 게이트를 거치지 않아 다음 경로로 비밀글 원문이 나가던 결함을 고정한다.
 * - 비회원 비밀글 수정 폼(form-data?post_id=) — 비밀번호 없이 회원·비회원 누구나 원문 수신
 * - 답글 폼(form-meta?parent_id=) — 남의 회원 비밀글 원문이 parent_post 로 수신
 *
 * 정당 경로(작성자 본인 · 비밀번호/수정 토큰을 맞힌 비회원 · 게시판 관리 권한자)는 원문을 받는다.
 * 관리 권한자는 남의 글 수정 폼을 열 수 있으므로 원문이 빈 채 저장돼 지워지지 않게 한다(설계 결정).
 *
 * 시나리오 축·효과는 매니페스트 tests/scenarios/board-secret-content-bypass-paths.yaml 참조.
 */
class PostFormSecretContentTest extends BoardTestCase
{
    private User $owner;

    private User $outsider;

    private int $guestSecretPostId;

    private int $memberSecretPostId;

    /**
     * 이 테스트 전용 게시판 슬러그를 반환합니다.
     *
     * @return string 게시판 슬러그
     */
    protected function getTestBoardSlug(): string
    {
        return 'post-form-secret';
    }

    /**
     * 게시판 권한·회원·게시글을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->setGuestPermissions(['posts.read', 'posts.write']);
        $this->grantUserRolePermissions(['posts.read', 'posts.write']);

        $this->owner = $this->createUser();
        $this->outsider = $this->createUser();

        $this->guestSecretPostId = $this->createTestPost([
            'title' => '비회원 비밀글',
            'content' => 'GUEST-SECRET-BODY',
            'is_secret' => true,
            'password' => bcrypt('guestpw'),
        ]);
        $this->memberSecretPostId = $this->createTestPost([
            'title' => '회원 비밀글',
            'content' => 'MEMBER-SECRET-BODY',
            'is_secret' => true,
            'user_id' => $this->owner->id,
        ]);
    }

    /**
     * 폼 API 경로.
     *
     * @param  string  $kind  폼 종류 (form-data 또는 form-meta)
     * @param  string  $query  쿼리 문자열
     * @return string 요청 경로
     */
    private function formUrl(string $kind, string $query): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$kind}?{$query}";
    }

    /**
     * 무관한 회원이 비밀번호 없이 비회원 비밀글 수정 폼을 열면 원문을 받지 못한다.
     *
     * @scenario viewer=outsider
     *
     * @effects guest_secret_post_form_data_masked_without_password
     *
     * @return void 반환값 없음
     */
    public function test_outsider_member_gets_no_original_for_guest_secret_post_form(): void
    {
        $response = $this->actingAs($this->outsider, 'sanctum')
            ->getJson($this->formUrl('form-data', "post_id={$this->guestSecretPostId}"));

        $response->assertOk();
        // 폼 데이터는 빈 본문 칸으로 내려간다(컨트롤러가 null 을 '' 로 채움) — 원문이 없어야 한다
        $this->assertSame('', $response->json('data.content'));
        $this->assertStringNotContainsString('GUEST-SECRET-BODY', $response->getContent());
    }

    /**
     * 비회원이 비밀번호 없이 비회원 비밀글 수정 폼을 열면 원문을 받지 못한다.
     *
     * @scenario viewer=guest
     *
     * @effects guest_secret_post_form_data_masked_without_password
     *
     * @return void 반환값 없음
     */
    public function test_guest_without_password_gets_no_original(): void
    {
        $response = $this->getJson($this->formUrl('form-data', "post_id={$this->guestSecretPostId}"));

        $response->assertOk();
        // 폼 데이터는 빈 본문 칸으로 내려간다(컨트롤러가 null 을 '' 로 채움) — 원문이 없어야 한다
        $this->assertSame('', $response->json('data.content'));
        $this->assertStringNotContainsString('GUEST-SECRET-BODY', $response->getContent());
    }

    /**
     * 비밀번호를 함께 보낸 비회원 작성자는 수정 폼에서 원문을 받는다.
     *
     * @scenario viewer=guest_author
     *
     * @effects guest_author_with_password_gets_original
     *
     * @return void 반환값 없음
     */
    public function test_guest_author_with_password_gets_original(): void
    {
        $response = $this->getJson($this->formUrl('form-data', "post_id={$this->guestSecretPostId}&password=guestpw"));

        $response->assertOk();
        $this->assertSame('GUEST-SECRET-BODY', $response->json('data.content'));
    }

    /**
     * 화면 흐름: 수정용 비밀번호 확인 → 받은 verification_token 으로 폼 데이터 조회.
     *
     * @scenario viewer=guest_author
     *
     * @effects guest_author_with_password_gets_original
     *
     * @return void 반환값 없음
     */
    public function test_guest_author_with_modify_token_gets_original(): void
    {
        $verify = $this->postJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$this->guestSecretPostId}/verify-password-for-modify",
            ['password' => 'guestpw']
        );
        $verify->assertOk();
        $token = $verify->json('data.verification_token');
        $this->assertNotEmpty($token);

        $response = $this->getJson($this->formUrl('form-data', "post_id={$this->guestSecretPostId}&verification_token={$token}"));

        $response->assertOk();
        $this->assertSame('GUEST-SECRET-BODY', $response->json('data.content'));
    }

    /**
     * 회원 비밀글 작성자는 수정 폼에서 원문을 받는다.
     *
     * @scenario viewer=owner
     *
     * @effects owner_form_data_gets_original
     *
     * @return void 반환값 없음
     */
    public function test_owner_gets_original_in_form_data(): void
    {
        $response = $this->actingAs($this->owner, 'sanctum')
            ->getJson($this->formUrl('form-data', "post_id={$this->memberSecretPostId}"));

        $response->assertOk();
        $this->assertSame('MEMBER-SECRET-BODY', $response->json('data.content'));
    }

    /**
     * 관리자 화면 게시판 관리 권한(admin.manage)만 있고 사용자 화면 비밀글 읽기·매니저 권한이 없어도,
     * 남의 글 수정 폼을 열 수 있는 사람이므로 원문을 받는다 — 빈 본문 저장으로 원문이 지워지지 않게.
     *
     * @scenario viewer=board_admin
     *
     * @effects board_admin_form_data_gets_original
     *
     * @return void 반환값 없음
     */
    public function test_board_admin_manage_holder_gets_original_in_user_form(): void
    {
        $admin = $this->createAdminUser(["sirsoft-board.{$this->board->slug}.admin.manage"]);
        $adminRole = Role::where('identifier', 'admin')->first();
        $writePerm = Permission::firstOrCreate(
            ['identifier' => "sirsoft-board.{$this->board->slug}.posts.write"],
            ['name' => ['ko' => 'posts.write', 'en' => 'posts.write'], 'type' => 'user']
        );
        $adminRole->permissions()->syncWithoutDetaching([$writePerm->id]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson($this->formUrl('form-data', "post_id={$this->memberSecretPostId}"));

        $response->assertOk();
        $this->assertSame('MEMBER-SECRET-BODY', $response->json('data.content'));
    }

    /**
     * 남의 회원 비밀글에 답글 폼을 열면 존재를 숨긴 404 — 폼 진입이 곧 원문 읽기가 되지 않게.
     *
     * @scenario viewer=outsider
     *
     * @effects reply_form_meta_blocked_for_unreadable_secret_parent
     *
     * @return void 반환값 없음
     */
    public function test_outsider_cannot_open_reply_form_for_secret_parent(): void
    {
        $meta = $this->actingAs($this->outsider, 'sanctum')
            ->getJson($this->formUrl('form-meta', "parent_id={$this->memberSecretPostId}"));
        $meta->assertNotFound();
        $this->assertStringNotContainsString('MEMBER-SECRET-BODY', $meta->getContent());

        $data = $this->actingAs($this->outsider, 'sanctum')
            ->getJson($this->formUrl('form-data', "parent_id={$this->memberSecretPostId}"));
        $data->assertNotFound();
    }

    /**
     * 비밀글 작성자는 자기 글의 답글 폼에서 원글 원문을 받는다.
     *
     * @scenario viewer=owner
     *
     * @effects owner_reply_form_meta_gets_parent_original
     *
     * @return void 반환값 없음
     */
    public function test_owner_reply_form_receives_parent_original(): void
    {
        $response = $this->actingAs($this->owner, 'sanctum')
            ->getJson($this->formUrl('form-meta', "parent_id={$this->memberSecretPostId}"));

        $response->assertOk();
        $this->assertSame('MEMBER-SECRET-BODY', $response->json('data.parent_post.content'));
    }

    /**
     * 비밀이 아닌 글은 답글 폼 진입·원문 수신이 그대로다 (게이트가 공개글을 막지 않는다).
     *
     * @scenario viewer=outsider
     *
     * @effects public_parent_reply_form_unchanged
     *
     * @return void 반환값 없음
     */
    public function test_public_parent_reply_form_is_unchanged(): void
    {
        $publicId = $this->createTestPost(['title' => '공개 글', 'content' => 'PUBLIC-BODY', 'user_id' => $this->owner->id]);

        $response = $this->actingAs($this->outsider, 'sanctum')
            ->getJson($this->formUrl('form-meta', "parent_id={$publicId}"));

        $response->assertOk();
        $this->assertSame('PUBLIC-BODY', $response->json('data.parent_post.content'));
    }

    /**
     * 무관한 회원·비회원이 비밀번호 없이 비회원 비밀글 수정 폼 틀(form-meta)을 열면 첨부 목록이 비어 있다
     * — 상세 조회가 같은 요청자에게 첨부 목록을 비워 주는 것과 같게, 파일 이름·크기가 새지 않게.
     *
     * @scenario viewer=outsider
     *
     * @effects guest_secret_post_form_meta_attachments_hidden_without_password
     *
     * @return void 반환값 없음
     */
    public function test_guest_secret_post_form_meta_hides_attachments_without_password(): void
    {
        $this->attachFileTo($this->guestSecretPostId, 'SECRET-FILENAME.pdf');

        $outsider = $this->actingAs($this->outsider, 'sanctum')
            ->getJson($this->formUrl('form-meta', "post_id={$this->guestSecretPostId}"));
        $outsider->assertOk();
        $this->assertSame([], $outsider->json('data.attachments'));
        $this->assertStringNotContainsString('SECRET-FILENAME', $outsider->getContent());

        $this->app['auth']->forgetGuards();
        $guest = $this->getJson($this->formUrl('form-meta', "post_id={$this->guestSecretPostId}"));
        $guest->assertOk();
        $this->assertSame([], $guest->json('data.attachments'));
        $this->assertStringNotContainsString('SECRET-FILENAME', $guest->getContent());
    }

    /**
     * 화면 흐름: 비회원 작성자가 수정용 비밀번호를 맞히면 폼 틀을 다시 받아 자기 첨부를 그대로 본다.
     * 게시판 관리 권한자는 비밀번호 없이 남의 글 수정 폼을 열 수 있으므로 첨부도 받는다.
     *
     * @scenario viewer=guest_author
     *
     * @effects guest_author_form_meta_attachments_after_password
     *
     * @return void 반환값 없음
     */
    public function test_guest_author_with_modify_token_and_manager_get_attachments_in_form_meta(): void
    {
        $this->attachFileTo($this->guestSecretPostId, 'SECRET-FILENAME.pdf');

        $verify = $this->postJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$this->guestSecretPostId}/verify-password-for-modify",
            ['password' => 'guestpw']
        );
        $verify->assertOk();
        $token = $verify->json('data.verification_token');

        $author = $this->withHeader('X-Board-Post-Verify-Token', $token)
            ->getJson($this->formUrl('form-meta', "post_id={$this->guestSecretPostId}"));
        $author->assertOk();
        $this->assertFalse($author->json('data.requires_password'));
        $this->assertSame('SECRET-FILENAME.pdf', $author->json('data.attachments.0.original_filename'));

        $manager = $this->createUser();
        $this->grantUserPermissionsTo($manager, ['manager']);
        $managerResponse = $this->actingAs($manager, 'sanctum')
            ->getJson($this->formUrl('form-meta', "post_id={$this->guestSecretPostId}"));
        $managerResponse->assertOk();
        $this->assertSame('SECRET-FILENAME.pdf', $managerResponse->json('data.attachments.0.original_filename'));
    }

    /**
     * 비밀이 아닌 비회원 글은 수정 폼 틀의 첨부 목록이 그대로다 (상세에서도 공개된 목록).
     *
     * @scenario viewer=outsider
     *
     * @effects public_guest_post_form_meta_attachments_unchanged
     *
     * @return void 반환값 없음
     */
    public function test_public_guest_post_form_meta_attachments_unchanged(): void
    {
        $publicGuestId = $this->createTestPost([
            'title' => '비회원 공개 글',
            'content' => 'PUBLIC-GUEST-BODY',
            'password' => bcrypt('guestpw'),
        ]);
        $this->attachFileTo($publicGuestId, 'PUBLIC-FILE.pdf');

        $response = $this->actingAs($this->outsider, 'sanctum')
            ->getJson($this->formUrl('form-meta', "post_id={$publicGuestId}"));

        $response->assertOk();
        $this->assertSame('PUBLIC-FILE.pdf', $response->json('data.attachments.0.original_filename'));
    }

    /**
     * 회원에게 이 게시판의 사용자 권한을 추가 역할로 부여합니다.
     *
     * @param  User  $user  대상 회원
     * @param  array<int, string>  $keys  게시판 권한 키 (예: manager)
     * @return void 반환값 없음
     */
    private function grantUserPermissionsTo(User $user, array $keys): void
    {
        $role = Role::create([
            'identifier' => 'pfs-'.uniqid(),
            'name' => ['ko' => '추가 권한', 'en' => 'Extra'],
        ]);
        foreach ($keys as $key) {
            $perm = Permission::firstOrCreate(
                ['identifier' => "sirsoft-board.{$this->board->slug}.{$key}"],
                ['name' => ['ko' => $key, 'en' => $key], 'type' => 'user']
            );
            $role->permissions()->syncWithoutDetaching([$perm->id]);
        }
        $user->roles()->attach($role->id);
    }

    /**
     * 게시글에 첨부 레코드를 붙입니다 (파일 본체 없이 메타데이터만 — 목록 직렬화 검사용).
     *
     * @param  int  $postId  게시글 ID
     * @param  string  $filename  원본 파일명
     * @return void 반환값 없음
     */
    private function attachFileTo(int $postId, string $filename): void
    {
        Attachment::create([
            'board_id' => $this->board->id,
            'post_id' => $postId,
            'original_filename' => $filename,
            'stored_filename' => 'stored-'.$postId.'.pdf',
            'disk' => 'local',
            'path' => "{$this->board->slug}/2026/10/02/stored-{$postId}.pdf",
            'mime_type' => 'application/pdf',
            'size' => 10,
            'collection' => 'attachments',
            'order' => 1,
        ]);
    }
}
