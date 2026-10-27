<?php

namespace Plugins\Sirsoft\Sso\Services\Provisioning;

use Plugins\Sirsoft\Sso\Exceptions\ScimException;

/**
 * SCIM 목록 `filter` 의 최소 해석 — `<속성> eq "<값>"` 한 개만 받는다.
 *
 * IdP 의 SCIM 클라이언트가 실제로 쓰는 것은 「이 사용자가 이미 있는지」 조회다(userName, externalId, emails.value, 그룹은
 * displayName, externalId). 그 밖의 연산자, and/or, 괄호는 `invalidFilter` 로 거절한다(ServiceProviderConfig 의 filter.supported 는
 * true 지만 연산 범위는 문서에 밝힌다).
 *
 * @since 1.0.0
 */
final class ScimFilter
{
    /**
     * 필터를 해석합니다.
     *
     * @param  string|null  $filter  쿼리의 filter 값
     * @param  array<int, string>  $attributes  받는 속성 이름 (소문자)
     * @return array{attribute: string, value: string}|null 속성(소문자)과 값, 필터가 없으면 null
     *
     * @throws ScimException 해석할 수 없거나 받지 않는 속성이면 400 invalidFilter
     */
    public static function parse(?string $filter, array $attributes): ?array
    {
        if ($filter === null || trim($filter) === '') {
            return null;
        }

        if (strlen($filter) > 500 || preg_match('/^\s*([A-Za-z][A-Za-z0-9_.:\-]*)\s+eq\s+"((?:\\\\.|[^"\\\\])*)"\s*$/i', $filter, $m) !== 1) {
            throw new ScimException(400, 'invalidFilter', __('sirsoft-sso::scim.error.invalid_filter'));
        }

        $attribute = mb_strtolower($m[1]);
        if (! in_array($attribute, $attributes, true)) {
            throw new ScimException(400, 'invalidFilter', __('sirsoft-sso::scim.error.unsupported_filter'));
        }

        return ['attribute' => $attribute, 'value' => stripcslashes($m[2])];
    }
}
