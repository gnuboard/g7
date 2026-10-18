<?php

namespace App\Support\ExtensionIcon;

/**
 * 확장 아이콘 규격 검증기
 *
 * 규정 원문은 docs/extension/extension-icons.md — 정사각·투명 배경·100KB 이하,
 * SVG 는 스크립트·이벤트 속성·외부 참조·<foreignObject> 금지.
 *
 * **의존성 0 의 순수 PHP 다.** 웹 인스톨러(public/install/api/scan-extensions.php)가
 * Laravel autoload 없이 이 파일을 require_once 하므로 `use` 문·파사드·헬퍼 함수를
 * 쓰지 않는다. 클래스 밖 의존을 추가하면 인스톨러 Step 4 가 fatal 로 죽는다.
 */
final class ExtensionIconValidator
{
    /** @var int 아이콘 파일 최대 바이트 (100KB) */
    public const MAX_BYTES = 102400;

    /** @var int 래스터 아이콘 최소 한 변 픽셀 */
    public const MIN_RASTER_EDGE = 128;

    /** @var list<string> 허용 확장자 */
    public const ALLOWED_EXTENSIONS = ['svg', 'png', 'webp'];

    /**
     * @var list<string> 파일 관례 후보 (이 순서로 첫 존재 파일만 검사)
     *
     * 해석기(ExtensionIconResolver)와 웹 인스톨러가 같은 목록을 봐야 두 화면의
     * 판정이 갈리지 않는다. 인스톨러는 Laravel 없이 도는 탓에 해석기를 못 읽으므로
     * 이 상수가 공유 지점이다.
     */
    public const CONVENTION_CANDIDATES = ['assets/icon.svg', 'assets/icon.png', 'assets/icon.webp'];

    /** @var array<string, string> 확장자별 MIME 타입 */
    public const MIME_BY_EXTENSION = [
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    /** @var float 정사각 판정 허용 오차 비율 (긴 변 대비) */
    private const SQUARE_TOLERANCE_RATIO = 0.001;

    /**
     * 파일 경로의 아이콘이 규격에 맞는지 검증합니다.
     *
     * 확장자를 경로에서 뽑아 validateBytes() 에 위임하되, 래스터는 바이트가 아니라
     * 파일 경로로 getimagesize() 를 부른다(스트림 래퍼 없이 동작해야 한다).
     *
     * @param  string  $path  아이콘 파일 절대 경로
     * @return array{valid: bool, format: ?string, bytes: int, width: ?float, height: ?float, errors: list<string>} 검증 결과
     */
    public static function validateFile(string $path): array
    {
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return self::failure(null, 0, ['unsupported_extension']);
        }

        if (! is_file($path) || ! is_readable($path)) {
            return self::failure($extension, 0, ['file_missing']);
        }

        $bytes = (int) filesize($path);

        if ($bytes === 0) {
            return self::failure($extension, 0, ['empty_file']);
        }

        if ($bytes > self::MAX_BYTES) {
            return self::failure($extension, $bytes, ['too_large']);
        }

        if ($extension === 'svg') {
            $contents = file_get_contents($path);

            if ($contents === false) {
                return self::failure($extension, $bytes, ['file_missing']);
            }

            return self::validateSvgBytes($contents, $bytes);
        }

        return self::validateRasterPath($path, $extension, $bytes);
    }

    /**
     * 메모리 위 바이트로 아이콘 규격을 검증합니다 (ZIP 미리보기·테스트 재사용).
     *
     * 래스터는 임시 파일로 떨궈 getimagesize() 를 부른다 — 확장자·치수 판정이
     * validateFile() 과 한 글자도 다르지 않아야 미리보기와 서빙 판정이 갈리지 않는다.
     *
     * @param  string  $bytes  아이콘 파일 내용
     * @param  string  $extension  확장자 (svg|png|webp, 점 없이)
     * @return array{valid: bool, format: ?string, bytes: int, width: ?float, height: ?float, errors: list<string>} 검증 결과
     */
    public static function validateBytes(string $bytes, string $extension): array
    {
        $extension = strtolower(ltrim($extension, '.'));

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return self::failure(null, strlen($bytes), ['unsupported_extension']);
        }

