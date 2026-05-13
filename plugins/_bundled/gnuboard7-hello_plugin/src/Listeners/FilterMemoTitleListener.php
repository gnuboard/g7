<?php

namespace Plugins\Gnuboard7\HelloPlugin\Listeners;

use App\Contracts\Extension\HookListenerInterface;

/**
 * 메모 제목 Filter 훅 리스너 (Filter 훅 시연)
 *
 * Hello 모듈이 `gnuboard7-hello_module.memo.title.filter` 필터 훅을 발행한다고
 * 가정하고, 그 제목 앞에 `[Hello] ` 접두사를 붙이는 간단한 데모입니다.
 *
 * 학습 포인트:
 * - Filter 훅 리스너는 반드시 `'type' => 'filter'` 를 명시해야 반환값이 체인에 반영됩니다.
 * - 훅이 실제로 발행되지 않더라도 리스너 등록 자체가 유효하며, 발행 시점이 오면 자동으로 호출됩니다.
 */
class FilterMemoTitleListener implements HookListenerInterface
{
    /**
     * 구독할 훅 목록 반환
     *
     * Filter 훅은 `'type' => 'filter'` 를 반드시 명시해야 합니다.
     * 미지정 시 Action 으로 처리되어 반환값이 무시됩니다.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'gnuboard7-hello_module.memo.title.filter' => [
                'method' => 'prependHelloPrefix',
                'priority' => 10,
                'type' => 'filter',
            ],
        ];
    }

    /**
     * HookListenerInterface 기본 핸들러 (미사용)
     *
     * @param  mixed  ...$args  훅 인자
     * @return void
     */
    public function handle(...$args): void
    {
        // 개별 메서드에서 처리
    }

    /**
     * 메모 제목 앞에 `[Hello] ` 접두사를 덧붙입니다.
     *
     * @param  mixed  $title  현재 제목 (앞선 필터의 반환값)
     * @param  mixed  ...$extra  추가 인자 (사용하지 않음)
     * @return string 변형된 제목
     */
    public function prependHelloPrefix(mixed $title, mixed ...$extra): string
    {
        $value = is_string($title) ? $title : (string) ($title ?? '');

        if (str_starts_with($value, '[Hello] ')) {
            return $value;
        }

        return '[Hello] '.$value;
    }
}
