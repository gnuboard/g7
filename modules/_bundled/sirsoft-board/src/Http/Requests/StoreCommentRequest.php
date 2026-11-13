<?php

namespace Modules\Sirsoft\Board\Http\Requests;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Board\Enums\PostStatus;
use Modules\Sirsoft\Board\Fields\GuestAuthorFields;
use Modules\Sirsoft\Board\Http\Requests\Concerns\ProvidesFallbackRuleMessages;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Modules\Sirsoft\Board\Rules\BlockedKeywordsRule;
use Modules\Sirsoft\Board\Rules\CommentValidationRule;
use Modules\Sirsoft\Board\Rules\CooldownRule;

/**
 * 댓글 생성 요청 폼 검증
 */
class StoreCommentRequest extends FormRequest
{
    use ProvidesFallbackRuleMessages;

    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * @return bool 권한 검증 결과 (권한 체크는 미들웨어 위임, 항상 true)
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
     * @return void 반환값 없음
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
        $board = app(BoardRepositoryInterface::class)->findBySlug((string) $slug);

        if (! $board) {
            return [];
        }

        // 비회원 여부 확인 (request()->user()를 사용해야 PermissionMiddleware에서 설정한 사용자를 인식)
        $isGuest = ! $this->user();

        // 스팸/보안 설정에서 쿨다운 값 가져오기
        $spamSecurity = g7_module_settings('sirsoft-board', 'spam_security', []);
        $commentCooldown = (int) ($spamSecurity['comment_cooldown_seconds'] ?? 0);

        // 금지 키워드 목록 가져오기 (게시글과 동일하게 게시판 설정 기준)
        $blockedKeywords = $board->blocked_keywords ?? [];

        $rules = [
            'content' => [
                'required',
                'string',
                'min:'.($board->min_comment_length ?? 2),
                'max:'.($board->max_comment_length ?? 1000),
                new BlockedKeywordsRule($blockedKeywords),
                new CooldownRule('comment', $commentCooldown, $slug),
            ],
            'post_id' => ['bail', 'required', 'integer', new CommentValidationRule($slug, 'post')],
            'parent_id' => [
                'nullable',
                'integer',
                // 부모 댓글은 요청 경로의 게시글에 속해야 한다 (교차 게시글 부모 차단)
                new CommentValidationRule($slug, 'parent_comment', (int) $this->route('postId')),
            ],
            // depth는 서비스에서 자동 계산되므로 요청 데이터에서 받지 않음 (보안상 이유)
            'is_secret' => ['boolean'],
            // 요청으로 지정할 수 있는 상태는 published 뿐 — 블라인드·복구는 관리자 전용 엔드포인트의 몫이다
            'status' => ['nullable', 'string', Rule::in([PostStatus::Published->value])],
            // 비회원인 경우 author_name, password 필수
            'author_name' => [$isGuest ? 'required' : 'nullable', 'string', 'max:50'],
            'password' => [$isGuest ? 'required' : 'nullable', 'string', 'min:4', 'max:20'],
        ];

        // 이름과 비밀번호는 위의 고정 규칙이다. 게시판이 켠 그 밖의 비회원 작성자 항목(이메일 등)은
        // 항목 엔진이 사용, 필수 설정대로 규칙을 만든다(요청 키 `guest_fields.{항목 키}`).
        if ($isGuest) {
            $rules += app(GuestAuthorFields::class)->rules($board, GuestAuthorFields::SURFACE_COMMENT);
        }

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
        return $this->guestFieldTexts('messages') + [
            'content.required' => __('sirsoft-board::validation.comment.content.required'),
            'content.min' => __('sirsoft-board::validation.comment.content.min'),
            'content.max' => __('sirsoft-board::validation.comment.content.max'),
            'parent_id.integer' => __('sirsoft-board::validation.comment.parent_id.integer'),
            'author_name.required' => __('sirsoft-board::validation.comment.author_name.required'),
            'author_name.max' => __('sirsoft-board::validation.comment.author_name.max'),
            'password.required' => __('sirsoft-board::validation.comment.password.required'),
            'password.min' => __('sirsoft-board::validation.comment.password.min'),
            // 상태 조작 거부 — 코어 기본 문구(「선택한 상태이(가) …」) 대신 게시글과 같은 꼴의 전용 문구
            'status.in' => __('sirsoft-board::validation.comment.status.in'),
        ];
    }

    /**
     * 검증할 필드의 이름을 커스터마이징
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->guestFieldTexts('attributes') + [
            'content' => __('sirsoft-board::validation.attributes.comment.content'),
            'parent_id' => __('sirsoft-board::validation.attributes.comment.parent_id'),
            'is_secret' => __('sirsoft-board::validation.attributes.comment.is_secret'),
            'status' => __('sirsoft-board::validation.attributes.comment.status'),
            'author_name' => __('sirsoft-board::validation.attributes.comment.author_name'),
            'password' => __('sirsoft-board::validation.attributes.comment.password'),
        ];
    }

    /**
     * 비회원 작성자 항목의 검증 문구(속성 이름 또는 형식 오류 문구)를 반환합니다.
     *
     * @param  string  $kind  attributes | messages
     * @return array<string, string> 요청 속성 경로 => 문구 (회원 요청이거나 게시판이 없으면 빈 배열)
     */
    private function guestFieldTexts(string $kind): array
    {
        if ($this->user()) {
            return [];
        }

        $board = app(BoardRepositoryInterface::class)->findBySlug((string) $this->route('slug'));
        if (! $board) {
            return [];
        }

        $fields = app(GuestAuthorFields::class);

        return $kind === 'messages'
            ? $fields->messages($board, GuestAuthorFields::SURFACE_COMMENT)
            : $fields->attributes($board, GuestAuthorFields::SURFACE_COMMENT);
    }

    /**
     * 검증 통과 후 비밀번호를 해싱합니다.
     *
     * @param  string|null  $key  반환할 특정 검증 값의 키 (null 이면 전체)
     * @param  mixed  $default  키가 없을 때 반환할 기본값
     * @return array<string, mixed>|mixed 검증된 데이터 (키 지정 시 해당 값)
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
