<?php

namespace App\Support\ContentMedia;

use App\Enums\ContentMediaKind;

/**
 * 본문 미디어 정책 값 객체 — 종류별 허용·파일당 MB·확장자, 문서당 최대 개수 (#883 4단계, D7·D8)
 *
 * 형식은 코어가 정하고 값은 확장이 정한다(게시판은 게시판별, 페이지·상품은 모듈 설정). 확장 값이 없으면 그 확장의 기존 첨부
 * 정책으로 만든 대체 정책(`fromAttachmentSettings`)을 쓴다 — 이미지는 받고, 동영상·오디오는 코어 「본문 콘텐츠」 스위치를 따른다. 확장자는 언제나 종류별 후보
 * 집합(`ContentMediaKind::candidateExtensions`) 안으로 좁힌다. 본문 미디어 개수는 첨부 개수와 따로 센다(D8).
 *
 * 저장 형식(`toArray`, 확장 설정·게시판 컬럼):
 * `{image:{enabled,max_size_mb,extensions[]}, video:{…}, audio:{…}, max_per_document}`
 *
 * @since 7.1.5
 */
final class ContentMediaPolicy
{
    /** 문서당 최대 개수 기본값 (D8) */
    public const DEFAULT_MAX_PER_DOCUMENT = 30;

    /** 문서당 최대 개수 상한 */
    public const MAX_PER_DOCUMENT_LIMIT = 200;

    /** 파일당 MB 상한 */
    public const MAX_SIZE_MB_LIMIT = 2048;

    /**
     * @param  array<string, array{enabled: bool, max_size_mb: int, extensions: array<int, string>}>  $kinds  종류별 정책
     * @param  int  $maxPerDocument  문서당 최대 개수
     */
    private function __construct(
        private readonly array $kinds,
        private readonly int $maxPerDocument,
    ) {}

    /**
     * 저장된 정책 배열로 만듭니다. 값이 없으면(null·빈 배열) 대체 정책을 돌려줍니다.
     *
     * @param  array<string, mixed>|null  $value  저장 값
     * @param  self  $fallback  값이 없을 때 쓸 정책
     * @return self 정책
     */
    public static function fromArray(?array $value, self $fallback): self
    {
        if ($value === null || $value === []) {
            return $fallback;
        }

        $kinds = [];
        foreach (ContentMediaKind::cases() as $kind) {
            $raw = is_array($value[$kind->value] ?? null) ? $value[$kind->value] : [];
            $base = $fallback->kinds[$kind->value];
            $kinds[$kind->value] = [
                'enabled' => array_key_exists('enabled', $raw) ? (bool) $raw['enabled'] : $base['enabled'],
                'max_size_mb' => self::clampMb($raw['max_size_mb'] ?? $base['max_size_mb']),
                'extensions' => self::normalizeExtensions($kind, $raw['extensions'] ?? $base['extensions']),
            ];
        }

        return new self($kinds, self::clampMaxPerDocument($value['max_per_document'] ?? $fallback->maxPerDocument));
    }

    /**
     * 확장의 기존 첨부 정책으로 대체 정책을 만듭니다 — 세 종류를 모두 열고 동영상·오디오는 코어 스위치가 정한다 (D7·D21).
     *
     * 확장 규칙이 비어 있으면 동영상·오디오는 코어 「본문 콘텐츠」의 업로드 동영상·오디오 스위치를 따른다(`acceptsUpload` 가
     * `coreAllows` 와 함께 판정 — 코어 기본 꺼짐). 확장자는 첨부 허용 확장자와 종류 후보의 교집합이고, 교집합이 비면(첨부가
     * 문서만 받는 게시판 등) 그 종류 후보 전부를 쓴다 — 본문 미디어는 첨부 설정과 따로 판정하기 때문이다(D26). 파일당 크기는
     * 첨부 파일당 크기다.
     *
     * @param  int  $maxMb  첨부 파일당 MB
     * @param  array<int, string>  $extensions  첨부 허용 확장자
     * @param  int  $maxPerDocument  문서당 최대 개수
     * @return self 대체 정책
     */
    public static function fromAttachmentSettings(int $maxMb, array $extensions, int $maxPerDocument = self::DEFAULT_MAX_PER_DOCUMENT): self
    {
        $kinds = [];
        foreach (ContentMediaKind::cases() as $kind) {
            $narrowed = self::normalizeExtensions($kind, $extensions);
            if ($narrowed === []) {
                $narrowed = $kind->candidateExtensions();
            }

            $kinds[$kind->value] = [
                'enabled' => true,
                'max_size_mb' => self::clampMb($maxMb),
                'extensions' => $narrowed,
            ];
        }

        return new self($kinds, self::clampMaxPerDocument($maxPerDocument));
    }

    /**
     * 이 종류를 받는지 판정합니다(켜져 있고 확장자가 하나 이상).
     *
     * @param  ContentMediaKind  $kind  종류
     * @return bool 받으면 true
     */
    public function allows(ContentMediaKind $kind): bool
    {
        $policy = $this->kinds[$kind->value];

        return $policy['enabled'] && $policy['extensions'] !== [];
    }

    /**
     * 종류별 파일당 상한(KB).
     *
     * @param  ContentMediaKind  $kind  종류
     * @return int KB
     */
    public function maxSizeKb(ContentMediaKind $kind): int
    {
        return $this->kinds[$kind->value]['max_size_mb'] * 1024;
    }

    /**
     * 종류별 파일당 상한(MB).
     *
     * @param  ContentMediaKind  $kind  종류
     * @return int MB
     */
    public function maxSizeMb(ContentMediaKind $kind): int
    {
        return $this->kinds[$kind->value]['max_size_mb'];
    }

