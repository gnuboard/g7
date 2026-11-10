<?php

namespace Modules\Sirsoft\Page\Http\Requests;

use App\Rules\ContentMediaFile;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Sirsoft\Page\Services\PageContentMediaService;

/**
 * 페이지 본문 미디어 업로드 요청 검증 (#883 4단계)
 *
 * 파일은 페이지 본문 미디어 정책(모듈 설정 `content_media`, 없으면 첨부 정책의 이미지)으로 검증한다. 임시 키는 필수다 — 본문
 * 미디어는 늘 임시로 올리고 페이지 저장 때 이 키로 잇는다(수정 화면도 같다).
 */
class UploadPageContentMediaRequest extends FormRequest
{
    /**
     * 요청 권한 확인
     *
     * @return bool 항상 true (권한은 미들웨어에서 검증)
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
            'file' => ['required', 'file', new ContentMediaFile(app(PageContentMediaService::class)->resolvePolicy())],
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
            'file' => __('sirsoft-page::validation.attributes.content_media.file'),
            'temp_key' => __('sirsoft-page::validation.attributes.content_media.temp_key'),
        ];
    }
}
