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
use Modules\Sirsoft\Board\Http\Requests\Concerns\ValidatesLengthRange;
use Modules\Sirsoft\Board\Rules\BoardTypeValidationRule;
use Modules\Sirsoft\Board\Rules\PermissionRolesRequiredRule;

class UpdateBoardRequest extends FormRequest
{
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
     * 검증 전 데이터 전처리
     *
     * blocked_keywords가 문자열로 전송된 경우 배열로 변환합니다.
     */
    protected function prepareForValidation(): void
    {
        $data = $this->all();

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
            'use_report', 'show_view_count', 'is_notice',
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
     * slug는 수정 불가하므로 제외됩니다.
     * 부분 업데이트를 지원하기 위해 sometimes 규칙을 적용합니다.
     *
     * @return array<string, mixed> 검증 규칙 배열
     */
    public function rules(): array
    {
        // config에서 제한값 가져오기
        $limits = config('sirsoft-board.limits', []);
        $perPageMin = $limits['per_page_min'] ?? 5;
        $perPageMax = $limits['per_page_max'] ?? 100;
        $maxFileSizeMax = $limits['max_file_size_max'] ?? 200; // MB
        $maxFileCountMax = $limits['max_file_count_max'] ?? 20;
        $categoryMax = $limits['category_max'] ?? 50;

        // 제목 길이 제한
        $minTitleLengthMin = $limits['min_title_length_min'] ?? 0;
        $minTitleLengthMax = $limits['min_title_length_max'] ?? 200;
        $maxTitleLengthMin = $limits['max_title_length_min'] ?? 1;
        $maxTitleLengthMax = $limits['max_title_length_max'] ?? 200;

        // 내용 길이 제한
        $minContentLengthMin = $limits['min_content_length_min'] ?? 0;
        $minContentLengthMax = $limits['min_content_length_max'] ?? 10000;
        $maxContentLengthMin = $limits['max_content_length_min'] ?? 1;
        $maxContentLengthMax = $limits['max_content_length_max'] ?? 50000;

        // 댓글 길이 제한
        $minCommentLengthMin = $limits['min_comment_length_min'] ?? 0;
        $minCommentLengthMax = $limits['min_comment_length_max'] ?? 1000;
        $maxCommentLengthMin = $limits['max_comment_length_min'] ?? 1;
        $maxCommentLengthMax = $limits['max_comment_length_max'] ?? 1000;

        // 답글/대댓글 깊이 제한
        $maxReplyDepthMin = $limits['max_reply_depth_min'] ?? 1;
        $maxReplyDepthMax = $limits['max_reply_depth_max'] ?? 10;
        $maxCommentDepthMin = $limits['max_comment_depth_min'] ?? 0;
        $maxCommentDepthMax = $limits['max_comment_depth_max'] ?? 10;

        $rules = [
            // 기본 정보 (slug 제외, name/description은 다국어 필드)
            'name' => ['sometimes', 'required', 'array', new LocaleRequiredTranslatable(maxLength: 100)],
            'description' => ['sometimes', 'nullable', 'array', new TranslatableField(maxLength: 500)],
            'is_active' => ['sometimes', 'boolean'],
            'type' => ['sometimes', 'required', 'string', 'max:50', new BoardTypeValidationRule],

            // 관리자 메뉴 표시 토글 (DB 컬럼 아님 - Service에서 메뉴 추가/제거에 사용)
            'add_to_menu' => ['sometimes', 'boolean'],

            // 목록 설정
            'per_page' => ['sometimes', 'required', 'integer', "min:{$perPageMin}", "max:{$perPageMax}"],
            'per_page_mobile' => ['sometimes', 'required', 'integer', "min:{$perPageMin}", "max:{$perPageMax}"],
            'order_by' => ['sometimes', 'required', 'in:created_at,view_count,title,author'],
            'order_direction' => ['sometimes', 'required', 'in:ASC,DESC'],

            // 분류 설정 (개수 상한은 config 기준, 빈/공백 이름 차단)
            'categories' => ['sometimes', 'nullable', 'array', "max:{$categoryMax}"],
            'categories.*' => ['string', 'filled', 'regex:/\S/', 'max:50'],

            // 기능 설정
            'show_view_count' => ['sometimes', 'required', 'boolean'],
            'secret_mode' => ['sometimes', 'required', 'in:disabled,enabled,always'],
            'use_comment' => ['sometimes', 'required', 'boolean'],
            'use_reply' => ['sometimes', 'required', 'boolean'],
            'use_report' => ['sometimes', 'required', 'boolean'],
            'comment_order' => ['sometimes', 'required', 'in:ASC,DESC'],
            'new_display_hours' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:720'],

            // 제목 길이 제한
            'min_title_length' => ['sometimes', 'nullable', 'integer', "min:{$minTitleLengthMin}", "max:{$minTitleLengthMax}"],
            'max_title_length' => ['sometimes', 'nullable', 'integer', "min:{$maxTitleLengthMin}", "max:{$maxTitleLengthMax}"],

            // 내용 길이 제한
            'min_content_length' => ['sometimes', 'nullable', 'integer', "min:{$minContentLengthMin}", "max:{$minContentLengthMax}"],
            'max_content_length' => ['sometimes', 'nullable', 'integer', "min:{$maxContentLengthMin}", "max:{$maxContentLengthMax}"],

            // 댓글 길이 제한
            'min_comment_length' => ['sometimes', 'nullable', 'integer', "min:{$minCommentLengthMin}", "max:{$minCommentLengthMax}"],
            'max_comment_length' => ['sometimes', 'nullable', 'integer', "min:{$maxCommentLengthMin}", "max:{$maxCommentLengthMax}"],

            // 파일 업로드 설정 (max_file_size는 MB 단위로 저장)
            'use_file_upload' => ['sometimes', 'required', 'boolean'],
            'max_file_size' => ['sometimes', 'nullable', 'integer', 'min:1', "max:{$maxFileSizeMax}"],
            'max_file_count' => ['sometimes', 'nullable', 'integer', 'min:1', "max:{$maxFileCountMax}"],
            // 허용 확장자: 전송되면 최소 1개 필수 (빈 배열 저장 시 전 파일 거부되던 버그 방지).
            // 부분 수정을 위해 sometimes 유지, nullable 제거.
            'allowed_extensions' => ['sometimes', 'array', 'min:1'],
            'allowed_extensions.*' => ['string', 'max:10'],

            // 관리자 설정 (관리자는 최소 1명 필수, 스텝은 선택적)
            'board_manager_ids' => ['sometimes', 'required', 'array', 'min:1'],
            'board_manager_ids.*' => ['uuid', Rule::exists(User::class, 'uuid')],
            'board_step_ids' => ['sometimes', 'nullable', 'array'],
            'board_step_ids.*' => ['uuid', Rule::exists(User::class, 'uuid')],

            // 권한 설정 (각 권한에 최소 1개 역할 필수)
            'permissions' => ['sometimes', 'required', 'array', new PermissionRolesRequiredRule],
            'permissions.*.roles' => ['required', 'array', 'min:1'],
            'permissions.*.roles.*' => ['string', Rule::exists(Role::class, 'identifier')],

            // 답글/대댓글 깊이 제한
            'max_reply_depth' => ['sometimes', 'nullable', 'integer', "min:{$maxReplyDepthMin}", "max:{$maxReplyDepthMax}"],
            'max_comment_depth' => ['sometimes', 'nullable', 'integer', "min:{$maxCommentDepthMin}", "max:{$maxCommentDepthMax}"],

            // 알림 설정
            'notify_admin_on_post' => ['sometimes', 'required', 'boolean'],
            'notify_author' => ['sometimes', 'required', 'boolean'],

            // 보안 설정 (배열도 허용)
            'blocked_keywords' => ['sometimes', 'nullable', 'array'],
            'blocked_keywords.*' => ['string', 'max:100'],
        ];

        // 카테고리 삭제는 차단하지 않는다. 삭제된 분류의 게시글은 category 문자열이 그대로
        // 남아 게시판 분류 목록에서만 빠지며, 사용자/관리자 목록의 '미분류' 필터로 조회된다
        // (PostRepository: category === 'unclassified' → 미등록 분류 글 포함). 안내문으로 고지.

        // 훅: 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 필터 제공
        return HookManager::applyFilters('sirsoft-board.board.update_validation_rules', $rules, $this);
    }

    /**
     * 검증기에 교차 검증 규칙을 추가합니다.
     *
     * 길이 제한 필드의 min ≤ max 관계를 검증합니다. 두 값이 함께 전송될 때만
     * 비교하므로 부분 수정(sometimes)에서는 적용되지 않습니다.
     *
     * @param  Validator  $validator  검증기
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
            'blocked_keywords' => __('sirsoft-board::admin.form.fields.blocked_keywords.label'),
            'add_to_menu' => __('sirsoft-board::validation.attributes.board.add_to_menu'),
        ];

        // 정수/설정 필드의 표시명 매핑 (.integer 등 기본 메시지 폴백 시 :attribute 가
        // 영문 snake_case 로 노출되는 것을 차단). 기존 settings.basic_defaults 라벨을 재사용.
        // 라벨 배열의 키는 'basic_defaults.per_page' 형태(점 포함 단일 키)이므로
        // settings 배열을 통째로 받아 인덱싱한다 (__() 의 점 경로 분해로는 도달 불가).
        $settingLabels = __('sirsoft-board::validation.attributes.settings');

        if (is_array($settingLabels)) {
            $settingFieldKeys = [
                'per_page', 'per_page_mobile', 'order_by', 'order_direction',
                'secret_mode', 'use_comment', 'use_reply', 'use_report',
                'comment_order', 'show_view_count',
                'max_reply_depth', 'max_comment_depth',
                'min_title_length', 'max_title_length',
                'min_content_length', 'max_content_length',
                'min_comment_length', 'max_comment_length',
                'use_file_upload', 'max_file_size', 'max_file_count',
                'allowed_extensions', 'notify_admin_on_post', 'notify_author',
                'new_display_hours',
            ];

            foreach ($settingFieldKeys as $field) {
                $labelKey = "basic_defaults.{$field}";

                if (isset($settingLabels[$labelKey])) {
                    $attributes[$field] = $settingLabels[$labelKey];
                }
            }
        }

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
            // name 검증 메시지
            'name.required' => __('sirsoft-board::validation.name.required'),
            'name.max' => __('sirsoft-board::validation.name.max'),

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
        ];
    }
}
