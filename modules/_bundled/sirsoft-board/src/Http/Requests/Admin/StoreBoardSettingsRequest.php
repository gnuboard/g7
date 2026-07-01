<?php

namespace Modules\Sirsoft\Board\Http\Requests\Admin;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 게시판 환경설정 저장 요청 검증
 *
 * 카테고리별 설정값을 검증합니다.
 * 권한 체크는 라우트의 permission 미들웨어에서 수행됩니다.
 */
class StoreBoardSettingsRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * 권한 체크는 라우트의 permission 미들웨어에서 수행됩니다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 전 입력 데이터 정규화
     *
     * 앞에 0이 붙은 숫자 문자열("00232" → 232)을 integer로 캐스팅합니다.
     */
    protected function prepareForValidation(): void
    {
        $integerFields = [
            'basic_defaults.per_page',
            'basic_defaults.per_page_mobile',
            'basic_defaults.max_reply_depth',
            'basic_defaults.max_comment_depth',
            'basic_defaults.min_title_length',
            'basic_defaults.max_title_length',
            'basic_defaults.min_content_length',
            'basic_defaults.max_content_length',
            'basic_defaults.min_comment_length',
            'basic_defaults.max_comment_length',
            'basic_defaults.max_file_size',
            'basic_defaults.max_file_count',
            'basic_defaults.new_display_hours',
            'report_policy.auto_hide_threshold',
            'report_policy.daily_report_limit',
            'report_policy.rejection_limit_count',
            'report_policy.rejection_limit_days',
            'spam_security.post_cooldown_seconds',
            'spam_security.comment_cooldown_seconds',
            'spam_security.report_cooldown_seconds',
            'spam_security.view_count_cache_ttl',
        ];

        $booleanFields = [
            'basic_defaults.use_comment',
            'basic_defaults.use_reply',
            'basic_defaults.show_view_count',
            'basic_defaults.use_report',
            'basic_defaults.use_file_upload',
            'basic_defaults.notify_admin_on_post',
            'basic_defaults.notify_author',
            'report_policy.notify_admin_on_report',
            'report_policy.notify_author_on_report_action',
            'seo.seo_boards',
            'seo.seo_board',
            'seo.seo_post_detail',
        ];

        $data = $this->all();

        foreach ($integerFields as $field) {
            [$category, $key] = explode('.', $field, 2);
            if (isset($data[$category][$key]) && is_string($data[$category][$key]) && $data[$category][$key] !== '') {
                $data[$category][$key] = intval($data[$category][$key]);
            }
        }

        foreach ($booleanFields as $field) {
            [$category, $key] = explode('.', $field, 2);
            if (isset($data[$category][$key])) {
                $data[$category][$key] = filter_var($data[$category][$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        // default_board_permissions 는 flat 키(값 = 역할 식별자 배열) 구조만 허용한다.
        // 레거시 오염 데이터로 nested 그룹 키(admin/posts/comments/attachments, 값 = 중첩 객체)가
        // 섞여 들어오면 권한 설정 화면에 raw i18n 키와 [object Object] 로 노출되므로,
        // 값이 순차 배열(역할 목록)이 아닌 항목은 저장 전에 제거해 재오염을 차단한다.
        $permissions = $data['basic_defaults']['default_board_permissions'] ?? null;
        if (is_array($permissions)) {
            $data['basic_defaults']['default_board_permissions'] = array_filter(
                $permissions,
                fn ($roles) => is_array($roles) && array_is_list($roles)
            );
        }

        $this->replace($data);
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $limits = config('sirsoft-board.limits', []);
        $perPageMin = $limits['per_page_min'] ?? 5;
        $perPageMax = $limits['per_page_max'] ?? 100;
        $maxReplyDepthMin = $limits['max_reply_depth_min'] ?? 1;
        $maxReplyDepthMax = $limits['max_reply_depth_max'] ?? 10;
        $maxCommentDepthMin = $limits['max_comment_depth_min'] ?? 0;
        $maxCommentDepthMax = $limits['max_comment_depth_max'] ?? 10;

        return [
            // 현재 탭 정보 (메타 데이터)
            '_tab' => ['sometimes', 'string', 'in:basic_defaults,report_policy,spam_security,general,seo,notifications,notification_definitions'],

            // ========================================
            // notifications (알림 채널 설정) 카테고리
            // ========================================
            'notifications' => ['sometimes', 'array'],
            'notifications.channels' => ['sometimes', 'array'],
            'notifications.channels.*.id' => ['required_with:notifications.channels', 'string', 'max:50'],
            'notifications.channels.*.is_active' => ['required_with:notifications.channels', 'boolean'],
            'notifications.channels.*.sort_order' => ['nullable', 'integer', 'min:0'],

            // ========================================
            // basic_defaults (기본 설정) 카테고리
            // ========================================
            'basic_defaults' => ['sometimes', 'array'],
            'basic_defaults.type' => ['nullable', 'string', 'max:50'],
            'basic_defaults.per_page' => ['nullable', 'integer', "min:{$perPageMin}", "max:{$perPageMax}"],
            'basic_defaults.per_page_mobile' => ['nullable', 'integer', "min:{$perPageMin}", "max:{$perPageMax}"],
            'basic_defaults.order_by' => ['nullable', 'string', 'in:created_at,view_count,title,author'],
            'basic_defaults.order_direction' => ['nullable', 'string', 'in:ASC,DESC'],
            'basic_defaults.secret_mode' => ['nullable', 'string', 'in:disabled,enabled,always'],
            'basic_defaults.use_comment' => ['nullable', 'boolean'],
            'basic_defaults.use_reply' => ['nullable', 'boolean'],
            'basic_defaults.max_reply_depth' => ['nullable', 'integer', "min:{$maxReplyDepthMin}", "max:{$maxReplyDepthMax}"],
            'basic_defaults.max_comment_depth' => ['nullable', 'integer', "min:{$maxCommentDepthMin}", "max:{$maxCommentDepthMax}"],
            'basic_defaults.comment_order' => ['nullable', 'string', 'in:ASC,DESC'],
            'basic_defaults.show_view_count' => ['nullable', 'boolean'],
            'basic_defaults.use_report' => ['nullable', 'boolean'],
            'basic_defaults.min_title_length' => ['nullable', 'integer', 'min:1', 'max:200'],
            'basic_defaults.max_title_length' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'basic_defaults.min_content_length' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'basic_defaults.max_content_length' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'basic_defaults.min_comment_length' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'basic_defaults.max_comment_length' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'basic_defaults.use_file_upload' => ['nullable', 'boolean'],
            'basic_defaults.max_file_size' => ['nullable', 'integer', 'min:1', 'max:200'],
            'basic_defaults.max_file_count' => ['nullable', 'integer', 'min:1', 'max:20'],
            'basic_defaults.blocked_keywords' => ['nullable', 'array'],
            'basic_defaults.blocked_keywords.*' => ['string', 'max:100'],
            // 허용 확장자는 최소 1개 필수 (빈 배열 차단 — 빈 값 저장 시 전 파일 거부되던 버그 방지).
            // 부분 탭 저장을 위해 sometimes 유지, nullable 제거.
            'basic_defaults.allowed_extensions' => ['sometimes', 'array', 'min:1'],
            'basic_defaults.allowed_extensions.*' => ['string', 'max:20'],
            'basic_defaults.notify_admin_on_post' => ['nullable', 'boolean'],
            'basic_defaults.notify_author' => ['nullable', 'boolean'],
            'basic_defaults.new_display_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
            // default_board_permissions는 flat key 구조 (예: {"posts.read": ["admin","user"], "manager": ["admin"]})
            // Laravel dot notation으로 하위 키를 개별 검증하면 flat key가 중첩 배열로 파싱되어 데이터 유실됨
            // → 배열 자체만 검증 (하위 키 개별 검증 금지)
            'basic_defaults.default_board_permissions' => ['nullable', 'array'],

            // ========================================
            // report_policy (신고 정책) 카테고리
            // ========================================
            'report_policy' => ['sometimes', 'array'],
            'report_policy.auto_hide_threshold' => ['nullable', 'integer', 'min:0', 'max:100'],
            'report_policy.auto_hide_target' => ['nullable', 'string', 'in:post,comment,both'],
            'report_policy.daily_report_limit' => ['nullable', 'integer', 'min:0', 'max:100'],
            'report_policy.rejection_limit_count' => ['nullable', 'integer', 'min:0', 'max:50'],
            'report_policy.rejection_limit_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'report_policy.notify_admin_on_report' => ['nullable', 'boolean'],
            'report_policy.notify_admin_on_report_scope' => ['nullable', 'string', 'in:per_case,per_report'],
            'report_policy.notify_admin_on_report_channels' => ['nullable', 'array'],
            'report_policy.notify_admin_on_report_channels.*' => ['string', 'in:mail,database'],
            'report_policy.notify_author_on_report_action' => ['nullable', 'boolean'],
            'report_policy.notify_author_on_report_action_channels' => ['nullable', 'array'],
            'report_policy.notify_author_on_report_action_channels.*' => ['string', 'in:mail,database'],

            // ========================================
            // report_permissions (신고 관리 권한) — 설정값이 아닌 DB 권한 데이터
            // validatedSettings()에서 제외됨
            // ========================================
            'report_permissions' => ['sometimes', 'array'],
            'report_permissions.view_roles' => ['required_with:report_permissions', 'array', 'min:1'],
            'report_permissions.view_roles.*' => ['string', Rule::exists(Role::class, 'identifier')],
            'report_permissions.manage_roles' => ['required_with:report_permissions', 'array', 'min:1'],
            'report_permissions.manage_roles.*' => ['string', Rule::exists(Role::class, 'identifier')],

            // ========================================
            // display (표시 설정) 카테고리
            // ========================================
            'display' => ['sometimes', 'array'],
            'display.date_display_format' => ['nullable', 'string', 'in:standard,relative'],

            // ========================================
            // spam_security (스팸/보안) 카테고리
            // ========================================
            'spam_security' => ['sometimes', 'array'],
            'spam_security.post_cooldown_seconds' => ['nullable', 'integer', 'min:0', 'max:3600'],
            'spam_security.comment_cooldown_seconds' => ['nullable', 'integer', 'min:0', 'max:3600'],
            'spam_security.report_cooldown_seconds' => ['nullable', 'integer', 'min:0', 'max:3600'],
            'spam_security.view_count_cache_ttl' => ['nullable', 'integer', 'min:60', 'max:604800'],

            // ========================================
            // seo (SEO 설정) 카테고리
            // ========================================
            'seo' => ['sometimes', 'array'],
            'seo.meta_boards_title' => ['nullable', 'string', 'max:500'],
            'seo.meta_boards_description' => ['nullable', 'string', 'max:1000'],
            'seo.meta_board_title' => ['nullable', 'string', 'max:500'],
            'seo.meta_board_description' => ['nullable', 'string', 'max:1000'],
            'seo.meta_post_title' => ['nullable', 'string', 'max:500'],
            'seo.meta_post_description' => ['nullable', 'string', 'max:1000'],
            'seo.seo_boards' => ['nullable', 'boolean'],
            'seo.seo_board' => ['nullable', 'boolean'],
            'seo.seo_post_detail' => ['nullable', 'boolean'],
        ];
    }

    /**
     * 검증 속성명 다국어 처리
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = __('sirsoft-board::validation.attributes.settings');

        return is_array($attributes) ? $attributes : [];
    }

    /**
     * 검증 오류 메시지 다국어 처리
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = __('sirsoft-board::validation.settings');
        $base = is_array($messages) ? $messages : [];

        return array_merge($base, [
            'basic_defaults.allowed_extensions.min' => __('sirsoft-board::validation.allowed_extensions.min'),
            'report_permissions.view_roles.required_with' => __('sirsoft-board::validation.report_permissions.view_roles.required_with'),
            'report_permissions.view_roles.min' => __('sirsoft-board::validation.report_permissions.view_roles.min'),
            'report_permissions.manage_roles.required_with' => __('sirsoft-board::validation.report_permissions.manage_roles.required_with'),
            'report_permissions.manage_roles.min' => __('sirsoft-board::validation.report_permissions.manage_roles.min'),
        ]);
    }

    /**
     * 검증된 데이터에서 카테고리 설정만 추출
     *
     * 최상위 레벨 오염 데이터(_tab 등)를 제외하고
     * 유효한 카테고리만 반환합니다.
     *
     * @return array<string, array<string, mixed>>
     */
    public function validatedSettings(): array
    {
        $validated = $this->validated();
        $validCategories = ['basic_defaults', 'report_policy', 'spam_security', 'display', 'seo', 'notifications'];

        return array_filter(
            $validated,
            fn ($key) => in_array($key, $validCategories),
            ARRAY_FILTER_USE_KEY
        );
    }
}
