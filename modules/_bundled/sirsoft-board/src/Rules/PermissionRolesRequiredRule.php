<?php

namespace Modules\Sirsoft\Board\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 게시판 권한에 최소 하나의 역할이 필수인지 검증하는 규칙
 *
 * 각 권한에 최소 하나 이상의 역할이 할당되어야 합니다.
 */
class PermissionRolesRequiredRule implements ValidationRule
{
    /**
     * 검증 수행
     *
     * @param  string  $attribute  검증할 속성명
     * @param  mixed  $value  검증할 값 (permissions 배열)
     * @param  Closure  $fail  실패 콜백
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }

        $emptyPermissions = [];

        foreach ($value as $permKey => $permData) {
            // roles가 없거나 빈 배열인 경우
            $roles = $permData['roles'] ?? [];

            if (empty($roles)) {
                // 권한 키를 다국어화된 이름으로 변환
                $permissionName = $this->getLocalizedPermissionName($permKey);
                $emptyPermissions[] = $permissionName;
            }
        }

        if (! empty($emptyPermissions)) {
            $fail(__('sirsoft-board::validation.permissions.roles_required', [
                'permissions' => implode(', ', $emptyPermissions),
            ]));
        }
    }

    /**
     * 권한 키를 다국어화된 이름으로 변환
     *
     * @param  string  $permKey  권한 키 (예: posts_read, admin.posts.read)
     * @return string 다국어화된 권한 이름
     */
    private function getLocalizedPermissionName(string $permKey): string
    {
        // 권한 키를 dot notation으로 변환 (posts_read -> posts.read)
        $i18nKey = str_replace('_', '.', $permKey);

        // 백엔드 validation 파일에서 권한 이름 조회
        $translationKey = "sirsoft-board::validation.permission_names.{$i18nKey}";
        $translatedName = __($translationKey);

        // 번역이 없으면 원래 키 반환
        return $translatedName === $translationKey ? $permKey : $translatedName;
    }
}
