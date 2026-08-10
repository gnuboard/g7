<?php

namespace App\Rules\LanguagePack;

use App\Enums\PermissionType;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Auth;

/**
 * `auto_activate` 로 설치 직후 활성화를 요청할 때 활성화 권한을 추가로 요구하는 Custom Rule.
 *
 * 언어팩 설치(`core.language_packs.install`)와 활성화(`core.language_packs.manage`)는 원래
 * 별도 권한이다. 그런데 설치 요청의 `auto_activate` 플래그는 install 권한만으로 활성 상태를
 * 만들 수 있어 경계가 무너져 있었다. 활성 팩만 fallback 경로에 배선되어 `require` 되므로,
 * 이 경계가 임의 PHP 실행의 마지막 관문이다.
 *
 * `authorize()` 가 아니라 필드 단위 Rule 이어야 한다 — `authorize()` 로 처리하면
 * `auto_activate` 를 보내지 않은 정상 설치 요청까지 403 이 된다. 필드 단위로 두면
 * 그 항목만 422 로 거부되고, 항목을 빼면 설치는 그대로 진행된다.
 *
 * 판정 기준은 활성화 라우트가 쓰는 권한과 같다(`core.language_packs.manage`, admin 타입) —
 * 두 경로가 다른 기준을 쓰면 한쪽이 우회로가 된다.
 */
class RequiresActivationPermission implements ValidationRule
{
    /** 활성화에 필요한 권한 식별자 */
    public const PERMISSION = 'core.language_packs.manage';

    /**
     * 활성화를 요청했다면 활성화 권한을 가지고 있는지 검증합니다.
     *
     * @param  string  $attribute  검증 대상 필드명
     * @param  mixed  $value  검증 대상 값
     * @param  Closure  $fail  실패 콜백
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $user = Auth::user();

        if ($user === null || ! $user->hasPermission(self::PERMISSION, PermissionType::Admin)) {
            $fail(__('language_packs.errors.auto_activate_requires_manage'));
        }
    }
}
