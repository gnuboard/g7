<?php

namespace Modules\Sirsoft\Board\Http\Requests;

use App\Extension\HookManager;
use App\Models\Role;
use App\Models\User;
use App\Rules\LocaleRequiredTranslatable;
use App\Rules\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Sirsoft\Board\Enums\ReplyDeletePolicy;
use Modules\Sirsoft\Board\Http\Requests\Concerns\MapsBoardSettingAttributes;
use Modules\Sirsoft\Board\Http\Requests\Concerns\ProvidesFallbackRuleMessages;
use Modules\Sirsoft\Board\Http\Requests\Concerns\ReadsBoardLimits;
use Modules\Sirsoft\Board\Http\Requests\Concerns\ValidatesLengthRange;
use Modules\Sirsoft\Board\Rules\BoardTypeValidationRule;
use Modules\Sirsoft\Board\Rules\SlugUniqueRule;

class StoreBoardRequest extends FormRequest
{
    use MapsBoardSettingAttributes;
    use ProvidesFallbackRuleMessages;
    use ReadsBoardLimits;
    use ValidatesLengthRange;

    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * 권한 체크는 라우트의 permission 미들웨어에서 수행됩니다.
     *
     * @return bool 항상 true (권한은 미들웨어에서 검증)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 전에 기본값 병합 및 데이터 전처리
     *
     * 프론트엔드에서 값이 전달되지 않은 필드에 대해
     * 모듈 환경설정의 기본값을 자동으로 설정합니다.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $settings = g7_module_settings('sirsoft-board', 'basic_defaults', []);

        // 기본값 정의 (permissions는 빈 배열로 초기화 → Service에서 Manager/Step 주입)
        $defaults = [
            'is_active' => true,
            'type' => $settings['type'] ?? 'basic',
            'per_page' => $settings['per_page'] ?? 20,
            'per_page_mobile' => $settings['per_page_mobile'] ?? 15,
            'order_by' => $settings['order_by'] ?? 'created_at',
            'order_direction' => $settings['order_direction'] ?? 'DESC',
            'secret_mode' => $settings['secret_mode'] ?? 'disabled',
            'use_comment' => $settings['use_comment'] ?? true,
            'use_reply' => $settings['use_reply'] ?? true,
            'use_reaction' => $settings['use_reaction'] ?? true,
            'use_comment_reaction' => $settings['use_comment_reaction'] ?? true,
            'active_reaction_types' => $settings['active_reaction_types'] ?? [],
            'max_reply_depth' => $settings['max_reply_depth'] ?? 5,
            'reply_delete_policy' => $settings['reply_delete_policy'] ?? ReplyDeletePolicy::Cascade->value,
            'max_comment_depth' => $settings['max_comment_depth'] ?? 10,
            'use_file_upload' => $settings['use_file_upload'] ?? false,
            'comment_order' => $settings['comment_order'] ?? 'ASC',
            'notify_admin_on_post' => $settings['notify_admin_on_post'] ?? true,
            'notify_author' => $settings['notify_author'] ?? true,
            'permissions' => [],  // 빈 배열 → Service에서 Manager/Step 주입
        ];

        // 요청 데이터와 기본값 병합 (요청 데이터 우선)
        $data = array_merge($defaults, $this->all());

        // active_reaction_types 가 명시적 null 로 오면 빈 배열로 정규화한다 (validation 전).
        // boards.active_reaction_types 는 nullable·기본값 없음이라 값을 주지 않고 만든 게시판은
        // NULL 이고, 복제(copyBoard)는 $board->toArray() 로 그 NULL 을 **키와 함께** 실어 보낸다.
        // 이때 위 array_merge 의 기본값(?? [])은 키가 이미 있어 발동하지 않고, 규칙
        // ['sometimes','array'] 가 명시적 null 을 거부해 복제 저장이 422 가 된다.
        // 규칙을 nullable 로 넓히는 대신 여기서 정규화한다 — "null 도 유효" 라는 새 계약을
        // 만들지 않고, 컬럼의 array 캐스트 계약과도 일치한다.
        if (array_key_exists('active_reaction_types', $data) && $data['active_reaction_types'] === null) {
            $data['active_reaction_types'] = [];
        }

