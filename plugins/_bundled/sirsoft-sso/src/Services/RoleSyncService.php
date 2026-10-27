<?php

namespace Plugins\Sirsoft\Sso\Services;

use App\Extension\HookManager;
use App\Models\User;
use App\Services\UserService;
use App\Support\Auth\SystemOperation;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Models\SsoAccount;
use Plugins\Sirsoft\Sso\Repositories\Contracts\ScimGroupRepositoryInterface;
use Plugins\Sirsoft\Sso\Repositories\Contracts\SsoRoleGrantRepositoryInterface;

/**
 * IdP 그룹 → G7 역할 매핑.
 *
 * 규칙(`role_mapping`)은 `[{group, role}]` — 그룹 이름 정확 일치(대소문자 구분은 IdP 설정 `group_case_sensitive`, 기본 끔), 역할은 역할
 * identifier. 모드(`role_sync_mode`):
 *  - `managed_only`: 규칙이 원하는 역할을 붙이고, 이 매핑이 붙였던 역할(`sso_role_grants`) 중 더는 원하지 않는 것만 뗀다. 회원이 이미
 *    수동으로 갖고 있던 역할은 기록하지 않으므로 떼지 않는다.
 *  - `authoritative`: 회원 역할 = 규칙 결과 ∪ {`user`} — 수동 역할도 뗀다.
 *  - `first_only`: 처음 만들 때(자동 생성, SCIM 생성)만 붙인다.
 *
 * 반영은 코어 일괄 역할 변경(`UserService::bulkUpdateRoles`)을 시스템 작업 문맥에서 부른다 — 등급 검사로 최고관리자는 걸러지고,
 * 역할 변경 훅과 활동 로그는 그 메서드의 `core.user.before/after_bulk_update_roles` 를 따른다. 최고관리자는 부르기 전에 건너뛴다.
 * 건너뛴 사유(최고관리자, 코어 등급 검사)는 `sirsoft-sso.roles.after_sync` 5번째 인자로 넘겨 활동 로그 `sso.roles_synced` 에 함께 남긴다.
 *
 * @since 1.0.0
 */
class RoleSyncService
{
    /** 역할 동기화 직후 훅 */
    public const ACTION_AFTER_SYNC = 'sirsoft-sso.roles.after_sync';

    /** 건너뛴 사유 — 최고관리자(매핑 대상 아님) */
    public const SKIPPED_SUPER_ADMIN = 'super_admin';

    /** 건너뛴 사유 — 코어 일괄 역할 변경의 등급 검사가 거름 */
    public const SKIPPED_CORE_GUARD = 'core_guard';

    /** 모든 회원이 갖는 기본 역할 */
    public const BASE_ROLE = 'user';

    /** @var array<int, string> 모드 */
    public const MODES = ['managed_only', 'authoritative', 'first_only'];

    /** @var array<int, string> 그룹 출처 */
    public const GROUP_SOURCES = ['assertion', 'scim', 'ldap'];

    /** 처음 만들 때 */
    public const TRIGGER_CREATED = 'created';

    /** 로그인, SCIM 그룹 변경, 주기 점검 */
    public const TRIGGER_UPDATE = 'update';

    /**
     * @param  SsoRoleGrantRepositoryInterface  $grants  매핑 기록과 역할 조회
     * @param  ScimGroupRepositoryInterface  $groups  SCIM 그룹 소속
     * @param  UserService  $users  코어 회원 서비스
     */
    public function __construct(
        private readonly SsoRoleGrantRepositoryInterface $grants,
        private readonly ScimGroupRepositoryInterface $groups,
        private readonly UserService $users,
    ) {}

    /**
     * 회원의 역할을 IdP 그룹에 맞춥니다.
     *
     * @param  User  $user  회원
     * @param  SsoAccount  $account  연결
     * @param  IdentityProvider  $idp  IdP
     * @param  array<int, string>  $assertedGroups  로그인(또는 디렉터리)이 준 그룹 — 그룹 출처가 `scim` 이면 무시하고 SCIM 소속을 쓴다
     * @param  string  $trigger  `created` 또는 `update`
     * @return array{added: array<int, string>, removed: array<int, string>, skipped: string|null} 붙인 역할, 뗀 역할(identifier), 건너뛴 사유(`super_admin`·`core_guard`)
     */
    public function sync(User $user, SsoAccount $account, IdentityProvider $idp, array $assertedGroups, string $trigger): array
    {
        $result = ['added' => [], 'removed' => [], 'skipped' => null];
        $mode = in_array($idp->role_sync_mode, self::MODES, true) ? $idp->role_sync_mode : 'managed_only';
        $rules = self::rules($idp);

        if ($mode === 'first_only' && $trigger !== self::TRIGGER_CREATED) {
            return $result;
        }

        if ($rules === [] && $mode !== 'authoritative') {
            return $result;
        }

        if ($user->is_super === true) {
            // 최고관리자 역할은 매핑으로 바꾸지 않고 활동 로그만 남긴다(W13)
            $result['skipped'] = self::SKIPPED_SUPER_ADMIN;
            HookManager::doAction(self::ACTION_AFTER_SYNC, $user, [], [], $idp, $result['skipped']);

            return $result;
        }

        $groups = $idp->group_source === 'scim' ? $this->groups->groupNamesFor((int) $account->getKey()) : $assertedGroups;
        $wanted = $this->wantedRoles($idp, $rules, $groups);
        if ($mode === 'authoritative') {
            $wanted[] = self::BASE_ROLE;
        }

        $wantedIds = $this->grants->roleIdsByIdentifier(array_values(array_unique($wanted)));
        $current = $this->grants->currentRoleIds((int) $user->getKey());
        $granted = $this->grants->roleIdsFor((int) $user->getKey(), (int) $idp->getKey());

        $add = array_values(array_diff(array_values($wantedIds), $current));
        $remove = $mode === 'authoritative'
            ? array_values(array_diff($current, array_values($wantedIds)))
            : array_values(array_intersect(array_diff($granted, array_values($wantedIds)), $current));

        // 기록 정리: 원하지 않게 된 기록은 지운다(회원이 이미 그 역할을 잃었어도).
        $this->grants->forget((int) $user->getKey(), (int) $idp->getKey(), array_values(array_diff($granted, array_values($wantedIds))));

        $coreSkipped = false;
        $added = $add !== [] ? $this->apply($user, $add, 'add', $coreSkipped) : [];
        $removed = $remove !== [] ? $this->apply($user, $remove, 'remove', $coreSkipped) : [];
        if ($coreSkipped) {
            $result['skipped'] = self::SKIPPED_CORE_GUARD;
        }

        if ($added !== []) {
            $this->grants->record((int) $user->getKey(), (int) $idp->getKey(), $added);
        }
        if ($removed !== []) {
            $this->grants->forget((int) $user->getKey(), (int) $idp->getKey(), $removed);
        }

        $result['added'] = $this->grants->identifiersOf($added);
        $result['removed'] = $this->grants->identifiersOf($removed);

        // 건너뛴 것(코어 등급 검사가 거른 변경)도 같은 활동 로그에 함께 남긴다(§6.13)
        if ($result['added'] !== [] || $result['removed'] !== [] || $result['skipped'] !== null) {
            HookManager::doAction(self::ACTION_AFTER_SYNC, $user, $result['added'], $result['removed'], $idp, $result['skipped']);
        }

        return $result;
    }

