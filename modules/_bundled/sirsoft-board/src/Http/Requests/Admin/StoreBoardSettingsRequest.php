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

        $data = $this->all();

        foreach ($integerFields as $field) {
            [$category, $key] = explode('.', $field, 2);
            if (isset($data[$category][$key]) && is_string($data[$category][$key]) && $data[$category][$key] !== '') {
                $data[$category][$key] = intval($data[$category][$key]);
            }
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
        return [
            // 현재 탭 정보 (메타 데이터)
            '_tab' => ['sometimes', 'string', 'in:basic_defaults,report_policy,spam_security,general'],

            // ========================================
            // basic_defaults (기본 설정) 카테고리
            // ========================================
            'basic_defaults' => ['sometimes', 'array'],
            'basic_defaults.type' => ['nullable', 'string', 'max:50'],
            'basic_defaults.per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'basic_defaults.per_page_mobile' => ['nullable', 'integer', 'min:1', 'max:100'],
            'basic_defaults.order_by' => ['nullable', 'string', 'in:created_at,view_count,title,author'],
            'basic_defaults.order_direction' => ['nullable', 'string', 'in:ASC,DESC'],
            'basic_defaults.secret_mode' => ['nullable', 'string', 'in:disabled,enabled,always'],
            'basic_defaults.use_comment' => ['nullable', 'boolean'],
            'basic_defaults.use_reply' => ['nullable', 'boolean'],
            'basic_defaults.max_reply_depth' => ['nullable', 'integer', 'min:1', 'max:5'],
            'basic_defaults.max_comment_depth' => ['nullable', 'integer', 'min:0', 'max:10'],
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
            'basic_defaults.allowed_extensions' => ['nullable', 'array'],
            'basic_defaults.allowed_extensions.*' => ['string', 'max:20'],
            'basic_defaults.notify_admin_on_post' => ['nullable', 'boolean'],
            'basic_defaults.notify_author' => ['nullable', 'boolean'],
            'basic_defaults.notify_admin_on_post_channels' => ['nullable', 'array'],
            'basic_defaults.notify_admin_on_post_channels.*' => ['string', 'in:mail'],
            'basic_defaults.notify_author_channels' => ['nullable', 'array'],
            'basic_defaults.notify_author_channels.*' => ['string', 'in:mail'],
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
            'report_policy.notify_admin_on_report_channels.*' => ['string', 'in:mail'],
            'report_policy.notify_author_on_report_action' => ['nullable', 'boolean'],
            'report_policy.notify_author_on_report_action_channels' => ['nullable', 'array'],
            'report_policy.notify_author_on_report_action_channels.*' => ['string', 'in:mail'],

            // ========================================
            // report_permissions (신고 관리 권한) — 설정값이 아닌 DB 권한 데이터
            // validatedSettings()에서 제외됨
            // ========================================
            'report_permissions'                => ['sometimes', 'array'],
            'report_permissions.view_roles'     => ['nullable', 'array'],
            'report_permissions.view_roles.*'   => ['string', Rule::exists(Role::class, 'identifier')],
            'report_permissions.manage_roles'   => ['nullable', 'array'],
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

        return is_array($messages) ? $messages : [];
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
        $validCategories = ['basic_defaults', 'report_policy', 'spam_security', 'display'];

        return array_filter(
            $validated,
            fn ($key) => in_array($key, $validCategories),
            ARRAY_FILTER_USE_KEY
        );
    }
}
