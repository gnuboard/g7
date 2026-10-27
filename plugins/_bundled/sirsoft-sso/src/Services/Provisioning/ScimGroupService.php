<?php

namespace Plugins\Sirsoft\Sso\Services\Provisioning;

use App\Models\User;
use Plugins\Sirsoft\Sso\Exceptions\ScimException;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Models\SsoScimGroup;
use Plugins\Sirsoft\Sso\Repositories\Contracts\ProvisioningAccountRepositoryInterface;
use Plugins\Sirsoft\Sso\Repositories\Contracts\ScimGroupRepositoryInterface;
use Plugins\Sirsoft\Sso\Services\RoleSyncService;

/**
 * SCIM 2.0 Groups — IdP 그룹과 소속을 받아 역할 매핑의 입력으로 둡니다.
 *
 * G7 역할을 만들거나 지우지 않는다. 소속이 바뀐 회원은 그 IdP 의 그룹 출처가 `scim` 일 때 역할 매핑을 다시 돌린다. 모르는 회원 id
 * (이 IdP 의 연결이 아닌 값)는 무시한다 — IdP 가 사용자보다 그룹을 먼저 보내는 순서도 있다.
 *
 * @since 1.0.0
 */
class ScimGroupService
{
    /** @var array<int, string> 목록 필터로 받는 속성 */
    public const FILTER_ATTRIBUTES = ['displayname', 'externalid'];

    /**
     * @param  ScimGroupRepositoryInterface  $groups  그룹 저장소
     * @param  ProvisioningAccountRepositoryInterface  $accounts  연결 조회
     * @param  RoleSyncService  $roles  역할 매핑
     */
    public function __construct(
        private readonly ScimGroupRepositoryInterface $groups,
        private readonly ProvisioningAccountRepositoryInterface $accounts,
        private readonly RoleSyncService $roles,
    ) {}

    /**
     * 목록을 반환합니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  string|null  $filter  SCIM 필터
     * @param  mixed  $startIndex  시작 위치
     * @param  mixed  $count  건수
     * @param  bool  $withMembers  소속을 함께 낼지 (`excludedAttributes=members` 면 false)
     * @return array<string, mixed> 목록 응답
     */
    public function list(IdentityProvider $idp, ?string $filter, mixed $startIndex, mixed $count, bool $withMembers = true): array
    {
        $parsed = ScimFilter::parse($filter, self::FILTER_ATTRIBUTES);
        $page = ScimSchema::page($startIndex, $count);
        $column = match ($parsed['attribute'] ?? null) {
            'displayname' => 'display_name',
            'externalid' => 'external_id',
            default => null,
        };

        $result = $this->groups->pageInIdp(
            (int) $idp->getKey(),
            $column !== null ? ['column' => $column, 'value' => $parsed['value']] : null,
            $page['start'] - 1,
            $page['count'],
        );

        $resources = $result['items']->map(fn (SsoScimGroup $g) => $this->resource($idp, $g, $withMembers))->values()->all();

        return ScimSchema::listResponse($resources, $result['total'], $page['start']);
    }

    /**
     * 그룹 하나를 반환합니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  string  $id  그룹 id
     * @param  bool  $withMembers  소속을 함께 낼지
     * @return array<string, mixed> 자원
     */
    public function get(IdentityProvider $idp, string $id, bool $withMembers = true): array
    {
        return $this->resource($idp, $this->find($idp, $id), $withMembers);
    }

    /**
     * 그룹을 만듭니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  array<string, mixed>  $payload  SCIM Group 자원
     * @return array<string, mixed> 만든 자원
     *
     * @throws ScimException 이름 없음 400, 같은 이름 409
     */
    public function create(IdentityProvider $idp, array $payload): array
    {
        $name = self::displayName($payload['displayName'] ?? null);
        if ($name === null) {
            throw new ScimException(400, 'invalidValue', __('sirsoft-sso::scim.error.display_name_required'));
        }
        if ($this->groups->findByName((int) $idp->getKey(), $name) !== null) {
            throw new ScimException(409, 'uniqueness', __('sirsoft-sso::scim.error.already_exists'));
        }

        $group = $this->groups->create([
            'idp_id' => (int) $idp->getKey(),
            'display_name' => $name,
            'external_id' => self::externalId($payload['externalId'] ?? null),
        ]);

        $this->replaceMembers($idp, $group, $payload['members'] ?? []);

        return $this->resource($idp, $group->refresh(), true);
    }

