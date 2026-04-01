<?php

namespace Modules\Sirsoft\Board\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Board\Models\Board;

/**
 * 게시판 설정 일괄 적용 요청 검증
 *
 * 환경설정 기본값을 기존 게시판에 일괄 적용할 때 사용합니다.
 * 권한 체크는 라우트의 permission 미들웨어에서 수행됩니다.
 */
class BulkApplySettingsRequest extends FormRequest
{
    /**
     * boards 테이블 컬럼에 매핑되는 일괄 적용 가능 필드 목록
     */
    private const BOARD_COLUMN_FIELDS = [
        'type',
        'per_page',
        'per_page_mobile',
        'order_by',
        'order_direction',
        'secret_mode',
        'use_comment',
        'use_reply',
        'max_reply_depth',
        'max_comment_depth',
        'comment_order',
        'show_view_count',
        'use_report',
        'min_title_length',
        'max_title_length',
        'min_content_length',
        'max_content_length',
        'min_comment_length',
        'max_comment_length',
        'use_file_upload',
        'max_file_size',
        'max_file_count',
        'allowed_extensions',
        'notify_admin_on_post',
        'notify_admin_on_post_channels',
        'notify_author',
        'notify_author_channels',
        'new_display_hours',
    ];

    /**
     * 권한 관련 일괄 적용 가능 필드
     *
     * default_board_permissions의 직접 하위 키 중 점(.)이 없는 키도 허용합니다.
     * 점이 포함된 키(admin.posts, posts.read 등)는 검증 규칙에서 별도 통과 처리됩니다.
     */
    private const PERMISSION_FIELDS = [
        'default_board_permissions',
        'manager',
    ];

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
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $allowedFields = array_merge(self::BOARD_COLUMN_FIELDS, self::PERMISSION_FIELDS);

        return [
            'fields' => ['required', 'array', 'min:1'],
            'fields.*' => ['string', function (string $attribute, mixed $value, \Closure $fail) use ($allowedFields) {
                // 개별 권한 키(예: admin.manage, user.write)는 점(.)을 포함한 문자열로 허용
                if (str_contains($value, '.')) {
                    return;
                }
                if (! in_array($value, $allowedFields, true)) {
                    $fail(__('sirsoft-board::validation.fields_invalid', ['value' => $value]));
                }
            }],
            'apply_all' => ['required', 'boolean'],
            'board_ids' => ['required_if:apply_all,false', 'array'],
            'board_ids.*' => ['integer', Rule::exists(Board::class, 'id')],
            'override_values' => ['sometimes', 'array'],
        ];
    }

    /**
     * 검증 속성명 다국어 처리
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = __('sirsoft-board::attributes.bulk_apply');

        return is_array($attributes) ? $attributes : [];
    }

    /**
     * 검증 오류 메시지 다국어 처리
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = __('sirsoft-board::validation.bulk_apply');

        return is_array($messages) ? $messages : [];
    }

    /**
     * boards 테이블 컬럼에 매핑되는 필드 목록 반환
     *
     * @return array<string> 컬럼 필드 목록
     */
    public static function getBoardColumnFields(): array
    {
        return self::BOARD_COLUMN_FIELDS;
    }

    /**
     * 권한 관련 필드 목록 반환
     *
     * @return array<string> 권한 필드 목록
     */
    public static function getPermissionFields(): array
    {
        return self::PERMISSION_FIELDS;
    }
}
