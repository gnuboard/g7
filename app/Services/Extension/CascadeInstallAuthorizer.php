<?php

namespace App\Services\Extension;

use App\Helpers\PermissionHelper;
use App\Models\User;

/**
 * 확장 설치 창의 동반 설치 항목별 권한 판정.
 *
 * 설치 창은 본 확장의 install 권한으로 열리지만, 함께 고른 의존 모듈·플러그인은 설치와
 * 활성화까지, 번들 언어팩은 설치와 자동 활성화까지 수행된다. 그 동작마다 대상 종류의
 * 권한이 따로 있으므로 항목별로 판정한다. 설치 창 미리보기(체크박스 잠금)와 설치 API
 * (403)가 같은 판정을 쓰도록 이 한 곳에 둔다.
 */
class CascadeInstallAuthorizer
{
    /**
     * 의존 확장을 함께 처리하는 데 필요한 권한 식별자를 돌려준다.
     *
     * 미설치 항목은 설치 후 활성화하고, 설치됐지만 꺼진 항목은 활성화만 한다
     * (OrchestratesCascadeInstall::installSelectedDependencies 와 같은 분기).
     *
     * @param  string  $type  의존 확장 종류 (module|plugin)
     * @param  bool  $isInstalled  이미 설치돼 있는지
     * @return array<int, string> 모두 필요한 권한 식별자 목록 (알 수 없는 종류면 빈 배열)
     */
    public function requiredDependencyPermissions(string $type, bool $isInstalled): array
    {
        $prefix = match ($type) {
            'module' => 'core.modules',
            'plugin' => 'core.plugins',
            default => null,
        };

        if ($prefix === null) {
            return [];
        }

        return $isInstalled
            ? ["{$prefix}.activate"]
            : ["{$prefix}.install", "{$prefix}.activate"];
    }

    /**
     * 번들 언어팩을 함께 설치·자동 활성화하는 데 필요한 권한 식별자를 돌려준다.
     *
     * @return array<int, string> 모두 필요한 권한 식별자 목록
     */
    public function requiredLanguagePackPermissions(): array
    {
        return ['core.language_packs.install', 'core.language_packs.manage'];
    }

    /**
     * 사용자가 이 의존 확장을 함께 처리할 수 있는지 판정한다.
     *
     * @param  string  $type  의존 확장 종류 (module|plugin)
     * @param  bool  $isInstalled  이미 설치돼 있는지
     * @param  User|null  $user  판정할 사용자 (null 이면 현재 인증 사용자)
     * @return bool 필요한 권한을 모두 가졌으면 true
     */
    public function canSelectDependency(string $type, bool $isInstalled, ?User $user = null): bool
    {
        $permissions = $this->requiredDependencyPermissions($type, $isInstalled);

        return $permissions !== [] && $this->hasAll($permissions, $user);
    }

    /**
     * 사용자가 번들 언어팩을 함께 설치할 수 있는지 판정한다.
     *
     * @param  User|null  $user  판정할 사용자 (null 이면 현재 인증 사용자)
     * @return bool 필요한 권한을 모두 가졌으면 true
     */
    public function canSelectLanguagePack(?User $user = null): bool
    {
        return $this->hasAll($this->requiredLanguagePackPermissions(), $user);
    }

    /**
     * 권한 식별자를 모두 가졌는지 확인한다.
     *
     * @param  array<int, string>  $permissions  권한 식별자 목록
     * @param  User|null  $user  판정할 사용자
     * @return bool 모두 가졌으면 true
     */
    private function hasAll(array $permissions, ?User $user): bool
    {
        $user ??= auth()->user();

        foreach ($permissions as $permission) {
            if (! PermissionHelper::check($permission, $user)) {
                return false;
            }
        }

        return true;
    }
}
