<?php

namespace App\Http\Requests\Concerns;

/**
 * 확장 목록의 「숨김 항목 포함」(include_hidden) 을 최고관리자에게만 허용하는 FormRequest 공용 동작
 *
 * 관리 화면은 체크박스를 최고관리자에게만 보이지만, 체크 값이 주소(`?include_hidden=1`)에 실리므로
 * 그 주소로 들어온 다른 관리자에게도 숨김 확장이 내려가고 끌 체크박스는 보이지 않았다.
 * 검증 전에 최고관리자가 아니면 값을 거짓으로 고정해 서버가 정책을 지킨다.
 *
 * @since 7.1.0
 */
trait RestrictsHiddenExtensionsToSuperAdmin
{
    /**
     * 최고관리자가 아니면 include_hidden 을 거짓으로 덮어씁니다.
     *
     * @return void 반환값 없음
     */
    protected function restrictIncludeHiddenToSuperAdmin(): void
    {
        if (! $this->has('include_hidden')) {
            return;
        }

        if (! (bool) ($this->user()?->is_super ?? false)) {
            $this->merge(['include_hidden' => false]);
        }
    }

    /**
     * 검증 전 입력을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function prepareForValidation(): void
    {
        $this->restrictIncludeHiddenToSuperAdmin();
    }
}
