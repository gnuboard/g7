<?php

namespace Plugins\Sirsoft\Sso\Repositories\Policy\Contracts;

use Illuminate\Support\Collection;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;

/**
 * 관리자 IdP 화면과 강제 해제 명령의 IdP 저장소 계약 (로그인 흐름 저장소와 분리 — 관리자 화면 전용 조회와 쓰기).
 *
 * @since 1.0.0
 */
interface AdminIdpRepositoryInterface
{
    /**
     * IdP 전체를 순서대로 반환합니다.
     *
     * @return Collection<int, IdentityProvider> IdP
     */
    public function all(): Collection;

    /**
     * IdP 수를 셉니다 (등록 상한 판정).
     *
     * @return int 개수
     */
    public function count(): int;

    /**
     * ID 로 찾습니다.
     *
     * @param  int  $id  IdP ID
     * @return IdentityProvider|null IdP
     */
    public function findById(int $id): ?IdentityProvider;

    /**
     * 키가 이미 있는지 확인합니다.
     *
     * @param  string  $key  키
     * @param  int|null  $exceptId  제외할 ID (수정 중인 자기 자신)
     * @return bool 있으면 true
     */
    public function keyExists(string $key, ?int $exceptId = null): bool;

    /**
     * IdP 를 만듭니다.
     *
     * @param  array<string, mixed>  $attributes  속성
     * @return IdentityProvider 만든 IdP
     */
    public function create(array $attributes): IdentityProvider;

    /**
     * IdP 를 고칩니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  array<string, mixed>  $attributes  바꿀 속성
     * @return IdentityProvider 고친 IdP
     */
    public function update(IdentityProvider $idp, array $attributes): IdentityProvider;

    /**
     * IdP 를 지웁니다 (연결이 남아 있으면 호출하지 않는다).
     *
     * @param  IdentityProvider  $idp  IdP
     * @return void 반환값 없음
     */
    public function delete(IdentityProvider $idp): void;

    /**
     * IdP 별 연결 수 (활성/전체).
     *
     * @param  array<int, int>  $idpIds  IdP ID
     * @return array<int, array{active: int, total: int}> IdP ID → 연결 수
     */
    public function accountCounts(array $idpIds): array;

    /**
     * 연결 회원 중 관리자가 있는지 확인합니다 (W35).
     *
     * @param  int  $idpId  IdP ID
     * @return bool 있으면 true
     */
    public function hasPrivilegedLinkedUser(int $idpId): bool;

    /**
     * 「비밀번호 로그인 막기」를 켠 IdP 목록.
     *
     * @return Collection<int, IdentityProvider> IdP
     */
    public function enforcing(): Collection;
}
