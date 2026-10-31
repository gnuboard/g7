<?php

namespace Modules\Sirsoft\Board\Exceptions;

use App\Helpers\ResponseHelper;
use Exception;
use Illuminate\Http\JsonResponse;

/**
 * 사용 중인 반응 유형 삭제 시도 예외
 *
 * 반응 이력이 남아 있는 유형은 삭제할 수 없고 비활성화만 가능합니다 (결정 R5).
 * DB 도 board_reactions.reaction_type_id FK 가 restrictOnDelete 라 마지막 방어선을 갖지만,
 * 운영자에게는 "몇 건이 걸려 있는지"를 알려야 하므로 서비스가 미리 세어 이 예외로 막습니다.
 *
 * `render()` 는 컨트롤러 밖에서 던져진 경우를 위한 것입니다 — 응답 형태를 컨트롤러 catch 와
 * **동일하게** 유지해, 같은 조건에 두 가지 응답 형태가 생기지 않게 합니다.
 */
class ReactionTypeInUseException extends Exception
{
    /**
     * @param  string  $name  반응 유형 표시명 (현재 로케일)
     * @param  int  $count  해당 유형에 남아 있는 반응 건수
     */
    public function __construct(
        private string $name,
        private int $count,
    ) {
        parent::__construct(trans_count('sirsoft-board::messages.reaction_type.in_use', [
            'name' => $name,
            'count' => $count,
        ]));
    }

    /**
     * 반응 유형 표시명을 반환합니다.
     *
     * @return string 유형 표시명
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * 남아 있는 반응 건수를 반환합니다.
     *
     * @return int 반응 건수
     */
    public function getCount(): int
    {
        return $this->count;
    }

    /**
     * 다국어 메시지 키를 반환합니다.
     *
     * 응답을 만들 때 이미 번역된 `getMessage()` 대신 이 키를 넘긴다 — 번역문을 키로
     * 해석하려 하면 실패해 원문이 그대로 노출된다.
     *
     * @return string 다국어 메시지 키
     */
    public function getMessageKey(): string
    {
        return 'sirsoft-board::messages.reaction_type.in_use';
    }

    /**
     * 메시지 치환 파라미터를 반환합니다.
     *
     * @return array<string, mixed> 치환 파라미터
     */
    public function getMessageParams(): array
    {
        return ['name' => $this->name, 'count' => $this->count];
    }

    /**
     * 컨트롤러 catch 와 동일한 422 응답으로 렌더링합니다.
     *
     * @return JsonResponse 사용 중 유형 삭제 거부 응답
     */
    public function render(): JsonResponse
    {
        return ResponseHelper::error(
            $this->getMessageKey(),
            422,
            ['code' => 'reaction_type_in_use'],
            $this->getMessageParams()
        );
    }
}
