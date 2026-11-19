<?php

namespace Tests\Feature\User;

use App\Extension\HookManager;
use App\Http\Resources\UserCollection;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PasswordPolicyFixtures;
use Tests\TestCase;

/**
 * 회원 칸 수정 잠금 `core.user.locked_fields` (#798 K8 · D8)
 *
 * 확장(예: 회사 IdP 가 관리하는 이름)이 잠근 칸은 마이페이지·관리자 화면·언어 변경 경로에서 「현재 값과 같아야 함」이다. 요청에 그 칸이
 * 없으면 통과하고, 허용 목록 밖 이름은 무시하며, 서비스 직접 호출(확장의 동기화)은 잠금을 받지 않는다. 회원 응답에 `locked_fields` 가 실린다.
 */
class LockedFieldsTest extends TestCase
{
    use DatabaseTransactions;
    use PasswordPolicyFixtures;

    /**
     * 잠금 필터를 둡니다.
     *
     * @param  array<int, string>  $fields  잠글 칸
     * @return void 반환값 없음
     */
    private function lock(array $fields): void
    {
        HookManager::addFilter('core.user.locked_fields', fn (array $current, User $user, string $surface) => array_merge($current, $fields));
    }

    /**
     * 회원을 만듭니다.
     *
     * @return User 회원
     */
    private function member(): User
    {
        return $this->makeMember(['email' => 'lock.'.Str::lower(Str::random(8)).'@example.test', 'name' => '홍길동', 'language' => 'ko']);
    }

    /**
     * 필터가 없으면 지금처럼 바꿀 수 있고 응답의 잠금 목록은 빈 배열이다.
     *
     * @scenario filter=none
     *
     * @effects no_filter_regression, resource_locked_fields_empty
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 필터가_없으면_지금과_같다(): void
    {
        $user = $this->member();
        $token = $this->issueToken($user);

        $this->withToken($token)->putJson('/api/me', ['name' => '새이름', 'email' => $user->email])->assertOk();
        $this->assertSame('새이름', $user->fresh()->name);
        $this->withToken($token)->getJson('/api/me')->assertJsonPath('data.locked_fields', []);
    }

    /**
     * 잠긴 칸은 같은 값이면 200, 다른 값이면 칸별 422, 요청에 없으면 통과. 허용 목록 밖 이름은 무시한다.
     *
     * @scenario surface=profile, value=same|different|absent, field=allowed|unknown
     *
     * @effects locked_same_value_passes, locked_different_value_422, locked_absent_passes, unknown_field_ignored, resource_lists_locked_fields
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 마이페이지_잠금(): void
    {
        $this->lock(['name', 'not_a_field']);
        $user = $this->member();
        $token = $this->issueToken($user);

        $this->withToken($token)->getJson('/api/me')->assertJsonPath('data.locked_fields', ['name']);

        $this->withToken($token)->putJson('/api/me', ['name' => '홍길동', 'email' => $user->email, 'nickname' => '길동'])->assertOk();
        $this->withToken($token)->putJson('/api/me', ['name' => '바꿈', 'email' => $user->email])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', __('user.field_locked'));
        $this->assertSame('홍길동', $user->fresh()->name);
        $this->assertSame('길동', $user->fresh()->nickname);
    }

    /**
     * 관리자 화면도 같은 규칙이고 관리자 회원 상세에 잠금 목록이 실린다.
     *
     * @scenario surface=admin
     *
     * @effects admin_surface_locked
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 관리자_화면_잠금(): void
    {
        $surfaces = [];
        HookManager::addFilter('core.user.locked_fields', function (array $current, User $user, string $surface) use (&$surfaces) {
            $surfaces[] = $surface;

            return ['email'];
        });
        $user = $this->member();
        $admin = $this->makeAdmin(['email' => 'lock.admin.'.Str::lower(Str::random(8)).'@example.test', 'is_super' => true]);
        $token = $this->issueToken($admin);

        $this->withToken($token)->getJson('/api/admin/users/'.$user->uuid)->assertJsonPath('data.locked_fields', ['email']);
        $this->withToken($token)->putJson('/api/admin/users/'.$user->uuid, ['name' => $user->name, 'email' => 'other.'.Str::random(6).'@example.test'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', __('user.field_locked'));
        $this->assertContains('admin', $surfaces);
    }

    /**
     * 관리자 회원 목록은 잠긴 칸 필터를 부르지 않는다 — 행마다 부르는 필터는 훅 규정상 금지(목록은 `core.user.filter_list_rows` 한 번).
     * 잠긴 칸은 수정 화면이 읽는 상세 응답에만 싣는다(비밀번호 상태 `password_status` 와 같은 선례).
     *
     * @scenario surface=admin_list
     *
     * @effects admin_list_never_calls_locked_fields_filter
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 관리자_목록은_잠긴_칸_필터를_부르지_않는다(): void
    {
        $calls = 0;
        HookManager::addFilter('core.user.locked_fields', function (array $current) use (&$calls) {
            $calls++;

            return ['email'];
        });
        $this->member();
        $this->member();
        $admin = $this->makeAdmin(['email' => 'lock.list.'.Str::lower(Str::random(8)).'@example.test', 'is_super' => true]);
        $token = $this->issueToken($admin);

        $rows = $this->withToken($token)->getJson('/api/admin/users')->assertOk()->json('data.data');
        $this->assertNotEmpty($rows);
        $this->assertSame(0, $calls);
        foreach ($rows as $row) {
            $this->assertArrayNotHasKey('locked_fields', $row);
        }

        // 행마다 관리자 배열을 만드는 컬렉션 경로도 필터를 부르지 않는다(상세 응답만 잠긴 칸을 싣는다)
        $collection = (new UserCollection(User::query()->paginate(3)))->withAdminInfo();
        $this->assertSame(0, $calls);
        foreach ($collection['data'] as $row) {
            $this->assertArrayNotHasKey('locked_fields', $row);
        }
    }

    /**
     * 언어 변경 전용 경로도 잠긴 언어를 바꾸지 못한다.
     *
     * @scenario surface=language_endpoint
     *
     * @effects language_endpoint_locked
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 언어_변경_경로_잠금(): void
    {
        $this->lock(['language']);
        $user = $this->member();

        $this->withToken($this->issueToken($user))->postJson('/api/user/profile/update-language', ['language' => 'en'])
            ->assertStatus(422)
            ->assertJsonPath('errors.language.0', __('user.field_locked'));
        $this->assertSame('ko', $user->fresh()->language);
    }

    /**
     * 서비스를 직접 부르는 확장 동기화는 잠금을 받지 않는다.
     *
     * @scenario surface=service_direct
     *
     * @effects service_direct_bypasses_lock
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 서비스_직접_호출은_통과(): void
    {
        $this->lock(['name']);
        $user = $this->member();
        Auth::forgetGuards();

        app(UserService::class)->updateUser($user, ['name' => 'IdP 이름']);

        $this->assertSame('IdP 이름', $user->fresh()->name);
    }
}
