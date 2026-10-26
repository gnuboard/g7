<?php

namespace Plugins\Sirsoft\Sso\Repositories\Contracts;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Plugins\Sirsoft\Sso\Models\SsoAccount;

/**
 * 프로비저닝(SCIM, 주기 점검, 퇴사)이 쓰는 회원 연결 조회 계약.
 *
 * 로그인 흐름의 연결 조회는 `SsoAccountRepositoryInterface` 가 맡는다 — 이 계약은 IdP 단위 목록, 퇴사 대상 고르기처럼 프로비저닝에만
 * 필요한 조회를 둔다.
 *
 * @since 1.0.0
 */
interface ProvisioningAccountRepositoryInterface
{
    /**
     * IdP 안의 연결을 ID 로 찾습니다 (SCIM 자원 id).
     *
     * @param  int  $idpId  IdP ID
     * @param  int  $id  연결 ID
     * @return SsoAccount|null 연결
     */
    public function findInIdp(int $idpId, int $id): ?SsoAccount;

    /**
     * IdP 안의 연결을 대조 키로 찾습니다.
     *
     * @param  int  $idpId  IdP ID
     * @param  string  $column  `subject` 또는 `scim_external_id`
     * @param  string  $value  값 (대소문자 구분)
     * @return SsoAccount|null 연결
     */
    public function findInIdpBy(int $idpId, string $column, string $value): ?SsoAccount;

    /**
     * IdP 안의 연결 목록을 한 쪽 반환합니다 (SCIM 목록 — 회원을 함께 읽는다).
     *
     * @param  int  $idpId  IdP ID
     * @param  array{column: string, value: string}|null  $filter  같음 조건 (`subject`, `scim_external_id`, `email`, `attr:{키}` — 그 IdP 의 추가 속성 값)
     * @param  int  $offset  건너뛸 행 수
     * @param  int  $limit  최대 행 수
     * @return array{total: int, items: Collection<int, SsoAccount>} 전체 수와 행
     */
    public function pageInIdp(int $idpId, ?array $filter, int $offset, int $limit): array;

    /**
     * 회원에게 이 연결 말고 다른 활성 연결이 있는지 확인합니다 (겸직 판정).
     *
     * @param  int  $userId  회원 ID
     * @param  int  $exceptAccountId  뺄 연결 ID
     * @return bool 있으면 true
     */
    public function hasOtherActive(int $userId, int $exceptAccountId): bool;

    /**
     * IdP 에 연결된 회원 중 관리자(관리자 권한 역할 보유) 또는 최고관리자가 있는지 확인합니다.
     *
     * @param  int  $idpId  IdP ID
     * @return bool 있으면 true
     */
    public function hasPrivilegedLinkedUser(int $idpId): bool;

    /**
     * 회원의 활성 연결을 IdP 와 함께 반환합니다.
     *
     * @param  int  $userId  회원 ID
     * @return Collection<int, SsoAccount> 연결
     */
    public function activeForUser(int $userId): Collection;

    /**
     * 주기 점검할 연결을 고릅니다 — 활성이거나 주기 점검이 끈(다시 켤 수 있는) 연결 중 마지막 점검이 기준 시각보다 앞선(또는 없는)
     * 연결을 오래된 순으로.
     *
     * @param  int  $idpId  IdP ID
     * @param  Carbon  $checkedBefore  이 시각 전에 점검한 연결만
     * @param  int  $limit  최대 행 수
     * @return Collection<int, SsoAccount> 연결
     */
    public function dueForPeriodicCheck(int $idpId, Carbon $checkedBefore, int $limit): Collection;

    /**
     * 퇴사 처리 시각이 기준보다 앞서고 여전히 비활성인 연결을 고릅니다 (N일 뒤 탈퇴).
     *
     * @param  int  $idpId  IdP ID
     * @param  Carbon  $deprovisionedBefore  기준 시각
     * @param  int  $limit  최대 행 수
     * @return Collection<int, SsoAccount> 연결
     */
    public function deprovisionedBefore(int $idpId, Carbon $deprovisionedBefore, int $limit): Collection;

    /**
     * 연결을 고칩니다 (fillable 밖의 칸도 — `periodic_checked_at`).
     *
     * @param  SsoAccount  $account  연결
     * @param  array<string, mixed>  $attributes  바꿀 속성
     * @return SsoAccount 고친 연결
     */
    public function update(SsoAccount $account, array $attributes): SsoAccount;

    /**
     * 연결을 지웁니다 (SCIM 그룹 소속은 호출자가 먼저 지운다).
     *
     * @param  SsoAccount  $account  연결
     * @return void
     */
    public function delete(SsoAccount $account): void;

    /**
     * IdP 의 마지막 주기 점검 시각을 남깁니다 (진단 화면 「마지막 점검 시각」).
     *
     * @param  int  $idpId  IdP ID
     * @param  Carbon  $at  시각
     * @return void
     */
    public function markIdpChecked(int $idpId, Carbon $at): void;
}
