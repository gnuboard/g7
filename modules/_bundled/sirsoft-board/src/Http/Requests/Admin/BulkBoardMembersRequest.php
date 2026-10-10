<?php

namespace Modules\Sirsoft\Board\Http\Requests\Admin;

use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Board\Http\Requests\Concerns\ProvidesFallbackRuleMessages;
use Modules\Sirsoft\Board\Models\Board;

/**
 * 게시판 관리자·스텝 일괄 지정 요청 검증
 *
 * 환경설정 「기본값」 탭의 일괄 지정 카드가 보낸다. 대상 게시판(전체 또는 고른 목록)의
 * 관리자 또는 스텝 명단에 회원을 더하거나 뺀다.
 */
class BulkBoardMembersRequest extends FormRequest
{
    use ProvidesFallbackRuleMessages;

    /**
     * 요청 권한을 확인합니다.
     *
     * 권한 검사는 라우트의 `permission:admin,sirsoft-board.boards.update` 미들웨어가 담당합니다.
     *
     * @return bool 항상 true (미들웨어에서 권한 제어)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 정의합니다.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'apply_all' => ['required', 'boolean'],
            'board_ids' => ['exclude_if:apply_all,true', 'required', 'array', 'min:1'],
            'board_ids.*' => ['integer', Rule::exists(Board::class, 'id')],
            'user_uuids' => ['required', 'array', 'min:1', 'max:100'],
            'user_uuids.*' => ['uuid', Rule::exists(User::class, 'uuid')],
            'member_type' => ['required', 'string', Rule::in(['manager', 'step'])],
            'mode' => ['required', 'string', Rule::in(['add', 'remove'])],
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('sirsoft-board.board.bulk_members_validation_rules', $rules, $this);
    }

    /**
     * 검증 속성명을 반환합니다.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'apply_all' => __('sirsoft-board::validation.attributes.bulk_members.apply_all'),
            'board_ids' => __('sirsoft-board::validation.attributes.bulk_members.board_ids'),
            'board_ids.*' => __('sirsoft-board::validation.attributes.bulk_members.board_ids'),
            'user_uuids' => __('sirsoft-board::validation.attributes.bulk_members.user_uuids'),
            'user_uuids.*' => __('sirsoft-board::validation.attributes.bulk_members.user_uuids'),
            'member_type' => __('sirsoft-board::validation.attributes.bulk_members.member_type'),
            'mode' => __('sirsoft-board::validation.attributes.bulk_members.mode'),
        ];
    }
}
