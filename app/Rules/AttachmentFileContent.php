<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * 첨부 파일의 **내용**이 허용 형식인지 검증하는 Custom Rule (KVE-2026-2366).
 *
 * 파일 내용으로 추론한 확장자(`guessExtension()`)가 허용 목록에 있어야 통과한다 — Laravel `mimes:` 와
 * 같은 판정이다. 이름만 이미지로 바꾼 SVG(`evil.png`)는 추론값이 svg 라 거부된다.
 *
 * `mimes:` 와 다른 점은 둘이다.
 * - 원래 텍스트인 형식(md·log·csv 등)은 내용이 일반 텍스트로만 추론되므로(`txt`), 그 이름의
 *   파일에 한해 `txt` 추론도 인정한다. eps 는 PostScript 로 추론되어 `ai` 로 나오므로 같이 인정한다.
 *   SVG·HTML 은 추론값이 `txt` 가 아니므로 이 대응으로 통과하지 않는다.
 * - 0바이트 파일은 내용으로 형식을 알 수 없어 형식 불일치 대신 빈 파일 안내로 거부한다.
 *
 * @since 7.1.0
 */
class AttachmentFileContent implements ValidationRule
{
    /**
     * 이름 확장자별로 추가 인정하는 내용 추론 확장자
     *
     * @var array<string, array<int, string>>
     */
    public const CONTENT_ALIASES = [
        'md' => ['txt'],
        'markdown' => ['txt'],
        'log' => ['txt'],
        'csv' => ['txt'],
        'tsv' => ['txt'],
        'eps' => ['ai'],
    ];

    /**
     * AttachmentFileContent 생성자
     *
     * @param  array<int, string>  $allowedExtensions  허용 확장자 목록 (소문자, 비어 있지 않음)
     */
    public function __construct(
        private readonly array $allowedExtensions,
    ) {}

    /**
     * 업로드 파일의 내용 추론 확장자가 허용 목록(과 이름별 대응)에 드는지 검증합니다.
     *
     * @param  string  $attribute  검증 대상 필드명
     * @param  mixed  $value  검증 대상 값 (UploadedFile 이 아니면 `file` 규칙이 처리하므로 통과)
     * @param  Closure  $fail  실패 콜백
     * @return void 반환값 없음
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            return;
        }

        if ((int) $value->getSize() === 0) {
            $fail(__('attachment.validation.file_empty'));

            return;
        }

        $inferred = $value->guessExtension();

        if ($inferred === null || ! in_array($inferred, $this->acceptedContentExtensions($value), true)) {
            $fail(__('attachment.validation.file_mimetype_invalid', [
                'extensions' => implode(', ', $this->allowedExtensions),
            ]));
        }
    }

    /**
     * 이 파일에 대해 인정하는 내용 추론 확장자 목록을 만듭니다.
     *
     * @param  UploadedFile  $file  업로드 파일
     * @return array<int, string> 인정 확장자 목록
     */
    private function acceptedContentExtensions(UploadedFile $file): array
    {
        $accepted = $this->allowedExtensions;

        // `mimes:` 와 같이 jpg·jpeg 는 한쪽만 허용해도 서로 인정한다 (추론값은 jpg 로 나온다).
        if (in_array('jpg', $accepted, true) || in_array('jpeg', $accepted, true)) {
            $accepted = array_merge($accepted, ['jpg', 'jpeg']);
        }

        $nameExtension = strtolower($file->getClientOriginalExtension());

        if (in_array($nameExtension, $this->allowedExtensions, true)) {
            $accepted = array_merge($accepted, self::CONTENT_ALIASES[$nameExtension] ?? []);
        }

        return array_values(array_unique($accepted));
    }
}