    /**
     * 그룹을 통째로 바꿉니다 (PUT — 이름, externalId, 소속).
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  string  $id  그룹 id
     * @param  array<string, mixed>  $payload  SCIM Group 자원
     * @return array<string, mixed> 바뀐 자원
     */
    public function replace(IdentityProvider $idp, string $id, array $payload): array
    {
        $group = $this->find($idp, $id);
        $this->rename($idp, $group, $payload['displayName'] ?? null);
        if (array_key_exists('externalId', $payload)) {
            $this->groups->update($group, ['external_id' => self::externalId($payload['externalId'])]);
        }
        $this->replaceMembers($idp, $group, $payload['members'] ?? []);

        return $this->resource($idp, $group->refresh(), true);
    }

    /**
     * 부분 수정합니다 (PATCH — 소속 더하기, 빼기, 바꾸기와 이름 바꾸기).
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  string  $id  그룹 id
     * @param  array<int, mixed>  $operations  PatchOp Operations
     * @return array<string, mixed> 바뀐 자원
     *
     * @throws ScimException 연산 모양이 틀리면 400
     */
    public function patch(IdentityProvider $idp, string $id, array $operations): array
    {
        $group = $this->find($idp, $id);
        $add = [];
        $remove = [];
        $replace = null;

        foreach ($operations as $operation) {
            $op = is_array($operation) ? mb_strtolower((string) ($operation['op'] ?? '')) : '';
            if (! in_array($op, ['add', 'replace', 'remove'], true)) {
                throw new ScimException(400, 'invalidSyntax', __('sirsoft-sso::scim.error.invalid_patch'));
            }

            $path = is_string($operation['path'] ?? null) ? trim($operation['path']) : '';
            $value = $operation['value'] ?? null;
            $lower = mb_strtolower($path);

            if ($path === '' && is_array($value) && ! array_is_list($value)) {
                if (array_key_exists('displayName', $value)) {
                    $this->rename($idp, $group, $value['displayName']);
                }
                if (array_key_exists('externalId', $value)) {
                    $this->groups->update($group, ['external_id' => self::externalId($value['externalId'])]);
                }
                if (array_key_exists('members', $value) && $op === 'replace') {
                    $replace = $this->memberIds($idp, $value['members']);
                } elseif (array_key_exists('members', $value)) {
                    $add = array_merge($add, $this->memberIds($idp, $value['members']));
                }

                continue;
            }

            if ($lower === 'displayname') {
                $this->rename($idp, $group, $value);
            } elseif ($lower === 'externalid') {
                $this->groups->update($group, ['external_id' => $op === 'remove' ? null : self::externalId($value)]);
            } elseif ($lower === 'members') {
                if ($op === 'add') {
                    $add = array_merge($add, $this->memberIds($idp, $value));
                } elseif ($op === 'replace' || $value === null) {
                    $replace = $op === 'replace' ? $this->memberIds($idp, $value) : [];
                } else {
                    $remove = array_merge($remove, $this->memberIds($idp, $value));
                }
            } elseif (preg_match('/^members\[\s*value\s+eq\s+"([^"]+)"\s*\]$/i', $path, $m) === 1 && $op === 'remove') {
                $remove = array_merge($remove, $this->memberIds($idp, [['value' => $m[1]]]));
            } else {
                throw new ScimException(400, 'invalidPath', __('sirsoft-sso::scim.error.invalid_patch'));
            }
        }

        $before = $this->groups->memberIds($group);
        if ($replace !== null) {
            $this->groups->changeMembers($group, array_values(array_diff($replace, $before)), array_values(array_diff($before, $replace)));
        }
        if ($add !== [] || $remove !== []) {
            $this->groups->changeMembers($group, $add, $remove);
        }

        $this->resyncRoles($idp, array_values(array_unique(array_merge($before, $this->groups->memberIds($group)))));

        return $this->resource($idp, $group->refresh(), true);
    }

    /**
     * 그룹을 지웁니다 — 옛 소속 회원의 역할 매핑을 다시 돌린다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  string  $id  그룹 id
     * @return void
     */
    public function delete(IdentityProvider $idp, string $id): void
    {
        $group = $this->find($idp, $id);
        $members = $this->groups->memberIds($group);
        $this->groups->delete($group);
        $this->resyncRoles($idp, $members);
    }

    /**
     * 그룹을 찾습니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  string  $id  그룹 id
     * @return SsoScimGroup 그룹
     *
     * @throws ScimException 없으면 404
     */
    private function find(IdentityProvider $idp, string $id): SsoScimGroup
    {
        $group = ctype_digit($id) ? $this->groups->findInIdp((int) $idp->getKey(), (int) $id) : null;
        if ($group === null) {
            throw new ScimException(404, null, __('sirsoft-sso::scim.error.not_found'));
        }

        return $group;
    }

