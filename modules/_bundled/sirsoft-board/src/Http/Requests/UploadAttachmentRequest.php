<?php

namespace Modules\Sirsoft\Board\Http\Requests;

use App\Extension\HookManager;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Sirsoft\Board\Http\Requests\Concerns\ResolvesAllowedExtensions;
use Modules\Sirsoft\Board\Http\Requests\Concerns\ValidatesAttachmentCount;
use Modules\Sirsoft\Board\Models\Board;

/**
 * 게시판 첨부파일 업로드 요청 검증
 *
 * 첨부 개수 상한은 게시글 작성 Request 들과 같이 여기서도 선차단한다 — 최종 불변조건은
 * `AttachmentService::assertAttachmentCountWithin()` 이 담당하지만(모든 경로가 지나는
 * SSoT), 요청 계층에서 막으면 다른 검증 오류와 같은 422 필드 오류 형태로 돌아가 업로더가
 * 응답을 분기하지 않아도 된다. 두 계층은 합산 기준·우선순위가 동일하므로 판정이 갈리지 않는다.
 */
class UploadAttachmentRequest extends FormRequest
{
    use ResolvesAllowedExtensions;
    use ValidatesAttachmentCount;

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

        // 게시판 값이 비어 있으면(null 또는 []) 모듈 기본값으로 폴백한다.
        // 빈 배열을 그대로 쓰면 'mimes:' 빈 규칙이 되어 전 파일이 거부된다.
        $defaults = g7_module_settings('sirsoft-board', 'basic_defaults', []);
        $allowedExtensions = $this->resolveAllowedExtensions(
            $board->allowed_extensions,
            $defaults['allowed_extensions'] ?? null
        );

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
     * 첨부 개수 상한을 선차단합니다.
     *
     * @param  Validator  $validator  검증기
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // 필드 규칙(용량·확장자)이 이미 걸린 요청에는 개수 판정을 덧붙이지 않는다 —
            // 파일 자체가 거부될 요청까지 상한 초과로 보고하면 원인이 뒤바뀐다.
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $postId = $this->input('post_id');

            $this->assertSingleAttachmentSlotAvailable(
                Board::where('slug', $this->route('slug'))->first(),
                $postId !== null && $postId !== '' ? (int) $postId : null,
                $this->input('temp_key')
            );
        });
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
