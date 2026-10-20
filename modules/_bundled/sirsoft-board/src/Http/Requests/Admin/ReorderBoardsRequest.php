<?php

namespace Modules\Sirsoft\Board\Http\Requests\Admin;

use App\Extension\HookManager;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Sirsoft\Board\Http\Requests\Concerns\ProvidesFallbackRuleMessages;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;

/**
 * 게시판 표시 순서 저장 요청 검증
 *
 * 관리자 게시판 목록의 「순서 변경」 모달이 보낸다. 전체 게시판 id 목록(활성·비활성 전부)을 새 순서로 받는다.
 * 모달을 연 사이 다른 관리자가 게시판을 만들거나 지웠다면 받은 목록이 현재 게시판 집합과 달라지므로
 * 거부한다 — 옛 목록으로 덮어쓰면 새 게시판의 순서가 사라지거나 지운 게시판의 id 가 남는다.
 */
class ReorderBoardsRequest extends FormRequest
{
    use ProvidesFallbackRuleMessages;

    /**
     * 요청 권한을 확인합니다.
     *
     * 권한 검사는 라우트의 `permission:admin,sirsoft-board.boards.update` 미들웨어가, 범위 제한 보유자 차단은
     * Service 가 담당합니다.
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
     * @return array<string, ValidationRule|array<mixed>|string> 검증 규칙
     */
    public function rules(): array
    {
        $rules = [
            'board_ids' => ['required', 'array', 'min:1'],
            'board_ids.*' => ['required', 'integer', 'distinct'],
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('sirsoft-board.board.reorder_validation_rules', $rules, $this);
    }

    /**
     * 기본 규칙 뒤에 받은 목록이 현재 게시판 집합과 같은지 확인합니다.
     *
     * @return array<int, callable> 추가 검증 콜백
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $received = array_map('intval', (array) $this->input('board_ids', []));
                sort($received);
                $current = app(BoardRepositoryInterface::class)->getAllIds();

                if ($received !== $current) {
                    $validator->errors()->add('board_ids', __('sirsoft-board::validation.boards.reorder_stale'));
                }
            },
        ];
    }

    /**
     * 검증 속성명을 반환합니다.
     *
     * @return array<string, string> 속성명
     */
    public function attributes(): array
    {
        return [
            'board_ids' => __('sirsoft-board::validation.attributes.board_ids'),
            'board_ids.*' => __('sirsoft-board::validation.attributes.board_ids'),
        ];
    }
}
