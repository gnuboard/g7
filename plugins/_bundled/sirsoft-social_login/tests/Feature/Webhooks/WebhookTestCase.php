<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Feature\Webhooks;

use App\Models\User;
use Plugins\Sirsoft\SocialLogin\Database\Seeders\ProviderSeeder;
use Plugins\Sirsoft\SocialLogin\Models\SocialAccount;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginProvider;
use Plugins\Sirsoft\SocialLogin\Services\ProviderRegistry;
use Plugins\Sirsoft\SocialLogin\Tests\PluginTestCase;

/**
 * 웹훅 시험 공통 — 제공자 행 준비, 연결 회원 만들기, 연결 끊기 안내 훅 수집.
 *
 * @since 1.0.0
 */
abstract class WebhookTestCase extends PluginTestCase
{
    /**
     * 내장 제공자를 시딩하고 한 제공자의 자격증명·추가 설정을 넣습니다.
     *
     * @param  string  $key  제공자 키
     * @param  array<string, mixed>  $values  컬럼 값
     * @return void 반환값 없음
     */
    protected function configureProvider(string $key, array $values): void
    {
        (new ProviderSeeder)->run();
        SocialLoginProvider::query()->where('key', $key)->firstOrFail()->forceFill(['enabled' => true] + $values)->save();
        app(ProviderRegistry::class)->flush();
    }

    /**
     * 제공자 연결이 있는 회원을 만듭니다.
     *
     * @param  string  $provider  제공자 키
     * @param  string  $uid  제공자 사용자 ID
     * @param  bool  $passwordless  비밀번호 없는 회원인지
     * @return User 회원
     */
    protected function linkedMember(string $provider, string $uid, bool $passwordless = true): User
    {
        $factory = User::factory();
        $user = ($passwordless ? $factory->passwordless() : $factory)->create(['email' => $this->uniqueEmail('wh')]);
        SocialAccount::query()->create(['user_id' => $user->id, 'provider_key' => $provider, 'provider_user_id' => $uid, 'linked_at' => now()]);

        return $user;
    }
}
