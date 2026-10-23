<?php

namespace App\Extension\Exceptions;

/**
 * 압축 파일 안에 허용되지 않는 항목 경로가 있을 때 던지는 예외.
 *
 * 압축 밖 경로(`..`·절대경로·드라이브 문자·UNC)·널 문자·심볼릭 링크 항목이
 * 하나라도 있으면 아무것도 풀지 않고 이 예외로 전체를 거부합니다.
 */
class UnsafeArchiveException extends \RuntimeException
{
    /**
     * @param  string  $entry  거부된 항목의 원래 이름
     */
    public function __construct(public readonly string $entry)
    {
        parent::__construct(__('extensions.errors.unsafe_archive_entry', ['entry' => $entry]));
    }
}