    /**
     * IdP 의 매핑 규칙을 정규화합니다 (그룹, 역할이 빈 행은 버린다).
     *
     * @param  IdentityProvider  $idp  IdP
     * @return array<int, array{group: string, role: string}> 규칙
     */
    public static function rules(IdentityProvider $idp): array
    {
        return self::normalizeRules($idp->role_mapping);
    }

    /**
     * 매핑 규칙 값을 정규화합니다 (저장 전 값 검사에도 쓴다).
     *
     * @param  mixed  $raw  `role_mapping` 값
     * @return array<int, array{group: string, role: string}> 규칙
     */
    public static function normalizeRules(mixed $raw): array
    {
        $rules = [];
        foreach (is_array($raw) ? $raw : [] as $rule) {
            $group = is_array($rule) ? trim((string) ($rule['group'] ?? '')) : '';
            $role = is_array($rule) ? trim((string) ($rule['role'] ?? '')) : '';
            if ($group !== '' && $role !== '') {
                $rules[] = ['group' => $group, 'role' => $role];
            }
        }

        return $rules;
    }

    /**
     * 그룹 값을 비교용으로 바꿉니다 — LDAP DN(`CN=인사팀,OU=…`)이면 CN 만(설정 `group_dn_cn`, 기본 켬), 대소문자 무시 설정이면 소문자.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  string  $group  그룹 값
     * @return string 비교 값
     */
    public static function normalizeGroup(IdentityProvider $idp, string $group): string
    {
        $group = trim($group);

        if ((bool) $idp->configValue('group_dn_cn', true) && preg_match('/^\s*cn=((?:\\\\,|[^,])+)\s*,/i', $group, $m) === 1) {
            $group = trim(str_replace('\\,', ',', $m[1]));
        }

        return (bool) $idp->configValue('group_case_sensitive', false) ? $group : mb_strtolower($group);
    }

    /**
     * 규칙이 원하는 역할 identifier 를 구합니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  array<int, array{group: string, role: string}>  $rules  규칙
     * @param  array<int, string>  $groups  회원의 그룹
     * @return array<int, string> 역할 identifier
     */
    private function wantedRoles(IdentityProvider $idp, array $rules, array $groups): array
    {
        $have = array_flip(array_map(static fn ($g) => self::normalizeGroup($idp, (string) $g), $groups));
        $wanted = [];

        foreach ($rules as $rule) {
            if (isset($have[self::normalizeGroup($idp, $rule['group'])])) {
                $wanted[] = $rule['role'];
            }
        }

        return $wanted;
    }

    /**
     * 코어 일괄 역할 변경으로 반영하고 실제로 바뀐 역할 ID 를 돌려줍니다.
     *
     * @param  User  $user  회원
     * @param  array<int, int>  $roleIds  역할 ID
     * @param  string  $mode  add, remove
     * @param  bool  $skipped  코어가 이 회원을 건너뛰었으면 true 로 바뀐다(이미 true 면 그대로)
     * @return array<int, int> 반영한 역할 ID (건너뛰면 빈 배열)
     */
    private function apply(User $user, array $roleIds, string $mode, bool &$skipped): array
    {
        $before = $this->grants->currentRoleIds((int) $user->getKey());

        $outcome = SystemOperation::run('external:sirsoft-sso:role-sync', fn () => $this->users->bulkUpdateRoles([(string) $user->uuid], $roleIds, $mode));

        if (($outcome['skipped_count'] ?? 0) > 0) {
            Log::info('[sirsoft-sso] 역할 동기화를 코어가 건너뛰었습니다', ['user_id' => $user->getKey(), 'mode' => $mode, 'skipped' => $outcome['skipped'] ?? []]);
            $skipped = true;
        }

        $after = $this->grants->currentRoleIds((int) $user->getKey());
        $user->unsetRelation('roles');

        return $mode === 'add'
            ? array_values(array_intersect($roleIds, array_diff($after, $before)))
            : array_values(array_intersect($roleIds, array_diff($before, $after)));
    }
}
