<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Enums;

/**
 * NHN KCP 본인확인의 동일인 식별 기준.
 *
 * settings 의 `duplicate_field` 필드와 1:1 매핑되며, 운영자가 가맹점 정책에 따라 선택한다.
 *
 * @since 1.0.0
 */
enum KcpDuplicateField: string
{
    /** Duplicate Information — 가맹점(사이트코드) 종속 동일인 식별값. 개인정보 부담 낮음 */
    case Di = 'di';

    /** Connecting Information — 전 기관 공통 동일인 식별값. 개인정보 부담 높음 */
    case Ci = 'ci';
}