    /**
     * 이름을 바꿉니다 (같은 IdP 의 다른 그룹 이름과 겹치면 409).
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  SsoScimGroup  $group  그룹
     * @param  mixed  $value  새 이름
     * @return void
     *
     * @throws ScimException 겹치면 409
     */
    private function rename(IdentityProvider $idp, SsoScimGroup $group, mixed $value): void
    {
        $name = self::displayName($value);
        if ($name === null || $name === $group->display_name) {
            return;
        }

        $other = $this->groups->findByName((int) $idp->getKey(), $name);
        if ($other !== null && (int) $other->getKey() !== (int) $group->getKey()) {
            throw new ScimException(409, 'uniqueness', __('sirsoft-sso::scim.error.already_exists'));
        }

        $members = $this->groups->memberIds($group);
        $this->groups->update($group, ['display_name' => $name]);
        $this->resyncRoles($idp, $members);
    }

    /**
     * 소속을 통째로 바꿉니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  SsoScimGroup  $group  그룹
     * @param  mixed  $members  SCIM members 값
     * @return void
     */
    private function replaceMembers(IdentityProvider $idp, SsoScimGroup $group, mixed $members): void
    {
        $wanted = $this->memberIds($idp, $members);
        $before = $this->groups->memberIds($group);
        $this->groups->changeMembers($group, array_values(array_diff($wanted, $before)), array_values(array_diff($before, $wanted)));
        $this->resyncRoles($idp, array_values(array_unique(array_merge($before, $wanted))));
    }

    /**
     * SCIM members 값에서 이 IdP 연결 id 만 고릅니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  mixed  $members  `[{value: "12"}, …]` (단일 객체도 받는다)
     * @return array<int, int> 연결 id
     */
    private function memberIds(IdentityProvider $idp, mixed $members): array
    {
        if (! is_array($members)) {
            return [];
        }
        $members = array_is_list($members) ? $members : [$members];

        $ids = [];
        foreach ($members as $member) {
            $value = is_array($member) ? ($member['value'] ?? null) : null;
            if (is_scalar($value) && ctype_digit((string) $value)
                && $this->accounts->findInIdp((int) $idp->getKey(), (int) $value) !== null) {
                $ids[] = (int) $value;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * 소속이 바뀐 회원의 역할 매핑을 다시 돌립니다 (그룹 출처가 `scim` 인 IdP 만).
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  array<int, int>  $accountIds  연결 id
     * @return void
     */
    private function resyncRoles(IdentityProvider $idp, array $accountIds): void
    {
        if ($idp->group_source !== 'scim') {
            return;
        }

        foreach ($accountIds as $accountId) {
            $account = $this->accounts->findInIdp((int) $idp->getKey(), $accountId);
            if ($account === null || ! $account->active || ! $account->user instanceof User) {
                continue;
            }

            try {
                $this->roles->sync($account->user, $account, $idp, [], RoleSyncService::TRIGGER_UPDATE);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * 그룹을 SCIM Group 자원으로 바꿉니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  SsoScimGroup  $group  그룹
     * @param  bool  $withMembers  소속을 낼지
     * @return array<string, mixed> 자원
     */
    private function resource(IdentityProvider $idp, SsoScimGroup $group, bool $withMembers): array
    {
        $resource = [
            'schemas' => [ScimSchema::GROUP],
            'id' => (string) $group->getKey(),
            'displayName' => $group->display_name,
            'meta' => [
                'resourceType' => 'Group',
                'created' => $group->created_at?->toIso8601String(),
                'lastModified' => $group->updated_at?->toIso8601String(),
                'location' => ScimSchema::baseUrl($idp).'/Groups/'.$group->getKey(),
            ],
        ];

        if ($group->external_id !== null) {
            $resource['externalId'] = $group->external_id;
        }

        if ($withMembers) {
            $resource['members'] = array_map(static fn (int $id) => [
                'value' => (string) $id,
                '$ref' => ScimSchema::baseUrl($idp).'/Users/'.$id,
            ], $this->groups->memberIds($group));
        }

        return $resource;
    }

    /**
     * 그룹 이름을 다듬습니다.
     *
     * @param  mixed  $value  값
     * @return string|null 이름 (빈 값이면 null)
     */
    private static function displayName(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : mb_substr($value, 0, 255);
    }

    /**
     * externalId 를 다듬습니다.
     *
     * @param  mixed  $value  값
     * @return string|null externalId
     */
    private static function externalId(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : mb_substr($value, 0, 191);
    }
}
