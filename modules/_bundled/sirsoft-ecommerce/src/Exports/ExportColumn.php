<?php

namespace Modules\Sirsoft\Ecommerce\Exports;

/**
 * 파일의 열 하나
 *
 * 대부분 필드 하나 = 열 하나다. 다국어 필드를 「언어별 컬럼」 형식으로 낼 때만 필드 하나가 언어 수만큼의
 * 열이 되고, 그때 `locale` 에 그 열의 언어가 실린다.
 */
final class ExportColumn
{
    /**
     * @param  ExportField  $field  원천 필드
     * @param  string  $header  헤더 문구 (요청자 언어)
     * @param  string|null  $locale  언어별 컬럼일 때 이 열의 언어
     */
    public function __construct(
        public readonly ExportField $field,
        public readonly string $header,
        public readonly ?string $locale = null,
    ) {}
}
