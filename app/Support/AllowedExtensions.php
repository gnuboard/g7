<?php

namespace App\Support;

use Symfony\Component\Mime\MimeTypes;

/**
 * 허용 확장자 목록 정규화
 *
 * 허용 확장자는 관리자 화면에서 콤마로 구분한 한 줄 텍스트로 들어오고, API 를 직접 쓰는
 * 경로에서는 배열로 들어온다. 저장 계층과 적용 계층이 각자 형태를 판단하면 한쪽이
 * 받아들이는 값을 다른 쪽이 조용히 버리게 되므로(관리자가 확장자를 바꿔도 업로드 제한은
 * 그대로), 형태 판정과 정규화를 이 한 곳에서만 수행한다.
 */
final class AllowedExtensions
{
    /**
     * 문자열/배열 입력을 소문자 확장자 배열로 정규화합니다.
     *
     * @param  mixed  $value  콤마 구분 문자열 또는 배열
     * @return array<int, string> 정규화된 확장자 목록 (빈 배열 = 제한 없음)
     */
    public static function normalize(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        $normalized = [];

        foreach ($value as $extension) {
            if (! is_string($extension) && ! is_numeric($extension)) {
                continue;
            }

            $extension = strtolower(ltrim(trim((string) $extension), '.'));

            if ($extension === '' || in_array($extension, $normalized, true)) {
                continue;
            }

            $normalized[] = $extension;
        }

        return $normalized;
    }

    /**
     * 허용 MIME 목록을 그 형식의 알려진 이름 확장자 목록으로 바꿉니다.
     *
     * 내용(MIME)만 검사하던 업로드 요청이 이름 확장자도 함께 좁힐 때 쓴다. 와일드카드(`image/*`)는 그 대분류의
     * 알려진 확장자 전부로 푼다. 모르는 MIME 은 확장자를 만들지 않는다.
     *
     * @param  array<int, mixed>  $mimes  허용 MIME 목록
     * @return array<int, string> 정규화된 확장자 목록 (빈 배열 = 파생할 것이 없음)
     *
     * @since 7.1.6
     */
    public static function fromMimeTypes(array $mimes): array
    {
        $mimeTypes = MimeTypes::getDefault();
        $extensions = [];

        foreach ($mimes as $mime) {
            if (! is_string($mime)) {
                continue;
            }

            $mime = strtolower(trim($mime));
            if ($mime === '') {
                continue;
            }

            if (str_ends_with($mime, '/*')) {
                $extensions = array_merge($extensions, self::extensionsOfTopLevelType(substr($mime, 0, -1)));

                continue;
            }

            $extensions = array_merge($extensions, $mimeTypes->getExtensions($mime));
        }

        return self::normalize($extensions);
    }

    /**
     * 허용 MIME 목록의 모든 형식에서 이름 확장자를 파생할 수 있는지 판정합니다.
     *
     * 확장자 표에 없는 MIME 이나 「임의 이진 파일」(`application/octet-stream`)이 하나라도 섞여 있으면
     * false 다. 그런 목록에서 파생한 확장자로 이름을 좁히면 그 형식의 정상 파일이 거부되므로, 호출측은
     * 이름 검사를 생략하고 내용 검사만 한다.
     *
     * @param  array<int, mixed>  $mimes  허용 MIME 목록
     * @return bool 모든 형식의 이름 확장자를 알면 true (빈 목록은 false)
     *
     * @since 7.1.6
     */
    public static function coversEveryMimeType(array $mimes): bool
    {
        $mimeTypes = MimeTypes::getDefault();
        $checked = 0;

        foreach ($mimes as $mime) {
            if (! is_string($mime) || trim($mime) === '') {
                continue;
            }

            $mime = strtolower(trim($mime));
            $checked++;

            if ($mime === 'application/octet-stream') {
                return false;
            }

            $known = str_ends_with($mime, '/*')
                ? self::extensionsOfTopLevelType(substr($mime, 0, -1))
                : $mimeTypes->getExtensions($mime);

            if ($known === []) {
                return false;
            }
        }

        return $checked > 0;
    }

    /**
     * 대분류(`image/` 등)에 속한 MIME 의 알려진 확장자를 모두 모읍니다.
     *
     * @param  string  $prefix  `/` 로 끝나는 대분류 접두(소문자)
     * @return array<int, string> 확장자 목록
     */
    private static function extensionsOfTopLevelType(string $prefix): array
    {
        $map = (new \ReflectionClass(MimeTypes::class))->getConstant('MAP');
        $extensions = [];

        foreach (is_array($map) ? $map : [] as $mime => $known) {
            if (str_starts_with((string) $mime, $prefix)) {
                $extensions = array_merge($extensions, (array) $known);
            }
        }

        return $extensions;
    }
}