        $size = strlen($bytes);

        if ($size === 0) {
            return self::failure($extension, 0, ['empty_file']);
        }

        if ($size > self::MAX_BYTES) {
            return self::failure($extension, $size, ['too_large']);
        }

        if ($extension === 'svg') {
            return self::validateSvgBytes($bytes, $size);
        }

        $temp = tempnam(sys_get_temp_dir(), 'g7icon');

        if ($temp === false) {
            return self::failure($extension, $size, ['raster_unreadable']);
        }

        file_put_contents($temp, $bytes);
        $result = self::validateRasterPath($temp, $extension, $size);
        @unlink($temp);

        return $result;
    }

    /**
     * 매니페스트 `icon` 경로 문자열의 규칙 위반을 판정합니다.
     *
     * 확장 루트 기준 상대경로만 허용한다 — 절대경로·URL·상위 탈출·역슬래시·
     * NUL 바이트·허용 밖 확장자는 전부 거부다. 실재 여부는 보지 않는다
     * (그 판정은 resolveInsideRoot()).
     *
     * @param  string  $iconPath  매니페스트에 적힌 경로
     * @return string|null 위반 코드(absolute_path|traversal|backslash|null_byte|unsupported_extension|empty), 위반 없으면 null
     */
    public static function manifestPathViolation(string $iconPath): ?string
    {
        if (strpos($iconPath, "\0") !== false) {
            return 'null_byte';
        }

        $trimmed = trim($iconPath);

        if ($trimmed === '') {
            return 'empty';
        }

        if (strpos($trimmed, '\\') !== false) {
            return 'backslash';
        }

        // 절대경로·URL·프로토콜 상대 경로 — 확장 루트 밖을 가리킨다.
        if ($trimmed[0] === '/'
            || strpos($trimmed, '//') === 0
            || preg_match('#^[A-Za-z]:[/\\\\]#', $trimmed) === 1
            || preg_match('#^[A-Za-z][A-Za-z0-9+.-]*:#', $trimmed) === 1) {
            return 'absolute_path';
        }

        foreach (explode('/', $trimmed) as $segment) {
            if ($segment === '..') {
                return 'traversal';
            }
        }

        $extension = strtolower((string) pathinfo($trimmed, PATHINFO_EXTENSION));

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return 'unsupported_extension';
        }

        return null;
    }

    /**
     * 루트 기준 상대경로를 해석해 루트 안의 실재 파일인지 확인합니다.
     *
     * realpath 로 심볼릭 링크까지 펼친 뒤 루트 안인지 본다 — 링크로 루트를
     * 빠져나가는 경로는 null 이다.
     *
     * @param  string  $root  확장 루트 절대 경로
     * @param  string  $relative  루트 기준 상대경로
     * @return string|null 정규화된 절대 경로, 실재하지 않거나 루트 밖이면 null
     */
    public static function resolveInsideRoot(string $root, string $relative): ?string
    {
        $realRoot = realpath($root);

        if ($realRoot === false) {
            return null;
        }

        $candidate = realpath(rtrim($realRoot, '/\\').DIRECTORY_SEPARATOR.ltrim($relative, '/\\'));

        if ($candidate === false || ! is_file($candidate)) {
            return null;
        }

        $normalizedRoot = rtrim($realRoot, '/\\').DIRECTORY_SEPARATOR;

        return strpos($candidate, $normalizedRoot) === 0 ? $candidate : null;
    }

    /**
     * SVG 바이트를 검증합니다.
     *
     * ① 문자열 선검사(DOCTYPE/ENTITY/xml-stylesheet) ② DOMDocument 파싱
     * (LIBXML_NOENT 절대 금지 — 외부 엔티티 확장 경로) ③ 루트 요소 ④ 위험 요소·
     * 속성 순회 ⑤ 정사각 판정 순으로 본다.
     *
     * @param  string  $bytes  SVG 내용
     * @param  int  $size  바이트 수
     * @return array{valid: bool, format: ?string, bytes: int, width: ?float, height: ?float, errors: list<string>} 검증 결과
     */
    private static function validateSvgBytes(string $bytes, int $size): array
    {
        $errors = [];
        $lowered = strtolower($bytes);

        if (strpos($lowered, '<!doctype') !== false || strpos($lowered, '<!entity') !== false) {
            $errors[] = 'svg_doctype_or_entity';
        }

        if (strpos($lowered, '<?xml-stylesheet') !== false) {
            $errors[] = 'svg_stylesheet_pi';
        }

        if ($errors !== []) {
            return self::failure('svg', $size, $errors);
        }

        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument;
        $loaded = $document->loadXML($bytes, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false || $document->documentElement === null) {
            return self::failure('svg', $size, ['svg_not_well_formed']);
        }

        $root = $document->documentElement;

        if (strtolower($root->localName) !== 'svg') {
            return self::failure('svg', $size, ['svg_root_not_svg']);
        }

        $elements = $document->getElementsByTagName('*');

        foreach ($elements as $element) {
            $name = strtolower($element->localName);

            if ($name === 'script') {
                $errors[] = 'svg_script_element';
            }

            if ($name === 'foreignobject') {
                $errors[] = 'svg_foreign_object';
            }

            if ($name === 'style' && self::hasExternalCssReference($element->textContent)) {
                $errors[] = 'svg_style_external_reference';
            }

            if ($element->attributes === null) {
                continue;
            }

            foreach ($element->attributes as $attribute) {
                $attributeName = strtolower($attribute->localName);
                $value = (string) $attribute->value;

                if (strpos($attributeName, 'on') === 0) {
                    $errors[] = 'svg_event_handler_attribute';

                    continue;
                }

                if (($attributeName === 'href' || $attributeName === 'xlink:href')
                    && ! self::isInternalFragment($value)) {
                    $errors[] = 'svg_external_reference';

                    continue;
                }

                if ($attributeName === 'style' && self::hasExternalCssReference($value)) {
                    $errors[] = 'svg_style_external_reference';
                }
            }
        }

        [$width, $height, $squareError] = self::measureSvg($root);

        if ($squareError !== null) {
            $errors[] = $squareError;
        }

        $errors = array_values(array_unique($errors));

        return [
            'valid' => $errors === [],
            'format' => 'svg',
            'bytes' => $size,
            'width' => $width,
            'height' => $height,
            'errors' => $errors,
        ];
    }

    /**
     * SVG 루트의 치수를 재고 정사각 여부를 판정합니다.
     *
     * viewBox 가 있으면 그 4수의 width/height 를, 없으면 width/height 속성을 본다.
     * 둘 다 없으면 정사각임을 확인할 길이 없어 not_square 다.
     *
     * @param  \DOMElement  $root  SVG 루트 요소
     * @return array{0: ?float, 1: ?float, 2: ?string} [가로, 세로, 오류 코드]
     */
    private static function measureSvg(\DOMElement $root): array
    {
        $viewBox = trim((string) $root->getAttribute('viewBox'));

        if ($viewBox !== '') {
            $parts = preg_split('/[\s,]+/', $viewBox) ?: [];

            if (count($parts) === 4 && is_numeric($parts[2]) && is_numeric($parts[3])) {
                $width = (float) $parts[2];
                $height = (float) $parts[3];

                return [$width, $height, self::squareError($width, $height)];
            }

            return [null, null, 'not_square'];
        }

        $width = self::parseLength((string) $root->getAttribute('width'));
        $height = self::parseLength((string) $root->getAttribute('height'));

        if ($width === null || $height === null) {
            return [$width, $height, 'not_square'];
        }

        return [$width, $height, self::squareError($width, $height)];
    }

    /**
     * 단위 없는(또는 px) 길이 문자열을 실수로 바꿉니다.
     *
     * @param  string  $value  길이 문자열
     * @return float|null 픽셀 값, 해석 불가면 null
     */
    private static function parseLength(string $value): ?float
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d+(?:\.\d+)?)(px)?$/i', $value, $matches) !== 1) {
            return null;
        }

        return (float) $matches[1];
    }

    /**
     * 두 변이 정사각인지 판정합니다.
     *
     * @param  float  $width  가로
     * @param  float  $height  세로
     * @return string|null 비정사각이면 오류 코드, 정사각이면 null
     */
    private static function squareError(float $width, float $height): ?string
    {
        if ($width <= 0.0 || $height <= 0.0) {
            return 'not_square';
        }

        $tolerance = self::SQUARE_TOLERANCE_RATIO * max($width, $height);

        return abs($width - $height) <= $tolerance ? null : 'not_square';
    }

    /**
     * href 값이 같은 문서 안 조각 참조인지 확인합니다.
     *
     * @param  string  $value  href 또는 xlink:href 값
     * @return bool `#` 로 시작하면 true
     */
    private static function isInternalFragment(string $value): bool
    {
        return strpos(trim($value), '#') === 0;
    }

    /**
     * CSS 조각에 외부 참조(@import·url(...))가 있는지 확인합니다.
     *
     * url(#fragment) 는 그라디언트·마스크 참조라 허용한다.
     *
     * @param  string  $css  스타일 요소 본문 또는 style 속성 값
     * @return bool 외부 참조가 있으면 true
     */
    private static function hasExternalCssReference(string $css): bool
    {
        $lowered = strtolower($css);

        if (strpos($lowered, '@import') !== false) {
            return true;
        }

        if (preg_match_all('/url\(\s*[\'"]?([^\'")]*)/i', $css, $matches) === false) {
            return false;
        }

        foreach ($matches[1] ?? [] as $target) {
            if (strpos(trim($target), '#') !== 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * 래스터 파일(PNG·WebP)의 타입·치수를 검증합니다.
     *
     * @param  string  $path  파일 절대 경로
     * @param  string  $extension  확장자 (png|webp)
     * @param  int  $size  바이트 수
     * @return array{valid: bool, format: ?string, bytes: int, width: ?float, height: ?float, errors: list<string>} 검증 결과
     */
    private static function validateRasterPath(string $path, string $extension, int $size): array
    {
        $info = @getimagesize($path);

        if ($info === false || ! isset($info[0], $info[1], $info[2])) {
            return self::failure($extension, $size, ['raster_unreadable']);
        }

        $expectedType = $extension === 'png' ? IMAGETYPE_PNG : IMAGETYPE_WEBP;

        if ($info[2] !== $expectedType) {
            return self::failure($extension, $size, ['raster_type_mismatch']);
        }

        $width = (float) $info[0];
        $height = (float) $info[1];
        $errors = [];

        $squareError = self::squareError($width, $height);

        if ($squareError !== null) {
            $errors[] = $squareError;
        }

        if ($width < self::MIN_RASTER_EDGE || $height < self::MIN_RASTER_EDGE) {
            $errors[] = 'raster_too_small';
        }

        return [
            'valid' => $errors === [],
            'format' => $extension,
            'bytes' => $size,
            'width' => $width,
            'height' => $height,
            'errors' => $errors,
        ];
    }

    /**
     * 실패 결과 배열을 만듭니다.
     *
     * @param  string|null  $format  확장자
     * @param  int  $bytes  바이트 수
     * @param  list<string>  $errors  오류 코드 목록
     * @return array{valid: bool, format: ?string, bytes: int, width: ?float, height: ?float, errors: list<string>} 실패 결과
     */
    private static function failure(?string $format, int $bytes, array $errors): array
    {
        return [
            'valid' => false,
            'format' => $format,
            'bytes' => $bytes,
            'width' => null,
            'height' => null,
            'errors' => $errors,
        ];
    }
}
