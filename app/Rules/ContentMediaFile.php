<?php

namespace App\Rules;

use App\Enums\ContentMediaKind;
use App\Support\ContentMedia\ContentMediaPolicy;
use App\Support\Files\FileContentSniffer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * 본문 미디어 파일 검증 — 정책이 허용한 종류·확장자·크기인지 (#883 4단계)
 *
 * 내용으로 추론한 MIME(`FileContentSniffer::mimeType` — finfo, `AttachmentFileContent` 와 같은 내용 판정 입구)과 파일 이름
 * 확장자가 **모두** 같은 종류의 허용 목록에 들어야 통과한다 — 이름만 바꾼 SVG·HTML 은 내용 추론으로 거부된다. 크기는 종류별
 * 상한이다.
 *
 * @since 7.1.5
 */
class ContentMediaFile implements ValidationRule
{
    /**
     * @param  ContentMediaPolicy  $policy  적용할 정책
     */
    public function __construct(private readonly ContentMediaPolicy $policy) {}

    /**
     * 업로드 파일을 검증합니다.
     *
     * @param  string  $attribute  필드명
     * @param  mixed  $value  값
     * @param  Closure  $fail  실패 콜백
     * @return void 반환값 없음
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail(__('attachment.content_media.validation.file_invalid'));

            return;
        }

        $message = $this->firstViolation($value);
        if ($message !== null) {
            $fail($message);
        }
    }

    /**
     * 서버 안에서 만든 파일(업로드 파일 필터가 돌려준 변환본 등)을 같은 규칙으로 판정합니다 (#887).
     *
     * HTTP 업로드가 아닌 파일이라 `isValid()`(업로드 여부) 대신 실제 파일이 있는지만 본다. 나머지 판정(빈 파일·내용 종류·
     * 이름 확장자·크기)은 `validate()` 와 같다.
     *
     * @param  ContentMediaPolicy  $policy  적용할 정책
     * @param  UploadedFile  $file  파일
     * @return array<int, string> 실패 문구 (통과면 빈 배열)
     */
    public static function violations(ContentMediaPolicy $policy, UploadedFile $file): array
    {
        $path = $file->getRealPath();
        if ($path === false || ! is_file($path)) {
            return [__('attachment.content_media.validation.file_invalid')];
        }

        $message = (new self($policy))->firstViolation($file);

        return $message === null ? [] : [$message];
    }

    /**
     * 파일 내용 MIME 으로 종류를 판정합니다(이름이 아니라 내용).
     *
     * @param  UploadedFile  $file  업로드 파일
     * @return ContentMediaKind|null 종류 (후보 밖이면 null)
     */
    public static function detectKind(UploadedFile $file): ?ContentMediaKind
    {
        return ContentMediaKind::fromMime(self::sniffMime($file));
    }

    /**
     * 파일 내용으로 MIME 을 추론합니다(finfo). 클라이언트가 보낸 Content-Type·이름은 보지 않는다.
     *
     * 판정은 `FileContentSniffer::mimeType()` 한 곳에서 한다(#887) — 이 메서드는 확장이 쓰던 호출 지점을 위해 남긴다.
     *
     * @param  UploadedFile  $file  업로드 파일
     * @return string|null MIME (알 수 없으면 null)
     */
    public static function sniffMime(UploadedFile $file): ?string
    {
        return FileContentSniffer::mimeType($file);
    }

    /**
     * 빈 파일·내용 종류·이름 확장자·크기를 차례로 판정해 첫 실패 문구를 돌려줍니다.
     *
     * @param  UploadedFile  $value  파일
     * @return string|null 실패 문구 (통과면 null)
     */
    private function firstViolation(UploadedFile $value): ?string
    {
        if ((int) $value->getSize() === 0) {
            return __('attachment.validation.file_empty');
        }

        $kind = self::detectKind($value);
        if ($kind === null || ! $this->policy->acceptsUpload($kind)) {
            return __('attachment.content_media.validation.kind_not_allowed', ['allowed' => $this->allowedSummary()]);
        }

        $nameExtension = strtolower((string) $value->getClientOriginalExtension());
        $allowed = $this->policy->extensions($kind);
        // 내용 MIME 이 허용 확장자들의 MIME 가운데 있어야 한다 — 이름만 바꾼 파일(예: 내용은 webm 인 .mp4)을 거른다
        $inferredOk = in_array((string) self::sniffMime($value), $kind->mimeTypesFor($allowed), true);

        if (! in_array($nameExtension, $allowed, true) || ! $inferredOk) {
            return __('attachment.content_media.validation.extension_not_allowed', [
                'kind' => __("attachment.content_media.kind.{$kind->value}"),
                'extensions' => implode(', ', $allowed),
            ]);
        }

        if ((int) ceil($value->getSize() / 1024) > $this->policy->maxSizeKb($kind)) {
            return __('attachment.content_media.validation.file_too_large', [
                'kind' => __("attachment.content_media.kind.{$kind->value}"),
                'max' => $this->policy->maxSizeMb($kind),
            ]);
        }

        return null;
    }

    /**
     * 허용되는 종류·확장자 요약 문구.
     *
     * @return string 요약
     */
    private function allowedSummary(): string
    {
        $parts = [];
        foreach (ContentMediaKind::cases() as $kind) {
            if ($this->policy->acceptsUpload($kind)) {
                $parts[] = __("attachment.content_media.kind.{$kind->value}").'('.implode(', ', $this->policy->extensions($kind)).')';
            }
        }

        return $parts === [] ? '-' : implode(' · ', $parts);
    }
}