        // blocked_keywords가 문자열이면 배열로 변환 (validation 전)
        if (isset($data['blocked_keywords']) && is_string($data['blocked_keywords'])) {
            $keywords = array_filter(
                array_map('trim', explode(',', $data['blocked_keywords'])),
                fn ($value) => $value !== ''
            );
            $data['blocked_keywords'] = array_values($keywords);
        }

        // allowed_extensions가 문자열이면 배열로 변환 (validation 전)
        if (isset($data['allowed_extensions']) && is_string($data['allowed_extensions'])) {
            $extensions = array_filter(
                array_map('trim', explode(',', $data['allowed_extensions'])),
                fn ($value) => $value !== ''
            );
            $data['allowed_extensions'] = array_values($extensions);
        }

        // boolean 필드 캐스팅 (Toggle 컴포넌트가 "on"/"off" 문자열을 전송할 수 있음)
        $booleanFields = [
            'is_active', 'use_comment', 'use_reply', 'use_file_upload',
            'use_report', 'use_scrap', 'use_reaction', 'use_comment_reaction', 'show_view_count', 'is_notice',
            'notify_admin_on_post', 'notify_author',
        ];

        foreach ($booleanFields as $field) {
            if (isset($data[$field])) {
                $data[$field] = filter_var($data[$field], FILTER_VALIDATE_BOOLEAN);
            }
        }

