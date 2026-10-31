<?php

namespace Plugins\Sirsoft\Sso\Repositories;

use Illuminate\Support\Collection;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Repositories\Contracts\IdentityProviderRepositoryInterface;

/**
 * IdP 저장소.
 *
 * @since 1.0.0
 */
class IdentityProviderRepository implements IdentityProviderRepositoryInterface
{
    /**
     * ID 로 찾습니다.
     *
     * @param  int  $id  IdP ID
     * @return IdentityProvider|null IdP
     */
    public function findById(int $id): ?IdentityProvider
    {
        return IdentityProvider::query()->find($id);
    }

    /**
     * 키로 찾습니다.
     *
     * @param  string  $key  IdP 키
     * @return IdentityProvider|null IdP
     */
    public function findByKey(string $key): ?IdentityProvider
    {
        if (preg_match(IdentityProvider::KEY_PATTERN, $key) !== 1) {
            return null;
        }

        return IdentityProvider::query()->where('key', $key)->first();
    }

    /**
     * 로그인 화면에 버튼을 보일 활성 IdP 를 순서대로 반환합니다.
     *
     * @return Collection<int, IdentityProvider> IdP 목록
     */
    public function visibleEnabled(): Collection
    {
        return IdentityProvider::query()
            ->where('enabled', true)
            ->where('button_visible', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'key', 'protocol', 'label', 'button_style', 'sort_order']);
    }

    /**
     * 활성 IdP 를 순서대로 반환합니다.
     *
     * @return Collection<int, IdentityProvider> IdP 목록
     */
    public function enabled(): Collection
    {
        return IdentityProvider::query()->where('enabled', true)->orderBy('sort_order')->orderBy('id')->get();
    }

    /**
     * 새 IdP 를 만듭니다.
     *
     * @param  array<string, mixed>  $attributes  속성
     * @return IdentityProvider 만든 IdP
     */
    public function create(array $attributes): IdentityProvider
    {
        return IdentityProvider::query()->create($attributes);
    }

    /**
     * 로그인 결과를 IdP 상태 칸에 남깁니다.
     *
     * @param  IdentityProvider  $idp  IdP
     * @param  string|null  $errorCode  오류 코드 (null 이면 성공)
     * @return void 반환값 없음
     */
    public function recordOutcome(IdentityProvider $idp, ?string $errorCode): void
    {
        $values = $errorCode === null
            ? ['last_success_at' => now()]
            : ['last_error_at' => now(), 'last_error_code' => mb_substr($errorCode, 0, 50)];

        IdentityProvider::query()->whereKey($idp->getKey())->update($values);
    }
}
