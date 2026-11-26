<?php

namespace App\Rules;

use App\Support\ExtensionIcon\ExtensionIconValidator;
use App\Support\Favicon\FaviconPolicy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * 파비콘 업로드 파일의 내용 검증
 *
 * 파비콘 컬렉션은 ico·svg 를 받는다(전역 첨부 허용 목록과 무관). 그래서 그 두 형식은 내용까지 본다.
 * 크기 상한은 1MB({@see FaviconPolicy::MAX_SIZE_KB}).
 *
 * - svg: 확장 아이콘과 같은 검사기({@see ExtensionIconValidator::validateBytes()}) — 스크립트·이벤트 속성·외부 참조·
 *   foreignObject·DOCTYPE 금지, 100KB 이하. 그 검사기의 정사각 요구(`not_square`)만 쓰지 않는다. 거부 사유는 검사기
 *   코드가 아니라 확장 아이콘과 같은 번역 문구(`extensions.icon.warnings.*`)로 알린다.
 *   파비콘 경로는 CSP 로도 내보내지만 업로드 단계에서 먼저 막는다.
 * - ico: 매직바이트 `00 00 01 00` (아이콘 리소스 헤더). 이름만 `.ico` 인 다른 파일을 거른다.
 *
 * 래스터(png·gif·jpg·webp)는 이름·내용 일치 검사(`AttachmentFileContent`)로 충분하다 — 확장 아이콘과 달리
 * 최소 크기·정사각 요구는 없다(16·32px 파비콘이 정상이다).
 */
class FaviconFileRule implements ValidationRule
{
    /**
     * 파비콘 svg 에 적용하지 않는 검사기 오류 코드 (정사각 요구)
     */
    private const SQUARE_ERROR = 'not_square';

    /**
     * 파일 내용을 검증합니다.
     *
     * @param  string  $attribute  검증 대상 필드명
     * @param  mixed  $value  업로드 파일 (UploadedFile 이 아니면 `file` 규칙이 처리하므로 통과)
     * @param  Closure  $fail  실패 콜백
     * @return void 반환값 없음
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            return;
        }

        if ((int) $value->getSize() > FaviconPolicy::MAX_SIZE_KB * 1024) {
            $fail(__('attachment.validation.favicon_too_large', ['max' => FaviconPolicy::MAX_SIZE_KB / 1024]));

            return;
        }

        $nameExtension = strtolower($value->getClientOriginalExtension());
        $inferred = $value->guessExtension();
        $bytes = (string) @file_get_contents($value->getRealPath());

        if ($nameExtension === 'svg' || $inferred === 'svg') {
            // 정사각 요구는 확장 아이콘 규칙이라 뺀다(래스터와 같이 파비콘에는 크기 요구가 없다)
            $errors = array_values(array_diff(
                ExtensionIconValidator::validateBytes($bytes, 'svg')['errors'],
                [self::SQUARE_ERROR],
            ));

            if ($errors !== []) {
                $reasons = array_map(fn (string $code): string => __('extensions.icon.warnings.'.$code), $errors);
                $fail(__('attachment.validation.favicon_svg_unsafe', ['reasons' => implode(' ', $reasons)]));
            }

            return;
        }

        if ($nameExtension === 'ico' || $inferred === 'ico') {
            if (strncmp($bytes, "\x00\x00\x01\x00", 4) !== 0) {
                $fail(__('attachment.validation.favicon_ico_invalid'));
            }
        }
    }
}
