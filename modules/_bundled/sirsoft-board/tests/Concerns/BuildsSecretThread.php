<?php

namespace Modules\Sirsoft\Board\Tests\Concerns;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * 비밀글 스레드 픽스처 (경로 규칙 시험 공용)
 *
 * 계획서의 D1 예시 스레드를 그대로 만든다:
 *
 *   A(원글) ─┬─ B ── D
 *            └─ C ── E(비회원)
 *
 * 모두 비밀글이다. 원글 A 는 회원(a) 글이거나, `$guestRoot` 이면 비밀번호 `a-pass` 를 가진
 * 비회원 글이다. E 는 비밀번호 `e-pass` 를 가진 비회원 글이다. 회원 b·c·d 는 각자의 글을,
 * outsider 는 아무 글도 쓰지 않은 회원이다.
 *
 * BoardTestCase 를 상속한 시험에서 쓴다($this->board·createTestPost 를 쓴다).
 */
trait BuildsSecretThread
{
    /**
     * 스레드 글 키(A~E) ⇒ 게시글 ID
     *
     * @var array<string, int>
     */
    protected array $thread = [];

    /**
     * 스레드 회원 키(a·b·c·d·outsider) ⇒ 회원
     *
     * @var array<string, User>
     */
    protected array $threadUsers = [];

    /**
     * D1 예시 스레드를 만듭니다.
     *
     * @param  bool  $guestRoot  원글 A 를 비회원(비밀번호 a-pass) 글로 만들지 여부
     * @param  bool  $secret  스레드 글을 모두 비밀글로 만들지 여부
     * @return void 반환값 없음
     */
    protected function buildSecretThread(bool $guestRoot = false, bool $secret = true): void
    {
        $this->grantThreadMemberPermissions();

        foreach (['a', 'b', 'c', 'd', 'outsider'] as $key) {
            $this->threadUsers[$key] = $this->createThreadMember();
        }

        $this->thread['A'] = $guestRoot
            ? $this->createThreadPost(null, null, 0, 'a-pass', $secret, '원글 A')
            : $this->createThreadPost(null, $this->threadUsers['a'], 0, null, $secret, '원글 A');
        $this->thread['B'] = $this->createThreadPost($this->thread['A'], $this->threadUsers['b'], 1, null, $secret, '답변 B');
        $this->thread['C'] = $this->createThreadPost($this->thread['A'], $this->threadUsers['c'], 1, null, $secret, '답변 C');
        $this->thread['D'] = $this->createThreadPost($this->thread['B'], $this->threadUsers['d'], 2, null, $secret, '답변 D');
        $this->thread['E'] = $this->createThreadPost($this->thread['C'], null, 2, 'e-pass', $secret, '답변 E');
    }

    /**
     * 스레드 글 하나를 만듭니다.
     *
     * @param  int|null  $parentId  부모 게시글 ID
     * @param  User|null  $author  회원 작성자 (null 이면 비회원)
     * @param  int  $depth  답글 깊이
     * @param  string|null  $password  비회원 비밀번호 (평문)
     * @param  bool  $secret  비밀글 여부
     * @param  string  $title  제목 (본문은 "{제목} 본문")
     * @return int 게시글 ID
     */
    protected function createThreadPost(?int $parentId, ?User $author, int $depth, ?string $password, bool $secret, string $title): int
    {
        return $this->createTestPost([
            'parent_id' => $parentId,
            'depth' => $depth,
            'user_id' => $author?->id,
            'author_name' => $author?->name ?? '비회원',
            'password' => $password !== null ? Hash::make($password) : null,
            'is_secret' => $secret,
            'title' => $title,
            'content' => $title.' 본문',
            'trigger_type' => 'user',
        ]);
    }

    /**
     * user 역할에 스레드 열람·작성에 필요한 게시판 권한을 줍니다.
     *
     * @return void 반환값 없음
     */
    protected function grantThreadMemberPermissions(): void
    {
        $this->grantUserRolePermissions(['posts.read', 'posts.write', 'comments.read', 'comments.write']);
    }

