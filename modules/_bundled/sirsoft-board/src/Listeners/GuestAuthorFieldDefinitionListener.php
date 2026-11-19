<?php

namespace Modules\Sirsoft\Board\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Collection;
use Modules\Sirsoft\Board\Fields\GuestAuthorFields;

/**
 * 비회원 작성자 항목에 게시판별 사용, 필수 설정을 입힙니다.
 *
 * 항목 엔진은 집합의 항목을 내줄 때 필터 `core.field.filter_definitions` 를 태운다. 비회원 작성자 집합
 * (`sirsoft-board.guest_author`)은 게시판마다, 글과 댓글마다 사용과 필수가 다르므로 이 필터에서 요청 문맥의
 * 게시판과 표면을 읽어 항목 사본의 값을 바꾼다. 저장된 항목 행은 바꾸지 않는다.
 */
class GuestAuthorFieldDefinitionListener implements HookListenerInterface
{
    /**
     * 이 리스너가 구독하는 훅 정의를 반환합니다.
     *
     * @return array<string, array<string, mixed>> 훅 이름 => 구독 설정
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.field.filter_definitions' => [
                'method' => 'applyBoardSettings',
                'type' => 'filter',
                'priority' => 10,
            ],
        ];
    }

    /**
     * 기본 핸들러 (미사용, applyBoardSettings 에서 처리)
     *
     * @param  mixed  ...$args  훅 인수
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 비회원 작성자 집합의 항목 목록에 게시판별 설정을 입힙니다.
     *
     * @param  mixed  $definitions  항목 목록 (사본의 컬렉션)
     * @param  mixed  $set  집합 식별자
     * @param  mixed  $scopeKey  범위 키 (게시판 ID)
     * @param  mixed  $context  요청 문맥 배열 (`surface`, `extra.board`)
     * @return mixed 설정을 입힌 항목 목록 (다른 집합이면 받은 그대로)
     */
    public function applyBoardSettings(mixed $definitions, mixed $set = null, mixed $scopeKey = null, mixed $context = []): mixed
    {
        if ($set !== GuestAuthorFields::SET || ! $definitions instanceof Collection) {
            return $definitions;
        }

        return app(GuestAuthorFields::class)->applyBoardSettings(
            $definitions,
            is_string($scopeKey) ? $scopeKey : null,
            is_array($context) ? $context : [],
        );
    }
}
