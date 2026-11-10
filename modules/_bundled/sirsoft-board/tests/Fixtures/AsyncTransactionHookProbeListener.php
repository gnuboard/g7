<?php

namespace Modules\Sirsoft\Board\Tests\Fixtures;

/**
 * 시험용 — 같은 훅을 sync 없이 구독한다(Action 기본값: 큐 래핑 + afterCommit) (#883 3단계)
 *
 * 트랜잭션 안 훅에 큐 리스너를 달면 커밋 뒤에 돈다 — 문서가 「sync 필수」라고 한 이유를 시험으로 보인다.
 */
class AsyncTransactionHookProbeListener extends TransactionHookProbeListener
{
    /**
     * 구독할 훅 목록을 반환합니다 — sync 없음.
     *
     * @return array<string, array<string, mixed>> 구독 목록
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-board.post.before_create_commit' => ['method' => 'onCreate', 'priority' => 10],
        ];
    }
}
