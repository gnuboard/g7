<?php

namespace Modules\Sirsoft\Board\Http\Requests;

use App\Rules\ContentMediaFile;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Modules\Sirsoft\Board\Services\PostContentMediaService;

/**
 * 게시판 본문 미디어 업로드 요청 검증 (#883 4단계)
 *
 * 파일은 그 게시판 본문 미디어 정책(종류별 허용·확장자·파일당 MB)으로 검증한다(`ContentMediaFile`). 「파일 첨부 사용」
 * (`use_file_upload`)·첨부 확장자는 보지 않는다(D26). 임시 키는 필수다 — 본문 미디어는 늘 임시로 올리고 글 저장 때 이 키로
 * 잇는다(수정 화면도 같다). `post_id` 를 주면 그 글을 고칠 수 있는지 컨트롤러가 본다.
 */
class UploadContentMediaRequest extends FormRequest
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
        $board = app(BoardRepositoryInterface::class)->findBySlug((string) $this->route('slug'));

        $fileRules = ['required', 'file'];
        if ($board) {
            $fileRules[] = new ContentMediaFile(app(PostContentMediaService::class)->resolvePolicy($board));
        }

        return [
            'file' => $fileRules,
            'temp_key' => ['required', 'string', 'uuid'],
            'post_id' => ['nullable', 'integer', 'min:1'],
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
            'file' => __('sirsoft-board::validation.attributes.content_media.file'),
            'temp_key' => __('sirsoft-board::validation.attributes.content_media.temp_key'),
            'post_id' => __('sirsoft-board::validation.attributes.content_media.post_id'),
        ];
    }

    /**
     * 검증 메시지
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => __('sirsoft-board::validation.attachment.file_required'),
            'file.file' => __('sirsoft-board::validation.attachment.file_invalid'),
            'temp_key.required' => __('sirsoft-board::validation.content_media.temp_key_required'),
            'temp_key.uuid' => __('sirsoft-board::validation.content_media.temp_key_required'),
            'post_id.integer' => __('sirsoft-board::validation.attachment.post_id_invalid'),
        ];
    }
}
