<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Fixtures;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Models\Product;

/**
 * 시험용 — 상품 저장 트랜잭션 안 훅(`before_*_commit`)을 sync 로 구독해 그 시점에 보이는 것을 적는다 (#883 3단계)
 */
class ProductTransactionHookProbeListener implements HookListenerInterface
{
    /** 훅이 본 것 — [훅 구분, 상품 ID, 그 시점 상품 이름(ko), 트랜잭션 깊이, snapshot 이름(ko)|null] */
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
            'sirsoft-ecommerce.product.before_create_commit' => ['method' => 'onCreate', 'priority' => 10, 'sync' => true],
            'sirsoft-ecommerce.product.before_update_commit' => ['method' => 'onUpdate', 'priority' => 10, 'sync' => true],
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
     * @param  Product  $product  저장된 상품
     * @param  array<string, mixed>  $data  저장 데이터
     * @return void 반환값 없음
     */
    public function onCreate(Product $product, array $data): void
    {
        $this->observe('create', $product, null);
    }

    /**
     * 수정 트랜잭션 안 훅.
     *
     * @param  Product  $product  저장된 상품
     * @param  array<string, mixed>  $data  저장 데이터
     * @param  array<string, mixed>  $snapshot  수정 전 스냅샷
     * @return void 반환값 없음
     */
    public function onUpdate(Product $product, array $data, array $snapshot): void
    {
        $this->observe('update', $product, $snapshot['name']['ko'] ?? null);
    }

    /**
     * 그 시점의 상태를 적고, 필요하면 예외를 던집니다.
     *
     * @param  string  $hook  훅 구분
     * @param  Product  $product  저장된 상품
     * @param  string|null  $snapshotName  수정 전 이름
     * @return void 반환값 없음
     */
    private function observe(string $hook, Product $product, ?string $snapshotName): void
    {
        $fresh = Product::query()->find($product->id);
        self::$seen[] = [$hook, $product->id, $fresh?->name['ko'] ?? null, DB::transactionLevel(), $snapshotName];

        if (self::$throw) {
            throw new \RuntimeException('product probe failure');
        }
    }
}