        $this->merge($data);
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, mixed> 검증 규칙 배열
     */
    public function rules(): array
    {
        // config 기준 제한값 (폴백 기본치는 ReadsBoardLimits 트레이트가 단일 관리)
        $limits = $this->boardLimits();
        $perPageMin = $limits['per_page_min'];
        $perPageMax = $limits['per_page_max'];
        $maxFileSizeMax = $limits['max_file_size_max']; // MB
        $maxFileCountMax = $limits['max_file_count_max'];
        $categoryMax = $limits['category_max'];

        // 제목 길이 제한
        $minTitleLengthMin = $limits['min_title_length_min'];
        $minTitleLengthMax = $limits['min_title_length_max'];
        $maxTitleLengthMin = $limits['max_title_length_min'];
        $maxTitleLengthMax = $limits['max_title_length_max'];

        // 내용 길이 제한
        $minContentLengthMin = $limits['min_content_length_min'];
        $minContentLengthMax = $limits['min_content_length_max'];
        $maxContentLengthMin = $limits['max_content_length_min'];
        $maxContentLengthMax = $limits['max_content_length_max'];

        // 댓글 길이 제한
        $minCommentLengthMin = $limits['min_comment_length_min'];
        $minCommentLengthMax = $limits['min_comment_length_max'];
        $maxCommentLengthMin = $limits['max_comment_length_min'];
        $maxCommentLengthMax = $limits['max_comment_length_max'];

        // 답글/대댓글 깊이 제한
        $maxReplyDepthMin = $limits['max_reply_depth_min'];
        $maxReplyDepthMax = $limits['max_reply_depth_max'];
        $maxCommentDepthMin = $limits['max_comment_depth_min'];
        $maxCommentDepthMax = $limits['max_comment_depth_max'];

        // NEW 배지 표시 기간 (0 = 표시 안 함)
        $newDisplayHoursMin = $limits['new_display_hours_min'];
        $newDisplayHoursMax = $limits['new_display_hours_max'];

        $rules = [
            // 기본 정보 (name, description은 다국어 필드 - 사이트 기본 언어 값만 필수. 코어 LocaleRequiredTranslatable 규약(docs/backend/validation.md 「다국어 필드 검증 규칙」))
            'name' => ['required', 'array', new LocaleRequiredTranslatable(maxLength: 100)],
            'slug' => ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9-]*$/', new SlugUniqueRule],
            'description' => ['nullable', 'array', new TranslatableField(maxLength: 500)],
            'is_active' => ['sometimes', 'boolean'],
            'type' => ['required', 'string', 'max:50', new BoardTypeValidationRule],

            // 관리자 메뉴 추가 토글 (DB 컬럼 아님 - Service에서 메뉴 등록에 사용)
            'add_to_menu' => ['sometimes', 'boolean'],

            // 목록 설정
            'per_page' => ['required', 'integer', "min:{$perPageMin}", "max:{$perPageMax}"],
            'per_page_mobile' => ['required', 'integer', "min:{$perPageMin}", "max:{$perPageMax}"],
            'order_by' => ['required', 'in:created_at,view_count,title,author'],
            'order_direction' => ['required', 'in:ASC,DESC'],

            // 분류 설정 (개수 상한은 config 기준, 빈/공백 이름 차단)
            'categories' => ['nullable', 'array', "max:{$categoryMax}"],
            'categories.*' => ['string', 'filled', 'regex:/\S/', 'max:50'],

            // 기능 설정
            'show_view_count' => ['required', 'boolean'],
            'secret_mode' => ['required', 'in:disabled,enabled,always'],
            'use_comment' => ['required', 'boolean'],
            'use_reply' => ['required', 'boolean'],
            'use_report' => ['required', 'boolean'],
            'use_scrap' => ['sometimes', 'boolean'],
            'use_reaction' => ['required', 'boolean'],
            'use_comment_reaction' => ['sometimes', 'boolean'],
            'active_reaction_types' => ['sometimes', 'array'],
            'active_reaction_types.*' => ['string'],
            'comment_order' => ['required', 'in:ASC,DESC'],
            // 하한 기본값 0 = 신규 배지를 붙이지 않음 (Post::isNew() 가 항상 false). 아카이브형 게시판이 쓰는 정상 값이다.
            'new_display_hours' => ['nullable', 'integer', "min:{$newDisplayHoursMin}", "max:{$newDisplayHoursMax}"],

            // 제목 길이 제한
            'min_title_length' => ['nullable', 'integer', "min:{$minTitleLengthMin}", "max:{$minTitleLengthMax}"],
            'max_title_length' => ['nullable', 'integer', "min:{$maxTitleLengthMin}", "max:{$maxTitleLengthMax}"],

            // 내용 길이 제한
            'min_content_length' => ['nullable', 'integer', "min:{$minContentLengthMin}", "max:{$minContentLengthMax}"],
            'max_content_length' => ['nullable', 'integer', "min:{$maxContentLengthMin}", "max:{$maxContentLengthMax}"],

            // 댓글 길이 제한
            'min_comment_length' => ['nullable', 'integer', "min:{$minCommentLengthMin}", "max:{$minCommentLengthMax}"],
            'max_comment_length' => ['nullable', 'integer', "min:{$maxCommentLengthMin}", "max:{$maxCommentLengthMax}"],

            // 파일 업로드 설정 (max_file_size는 MB 단위로 저장)
            'use_file_upload' => ['required', 'boolean'],
            'max_file_size' => ['nullable', 'integer', 'min:1', "max:{$maxFileSizeMax}"],
            'max_file_count' => ['nullable', 'integer', 'min:1', "max:{$maxFileCountMax}"],
            // 허용 확장자: 첨부 사용 게시판은 최소 1개 필수 (빈 배열 저장 시 전 파일 거부되던 버그 방지).
            // 첨부 미사용 게시판은 검증 제외(exclude_if) — min:1 이 빈 배열에 무조건 걸리므로
            // required_if 만으로는 빈 값 허용이 불가하다. exclude_if 로 필드 자체를 검증에서 제거한다.
            'allowed_extensions' => ['exclude_if:use_file_upload,false', 'required', 'array', 'min:1'],
            'allowed_extensions.*' => ['string', 'max:10'],

            // 게시판 관리 인원 설정 (관리자는 최소 1명 필수, 스텝은 선택적)
            'board_manager_ids' => ['required', 'array', 'min:1'],
            'board_manager_ids.*' => ['uuid', Rule::exists(User::class, 'uuid')],
            'board_step_ids' => ['nullable', 'array'],
            'board_step_ids.*' => ['uuid', Rule::exists(User::class, 'uuid')],

            // 권한 설정 (없으면 Service에서 Manager/Step 주입, 등록 시 빈 배열이므로 roles 검증 불필요)
            'permissions' => ['sometimes', 'array'],
            'permissions.*.roles' => ['nullable', 'array'],
            'permissions.*.roles.*' => ['string', Rule::exists(Role::class, 'identifier')],

            // 답글/대댓글 깊이 제한
            'max_reply_depth' => ['nullable', 'integer', "min:{$maxReplyDepthMin}", "max:{$maxReplyDepthMax}"],
            'reply_delete_policy' => ['required', 'string', Rule::in(ReplyDeletePolicy::values())],
            'max_comment_depth' => ['nullable', 'integer', "min:{$maxCommentDepthMin}", "max:{$maxCommentDepthMax}"],

            // 알림 설정
            'notify_admin_on_post' => ['required', 'boolean'],
            'notify_author' => ['required', 'boolean'],

            // 보안 설정 (배열도 허용)
            'blocked_keywords' => ['nullable', 'array'],
            'blocked_keywords.*' => ['string', 'max:100'],
        ];

