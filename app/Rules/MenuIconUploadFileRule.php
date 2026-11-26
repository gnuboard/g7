<?php

namespace App\Rules;

use App\Services\MenuIconService;
use App\Support\Files\FileContentSniffer;
use App\Support\MenuIcon\MenuIconSvgValidator;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * 메뉴 아이콘 업로드 파일 내용 검증 규칙.
 *
 * - 이름이 .svg 면 내용도 SVG 여야 하고 인라인 SVG 규칙(32KB·위험 요소)을 통과해야 한다.
 * - 이름이 래스터면 내용이 SVG 가 아니어야 하고(`.png` 로 이름 바꾼 SVG 거부) 이미지로 읽혀야 한다.
 *
 * @since 7.2.1
 */
class MenuIconUploadFileRule implements ValidationRule
{
    /**
     * 업로드 파일을 검증합니다.
     *
     * @param  string  $attribute  검증 대상 필드명
     * @param  mixed  $value  검증 대상 값
     * @param  Closure  $fail  실패 콜백
     * @return void 반환값 없음
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            return;
        }

        $inferred = FileContentSniffer::extension($value);

        if (MenuIconService::isSvgUpload($value)) {
            if ($inferred !== 'svg') {
                $fail(__('validation.menu.icon_upload.file_type'));

                return;
            }

            $violations = MenuIconSvgValidator::violations((string) file_get_contents($value->getRealPath()));

            if ($violations === ['too_large']) {
                $fail(__('validation.menu.icon_upload.svg_too_large', ['max' => (int) (MenuIconSvgValidator::MAX_BYTES / 1024)]));
            } elseif ($violations !== []) {
                $fail(__('validation.menu.icon_upload.svg_unsafe', ['reasons' => MenuIconSvgValidator::describe($violations)]));
            }

            return;
        }

        if ($inferred === 'svg') {
            $fail(__('validation.menu.icon_upload.file_type'));

            return;
        }

        if (@getimagesize($value->getRealPath()) === false) {
            $fail(__('validation.menu.icon_upload.raster_unreadable'));
        }
    }
}
