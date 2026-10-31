<?php

namespace Plugins\Sirsoft\Sso\Repositories\Contracts;

use Illuminate\Support\Collection;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;

/**
 * IdP 저장소 계약.
 *
 * @since 1.0.0
 */
interface IdentityProviderRepositoryInterface
{
    /**
     * ID 로 찾습니다.
     *
     * @param  int  $id  IdP ID
     * @return IdentityProvider|null IdP
     */
    public function findById(int $id): ?IdentityProvider;

    /**
     * 키로 찾습니다.
     *
     * @param  string  $key  IdP 키
     * @return IdentityProvider|null IdP
     */
    public function findByKey(string $key): ?IdentityProvider;

    /**
     * 로그인 화면에 버튼을 보일 활성 IdP 를 순서대로 반환합니다.
     *
     * @return Collection<int, IdentityProvider> IdP 목록
     */
    public function visibleEnabled(): Collection;

    /**
     * 활성 IdP 를 순서대로 반환합니다.
     *
     * @return Collection<int, IdentityProvider> IdP 목록
     */
    public function enabled(): Collection;

    /**
     * 새 IdP 를 만듭니다.
     *
     * @param  array<string, mixed>  $attributes  속성
     * @return IdentityProvider 만든 IdP
     */
    public function create(array $attributes): IdentityProvider;

    /**
     * 로그인 결과를 IdP 상태 칸(마지막 성공, 마지막 오류)에 남깁니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  string|null  $errorCode  오류 코드 (null 이면 성공)
     * @return void 반환값 없음
     */
    public function recordOutcome(IdentityProvider $idp, ?string $errorCode): void;
}
