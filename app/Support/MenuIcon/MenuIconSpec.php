<?php

namespace App\Support\MenuIcon;

use App\Support\ExtensionIcon\ExtensionIconValidator;

/**
 * 관리자 메뉴 아이콘 값 객체 — FA 클래스 · 이미지 · 인라인 SVG 세 형태를 한 모양으로 다룬다.
 *
 * `menus.icon` 컬럼에는 정규형 한 가지만 저장한다.
 * - class: FA 클래스 평문(`fas fa-users`, 공백 정규화)
 * - image·svg: 키 사전순 JSON(`{"path":"/qa/a.png","source":"site","type":"image"}`) — 표시용 `url` 은 저장하지 않는다
 *
 * 같은 뜻이면 바이트가 같아야 사용자 수정 표식(`user_overrides`)·변경 감지가 오탐하지 않는다.
 *
 * @since 7.2.1
 */
final class MenuIconSpec
{
    /** @var string FA 클래스 아이콘 */
    public const TYPE_CLASS = 'class';

    /** @var string 이미지 아이콘(`<img>`) */
    public const TYPE_IMAGE = 'image';

    /** @var string 인라인 SVG 코드 아이콘 */
    public const TYPE_SVG = 'svg';

    /** @var string 사이트 내부 경로(`/` 로 시작) */
    public const SOURCE_SITE = 'site';

    /** @var string 확장 패키지 기준 상대경로 */
    public const SOURCE_PACKAGE = 'package';

    /** @var string 코어 첨부(업로드) */
    public const SOURCE_ATTACHMENT = 'attachment';

    /** @var int FA 클래스 최대 길이 */
    public const CLASS_MAX_LENGTH = 100;

    /** @var string FA 클래스 패턴 — 하이픈 단어를 공백으로 이은 것 (프론트 MENU_ICON_CLASS_PATTERN 과 동일) */
    public const CLASS_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*(?:\s+[a-z0-9]+(?:-[a-z0-9]+)*)*$/i';

    /** @var list<string> 이미지 아이콘 허용 확장자 (SVG 는 이미지로 받지 않는다 — 인라인 svg 형만) */
    public const IMAGE_EXTENSIONS = ['png', 'webp', 'jpg', 'jpeg', 'gif'];

    /** @var int 이미지 경로 최대 길이 */
    public const PATH_MAX_LENGTH = 500;

    /** @var string 업로드 첨부 컬렉션 */
    public const ATTACHMENT_COLLECTION = 'menu_icons';

    /**
     * 생성자 — 정적 생성자를 쓴다.
     *
     * @param  string  $type  형(class|image|svg)
     * @param  string|null  $value  class 값 또는 svg 코드
     * @param  string|null  $source  image 출처(site|package|attachment)
     * @param  string|null  $path  image 경로(site·package)
     * @param  int|null  $attachmentId  image 첨부 ID
     * @param  string|null  $hash  image 첨부 해시
     */
    private function __construct(
        public readonly string $type,
        public readonly ?string $value = null,
        public readonly ?string $source = null,
        public readonly ?string $path = null,
        public readonly ?int $attachmentId = null,
        public readonly ?string $hash = null,
    ) {}

    /**
     * FA 클래스 아이콘을 만듭니다 (공백 정규화).
     *
     * @param  string  $value  FA 클래스 문자열
     * @return self 값 객체
     */
    public static function classIcon(string $value): self
    {
        return new self(self::TYPE_CLASS, self::normalizeClass($value));
    }

    /**
     * 사이트 내부 경로 이미지 아이콘을 만듭니다.
     *
     * @param  string  $path  `/` 로 시작하는 사이트 경로
     * @return self 값 객체
     */
    public static function siteImage(string $path): self
    {
        return new self(self::TYPE_IMAGE, source: self::SOURCE_SITE, path: trim($path));
    }

    /**
     * 확장 패키지 기준 상대경로 이미지 아이콘을 만듭니다.
     *
     * @param  string  $path  확장 루트 기준 상대경로
     * @return self 값 객체
     */
    public static function packageImage(string $path): self
    {
        return new self(self::TYPE_IMAGE, source: self::SOURCE_PACKAGE, path: trim($path));
    }

