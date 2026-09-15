<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Contracts\Repositories\AttachmentRepositoryInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Tests\TestCase;

/**
 * 코어 첨부파일 Repository 의 컬렉션 타입 계약 테스트.
 *
 * `findByIds()` 는 진짜 모델 컬렉션을 반환하므로 `Eloquent\Collection` 선언이 정당하다.
 * 그런데 빈 입력 분기만 `collect()`(Support)를 돌려주고 있어, 호출 경로가 생기는 순간
 * TypeError 로 죽는 잠복 결함이었다. 현재 호출처(`SettingsService`)는 둘 다 `empty()`
 * 사전 검사가 있어 도달하지 않지만, 사전 검사가 빠지는 순간 fatal 이다.
 */
class AttachmentRepositoryTest extends TestCase
{
    /**
     * 빈 입력에서도 선언된 Eloquent 컬렉션을 반환해야 한다.
     */
    public function test_find_by_ids_returns_eloquent_collection_for_empty_input(): void
    {
        $repository = app(AttachmentRepositoryInterface::class);

        $result = $repository->findByIds([]);

        $this->assertInstanceOf(
            EloquentCollection::class,
            $result,
            '빈 입력 분기만 Support 를 돌려주면 분기별 타입이 갈려 호출 즉시 TypeError 가 됩니다.',
        );
        $this->assertTrue($result->isEmpty());
    }
}