    /**
     * 종류별 허용 확장자.
     *
     * @param  ContentMediaKind  $kind  종류
     * @return array<int, string> 확장자
     */
    public function extensions(ContentMediaKind $kind): array
    {
        return $this->kinds[$kind->value]['extensions'];
    }

    /**
     * 문서당 최대 개수.
     *
     * @return int 개수
     */
    public function maxPerDocument(): int
    {
        return $this->maxPerDocument;
    }

    /**
     * 지금 업로드를 받는지 — 이 정책이 받고(`allows`) 코어 「본문 콘텐츠」 설정도 그 종류를 허용할 때.
     *
     * 화면에 내려주는 정책(`toClientArray` 의 `enabled`)과 같은 판정이다. 서버 검증이 이것을 쓰지 않으면 코어 설정으로
     * 동영상 업로드를 껐는데도 API 를 직접 부르면 올라간다.
     *
     * @param  ContentMediaKind  $kind  종류
     * @return bool 받으면 true
     */
    public function acceptsUpload(ContentMediaKind $kind): bool
    {
        return $this->allows($kind) && self::coreAllows($kind);
    }

    /**
     * 저장 형식 배열.
     *
     * @return array<string, mixed> 정책 배열
     */
    public function toArray(): array
    {
        return $this->kinds + ['max_per_document' => $this->maxPerDocument];
    }

    /**
     * 화면(편집기)에 내려줄 정책 — 계약 `ContentMediaPolicyPayload`.
     *
     * 파일당 MB 는 PHP 업로드 상한(`upload_max_filesize`·`post_max_size`)과 비교한 실효값이다 — 정책이 서버 상한보다 크면 그
     * 크기 파일은 413 이 나므로 화면이 미리 막게 한다. 동영상·오디오는 코어 「본문 콘텐츠」 설정의 업로드 후보가 꺼져 있으면
     * 확장 정책과 관계없이 끈다(`allow_uploaded_video`·`allow_uploaded_audio`, 기본 꺼짐 — D21).
     *
     * @return array{image: array, video: array, audio: array, maxPerDocument: int} 클라이언트 정책
     */
    public function toClientArray(): array
    {
        $serverMb = self::serverUploadLimitMb();
        $payload = [];
        foreach (ContentMediaKind::cases() as $kind) {
            $enabled = $this->acceptsUpload($kind);
            $extensions = $this->extensions($kind);
            $payload[$kind->value] = [
                'enabled' => $enabled,
                'maxSizeMb' => $serverMb !== null ? min($this->maxSizeMb($kind), $serverMb) : $this->maxSizeMb($kind),
                'extensions' => $extensions,
                'mimeTypes' => $kind->mimeTypesFor($extensions),
            ];
        }
        $payload['maxPerDocument'] = $this->maxPerDocument;

        return $payload;
    }

    /**
     * 코어 「본문 콘텐츠」 설정이 이 종류의 업로드를 허용하는지 — 이미지는 늘 허용.
     *
     * @param  ContentMediaKind  $kind  종류
     * @return bool 허용하면 true
     */
    public static function coreAllows(ContentMediaKind $kind): bool
    {
        return match ($kind) {
            ContentMediaKind::Image => true,
            ContentMediaKind::Video => (bool) g7_core_settings('content.allow_uploaded_video', false),
            ContentMediaKind::Audio => (bool) g7_core_settings('content.allow_uploaded_audio', false),
        };
    }

    /**
     * PHP 업로드 상한(MB) — 둘 중 작은 값, 알 수 없으면 null.
     *
     * @return int|null MB
     */
    public static function serverUploadLimitMb(): ?int
    {
        $limits = array_filter([
            self::iniBytes((string) ini_get('upload_max_filesize')),
            self::iniBytes((string) ini_get('post_max_size')),
        ], fn ($v) => $v !== null && $v > 0);

        return $limits === [] ? null : max(1, (int) floor(min($limits) / 1048576));
    }

    /**
     * php.ini 크기 표기(예: 64M)를 바이트로 바꿉니다.
     *
     * @param  string  $value  표기
     * @return int|null 바이트 (해석 불가면 null)
     */
    private static function iniBytes(string $value): ?int
    {
        $value = trim($value);
        if ($value === '' || ! preg_match('/^(\d+)\s*([KMG]?)/i', $value, $m)) {
            return null;
        }

        return (int) $m[1] * match (strtoupper($m[2])) {
            'G' => 1073741824,
            'M' => 1048576,
            'K' => 1024,
            default => 1,
        };
    }

    /**
     * 확장자를 그 종류 후보 안으로 좁힙니다.
     *
     * @param  ContentMediaKind  $kind  종류
     * @param  mixed  $extensions  확장자 목록
     * @return array<int, string> 좁힌 목록 (후보 순서)
     */
    private static function normalizeExtensions(ContentMediaKind $kind, mixed $extensions): array
    {
        $given = array_map(fn ($e) => strtolower(trim((string) $e)), is_array($extensions) ? $extensions : []);

        return array_values(array_filter($kind->candidateExtensions(), fn ($c) => in_array($c, $given, true)));
    }

    /**
     * 파일당 MB 를 1~상한으로 맞춥니다.
     *
     * @param  mixed  $value  값
     * @return int MB
     */
    private static function clampMb(mixed $value): int
    {
        return max(1, min(self::MAX_SIZE_MB_LIMIT, (int) $value));
    }

    /**
     * 문서당 최대 개수를 1~상한으로 맞춥니다.
     *
     * @param  mixed  $value  값
     * @return int 개수
     */
    private static function clampMaxPerDocument(mixed $value): int
    {
        return max(1, min(self::MAX_PER_DOCUMENT_LIMIT, (int) $value));
    }
}
