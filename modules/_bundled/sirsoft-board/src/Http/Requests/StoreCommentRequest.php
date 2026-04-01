<?php

namespace Modules\Sirsoft\Board\Http\Requests;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Board\Enums\PostStatus;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Rules\CommentValidationRule;
use Modules\Sirsoft\Board\Rules\CooldownRule;

/**
 * 댓글 생성 요청 폼 검증
 */
class StoreCommentRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 전 데이터 준비
     *
     * route 파라미터를 검증 데이터에 merge합니다.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'post_id' => $this->route('postId'),
        ]);
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

        if (! $board) {
            return [];
        }

        // 비회원 여부 확인 (request()->user()를 사용해야 PermissionMiddleware에서 설정한 사용자를 인식)
        $isGuest = ! $this->user();

        // 스팸/보안 설정에서 쿨다운 값 가져오기
        $spamSecurity = g7_module_settings('sirsoft-board', 'spam_security', []);
        $commentCooldown = (int) ($spamSecurity['comment_cooldown_seconds'] ?? 0);

        $rules = [
            'content' => [
                'required',
                'string',
                'min:'.($board->min_comment_length ?? 2),
                'max:'.($board->max_comment_length ?? 1000),
                new CooldownRule('comment', $commentCooldown, $slug),
            ],
            'post_id' => ['bail', 'required', 'integer', new CommentValidationRule($slug, 'post')],
            'parent_id' => [
                'nullable',
                'integer',
                new CommentValidationRule($slug, 'parent_comment'),
            ],
            // depth는 서비스에서 자동 계산되므로 요청 데이터에서 받지 않음 (보안상 이유)
            'is_secret' => ['boolean'],
            'status' => ['nullable', 'string', Rule::in(PostStatus::values())],
            // 비회원인 경우 author_name, password 필수
            'author_name' => [$isGuest ? 'required' : 'nullable', 'string', 'max:50'],
            'password' => [$isGuest ? 'required' : 'nullable', 'string', 'min:4', 'max:20'],
        ];

        // 훅: 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 필터 제공
        return HookManager::applyFilters('sirsoft-board.comment.store_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'content.required' => __('sirsoft-board::validation.comment.content.required'),
            'content.min' => __('sirsoft-board::validation.comment.content.min'),
            'content.max' => __('sirsoft-board::validation.comment.content.max'),
            'parent_id.integer' => __('sirsoft-board::validation.comment.parent_id.integer'),
            'author_name.required' => __('sirsoft-board::validation.comment.author_name.required'),
            'author_name.max' => __('sirsoft-board::validation.comment.author_name.max'),
            'password.required' => __('sirsoft-board::validation.comment.password.required'),
            'password.min' => __('sirsoft-board::validation.comment.password.min'),
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
            'content' => __('sirsoft-board::validation.attributes.comment.content'),
            'parent_id' => __('sirsoft-board::validation.attributes.comment.parent_id'),
            'is_secret' => __('sirsoft-board::validation.attributes.comment.is_secret'),
            'status' => __('sirsoft-board::validation.attributes.comment.status'),
            'author_name' => __('sirsoft-board::validation.attributes.comment.author_name'),
            'password' => __('sirsoft-board::validation.attributes.comment.password'),
        ];
    }

    /**
     * 검증 통과 후 비밀번호를 해싱합니다.
     *
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null): mixed
    {
        $validated = parent::validated($key, $default);

        // key가 지정된 경우 특정 값 반환
        if ($key !== null) {
            return $validated;
        }

        // 비밀번호가 있으면 해싱
        if (! empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        return $validated;
    }
}
