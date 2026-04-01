<?php

namespace Modules\Sirsoft\Board\Http\Requests;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Sirsoft\Board\Models\Board;

/**
 * 게시판 첨부파일 업로드 요청 검증
 */
class UploadAttachmentRequest extends FormRequest
{
    /**
     * 요청 권한 확인
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
        $slug = $this->route('slug');
        $board = Board::where('slug', $slug)->first();

        // 게시판이 없으면 기본 규칙 반환
        if (! $board) {
            return [
                'file' => ['required', 'file'],
                'post_id' => ['nullable', 'integer', 'min:1'],
                'collection' => ['sometimes', 'string', 'max:100'],
                'temp_key' => ['nullable', 'string', 'max:64'],
            ];
        }

        // 게시판 설정을 기반으로 한 검증 규칙
        $maxSizeMB = $board->max_file_size ?? 10;
        $maxSizeKB = $maxSizeMB * 1024;

        $allowedExtensions = $board->allowed_extensions ?? ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'zip'];
        $mimes = implode(',', $allowedExtensions);

        $rules = [
            'file' => ['required', 'file', 'max:'.$maxSizeKB, 'mimes:'.$mimes],
            'post_id' => ['nullable', 'integer', 'min:1'],
            'collection' => ['sometimes', 'string', 'max:100'],
            'temp_key' => ['nullable', 'string', 'max:64'],
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('sirsoft-board.attachment.upload_validation_rules', $rules, $this);
    }

    /**
     * 검증 메시지
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $slug = $this->route('slug');
        $board = Board::where('slug', $slug)->first();

        $maxSizeMB = $board?->max_file_size ?? 10;

        return [
            'file.required' => __('sirsoft-board::validation.attachment.file_required'),
            'file.file' => __('sirsoft-board::validation.attachment.file_invalid'),
            'file.max' => __('sirsoft-board::validation.attachment.file_max', ['max' => $maxSizeMB]),
            'file.mimes' => __('sirsoft-board::validation.attachment.file_mimes'),
            'post_id.required' => __('sirsoft-board::validation.attachment.post_id_required'),
            'post_id.integer' => __('sirsoft-board::validation.attachment.post_id_invalid'),
        ];
    }

    /**
     * 검증 전 데이터 준비
     */
    protected function prepareForValidation(): void
    {
        // query string으로 전달된 temp_key, post_id를 body로 merge
        $this->merge([
            'collection' => $this->collection ?? 'attachments',
            'temp_key' => $this->query('temp_key') ?? $this->temp_key,
            'post_id' => $this->query('post_id') ?? $this->post_id,
        ]);
    }
}
