<?php

namespace App\Rules;

use App\Enums\ContentMediaKind;
use App\Support\ContentMedia\ContentMediaPolicy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * 본문 미디어 파일 검증 — 정책이 허용한 종류·확장자·크기인지 (#883 4단계)
 *
 * 내용으로 추론한 MIME(finfo, `AttachmentFileContent` 와 같은 기준)과 파일 이름 확장자가 **모두** 같은 종류의 허용 목록에
 * 들어야 통과한다 — 이름만 바꾼 SVG·HTML 은 내용 추론으로 거부된다. 크기는 종류별 상한이다.
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

        if ((int) $value->getSize() === 0) {
            $fail(__('attachment.validation.file_empty'));

            return;
        }

        $kind = self::detectKind($value);
        if ($kind === null || ! $this->policy->acceptsUpload($kind)) {
            $fail(__('attachment.content_media.validation.kind_not_allowed', ['allowed' => $this->allowedSummary()]));

            return;
        }

        $nameExtension = strtolower((string) $value->getClientOriginalExtension());
        $allowed = $this->policy->extensions($kind);
        // 내용 MIME 이 허용 확장자들의 MIME 가운데 있어야 한다 — 이름만 바꾼 파일(예: 내용은 webm 인 .mp4)을 거른다
        $inferredOk = in_array((string) self::sniffMime($value), $kind->mimeTypesFor($allowed), true);

        if (! in_array($nameExtension, $allowed, true) || ! $inferredOk) {
            $fail(__('attachment.content_media.validation.extension_not_allowed', [
                'kind' => __("attachment.content_media.kind.{$kind->value}"),
                'extensions' => implode(', ', $allowed),
            ]));

            return;
        }

        if ((int) ceil($value->getSize() / 1024) > $this->policy->maxSizeKb($kind)) {
            $fail(__('attachment.content_media.validation.file_too_large', [
                'kind' => __("attachment.content_media.kind.{$kind->value}"),
                'max' => $this->policy->maxSizeMb($kind),
            ]));
        }
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
     * `UploadedFile::getMimeType()` 은 구현에 따라 이름 확장자로 답할 수 있어(시험용 가짜 파일 등) 내용 판정의 근거가 되지 못한다.
     * finfo 를 쓸 수 없는 환경에서만 `getMimeType()` 으로 물러난다.
     *
     * @param  UploadedFile  $file  업로드 파일
     * @return string|null MIME (알 수 없으면 null)
     */
    public static function sniffMime(UploadedFile $file): ?string
    {
        $path = $file->getRealPath();
        if ($path !== false && is_file($path) && class_exists(\finfo::class)) {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
            if (is_string($mime) && $mime !== '') {
                return strtolower($mime);
            }
        }

        $mime = $file->getMimeType();

        return $mime === null ? null : strtolower($mime);
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
