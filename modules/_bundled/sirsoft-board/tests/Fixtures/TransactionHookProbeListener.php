<?php

namespace Modules\Sirsoft\Board\Tests\Fixtures;

use App\Contracts\Extension\HookListenerInterface;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Models\Post;

/**
 * 시험용 — 글 저장 트랜잭션 안 훅(`before_*_commit`)을 sync 로 구독해 그 시점에 보이는 것을 적는다 (#883 3단계)
 *
 * 실제 등록 경로(`HookListenerRegistrar::register`)로 붙인다. `$throw` 를 켜면 예외를 던져 저장 전체가 되돌아가는지 본다.
 */
class TransactionHookProbeListener implements HookListenerInterface
{
    /** 훅이 본 것 — [훅 이름, 글 ID, 그 시점 DB 에 글 행이 있는지, 그 글에 연결된 첨부 수, 트랜잭션 깊이] */
    public static array $seen = [];

    /** 참이면 훅에서 예외를 던진다 */
    public static bool $throw = false;

    /**
     * 구독할 훅 목록을 반환합니다 — 트랜잭션 안에서 돌아야 하므로 sync.
     *
     * @return array<string, array<string, mixed>> 구독 목록
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-board.post.before_create_commit' => ['method' => 'onCreate', 'priority' => 10, 'sync' => true],
            'sirsoft-board.post.before_update_commit' => ['method' => 'onUpdate', 'priority' => 10, 'sync' => true],
        ];
    }

    /**
     * 기본 훅 핸들러 (HookListenerInterface 필수 메서드).
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 생성 트랜잭션 안 훅.
     *
     * @param  Post  $post  저장된 글
     * @return void 반환값 없음
     */
    public function onCreate(Post $post): void
    {
        $this->observe('create', $post);
    }

    /**
     * 수정 트랜잭션 안 훅.
     *
     * @param  Post  $post  저장된 글
     * @return void 반환값 없음
     */
    public function onUpdate(Post $post): void
    {
        $this->observe('update', $post);
    }

    /**
     * 그 시점의 상태를 적고, 필요하면 예외를 던집니다.
     *
     * @param  string  $hook  훅 구분
     * @param  Post  $post  저장된 글
     * @return void 반환값 없음
     */
    private function observe(string $hook, Post $post): void
    {
        self::$seen[] = [
            $hook,
            $post->id,
            Post::query()->whereKey($post->id)->exists(),
            Attachment::query()->where('post_id', $post->id)->count(),
            \Illuminate\Support\Facades\DB::transactionLevel(),
        ];

        if (self::$throw) {
            throw new \RuntimeException('probe listener failure');
        }
    }
}
