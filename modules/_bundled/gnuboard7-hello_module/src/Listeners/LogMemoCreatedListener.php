<?php

namespace Modules\Gnuboard7\HelloModule\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Log;
use Modules\Gnuboard7\HelloModule\Models\Memo;

/**
 * 메모 생성 로그 리스너 (학습용 데모)
 *
 * 자신(MemoService)이 발행한 `gnuboard7-hello_module.memo.created` 훅을
 * 자신이 받아 로그에 기록하는 간단한 데모 리스너입니다.
 */
class LogMemoCreatedListener implements HookListenerInterface
{
    /**
     * 구독할 훅 목록 반환
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'gnuboard7-hello_module.memo.created' => [
                'method' => 'onMemoCreated',
                'priority' => 10,
            ],
        ];
    }

    /**
     * 기본 훅 핸들러 (HookListenerInterface 필수 메서드)
     *
     * @param  mixed  ...$args  훅 인자
     * @return void
     */
    public function handle(...$args): void
    {
        // 개별 메서드에서 처리
    }

    /**
     * 메모 생성 시 로그를 기록합니다.
     *
     * @param  mixed  ...$args  훅 인자 (첫 번째: Memo 모델)
     * @return void
     */
    public function onMemoCreated(...$args): void
    {
        $memo = $args[0] ?? null;

        if (! $memo instanceof Memo) {
            return;
        }

        Log::info('[HelloModule] Memo created', [
            'id' => $memo->id,
            'uuid' => $memo->uuid,
            'title' => $memo->title,
        ]);
    }
}
