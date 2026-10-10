<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Repositories;

use Illuminate\Database\QueryException;
use Modules\Sirsoft\Ecommerce\Models\ClaimImage;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimImageRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 클레임 첨부 해시 충돌 테스트 (P17)
 *
 * `ClaimImage::generateHash()` 는 "조회 후 삽입" 이라 두 요청 사이에 같은 값이 끼어들 수 있다.
 * 그 경합을 `hash` UNIQUE 가 잡는데, 그 `QueryException` 을 아무도 받지 않으면 업로드가 500 이
 * 되고 방금 올린 파일까지 되돌려진다 — 사용자에게는 원인 없는 실패다.
 *
 * 경합 자체는 테스트에서 재현할 수 없으므로, `creating` 훅에서 이미 쓰인 해시를 **한 번만**
 * 강제해 그 삽입이 UNIQUE 에 걸리는 상황을 그대로 만든다.
 */
class ClaimImageHashCollisionTest extends ModuleTestCase
{
    /**
     * 첨부 행 생성 데이터를 만듭니다.
     *
     * @return array<string, mixed> 생성 데이터
     */
    private function payload(string $path): array
    {
        return [
            'claimable_type' => ClaimImage::PENDING_CLAIMABLE_TYPE,
            'claimable_id' => 1,
            'disk' => 'modules',
            'path' => $path,
            'original_name' => 'x.png',
            'mime_type' => 'image/png',
            'size' => 100,
            'sort_order' => 1,
        ];
    }

    /**
     * 다음 한 건의 `creating` 에서만 주어진 해시를 강제합니다. (경합 재현)
     */
    private function forceHashOnce(string $hash): void
    {
        $used = false;

        ClaimImage::creating(function (ClaimImage $model) use ($hash, &$used) {
            if (! $used) {
                $used = true;
                $model->hash = $hash;
            }
        });
    }

    protected function tearDown(): void
    {
        ClaimImage::flushEventListeners();
        ClaimImage::boot();

        parent::tearDown();
    }

    /**
     * 해시가 충돌해도 업로드는 성공하고, 다른 해시로 저장된다.
     */
    #[Test]
    public function create_retries_with_a_fresh_hash_on_a_collision(): void
    {
        $repository = app(ClaimImageRepositoryInterface::class);

        $first = $repository->create($this->payload('claims/pending/1/first.png'));
        $this->assertNotEmpty($first->hash);

        $this->forceHashOnce($first->hash);

        $second = $repository->create($this->payload('claims/pending/1/second.png'));

        $this->assertNotSame(
            $first->hash,
            $second->hash,
            '충돌한 해시를 그대로 다시 쓰면 두 번째 시도도 같은 제약에 걸린다'
        );
        $this->assertNotNull(ClaimImage::find($second->id));
        $this->assertSame('claims/pending/1/second.png', $second->path);
    }

    /**
     * 호출부가 해시를 직접 넘긴 경우에는 재채번하지 않고 예외를 그대로 올린다.
     *
     * 그 값은 호출부의 의도이므로 조용히 다른 값으로 바꿔 저장하면 안 된다.
     */
    #[Test]
    public function create_does_not_reassign_a_caller_supplied_hash(): void
    {
        $repository = app(ClaimImageRepositoryInterface::class);

        $first = $repository->create($this->payload('claims/pending/1/a.png'));

        $this->expectException(QueryException::class);

        $repository->create($this->payload('claims/pending/1/b.png') + ['hash' => $first->hash]);
    }
}
