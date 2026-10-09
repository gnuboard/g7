<?php

namespace Modules\Sirsoft\Board\Http\Requests\Admin;

use App\Models\Attachment;
use App\Models\Role;
use App\Rules\LocaleRequiredTranslatable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Sirsoft\Board\Enums\ReplyDeletePolicy;
use Modules\Sirsoft\Board\Http\Requests\Concerns\ProvidesFallbackRuleMessages;
use Modules\Sirsoft\Board\Http\Requests\Concerns\ReadsBoardLimits;
use Modules\Sirsoft\Board\Models\ReactionType;
use Modules\Sirsoft\Board\Repositories\Contracts\ReactionTypeRepositoryInterface;
use Modules\Sirsoft\Board\Services\ReactionTypeService;

/**
 * 게시판 환경설정 저장 요청 검증
 *
 * 카테고리별 설정값을 검증합니다.
 * 권한 체크는 라우트의 permission 미들웨어에서 수행됩니다.
 */
class StoreBoardSettingsRequest extends FormRequest
{
    use ProvidesFallbackRuleMessages;
    use ReadsBoardLimits;

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
     *
     * @return void
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
            'attachment_settings.purge_retention_days',
            'privacy_retention.ip_retention_days',
            'privacy_retention.guest_retention_days',
        ];

        $booleanFields = [
            'basic_defaults.use_comment',
            'basic_defaults.use_reply',
            'basic_defaults.show_view_count',
            'basic_defaults.use_report',
            'basic_defaults.use_scrap',
            'basic_defaults.use_reaction',
            'basic_defaults.use_comment_reaction',
            'basic_defaults.use_file_upload',
            'basic_defaults.notify_admin_on_post',
            'basic_defaults.notify_author',
            'report_policy.notify_admin_on_report',
            'report_policy.notify_author_on_report_action',
            'seo.seo_boards',
            'seo.seo_board',
            'seo.seo_post_detail',
            'attachment_settings.purge_enabled',
            'privacy_retention.enabled',
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

        // 반응 유형 편집기 행 정규화 — 화면은 빈 문자열/문자열 숫자를 그대로 보낸다.
        // id·icon_attachment_id·display_order 는 '' 를 null 로, 숫자는 int 로 맞추고
        // is_active 는 'false' 같은 문자열도 불리언으로 해석한다.
        $reactionTypes = $data['basic_defaults']['reaction_types'] ?? null;
        if (is_array($reactionTypes)) {
            foreach ($reactionTypes as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }

                foreach (['id', 'icon_attachment_id', 'display_order'] as $numeric) {
                    if (! array_key_exists($numeric, $row)) {
                        continue;
                    }

                    $row[$numeric] = ($row[$numeric] === '' || $row[$numeric] === null)
                        ? null
                        : (int) $row[$numeric];
                }

                if (array_key_exists('is_active', $row)) {
                    $row['is_active'] = filter_var($row['is_active'], FILTER_VALIDATE_BOOLEAN);
                }

                if (isset($row['code']) && is_string($row['code'])) {
                    $row['code'] = strtolower(trim($row['code']));
                }

                $reactionTypes[$index] = $row;
            }

            $data['basic_defaults']['reaction_types'] = array_values($reactionTypes);
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
        // config 기준 제한값 (폴백 기본치는 ReadsBoardLimits 트레이트가 단일 관리)
        $limits = $this->boardLimits();
        $perPageMin = $limits['per_page_min'];
        $perPageMax = $limits['per_page_max'];
        $maxReplyDepthMin = $limits['max_reply_depth_min'];
        $maxReplyDepthMax = $limits['max_reply_depth_max'];
        $maxCommentDepthMin = $limits['max_comment_depth_min'];
        $maxCommentDepthMax = $limits['max_comment_depth_max'];

        // 길이 제한 (하한값은 config 선언을 따른다 — min_title/min_comment 는 0 허용)
        $minTitleLengthMin = $limits['min_title_length_min'];
        $minTitleLengthMax = $limits['min_title_length_max'];
        $maxTitleLengthMin = $limits['max_title_length_min'];
        $maxTitleLengthMax = $limits['max_title_length_max'];
        $minContentLengthMin = $limits['min_content_length_min'];
        $minContentLengthMax = $limits['min_content_length_max'];
        $maxContentLengthMin = $limits['max_content_length_min'];
        $maxContentLengthMax = $limits['max_content_length_max'];
        $minCommentLengthMin = $limits['min_comment_length_min'];
        $minCommentLengthMax = $limits['min_comment_length_max'];
        $maxCommentLengthMin = $limits['max_comment_length_min'];
        $maxCommentLengthMax = $limits['max_comment_length_max'];

        // 파일 업로드 제한
        $maxFileSizeMin = $limits['max_file_size_min'];
        $maxFileSizeMax = $limits['max_file_size_max'];
        $maxFileCountMin = $limits['max_file_count_min'];
        $maxFileCountMax = $limits['max_file_count_max'];

        // NEW 배지 표시 기간 (0 = 표시 안 함)
        $newDisplayHoursMin = $limits['new_display_hours_min'];
        $newDisplayHoursMax = $limits['new_display_hours_max'];

        return [
            // 현재 탭 정보 (메타 데이터)
            '_tab' => ['sometimes', 'string', 'in:basic_defaults,report_policy,spam_security,general,seo,notifications,notification_definitions,privacy_retention'],

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
            'basic_defaults.reply_delete_policy' => ['nullable', 'string', Rule::in(ReplyDeletePolicy::values())],
            'basic_defaults.max_comment_depth' => ['nullable', 'integer', "min:{$maxCommentDepthMin}", "max:{$maxCommentDepthMax}"],
            'basic_defaults.comment_order' => ['nullable', 'string', 'in:ASC,DESC'],
            'basic_defaults.show_view_count' => ['nullable', 'boolean'],
            'basic_defaults.use_report' => ['nullable', 'boolean'],
            'basic_defaults.use_scrap' => ['nullable', 'boolean'],
            'basic_defaults.use_reaction' => ['nullable', 'boolean'],
            'basic_defaults.use_comment_reaction' => ['nullable', 'boolean'],
            'basic_defaults.active_reaction_types' => ['nullable', 'array'],
            'basic_defaults.active_reaction_types.*' => ['string', 'max:50'],

            // 반응 유형 인라인 편집기 행 (설정 JSON 에 저장하지 않고 컨트롤러가 떼어내
            // ReactionTypeService::syncTypes() 로 DB 에 반영한다)
            'basic_defaults.reaction_types' => ['sometimes', 'array', 'max:50'],
            'basic_defaults.reaction_types.*.id' => ['nullable', 'integer', Rule::exists(ReactionType::class, 'id')],
            'basic_defaults.reaction_types.*.code' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,49}$/', 'distinct'],
            // 이름은 화면(요청) 언어 값만 필수 — 게시판 유형 이름과 같은 계약이다. 한국어를 고정 필수로 받아 영어 화면 관리자도
            // 쓰지 않는 한국어 칸을 채워야 했다(7.1.0 S2 #9). 오류는 이름 칸 전체 키(name)로 돌아간다
            'basic_defaults.reaction_types.*.name' => ['required', new LocaleRequiredTranslatable(maxLength: 50)],
            'basic_defaults.reaction_types.*.icon_attachment_id' => ['nullable', 'integer', Rule::exists(Attachment::class, 'id')
                ->where('collection', ReactionTypeService::ICON_COLLECTION)
                ->where('source_identifier', ReactionTypeService::ICON_SOURCE_IDENTIFIER)
                ->whereNull('deleted_at')],
            'basic_defaults.reaction_types.*.display_order' => ['nullable', 'integer', 'min:0'],
            'basic_defaults.reaction_types.*.is_active' => ['nullable', 'boolean'],
            'basic_defaults.min_title_length' => ['nullable', 'integer', "min:{$minTitleLengthMin}", "max:{$minTitleLengthMax}"],
            'basic_defaults.max_title_length' => ['nullable', 'integer', "min:{$maxTitleLengthMin}", "max:{$maxTitleLengthMax}"],
            'basic_defaults.min_content_length' => ['nullable', 'integer', "min:{$minContentLengthMin}", "max:{$minContentLengthMax}"],
            'basic_defaults.max_content_length' => ['nullable', 'integer', "min:{$maxContentLengthMin}", "max:{$maxContentLengthMax}"],
            'basic_defaults.min_comment_length' => ['nullable', 'integer', "min:{$minCommentLengthMin}", "max:{$minCommentLengthMax}"],
            'basic_defaults.max_comment_length' => ['nullable', 'integer', "min:{$maxCommentLengthMin}", "max:{$maxCommentLengthMax}"],
            'basic_defaults.use_file_upload' => ['nullable', 'boolean'],
            'basic_defaults.max_file_size' => ['nullable', 'integer', "min:{$maxFileSizeMin}", "max:{$maxFileSizeMax}"],
            'basic_defaults.max_file_count' => ['nullable', 'integer', "min:{$maxFileCountMin}", "max:{$maxFileCountMax}"],
            'basic_defaults.blocked_keywords' => ['nullable', 'array'],
            'basic_defaults.blocked_keywords.*' => ['string', 'max:100'],
            // 허용 확장자: 첨부 사용 기본값일 때만 최소 1개 필수 (빈 값 저장 시 전 파일 거부되던 버그 방지).
            // 첨부 미사용 기본값은 exclude_if 로 검증 자체에서 배제한다 — 환경설정 화면도 탭 데이터를
            // 통째로 PUT 하므로 요청에는 항상 이 키가 존재하고, sometimes 는 보호가 되지 않는다.
            // null 조건이 별도로 필요한 이유: prepareForValidation() 의 boolean 캐스팅이 isset() 가드를
            // 쓰는데 isset(null) 은 false 라 null 이 false 로 캐스팅되지 않고 그대로 통과한다.
            // exclude_if 는 배열 앞쪽이어야 한다. 앞선 규칙이 먼저 실패하면 배제되어도 메시지가 남는다.
            'basic_defaults.allowed_extensions' => [
                'exclude_if:basic_defaults.use_file_upload,false',
                'exclude_if:basic_defaults.use_file_upload,null',
                'sometimes', 'required', 'array', 'min:1',
            ],
            'basic_defaults.allowed_extensions.*' => ['string', 'max:20'],
            'basic_defaults.notify_admin_on_post' => ['nullable', 'boolean'],
            'basic_defaults.notify_author' => ['nullable', 'boolean'],
            // 하한 기본값 0 = 신규 배지를 붙이지 않음 — 게시판별 값과 같은 범위를 쓴다(한쪽만 좁으면 기본값을 개별 게시판에 못 준다).
            'basic_defaults.new_display_hours' => ['nullable', 'integer', "min:{$newDisplayHoursMin}", "max:{$newDisplayHoursMax}"],
            // default_board_permissions는 flat key 구조 (예: {"posts.read": ["admin","user"], "manager": ["admin"]})
            // Laravel dot notation으로 하위 키를 개별 검증하면 flat key가 중첩 배열로 파싱되어 데이터 유실됨
            // → 배열 자체만 검증 (하위 키 개별 검증 금지)
            'basic_defaults.default_board_permissions' => ['nullable', 'array'],

            // ========================================
            // report_policy (신고 정책) 카테고리
            // ========================================
            'report_policy' => ['sometimes', 'array'],
            'report_policy.auto_hide_threshold' => ['nullable', 'integer', 'min:'.$this->boardLimit('auto_hide_threshold_min', 0), 'max:'.$this->boardLimit('auto_hide_threshold_max', 100)],
            'report_policy.auto_hide_target' => ['nullable', 'string', 'in:post,comment,both'],
            'report_policy.daily_report_limit' => ['nullable', 'integer', 'min:'.$this->boardLimit('daily_report_limit_min', 0), 'max:'.$this->boardLimit('daily_report_limit_max', 100)],
            'report_policy.rejection_limit_count' => ['nullable', 'integer', 'min:'.$this->boardLimit('rejection_limit_count_min', 0), 'max:'.$this->boardLimit('rejection_limit_count_max', 50)],
            'report_policy.rejection_limit_days' => ['nullable', 'integer', 'min:'.$this->boardLimit('rejection_limit_days_min', 1), 'max:'.$this->boardLimit('rejection_limit_days_max', 365)],
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
            'spam_security.post_cooldown_seconds' => ['nullable', 'integer', 'min:'.$this->boardLimit('post_cooldown_seconds_min', 0), 'max:'.$this->boardLimit('post_cooldown_seconds_max', 3600)],
            'spam_security.comment_cooldown_seconds' => ['nullable', 'integer', 'min:'.$this->boardLimit('comment_cooldown_seconds_min', 0), 'max:'.$this->boardLimit('comment_cooldown_seconds_max', 3600)],
            'spam_security.report_cooldown_seconds' => ['nullable', 'integer', 'min:'.$this->boardLimit('report_cooldown_seconds_min', 0), 'max:'.$this->boardLimit('report_cooldown_seconds_max', 3600)],
            'spam_security.view_count_cache_ttl' => ['nullable', 'integer', 'min:'.$this->boardLimit('view_count_cache_ttl_min', 60), 'max:'.$this->boardLimit('view_count_cache_ttl_max', 604800)],

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

            // 첨부 정리 정책 — 사용자 파일을 파기하므로 보존기간 하한을 서버가 강제한다.
            // 상한(3650일)은 "사실상 정리하지 않음" 과 구분되지 않는 값을 저장하지 않기 위함이다.
            'attachment_settings.purge_enabled' => ['nullable', 'boolean'],
            'attachment_settings.purge_retention_days' => ['nullable', 'integer', 'min:'.$limits['attachment_purge_retention_days_min'], 'max:'.$limits['attachment_purge_retention_days_max']],

            // 개인정보 보유기간 (이슈 #636) — 되돌릴 수 없는 파기라 하한을 서버가 강제한다.
            // IP 축은 게시물·댓글 전체(회원 글 포함)의 작성 IP 를, 비회원 축은 비회원 글의 작성자
            // 정보를 다룬다. 비회원 기간 0 은 「그 축을 쓰지 않음」이다.
            'privacy_retention.enabled' => ['nullable', 'boolean'],
            'privacy_retention.ip_retention_days' => ['nullable', 'integer', 'min:'.$limits['privacy_ip_retention_days_min'], 'max:'.$limits['privacy_ip_retention_days_max']],
            'privacy_retention.guest_retention_days' => ['nullable', 'integer', 'min:'.$limits['privacy_guest_retention_days_min'], 'max:'.$limits['privacy_guest_retention_days_max']],
            'privacy_retention.guest_mode' => ['nullable', 'string', 'in:anonymize,delete'],
        ];
    }

    /**
     * 검증 속성명 다국어 처리
     *
     * @return array<string, string>
     */
    /**
     * 규칙만으로 판정할 수 없는 조건을 추가 검증합니다.
     *
     * 신규 행(id 없음)의 code 가 이미 DB 에 있으면 그 행에 오류를 붙인다.
     * Rule::unique()->ignore() 는 행별 id 를 볼 수 없어(규칙은 행 단위로 평가되지만
     * ignore 값은 규칙 정의 시점에 고정된다) 여기서 직접 판정한다.
     *
     * @param  Validator  $validator  검증기
     * @return void
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $rows = $this->input('basic_defaults.reaction_types');

            if (! is_array($rows)) {
                return;
            }

            $newCodes = [];

            foreach ($rows as $index => $row) {
                if (! is_array($row) || ! empty($row['id']) || empty($row['code'])) {
                    continue;
                }

                $newCodes[$index] = $row['code'];
            }

            if ($newCodes === []) {
                return;
            }

            // FormRequest 는 모델을 직접 부르지 않는다 — Repository 계약을 경유한다.
            $repository = app(ReactionTypeRepositoryInterface::class);
            $taken = $repository->all()
                ->pluck('code')
                ->intersect(array_values($newCodes))
                ->values()
                ->all();

            foreach ($newCodes as $index => $code) {
                if (in_array($code, $taken, true)) {
                    $validator->errors()->add(
                        "basic_defaults.reaction_types.{$index}.code",
                        __('sirsoft-board::validation.reaction_type.code.unique'),
                    );
                }
            }
        });
    }

    /**
     * attributes
     *
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        $attributes = __('sirsoft-board::validation.attributes.settings');

        return array_merge(is_array($attributes) ? $attributes : [], [
            // 개인정보 보유기간 (이슈 #636) — 키별로 명시해 오류 문구에 필드 키가 노출되지 않게 한다
            'privacy_retention.enabled' => __('sirsoft-board::validation.privacy_retention_attributes.enabled'),
            'privacy_retention.ip_retention_days' => __('sirsoft-board::validation.privacy_retention_attributes.ip_retention_days'),
            'privacy_retention.guest_retention_days' => __('sirsoft-board::validation.privacy_retention_attributes.guest_retention_days'),
            'privacy_retention.guest_mode' => __('sirsoft-board::validation.privacy_retention_attributes.guest_mode'),
        ]);
    }

    /**
     * 검증 오류 메시지 다국어 처리
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        // validation.settings 는 선택적 오버라이드 그룹이다 — 정의되지 않으면 Laravel 기본
        // 검증 메시지 + validation.attributes.settings 의 속성명 조합이 그대로 쓰인다.
        // (미정의여도 원문 키가 노출되지 않도록 is_array 가드로 걸러낸다)
        $messages = __('sirsoft-board::validation.settings');
        $base = is_array($messages) ? $messages : [];

        return array_merge($base, [
            'basic_defaults.allowed_extensions.required' => __('sirsoft-board::validation.allowed_extensions.min'),
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
        $validCategories = ['basic_defaults', 'report_policy', 'spam_security', 'display', 'seo', 'notifications', 'attachment_settings', 'privacy_retention'];

        return array_filter(
            $validated,
            fn ($key) => in_array($key, $validCategories),
            ARRAY_FILTER_USE_KEY
        );
    }
}
