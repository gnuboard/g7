<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Rules\ContentMediaFile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\ContentMediaOwnerType;
use Modules\Sirsoft\Ecommerce\Services\ContentMediaService;

/**
 * 이커머스 본문 미디어 업로드 요청 검증 (#883 4단계)
 *
 * 소유 종류(상품 설명·공통 정보)와 임시 키는 필수다. 파일은 본문 미디어 정책으로 검증한다(내용 판정).
 */
class UploadContentMediaRequest extends FormRequest
{
    /**
     * 요청 권한 확인
     *
     * @return bool 항상 true (권한은 미들웨어·컨트롤러에서 검증)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'owner_type' => ['required', 'string', Rule::in(ContentMediaOwnerType::values())],
            'file' => ['required', 'file', new ContentMediaFile(app(ContentMediaService::class)->resolvePolicy())],
            'temp_key' => ['required', 'string', 'uuid'],
        ];
    }

    /**
     * 검증 속성 표시명
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'owner_type' => __('sirsoft-ecommerce::validation.attributes.content_media.owner_type'),
            'file' => __('sirsoft-ecommerce::validation.attributes.content_media.file'),
            'temp_key' => __('sirsoft-ecommerce::validation.attributes.content_media.temp_key'),
        ];
    }
}
