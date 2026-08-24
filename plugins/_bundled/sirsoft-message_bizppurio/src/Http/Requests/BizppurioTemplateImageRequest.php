<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * 이미지형 알림톡 템플릿 이미지 업로드 검증 (#597 §3.2 · 부록 A-7).
 *
 * kapi 이미지 업로드 제약(jpg/png · ≤500KB · 가로 ≥500px · 가로:세로 2:1)을 프록시
 * 단계에서 사전 검증한다 — kapi 왕복 없이 즉시 인라인 오류를 돌려준다. 비율은 Laravel
 * dimensions:ratio 가 부동소수 오차에 관대하지 않아 after 훅에서 직접 판정한다.
 */
class BizppurioTemplateImageRequest extends FormRequest
{
    /** 최대 파일 크기 (KB) — kapi 제약 500KB */
    private const MAX_KILOBYTES = 500;

    /** 최소 가로 픽셀 — kapi 제약 500px */
    private const MIN_WIDTH = 500;

    /**
     * 권한은 라우트 미들웨어(messaging.manage)에서 처리한다.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'image' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png',
                'max:'.self::MAX_KILOBYTES,
                'dimensions:min_width='.self::MIN_WIDTH,
            ],
        ];
    }

    /**
     * 가로:세로 = 2:1 비율을 after 훅으로 검증합니다.
     *
     * @param  Validator  $validator  검증기
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $file = $this->file('image');
            if ($file === null || ! $file->isValid()) {
                return;
            }

            $size = @getimagesize((string) $file->getRealPath());
            if ($size === false) {
                return;
            }

            [$width, $height] = $size;
            if ($height <= 0 || abs(($width / $height) - 2.0) > 0.01) {
                $v->errors()->add('image', __('sirsoft-message_bizppurio::messages.validation.image_ratio_invalid'));
            }
        });
    }

    /**
     * 검증 오류 문구에 쓰일 필드 라벨을 반환합니다.
     *
     * @return array<string, string> 필드 경로 → 라벨
     */
    public function attributes(): array
    {
        $label = static fn (string $key): string => __("sirsoft-message_bizppurio::messages.validation.attributes.{$key}");

        return [
            'image' => $label('image'),
        ];
    }
}