        // 훅: 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 필터 제공
        return HookManager::applyFilters('sirsoft-board.board.store_validation_rules', $rules, $this);
    }

    /**
     * 검증기에 교차 검증 규칙을 추가합니다.
     *
     * 길이 제한 필드의 min ≤ max 관계를 검증합니다.
     *
     * @param  Validator  $validator  검증기
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        $this->applyLengthRangeValidation($validator);
    }

    /**
     * 검증할 필드의 이름을 커스터마이징
     *
     * @return array<string, string> 필드명 → 표시명 매핑
     */
    public function attributes(): array
    {
        $attributes = [
            // 다국어 이름·설명 — 코어 다국어 필수 문구(「:attribute의 :language(:locale) 값은 필수입니다」)가 칸 이름을 싣는다.
            // 없으면 「name의 한국어(ko) 값은 필수입니다」처럼 필드 키가 그대로 보인다
            'name' => __('sirsoft-board::validation.attributes.board.name'),
            'description' => __('sirsoft-board::validation.attributes.board.description'),
            'blocked_keywords' => __('sirsoft-board::validation.attributes.board.blocked_keywords'),
            'add_to_menu' => __('sirsoft-board::validation.attributes.board.add_to_menu'),
        ];

        // 정수/설정 필드의 표시명 매핑 — 수정 요청과 같은 매핑을 쓴다. 없으면 생성 화면 오류 문구에
        // 「new display hours 필드는…」처럼 필드 키가 그대로 보인다
        $attributes = array_merge($attributes, $this->boardSettingAttributes());

        // 권한 필드에 대한 동적 속성 매핑
        $permissionDefinitions = config('sirsoft-board.board_permission_definitions', []);

        foreach ($permissionDefinitions as $permKey => $permData) {
            // 권한 키를 dot notation으로 변환 (admin.posts.read 그대로 또는 posts_read -> posts.read)
            $i18nKey = str_replace('_', '.', $permKey);

            // 다국어 권한 이름 조회
            $translationKey = "sirsoft-board::validation.permission_names.{$i18nKey}";
            $permissionName = __($translationKey);

            // 번역이 없으면 원래 키 사용
            if ($permissionName === $translationKey) {
                $permissionName = $permKey;
            }

            // permissions.{key}.roles 필드에 대한 속성 이름 지정
            $fieldKey = str_replace('.', '_', $permKey); // admin.posts.read -> admin_posts_read
            $attributes["permissions.{$fieldKey}.roles"] = "{$permissionName} ".__('sirsoft-board::validation.role_field_suffix');
        }

        return $attributes;
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string> 규칙 키 → 메시지 매핑
     */
    public function messages(): array
    {
        return [
            // slug 검증 메시지
            'slug.required' => __('sirsoft-board::validation.slug.required'),
            'slug.regex' => __('sirsoft-board::validation.slug.format'),
            'slug.max' => __('sirsoft-board::validation.slug.max'),

            // name 검증 메시지
            'name.required' => __('sirsoft-board::validation.name.required'),
            'name.max' => __('sirsoft-board::validation.name.max'),
            'name.string' => __('sirsoft-board::validation.name.string'),

            // type 검증 메시지
            'type.required' => __('sirsoft-board::validation.type.required'),

            // 목록 설정 검증 메시지
            'per_page.required' => __('sirsoft-board::validation.per_page.required'),
            'per_page.min' => __('sirsoft-board::validation.per_page.min'),
            'per_page.max' => __('sirsoft-board::validation.per_page.max'),
            'per_page_mobile.required' => __('sirsoft-board::validation.per_page_mobile.required'),
            'per_page_mobile.min' => __('sirsoft-board::validation.per_page_mobile.min'),
            'per_page_mobile.max' => __('sirsoft-board::validation.per_page_mobile.max'),

            // 정렬 설정 검증 메시지
            'order_by.required' => __('sirsoft-board::validation.order_by.required'),
            'order_by.in' => __('sirsoft-board::validation.order_by.in'),
            'order_direction.required' => __('sirsoft-board::validation.order_direction.required'),
            'order_direction.in' => __('sirsoft-board::validation.order_direction.in'),

            // 분류 검증 메시지
            'categories.array' => __('sirsoft-board::validation.categories.array'),
            'categories.max' => __('sirsoft-board::validation.categories.max'),
            'categories.*.max' => __('sirsoft-board::validation.categories.item_max'),
            'categories.*.filled' => __('sirsoft-board::validation.categories.item_required'),
            'categories.*.regex' => __('sirsoft-board::validation.categories.item_required'),

            // 기능 설정 검증 메시지
            'show_view_count.required' => __('sirsoft-board::validation.show_view_count.required'),
            'secret_mode.required' => __('sirsoft-board::validation.secret_mode.required'),
            'secret_mode.in' => __('sirsoft-board::validation.secret_mode.in'),
            'use_comment.required' => __('sirsoft-board::validation.use_comment.required'),
            'use_reply.required' => __('sirsoft-board::validation.use_reply.required'),
            'use_report.required' => __('sirsoft-board::validation.use_report.required'),

            // 제목 길이 제한 검증 메시지
            'min_title_length.min' => __('sirsoft-board::validation.min_title_length.min'),
            'min_title_length.max' => __('sirsoft-board::validation.min_title_length.max'),
            'max_title_length.min' => __('sirsoft-board::validation.max_title_length.min'),
            'max_title_length.max' => __('sirsoft-board::validation.max_title_length.max'),

            // 내용 길이 제한 검증 메시지
            'min_content_length.min' => __('sirsoft-board::validation.min_content_length.min'),
            'min_content_length.max' => __('sirsoft-board::validation.min_content_length.max'),
            'max_content_length.min' => __('sirsoft-board::validation.max_content_length.min'),
            'max_content_length.max' => __('sirsoft-board::validation.max_content_length.max'),

            // 댓글 길이 제한 검증 메시지
            'min_comment_length.min' => __('sirsoft-board::validation.min_comment_length.min'),
            'min_comment_length.max' => __('sirsoft-board::validation.min_comment_length.max'),
            'max_comment_length.min' => __('sirsoft-board::validation.max_comment_length.min'),
            'max_comment_length.max' => __('sirsoft-board::validation.max_comment_length.max'),

            // 답글/대댓글 깊이 검증 메시지
            'max_reply_depth.min' => __('sirsoft-board::validation.max_reply_depth.min'),
            'max_reply_depth.max' => __('sirsoft-board::validation.max_reply_depth.max'),
            'max_comment_depth.min' => __('sirsoft-board::validation.max_comment_depth.min'),
            'max_comment_depth.max' => __('sirsoft-board::validation.max_comment_depth.max'),

            // 파일 업로드 검증 메시지
            'use_file_upload.required' => __('sirsoft-board::validation.use_file_upload.required'),
            'max_file_size.min' => __('sirsoft-board::validation.max_file_size.min'),
            'max_file_size.max' => __('sirsoft-board::validation.max_file_size.max'),
            'max_file_count.min' => __('sirsoft-board::validation.max_file_count.min'),
            'max_file_count.max' => __('sirsoft-board::validation.max_file_count.max'),
            'allowed_extensions.required' => __('sirsoft-board::validation.allowed_extensions.min'),
            'allowed_extensions.min' => __('sirsoft-board::validation.allowed_extensions.min'),

            // 관리자 설정 검증 메시지
            'board_manager_ids.required' => __('sirsoft-board::validation.board_manager_ids.required'),
            'board_manager_ids.min' => __('sirsoft-board::validation.board_manager_ids.min'),

            // 권한 설정 검증 메시지
            'permissions.required' => __('sirsoft-board::validation.permissions.required'),
            'permissions.*.mode.required' => __('sirsoft-board::validation.permissions.mode.required'),
            'permissions.*.mode.in' => __('sirsoft-board::validation.permissions.mode.in'),
            'permissions.*.roles.*.exists' => __('sirsoft-board::validation.permissions.roles.exists'),

            // 알림 설정 검증 메시지
            'notify_admin_on_post.required' => __('sirsoft-board::validation.notify_admin_on_post.required'),
            'notify_author.required' => __('sirsoft-board::validation.notify_author.required'),

            // 보안 설정 검증 메시지
            'blocked_keywords.max' => __('sirsoft-board::validation.blocked_keywords.max'),
            'blocked_keywords.string' => __('sirsoft-board::validation.blocked_keywords.string'),
        ];
    }
}
