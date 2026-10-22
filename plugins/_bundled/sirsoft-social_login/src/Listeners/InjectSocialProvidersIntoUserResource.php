<?php

namespace Plugins\Sirsoft\SocialLogin\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;
use Plugins\Sirsoft\SocialLogin\Repositories\Contracts\SocialAccountRepositoryInterface;
use Plugins\Sirsoft\SocialLogin\Services\ProviderSettings;

/**
 * 회원 응답(내 정보·프로필·관리자 상세)에 연결된 제공자 목록 `social_providers` 를 더합니다.
 *
 * 응답 1건당 쿼리 1번이다. 회원 목록은 이 필터를 타지 않는다(목록 열은 목록 전용 일괄 필터가 맡는다). 제공자 사용자 ID·
 * 토큰은 싣지 않는다.
 *
 * @since 1.0.0
 */
class InjectSocialProvidersIntoUserResource implements HookListenerInterface
{
    /**
     * @param  SocialAccountRepositoryInterface  $accounts  연결 저장소
     * @param  ProviderSettings  $settings  제공자 정책
     */
    public function __construct(
        private readonly SocialAccountRepositoryInterface $accounts,
        private readonly ProviderSettings $settings,
    ) {}

    /**
     * 구독할 훅과 메서드 매핑을 반환합니다.
     *
     * @return array<string, array<string, mixed>> 훅 => 옵션
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.user.filter_resource_data' => ['method' => 'inject', 'priority' => 60, 'type' => 'filter', 'sync' => true],
        ];
    }

    /**
     * 인터페이스 기본 핸들러 (개별 메서드에서 처리).
     *
     * @param  mixed  ...$args  훅 인자
     * @return void
     */
    public function handle(...$args): void {}

    /**
     * 연결된 제공자 목록을 더합니다 (이미 있는 키는 덮어쓰지 않는다).
     *
     * @param  array<string, mixed>  $data  회원 응답
     * @param  User|null  $user  회원
     * @return array<string, mixed> 더한 응답
     */
    public function inject(array $data, ?User $user = null): array
    {
        if (! $user instanceof User || array_key_exists('social_providers', $data)) {
            return $data;
        }

        $keys = $this->accounts->providersForUsers([(int) $user->getKey()])[(int) $user->getKey()] ?? [];

        $data['social_providers'] = array_map(fn (string $key) => [
            'key' => $key,
            'label' => $this->settings->label($key),
            'brand' => $this->settings->brand($key),
        ], $keys);

        return $data;
    }
}