    /**
     * 업로드 첨부 이미지 아이콘을 만듭니다.
     *
     * @param  int  $attachmentId  첨부 ID
     * @param  string  $hash  첨부 해시
     * @return self 값 객체
     */
    public static function attachmentImage(int $attachmentId, string $hash): self
    {
        return new self(self::TYPE_IMAGE, source: self::SOURCE_ATTACHMENT, attachmentId: $attachmentId, hash: $hash);
    }

    /**
     * 인라인 SVG 코드 아이콘을 만듭니다.
     *
     * @param  string  $svg  SVG 코드
     * @return self 값 객체
     */
    public static function svg(string $svg): self
    {
        return new self(self::TYPE_SVG, MenuIconSvgValidator::normalize($svg));
    }

    /**
     * 저장값(`menus.icon` 원문)을 해석합니다.
     *
     * `{` 로 시작하고 디코드 결과에 유효한 type 이 있으면 구조형, 아니면 class 로 읽는다.
     * 레거시 구제: JSON 이 아닌 문자열 중 사이트 이미지 경로 규칙을 통과하는 값(옛 API 로 저장된 `/storage/a.png`)은
     * image·site 로 읽는다.
     *
     * @param  string|null  $stored  저장값
     * @return self|null 값 객체, 비었으면 null
     */
    public static function fromStored(?string $stored): ?self
    {
        if ($stored === null) {
            return null;
        }

        $trimmed = trim($stored);

        if ($trimmed === '') {
            return null;
        }

        if ($trimmed[0] === '{') {
            $decoded = json_decode($trimmed, true);

            if (is_array($decoded)) {
                $spec = self::fromArray($decoded);

                if ($spec !== null) {
                    return $spec;
                }
            }
        }

        if (self::sitePathViolation($trimmed) === null) {
            return self::siteImage($trimmed);
        }

        return self::classIcon($trimmed);
    }

    /**
     * 배열(선언·요청 본문·디코드 JSON)을 해석합니다. 표시용 키(url 등)는 무시합니다.
     *
     * 형식만 본다 — 경로 규칙·SVG 안전성 검사는 호출부(규칙·선언 정규화기)의 몫이다.
     *
     * @param  array<string, mixed>  $data  아이콘 배열
     * @return self|null 값 객체, 형을 알 수 없거나 필수 값이 없으면 null
     */
    public static function fromArray(array $data): ?self
    {
        $type = $data['type'] ?? null;

        if ($type === self::TYPE_CLASS) {
            $value = $data['value'] ?? null;

            return is_string($value) && trim($value) !== '' ? self::classIcon($value) : null;
        }

        if ($type === self::TYPE_SVG) {
            $value = $data['value'] ?? null;

            return is_string($value) && trim($value) !== '' ? self::svg($value) : null;
        }

        if ($type !== self::TYPE_IMAGE) {
            return null;
        }

        $source = $data['source'] ?? null;

        if ($source === self::SOURCE_SITE || $source === self::SOURCE_PACKAGE) {
            $path = $data['path'] ?? null;

            if (! is_string($path) || trim($path) === '') {
                return null;
            }

            return $source === self::SOURCE_SITE ? self::siteImage($path) : self::packageImage($path);
        }

        if ($source === self::SOURCE_ATTACHMENT) {
            $id = $data['attachment_id'] ?? null;

            if (! is_numeric($id) || (int) $id <= 0) {
                return null;
            }

            $hash = $data['hash'] ?? '';

            return self::attachmentImage((int) $id, is_string($hash) ? $hash : '');
        }

        return null;
    }

    /**
     * 어떤 입력이든 정규 저장 문자열로 바꿉니다 (모델 뮤테이터·비교용).
     *
     * 문자열은 저장값으로 해석하고(레거시 class 문자열 ≡ 구조형 class), 배열은 fromArray 로 해석한다.
     * 해석할 수 없는 배열은 null, 비어 있는 값은 null 이다.
     *
     * @param  mixed  $value  입력값
     * @return string|null 정규 저장값
     */
    public static function canonicalize(mixed $value): ?string
    {
        if ($value instanceof self) {
            return $value->toStored();
        }

        if (is_array($value)) {
            return self::fromArray($value)?->toStored();
        }

        if (is_string($value)) {
            return self::fromStored($value)?->toStored();
        }

        return null;
    }

