<?php

namespace Plugins\Sirsoft\SocialLogin\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Helpers\PermissionHelper;
use Illuminate\Database\Eloquent\Builder;
use Plugins\Sirsoft\SocialLogin\Repositories\Contracts\SocialAccountRepositoryInterface;

/**
 * 관리자 회원 목록의 「가입경로(소셜 연결)」 확장 필터.
 *
 * 코어 확장 필터 통로를 쓴다 — 화면은 `ext_filters[]=sirsoft-social_login.provider={제공자 키}` 를 보내고, 코어 목록 요청이
 * 그것을 `ext.sirsoft-social_login.provider` 로 펼친다. 이 리스너는 그 키의 검증 규칙·항목명·쿼리 조건(그 제공자 연결이 있는
 * 회원)을 더한다. 회원 연결 열람 권한(`sirsoft-social_login.accounts.view`)이 없으면 조건을 걸지 않는다.
 *
 * @since 1.0.0
 */
class UserListSocialProviderFilter implements HookListenerInterface
{
    /** 확장 필터 키 접두 (`ext.{확장id}`) */
    private const EXT = 'sirsoft-social_login';

    /** 조건 적용에 필요한 권한 */
    public const PERMISSION = 'sirsoft-social_login.accounts.view';

    /**
     * @param  SocialAccountRepositoryInterface  $accounts  연결 저장소 (조건 서브쿼리)
     */
    public function __construct(private readonly SocialAccountRepositoryInterface $accounts) {}

    /**
     * 구독할 훅과 메서드 매핑을 반환합니다.
     *
     * @return array<string, array<string, mixed>> 훅 => 옵션
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.user.list_validation_rules' => ['method' => 'addValidationRules', 'type' => 'filter', 'priority' => 30, 'sync' => true],
            'core.user.list_validation_attributes' => ['method' => 'addValidationAttributes', 'type' => 'filter', 'priority' => 30, 'sync' => true],
            'core.user.list_query_extensions' => ['method' => 'addQueryExtension', 'type' => 'filter', 'priority' => 30, 'sync' => true],
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
     * 제공자 키의 검증 규칙을 더합니다.
     *
     * @param  array<string, mixed>  $rules  코어 규칙
     * @return array<string, mixed> 규칙
     */
    public function addValidationRules(array $rules): array
    {
        $rules['ext.'.self::EXT.'.provider'] = ['nullable', 'string', 'max:50'];

        return $rules;
    }

    /**
     * 제공자 키의 오류 문구 항목명을 더합니다.
     *
     * @param  array<string, string>  $attributes  코어 항목명
     * @return array<string, string> 항목명
     */
    public function addValidationAttributes(array $attributes): array
    {
        $attributes['ext.'.self::EXT.'.provider'] = __('sirsoft-social_login::validation.attributes.user_list_provider');

        return $attributes;
    }

    /**
     * 「그 제공자 연결이 있는 회원」 조건 클로저를 더합니다.
     *
     * @param  array<int, callable>  $closures  이미 모인 클로저
     * @param  array<string, mixed>  $filters  검증된 목록 조건
     * @return array<int, callable> 클로저 목록
     */
    public function addQueryExtension(array $closures, array $filters = []): array
    {
        $provider = $filters['ext'][self::EXT]['provider'] ?? null;
        if (! is_string($provider) || $provider === '' || ! PermissionHelper::check(self::PERMISSION)) {
            return $closures;
        }

        $closures[] = function (Builder $query) use ($provider) {
            $query->whereIn('id', $this->accounts->userIdsLinkedToProviderQuery($provider));
        };

        return $closures;
    }
}
