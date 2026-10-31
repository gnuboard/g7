<?php

namespace Plugins\Sirsoft\SocialLogin\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Helpers\PermissionHelper;
use App\Models\User;
use Illuminate\Support\Collection;
use Plugins\Sirsoft\SocialLogin\Repositories\Contracts\SocialAccountRepositoryInterface;
use Plugins\Sirsoft\SocialLogin\Services\ProviderSettings;

/**
 * 관리자 회원 목록 「소셜 연결」 열 데이터.
 *
 * 코어 목록 일괄 필터 `core.user.filter_list_rows` 에서 페이지의 회원 id 로 연결을 **한 번** 조회해 각 행에
 * `social_providers: [{key, label, brand}]` 를 더한다(행마다 조회하지 않는다). 행에 같은 키가 이미 있으면 덮어쓰지 않는다.
 * 회원 연결 열람 권한이 없으면 행을 그대로 돌려준다.
 *
 * @since 1.0.0
 */
class UserListSocialProvidersColumn implements HookListenerInterface
{
    /** 행에 더하는 키 */
    public const ROW_KEY = 'social_providers';

    /**
     * @param  SocialAccountRepositoryInterface  $accounts  연결 저장소
     * @param  ProviderSettings  $settings  제공자 표시 이름·계열
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
            'core.user.filter_list_rows' => ['method' => 'addColumn', 'type' => 'filter', 'priority' => 30, 'sync' => true],
        ];
    }

    /**
     * 인터페이스 기본 핸들러 (개별 메서드에서 처리).
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 목록 행에 연결 제공자 목록을 더합니다.
     *
     * @param  array<int, array<string, mixed>>  $rows  목록 행 (회원 묶음과 같은 순서)
     * @param  Collection<int, User>|null  $users  페이지의 회원 모델
     * @return array<int, array<string, mixed>> 행
     */
    public function addColumn(array $rows, ?Collection $users = null): array
    {
        if ($users === null || $users->isEmpty() || ! PermissionHelper::check(UserListSocialProviderFilter::PERMISSION)) {
            return $rows;
        }

        $ids = $users->map(fn ($user) => (int) $user->getKey())->values()->all();
        $map = $this->accounts->providersForUsers($ids);

        foreach (array_values($rows) as $index => $row) {
            if (! is_array($row) || array_key_exists(self::ROW_KEY, $row)) {
                continue;
            }

            $keys = $map[$ids[$index] ?? 0] ?? [];
            $rows[array_keys($rows)[$index]][self::ROW_KEY] = array_map(fn (string $key) => [
                'key' => $key,
                'label' => $this->settings->label($key),
                'brand' => $this->settings->brand($key),
            ], $keys);
        }

        return $rows;
    }
}
