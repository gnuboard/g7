<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 비회원 임시 첨부 소유 쿠키 (#883 2단계)
 *
 * 비회원 업로드 응답에 무작위 값 쿠키(`g7_board_guest_upload`)를 붙이고 첨부 행에는 그 해시만 둔다. 그 쿠키가 있으면
 * 비회원도 글에 연결되기 전의 자기 임시 첨부를 지우고 순서를 바꿀 수 있다. 종전에는 403 이라 업로더의 X 가 서버에 남았다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-board/tests/scenarios/guest-upload-owner-cookie.yaml
 */
class GuestTempAttachmentCookieOwnershipTest extends BoardTestCase
{
    private const COOKIE = 'g7_board_guest_upload';

    /**
     * 테스트 게시판 슬러그를 반환합니다.
     *
     * @return string 게시판 슬러그
     */
    protected function getTestBoardSlug(): string
    {
        return 'guest-cookie-owner';
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
            'name' => ['ko' => '비회원 소유 쿠키 게시판', 'en' => 'Guest Cookie Board'],
            'is_active' => true,
            'use_file_upload' => true,
            'max_file_count' => 5,
        ];
    }

    /**
     * 비회원·회원 권한을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->setGuestPermissions(['posts.read', 'posts.write', 'attachments.upload']);
        $this->grantUserRolePermissions(['posts.read', 'posts.write', 'attachments.upload']);

        // 시험의 JSON 요청은 이 설정이 있어야 쿠키를 싣는다 — 브라우저 XHR 은 같은 출처면 자동으로 싣는다
        $this->withCredentials();
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
     * 쿠키가 같으면 비회원이 자기 임시 첨부를 지우고 순서를 바꾼다. 해시는 응답에 실리지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=cookie_matches
     *
     * @effects guest_can_delete_and_reorder_own_temp_with_cookie, owner_hash_never_serialized
     */
    public function test_guest_with_cookie_can_delete_and_reorder_own_temp_attachments(): void
    {
        $tempKey = (string) Str::uuid();
        $first = $this->guestUpload($tempKey);
        $token = $this->cookieFrom($first);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', $token);
        $this->assertArrayNotHasKey('guest_owner_hash', $first->json('data.data'));

        $second = $this->withUnencryptedCookie(self::COOKIE, $token)->postJson($this->attachmentsUrl(), [
            'file' => UploadedFile::fake()->image('second.png'),
            'temp_key' => $tempKey,
        ])->assertStatus(201);
        $this->assertSame($token, $this->cookieFrom($second), '두 번째 업로드도 같은 소유 토큰을 쓴다');

        $firstId = (int) $first->json('data.data.id');
        $secondId = (int) $second->json('data.data.id');

        $this->withUnencryptedCookie(self::COOKIE, $token)
            ->patchJson("{$this->attachmentsUrl()}/reorder", ['order' => [['id' => $secondId, 'order' => 1], ['id' => $firstId, 'order' => 2]]])
            ->assertStatus(200);
        $this->assertSame(1, (int) Attachment::query()->findOrFail($secondId)->order);

        $this->withUnencryptedCookie(self::COOKIE, $token)
            ->deleteJson("{$this->attachmentsUrl()}/{$firstId}")
            ->assertStatus(200);
        $this->assertSoftDeleted('board_attachments', ['id' => $firstId]);
    }

    /**
     * 쿠키가 없거나 위조됐거나 다른 비회원 쿠키면 403 이다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=cookie_missing_or_foreign
     *
     * @effects guest_without_matching_cookie_denied
     */
    public function test_guest_without_matching_cookie_is_denied(): void
    {
        $id = (int) $this->guestUpload((string) Str::uuid())->json('data.data.id');
        $otherGuestToken = $this->cookieFrom($this->guestUpload((string) Str::uuid()));

        $this->deleteJson("{$this->attachmentsUrl()}/{$id}")->assertStatus(403);
        $this->withUnencryptedCookie(self::COOKIE, Str::random(40))->deleteJson("{$this->attachmentsUrl()}/{$id}")->assertStatus(403);
        $this->withUnencryptedCookie(self::COOKIE, $otherGuestToken)->deleteJson("{$this->attachmentsUrl()}/{$id}")->assertStatus(403);
        $this->withUnencryptedCookie(self::COOKIE, $otherGuestToken)
            ->patchJson("{$this->attachmentsUrl()}/reorder", ['order' => [['id' => $id, 'order' => 1]]])
            ->assertStatus(403);

        $this->assertNotSoftDeleted('board_attachments', ['id' => $id]);
    }

    /**
     * 글에 연결되면 쿠키가 있어도 지울 수 없고, 해시는 지워진다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=after_link
     *
     * @effects owner_hash_cleared_on_link
     */
    public function test_cookie_no_longer_works_after_link(): void
    {
        $tempKey = (string) Str::uuid();
        $upload = $this->guestUpload($tempKey);
        $token = $this->cookieFrom($upload);
        $id = (int) $upload->json('data.data.id');

        $this->postJson($this->postsUrl(), [
            'title' => '비회원 쿠키 연결',
            'content' => '본문은 최소 길이를 넘겨야 검증을 통과합니다.',
            'author_name' => '비회원',
            'password' => 'guest1234',
            'temp_key' => $tempKey,
        ])->assertStatus(201);

        $attachment = Attachment::query()->findOrFail($id);
        $this->assertNotNull($attachment->post_id);
        $this->assertNull($attachment->guest_owner_hash);

        $this->withUnencryptedCookie(self::COOKIE, $token)->deleteJson("{$this->attachmentsUrl()}/{$id}")->assertStatus(403);
    }

    /**
     * 로그인 전에 비회원으로 올린 첨부는 같은 쿠키로 로그인 뒤 자기 글에 잇는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=login_after_upload
     *
     * @effects member_links_pre_login_upload_with_cookie
     */
    public function test_member_links_pre_login_guest_upload_with_same_cookie(): void
    {
        $tempKey = (string) Str::uuid();
        $upload = $this->guestUpload($tempKey);
        $token = $this->cookieFrom($upload);
        $id = (int) $upload->json('data.data.id');

        $member = User::factory()->create(['email' => 'cookie-member@test.com']);
        $role = Role::where('identifier', 'user')->first();
        if ($role) {
            $member->roles()->attach($role->id);
        }

        $postId = (int) $this->actingAs($member, 'sanctum')
            ->withUnencryptedCookie(self::COOKIE, $token)
            ->postJson($this->postsUrl(), [
                'title' => '로그인 후 연결',
                'content' => '본문은 최소 길이를 넘겨야 검증을 통과합니다.',
                'temp_key' => $tempKey,
            ])->assertStatus(201)->json('data.id');

        $this->assertSame($postId, (int) Attachment::query()->findOrFail($id)->post_id);
    }

    /**
     * 비회원으로 파일을 하나 올립니다.
     *
     * @param  string  $tempKey  임시 키
     * @return TestResponse 업로드 응답
     */
    private function guestUpload(string $tempKey): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson($this->attachmentsUrl(), [
            'file' => UploadedFile::fake()->image('guest-'.Str::random(4).'.png'),
            'temp_key' => $tempKey,
        ])->assertStatus(201);
    }

    /**
     * 응답의 소유 쿠키 값을 꺼냅니다.
     *
     * @param  TestResponse  $response  업로드 응답
     * @return string 쿠키 값
     */
    private function cookieFrom(TestResponse $response): string
    {
        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === self::COOKIE);
        $this->assertNotNull($cookie, '비회원 업로드 응답에 소유 쿠키가 있어야 한다');
        $this->assertSame('/api/modules/sirsoft-board', $cookie->getPath());
        $this->assertTrue($cookie->isHttpOnly());

        return (string) $cookie->getValue();
    }

    /**
     * 사용자 첨부 업로드 API 경로를 반환합니다.
     *
     * @return string API 경로
     */
    private function attachmentsUrl(): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->getTestBoardSlug()}/attachments";
    }

    /**
     * 사용자 글 작성 API 경로를 반환합니다.
     *
     * @return string API 경로
     */
    private function postsUrl(): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->getTestBoardSlug()}/posts";
    }
}
