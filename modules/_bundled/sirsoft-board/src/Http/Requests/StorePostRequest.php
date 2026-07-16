<?php

namespace Modules\Sirsoft\Board\Http\Requests;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Board\Enums\PostStatus;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Rules\BlockedKeywordsRule;
use Modules\Sirsoft\Board\Rules\CooldownRule;
use Modules\Sirsoft\Board\Rules\ParentPostValidationRule;

/**
 * 게시글 생성 요청 폼 검증
 */
class StorePostRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $slug = $this->route('slug');
        $board = Board::where('slug', $slug)->first();

        // 게시판이 없으면 모듈 환경설정 기본값 사용
        $defaults = g7_module_settings('sirsoft-board', 'basic_defaults', []);

        $minTitleLength = $board->min_title_length ?? $defaults['min_title_length'] ?? 2;
        $maxTitleLength = $board->max_title_length ?? $defaults['max_title_length'] ?? 200;
        $minContentLength = $board->min_content_length ?? $defaults['min_content_length'] ?? 10;
        $maxContentLength = $board->max_content_length ?? $defaults['max_content_length'] ?? 10000;

        // 금지 키워드 목록 가져오기
        $blockedKeywords = $board->blocked_keywords ?? [];

        // 스팸/보안 설정에서 쿨다운 값 가져오기
        $spamSecurity = g7_module_settings('sirsoft-board', 'spam_security', []);
        $postCooldown = (int) ($spamSecurity['post_cooldown_seconds'] ?? 0);

        $rules = [
            'title' => [
                'required',
                'string',
                'min:'.$minTitleLength,
                'max:'.$maxTitleLength,
                new BlockedKeywordsRule($blockedKeywords),
                new CooldownRule('post', $postCooldown, $slug),
            ],
            'content' => [
                'required',
                'string',
                'min:'.$minContentLength,
                'max:'.$maxContentLength,
                new BlockedKeywordsRule($blockedKeywords),
            ],
            'content_mode' => ['nullable', 'string', 'in:text,html'],
            'category' => ['nullable', 'string', 'max:50'],
            'is_notice' => ['nullable', 'boolean'],
            'is_secret' => ['nullable', 'boolean'],
            'status' => ['nullable', 'string', Rule::in(PostStatus::values())],
            // user_id는 Controller에서 Auth::id()로 자동 설정되므로 검증하지 않음
            'author_name' => ['nullable', 'string', 'max:50'],
            'password' => ['nullable', 'string', 'min:4'],
            // temp_key는 게시판 존재 여부와 무관하게 항상 허용
            'temp_key' => ['nullable', 'string', 'max:64'],
        ];

        // 게시판이 있는 경우에만 parent_id와 attachment_ids 검증
        if ($board) {
            $rules['parent_id'] = [
                'nullable',
                'integer',
                Rule::exists(Post::class, 'id')->where('board_id', $board->id),
                new ParentPostValidationRule($slug),
            ];
            $rules['attachment_ids'] = ['nullable', 'array'];
            $rules['attachment_ids.*'] = ['integer', 'min:1'];
        }

        // 파일 업로드 검증 규칙 (게시판 설정에 따라)
        $useFileUpload = $board->use_file_upload ?? $defaults['use_file_upload'] ?? false;
        if ($useFileUpload) {
            $maxFiles = $board->max_file_count ?? $defaults['max_file_count'] ?? 5;
            $maxSizeMB = $board->max_file_size ?? $defaults['max_file_size'] ?? 10;
            $maxSizeKB = $maxSizeMB * 1024;
            $allowedExtensions = $board->allowed_extensions ?? $defaults['allowed_extensions'] ?? ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'zip'];
            $mimes = implode(',', $allowedExtensions);

            $rules['files'] = ['nullable', 'array', 'max:'.$maxFiles];
            $rules['files.*'] = ['file', 'max:'.$maxSizeKB, 'mimes:'.$mimes];
        }

        // 훅: 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 필터 제공
        return HookManager::applyFilters('sirsoft-board.post.store_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => __('sirsoft-board::validation.post.title.required'),
            'title.min' => __('sirsoft-board::validation.post.title.min'),
            'title.max' => __('sirsoft-board::validation.post.title.max'),
            'content.required' => __('sirsoft-board::validation.post.content.required'),
            'content.min' => __('sirsoft-board::validation.post.content.min'),
            'content.max' => __('sirsoft-board::validation.post.content.max'),
            'category.max' => __('sirsoft-board::validation.post.category.max'),
            'status.in' => __('sirsoft-board::validation.post.status.in'),
            'author_name.max' => __('sirsoft-board::validation.post.author_name.max'),
            'password.min' => __('sirsoft-board::validation.post.password.min'),
            'parent_id.exists' => __('sirsoft-board::validation.post.parent_id.exists'),
            'files.array' => __('sirsoft-board::validation.post.files.array'),
            'files.max' => __('sirsoft-board::validation.post.files.max'),
            'files.*.file' => __('sirsoft-board::validation.post.files.file'),
            'files.*.max' => __('sirsoft-board::validation.post.files.file_max'),
            'files.*.mimes' => __('sirsoft-board::validation.post.files.mimes'),
        ];
    }

    /**
     * 검증할 필드의 이름을 커스터마이징
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => __('sirsoft-board::validation.attributes.post.title'),
            'content' => __('sirsoft-board::validation.attributes.post.content'),
            'content_mode' => __('sirsoft-board::validation.attributes.post.content_mode'),
            'category' => __('sirsoft-board::validation.attributes.post.category'),
            'is_notice' => __('sirsoft-board::validation.attributes.post.is_notice'),
            'is_secret' => __('sirsoft-board::validation.attributes.post.is_secret'),
            'status' => __('sirsoft-board::validation.attributes.post.status'),
            'author_name' => __('sirsoft-board::validation.attributes.post.author_name'),
            'password' => __('sirsoft-board::validation.attributes.post.password'),
            'parent_id' => __('sirsoft-board::validation.attributes.post.parent_id'),
            'files' => __('sirsoft-board::validation.attributes.post.files'),
            'files.*' => __('sirsoft-board::validation.attributes.post.file'),
        ];
    }
}