    /**
     * user 역할을 가진 회원을 만듭니다.
     *
     * @return User 회원
     */
    protected function createThreadMember(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('identifier', 'user')->value('id'));

        return $user;
    }

    /**
     * 게시판 권한 하나를 스코프와 함께 가진 회원을 만듭니다.
     *
     * 권한 행에 코어 스코프 메타(resource_route_key·owner_key)를 실제 게시판 권한 정의와 같게 싣는다.
     *
     * @param  string  $action  권한 키 (예: posts.read-secret, manager)
     * @param  string|null  $scope  null(전체)·self·role
     * @return User 회원
     */
    protected function createScopedBoardUser(string $action, ?string $scope): User
    {
        $slug = $this->board->slug;
        $meta = match ($action) {
            'manager', 'admin.manage' => ['resource_route_key' => 'board', 'owner_key' => 'created_by'],
            default => ['resource_route_key' => 'post', 'owner_key' => 'user_id'],
        };

        $permission = Permission::firstOrCreate(
            ['identifier' => "sirsoft-board.{$slug}.{$action}"],
            ['name' => ['ko' => $action, 'en' => $action], 'type' => str_starts_with($action, 'admin.') ? 'admin' : 'user'],
        );
        $permission->forceFill($meta)->save();

        $role = Role::create([
            'identifier' => 'thread-'.str_replace('.', '-', $action).'-'.($scope ?? 'all').'-'.uniqid(),
            'name' => ['ko' => $action, 'en' => $action],
        ]);
        $role->permissions()->attach($permission->id, ['scope_type' => $scope, 'granted_at' => now()]);

        $user = $this->createThreadMember();
        $user->roles()->attach($role->id);

        return $user;
    }

    /**
     * 사용자 상세 API 주소를 반환합니다.
     *
     * @param  string  $key  스레드 글 키 (A~E)
     * @return string 주소
     */
    protected function threadPostUrl(string $key): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$this->thread[$key]}";
    }

    /**
     * 비회원이 글 비밀번호를 맞혀 열람 토큰을 받습니다.
     *
     * @param  string  $key  스레드 글 키
     * @param  string  $password  평문 비밀번호
     * @param  string|null  $token  이미 가진 토큰 (재사용 확인용)
     * @return string 열람 토큰
     */
    protected function verifyThreadGuestPassword(string $key, string $password, ?string $token = null): string
    {
        $headers = $token !== null ? ['X-Board-Secret-View-Token' => $token] : [];
        $response = $this->withHeaders($headers)->postJson($this->threadPostUrl($key).'/verify-password', ['password' => $password]);
        $response->assertOk();

        return (string) $response->json('secret_view_token');
    }

    /**
     * 열람자 기준으로 상세 응답의 본문을 읽습니다.
     *
     * @param  string  $key  스레드 글 키
     * @param  User|null  $viewer  회원 열람자 (null 이면 비회원)
     * @param  string|null  $token  열람 토큰
     * @return string|null 본문 (가려졌으면 null)
     */
    protected function threadContentFor(string $key, ?User $viewer, ?string $token = null): ?string
    {
        if ($viewer !== null) {
            $this->actingAs($viewer);
        } else {
            $this->app['auth']->forgetGuards();
        }

        $headers = $token !== null ? ['X-Board-Secret-View-Token' => $token] : [];
        $response = $this->withHeaders($headers)->getJson($this->threadPostUrl($key));
        $response->assertOk();

        return $response->json('data.content');
    }

    /**
     * 스레드 글을 소프트 삭제합니다.
     *
     * @param  string  $key  스레드 글 키
     * @return void 반환값 없음
     */
    protected function trashThreadPost(string $key): void
    {
        DB::table('board_posts')->where('id', $this->thread[$key])->update([
            'deleted_at' => now(),
            'status' => 'deleted',
            'trigger_type' => 'user',
        ]);
    }
}
