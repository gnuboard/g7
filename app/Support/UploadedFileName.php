<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * 업로드 파일의 원래 이름 (사용자가 고른 그대로).
 *
 * 브라우저는 multipart/form-data 의 `filename` 안에서 큰따옴표를 `%22`, CR 을 `%0D`, LF 를 `%0A` 로
 * 바꿔 보낸다(WHATWG HTML 표준의 form-data 인코딩 — Chromium, Firefox, Safari 공통). 서버가
 * `getClientOriginalName()` 을 그대로 저장하면 `견적 "최종".pdf` 가 `견적 %22최종%22.pdf` 로 저장되고
 * 목록과 내려받은 파일 이름에 그대로 보인다.
 *
 * 표준이 바꾸는 세 가지만 되돌린다(`%0d`·`%0a` 소문자 포함). `%20`·`%25` 같은 다른 퍼센트 문자열은
 * 브라우저가 만든 것이 아니라 사용자가 실제로 붙인 이름이라 그대로 둔다. 되돌린 CR/LF 는 이름에 남는다 —
 * 내려받기 머리글은 `HeaderUtils::makeDisposition()` 이 `filename*` 를 퍼센트 인코딩하고 ASCII 대체 이름에서
 * 제어 문자를 지우므로(`FilesystemAdapter::fallbackName()` 의 `Str::ascii()`, `InlineMediaPolicy`) 머리글에
 * 줄바꿈이 실리지 않는다.
 *
 * 이미 저장된 이름은 바꾸지 않는다. 새로 올리는 파일부터 적용된다.
 *
 * 브라우저는 이름 속 `%` 를 인코딩하지 않으므로, 이름에 글자 그대로 `%22` 가 든 파일(예: `할인%22.pdf`)도
 * `"` 로 바뀐다. 서버에서는 두 경우를 구별할 수 없고, 큰따옴표가 든 이름이 훨씬 흔해 이쪽을 택한다.
 */
final class UploadedFileName
{
    /**
     * 브라우저 form-data 인코딩을 되돌리는 치환표 (대소문자 무관).
     *
     * @var array<string, string>
     */
    private const FORM_DATA_ESCAPES = [
        '%22' => '"',
        '%0D' => "\r",
        '%0d' => "\r",
        '%0A' => "\n",
        '%0a' => "\n",
    ];

    /**
     * 업로드 파일의 원래 이름을 돌려줍니다.
     *
     * @param  UploadedFile  $file  업로드 파일
     * @return string 브라우저 인코딩을 되돌린 원래 이름 (`getClientOriginalName()` 기준, 경로는 이미 떨어져 있다)
     */
    public static function original(UploadedFile $file): string
    {
        return self::restore((string) $file->getClientOriginalName());
    }

    /**
     * 브라우저가 바꿔 보낸 `%22`·`%0D`·`%0A` 만 원래 글자로 되돌립니다.
     *
     * 한 번에 치환하므로(strtr) 되돌린 결과가 다시 치환 대상이 되지 않는다.
     *
     * @param  string  $name  클라이언트가 보낸 파일 이름
     * @return string 되돌린 이름
     */
    public static function restore(string $name): string
    {
        if (! str_contains($name, '%')) {
            return $name;
        }

        return strtr($name, self::FORM_DATA_ESCAPES);
    }
}
