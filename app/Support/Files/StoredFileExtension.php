<?php

namespace App\Support\Files;

use Illuminate\Http\UploadedFile;

/**
 * 저장 파일명에 쓸 확장자 — 올린 이름이 아니라 파일 내용에서 정한다.
 *
 * 업로드 검증(`image`·`mimes`·내용 판정 규칙)의 통과 기준은 **내용**이다. 저장 경로에 올린 이름의 확장자를
 * 그대로 쓰면, 내용은 정상 이미지이고 이름만 `.html`·`.js` 인 파일이 그 확장자로 저장되고, 그 디스크를 직접
 * 내보내는 웹서버·객체 저장소가 **확장자로** 처리해 같은 출처에서 실행되는 파일이 된다.
 *
 * 저장 지점은 이 클래스로 확장자를 정한다. 원본 파일 이름(표시·다운로드 이름)은 건드리지 않는다.
 *
 * @since 7.1.6
 */
final class StoredFileExtension
{
    /**
     * 저장 파일명에 쓸 확장자를 반환합니다(내용 기준, 소문자, 점 없음).
     *
     * @param  UploadedFile  $file  업로드 파일
     * @param  string  $fallback  내용에서 확장자를 정하지 못했을 때 쓸 값
     * @return string 저장 확장자
     */
    public static function resolve(UploadedFile $file, string $fallback = 'bin'): string
    {
        $extension = FileContentSniffer::extension($file);

        if (! is_string($extension) || $extension === '') {
            $extension = $fallback;
        }

        return strtolower($extension);
    }
}
