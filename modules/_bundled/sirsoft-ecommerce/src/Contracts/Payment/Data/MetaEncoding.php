<?php

namespace Modules\Sirsoft\Ecommerce\Contracts\Payment\Data;

/**
 * 결제 meta 저장 인코딩 (공개 계약 열거)
 *
 * @since 1.4.0
 */
enum MetaEncoding: string
{
    /** 모델 캐스트와 같은 JSON(유니코드 이스케이프), 수정 시각 갱신 */
    case Eloquent = 'eloquent';

    /** 유니코드를 이스케이프하지 않은 JSON, 수정 시각 갱신 */
    case UnescapedUnicode = 'unescaped_unicode';
}
