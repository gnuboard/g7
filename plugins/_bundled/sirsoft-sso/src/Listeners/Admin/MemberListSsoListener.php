<?php

namespace Plugins\Sirsoft\Sso\Listeners\Admin;

use App\Contracts\Extension\HookListenerInterface;
use App\Helpers\PermissionHelper;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Plugins\Sirsoft\Sso\Repositories\Policy\Contracts\AdminAccountRepositoryInterface;

/**
 * 관리자 회원 목록의 「SSO IdP」 필터와 「부서」, 「직급」 열 데이터 (§6.15 회원 목록 주입, A1 A6t 결과).
 *
 * - 필터: 코어 확장 필터 통로 — 화면이 `ext_filters[]=sirsoft-sso.idp={IdP 키}` 를 보내면 코어 목록 요청이
 *   `ext.sirsoft-sso.idp` 로 펼친다. 이 리스너가 그 키의 검증 규칙, 항목명, 쿼리 조건(그 IdP 와 연결된 회원)을 더한다.
 * - 열: 코어 목록 일괄 필터 `core.user.filter_list_rows` 에서 페이지의 회원 id 로 추가 속성을 **한 번** 조회해 각 행에
 *   `sso_department`, `sso_title` 을 더한다(행마다 조회하지 않는다).
 * 회원 연결 열람 권한이 없으면 조건도 열 값도 더하지 않는다.
 *
 * @since 1.0.0
 */
class MemberListSsoListener implements HookListenerInterface
{
    /** 확장 필터 키 접두 (`ext.{확장id}`) */
    private const EXT = 'sirsoft-sso';

    /** 열로 보이는 추가 속성 키 → 행 키 */
    public const COLUMNS = ['department' => 'sso_department', 'title' => 'sso_title'];

    /**
     * @param  AdminAccountRepositoryInterface  $accounts  연결, 속성 조회
     */
    public function __construct(private readonly AdminAccountRepositoryInterface $accounts) {}

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
            'core.user.filter_list_rows' => ['method' => 'addColumns', 'type' => 'filter', 'priority' => 30, 'sync' => true],
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
     * IdP 키의 검증 규칙을 더합니다.
     *
     * @param  array<string, mixed>  $rules  코어 규칙
     * @return array<string, mixed> 규칙
     */
    public function addValidationRules(array $rules): array
    {
        $rules['ext.'.self::EXT.'.idp'] = ['nullable', 'string', 'regex:/^[a-z][a-z0-9_]{1,49}$/'];
        $rules['ext.'.self::EXT.'.unlinked'] = ['nullable', 'boolean'];

        return $rules;
    }

    /**
     * IdP 키의 오류 문구 항목명을 더합니다.
     *
     * @param  array<string, string>  $attributes  코어 항목명
     * @return array<string, string> 항목명
     */
    public function addValidationAttributes(array $attributes): array
    {
        $attributes['ext.'.self::EXT.'.idp'] = __('sirsoft-sso::admin.attributes.user_list_idp');
        $attributes['ext.'.self::EXT.'.unlinked'] = __('sirsoft-sso::admin.attributes.user_list_unlinked');

        return $attributes;
    }

    /**
     * 「그 IdP 와 연결된 회원」 또는 「회사 계정 연결이 없는 회원」(`unlinked`) 조건 클로저를 더합니다.
     * 둘이 함께 오면 IdP 조건이 이긴다(화면은 한 선택지만 보낸다).
     *
     * @param  array<int, callable>  $closures  이미 모인 클로저
     * @param  array<string, mixed>  $filters  검증된 목록 조건
     * @return array<int, callable> 클로저 목록
     */
    public function addQueryExtension(array $closures, array $filters = []): array
    {
        if (! PermissionHelper::check(MemberDetailSsoListener::PERMISSION)) {
            return $closures;
        }

        $idp = $filters['ext'][self::EXT]['idp'] ?? null;
        if (! is_string($idp) || $idp === '') {
            if (filter_var($filters['ext'][self::EXT]['unlinked'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $closures[] = function (Builder $query) {
                    $query->whereNotIn('id', $this->accounts->userIdsWithAnyLinkQuery());
                };
            }

            return $closures;
        }

        $closures[] = function (Builder $query) use ($idp) {
            $query->whereIn('id', $this->accounts->userIdsLinkedToIdpQuery($idp));
        };

        return $closures;
    }

    /**
     * 목록 행에 부서, 직급을 더합니다.
     *
     * @param  array<int, array<string, mixed>>  $rows  목록 행 (회원 묶음과 같은 순서)
     * @param  Collection<int, User>|null  $users  페이지의 회원 모델
     * @return array<int, array<string, mixed>> 행
     */
    public function addColumns(array $rows, ?Collection $users = null): array
    {
        if ($users === null || $users->isEmpty() || ! PermissionHelper::check(MemberDetailSsoListener::PERMISSION)) {
            return $rows;
        }

        $ids = $users->map(fn ($user) => (int) $user->getKey())->values()->all();
        $map = $this->accounts->attributesForUsers($ids, array_keys(self::COLUMNS));
        $keys = array_keys($rows);

        foreach (array_values($rows) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $values = $map[$ids[$index] ?? 0] ?? [];
            foreach (self::COLUMNS as $attribute => $column) {
                if (! array_key_exists($column, $row)) {
                    $rows[$keys[$index]][$column] = $values[$attribute] ?? null;
                }
            }
        }

        return $rows;
    }
}