    /**
     * 입력이 현재 저장값과 같은 아이콘인지 판정합니다 (수정 요청의 되돌림 판정).
     *
     * @param  mixed  $value  요청의 icon_spec 배열 또는 레거시 icon 문자열
     * @param  string|null  $stored  메뉴의 현재 저장값(`menus.icon` 원문)
     * @return bool 같은 아이콘이면 true (저장값이 비었으면 false — 빈 값은 되돌릴 것이 없다)
     */
    public static function isUnchanged(mixed $value, ?string $stored): bool
    {
        $current = self::canonicalize($stored);

        if ($current === null) {
            return false;
        }

        if (self::canonicalize($value) === $current) {
            return true;
        }

        // 응답이 코드를 가린 위험 저장 SVG(`value: null`)를 편집 폼이 그대로 되돌려 보낸 것도 같은 아이콘이다
        return is_array($value)
            && ($value['type'] ?? null) === self::TYPE_SVG
            && ($value['value'] ?? null) === null
            && (self::fromStored($stored)?->isUnsafeSvg() ?? false);
    }

    /**
     * 정규 저장 문자열을 만듭니다.
     *
     * @return string 저장값
     */
    public function toStored(): string
    {
        if ($this->type === self::TYPE_CLASS) {
            return (string) $this->value;
        }

        $data = $this->toArray();
        ksort($data);

        return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * 저장용 배열(표시용 url 제외)을 만듭니다.
     *
     * @return array<string, mixed> 아이콘 배열
     */
    public function toArray(): array
    {
        return match (true) {
            $this->type === self::TYPE_CLASS => ['type' => self::TYPE_CLASS, 'value' => $this->value],
            $this->type === self::TYPE_SVG => ['type' => self::TYPE_SVG, 'value' => $this->value],
            $this->source === self::SOURCE_ATTACHMENT => [
                'type' => self::TYPE_IMAGE,
                'source' => self::SOURCE_ATTACHMENT,
                'attachment_id' => $this->attachmentId,
                'hash' => $this->hash,
            ],
            default => ['type' => self::TYPE_IMAGE, 'source' => $this->source, 'path' => $this->path],
        };
    }

    /**
     * SVG 코드가 입력 검사를 통과하지 못하는지 — 7.2.0 이하 API 로 저장된 JSON 모양 문자열은 입력 검사를 거치지 않았다.
     * 응답은 이런 코드를 싣지 않고(`value: null`), 그 가린 값의 되돌림은 같은 아이콘으로 본다.
     *
     * @return bool svg 이고 검사에 걸리면 true (svg 가 아니면 false)
     */
    public function isUnsafeSvg(): bool
    {
        return $this->type === self::TYPE_SVG && ! MenuIconSvgValidator::isSafe((string) $this->value);
    }

    /**
     * 호환 문자열 icon — class 면 클래스, image·svg 면 null (외부 관리자 템플릿은 FA 클래스만 기대한다).
     *
     * @return string|null 호환 문자열
     */
    public function legacyIcon(): ?string
    {
        return $this->type === self::TYPE_CLASS ? $this->value : null;
    }

    /**
     * FA 클래스 문자열이 형식에 맞는지 판정합니다.
     *
     * @param  string  $value  클래스 문자열
     * @return string|null 위반 코드(class_max|class_invalid), 위반 없으면 null
     */
    public static function classViolation(string $value): ?string
    {
        $normalized = self::normalizeClass($value);

        if (mb_strlen($normalized) > self::CLASS_MAX_LENGTH) {
            return 'class_max';
        }

        if ($normalized === '' || preg_match(self::CLASS_PATTERN, $normalized) !== 1) {
            return 'class_invalid';
        }

        return null;
    }

    /**
     * 사이트 내부 이미지 경로의 규칙 위반을 판정합니다.
     *
     * `/` 로 시작하는 사이트 내부 경로만 허용한다 — 외부 `http(s)://`·`//`·`data:`·`javascript:`,
     * 상위 탈출(`..`)·역슬래시·NUL·쿼리·허용 밖 확장자(svg 포함)는 거부한다.
     *
     * @param  string  $path  경로
     * @return string|null 위반 코드(empty|too_long|null_byte|backslash|external|traversal|query|whitespace|extension), 위반 없으면 null
     */
    public static function sitePathViolation(string $path): ?string
    {
        if (strpos($path, "\0") !== false) {
            return 'null_byte';
        }

        $trimmed = trim($path);

        if ($trimmed === '') {
            return 'empty';
        }

        if (mb_strlen($trimmed) > self::PATH_MAX_LENGTH) {
            return 'too_long';
        }

        if (strpos($trimmed, '\\') !== false) {
            return 'backslash';
        }

        if ($trimmed[0] !== '/' || str_starts_with($trimmed, '//')) {
            return 'external';
        }

        foreach (explode('/', $trimmed) as $segment) {
            if ($segment === '..' || $segment === '.') {
                return 'traversal';
            }
        }

        if (strpbrk($trimmed, '?#') !== false) {
            return 'query';
        }

        // 사이트 안 경로의 공백은 외부 주소가 아니라 경로 형식 오류다 — 운영자에게 「외부 주소」 문구가 가지 않게 따로 판정
        if (preg_match('/\s/u', $trimmed) === 1) {
            return 'whitespace';
        }

        $extension = strtolower((string) pathinfo($trimmed, PATHINFO_EXTENSION));

        if (! in_array($extension, self::IMAGE_EXTENSIONS, true)) {
            return 'extension';
        }

        return null;
    }

    /**
     * 확장 패키지 기준 상대경로 이미지의 규칙 위반을 판정합니다.
     *
     * 경로 규칙은 `ExtensionIconValidator::manifestPathViolation()`(절대경로·URL·상위 탈출·역슬래시·NUL)을
     * 재사용하고, 확장자만 이미지 아이콘 허용 목록으로 다시 판정한다(jpg·gif 허용, svg 거부).
     *
     * @param  string  $path  상대경로
     * @return string|null 위반 코드, 위반 없으면 null
     */
    public static function packagePathViolation(string $path): ?string
    {
        $violation = ExtensionIconValidator::manifestPathViolation($path);

        if ($violation !== null && $violation !== 'unsupported_extension') {
            return $violation;
        }

        if (mb_strlen(trim($path)) > self::PATH_MAX_LENGTH) {
            return 'too_long';
        }

        $extension = strtolower((string) pathinfo(trim($path), PATHINFO_EXTENSION));

        return in_array($extension, self::IMAGE_EXTENSIONS, true) ? null : 'extension';
    }

    /**
     * 활동 로그 표시용 요약을 만듭니다 — SVG 원문(최대 32KB)을 로그에 통째로 쌓지 않는다.
     *
     * class→값, site→`image:/…`, package→`image:package:…`, attachment→`image:attachment#id`,
     * svg→`svg:<sha1 앞 8자>(<N>B)`.
     *
     * @param  mixed  $value  저장값
     * @return string|null 요약
     */
    public static function activityLogSummary(mixed $value): ?string
    {
        $spec = is_string($value) ? self::fromStored($value) : (is_array($value) ? self::fromArray($value) : null);

        if ($spec === null) {
            return null;
        }

        return match (true) {
            $spec->type === self::TYPE_CLASS => $spec->value,
            $spec->type === self::TYPE_SVG => sprintf('svg:%s(%dB)', substr(sha1((string) $spec->value), 0, 8), strlen((string) $spec->value)),
            $spec->source === self::SOURCE_SITE => 'image:'.$spec->path,
            $spec->source === self::SOURCE_PACKAGE => 'image:package:'.$spec->path,
            default => 'image:attachment#'.$spec->attachmentId,
        };
    }

    /**
     * FA 클래스 문자열의 공백을 정규화합니다.
     *
     * @param  string  $value  클래스 문자열
     * @return string 정규화된 문자열
     */
    private static function normalizeClass(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
