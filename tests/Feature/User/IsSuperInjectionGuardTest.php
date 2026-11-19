<?php

namespace Tests\Feature\User;

use App\Extension\HookManager;
use App\Services\UserService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PasswordPolicyFixtures;
use Tests\TestCase;

/**
 * 최고관리자 지정 주입 차단 (#798 K15 · P9)
 *
 * 확장이 회원 생성·수정 데이터 필터로 `is_super` 를 바꿔 넣어도 저장되지 않고 경고가 남는다. 필터 전 값(관리자가 요청한 값)은 그대로다.
 */
class IsSuperInjectionGuardTest extends TestCase
{
    use DatabaseTransactions;
    use PasswordPolicyFixtures;

    /**
     * 생성 필터가 `is_super=true` 를 넣어도 일반 회원으로 저장되고 경고가 남는다.
     *
     * @scenario surface=create, injection=filter
     *
     * @effects create_injection_reverted, injection_warned
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 생성_주입을_되돌린다(): void
    {
        Auth::forgetGuards();
        Log::spy();
        HookManager::addFilter('core.user.filter_create_data', fn (array $data) => array_merge($data, ['is_super' => true]));

        $user = app(UserService::class)->createUser([
            'name' => '주입',
            'email' => 'inj.'.Str::lower(Str::random(8)).'@example.test',
            'password' => 'Passw0rd!new9',
        ]);

        $this->assertFalse((bool) $user->fresh()->is_super);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => ($context['filter'] ?? null) === 'core.user.filter_create_data')->once();
    }

    /**
     * 수정 필터가 `is_super` 를 바꿔도 되돌린다.
     *
     * @scenario surface=update, injection=filter
     *
     * @effects update_injection_reverted
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 수정_주입을_되돌린다(): void
    {
        Auth::forgetGuards();
        $user = $this->makeMember(['email' => 'inj.'.Str::lower(Str::random(8)).'@example.test']);
        HookManager::addFilter('core.user.filter_update_data', fn (array $data) => array_merge($data, ['is_super' => true]));

        app(UserService::class)->updateUser($user, ['name' => '수정']);

        $this->assertFalse((bool) $user->fresh()->is_super);
        $this->assertSame('수정', $user->fresh()->name);
    }

    /**
     * 필터가 건드리지 않으면 요청한 값이 그대로 저장된다 (설치 시더·최고관리자 지정 회귀).
     *
     * @scenario injection=none
     *
     * @effects requested_is_super_kept
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 요청한_값은_그대로다(): void
    {
        Auth::forgetGuards();

        $user = app(UserService::class)->createUser([
            'name' => '최고',
            'email' => 'inj.'.Str::lower(Str::random(8)).'@example.test',
            'password' => 'Passw0rd!new9',
            'is_super' => true,
        ]);

        $this->assertTrue((bool) $user->fresh()->is_super);
    }
}
