<?php

namespace App\Support\SampleData;

use OverflowException;

/**
 * FakerShim::unique() 프록시
 *
 * Faker\UniqueGenerator 와 동일한 API 제공:
 *   $faker->unique()->name() → 이전에 반환한 값과 겹치지 않을 때까지 재시도
 *
 * 사용한 (메서드 × 인자) 조합마다 독립된 중복 방지 집합을 유지합니다.
 */
class UniqueShim
{
    /**
     * @param  array<string, array<string, true>>  $seen
     */
    public function __construct(
        private FakerShim $shim,
        private array &$seen,
        private int $maxRetries = 10000,
    ) {}

    public function __call(string $method, array $arguments): mixed
    {
        $bucket = $method.':'.md5(serialize($arguments));
        if (! isset($this->seen[$bucket])) {
            $this->seen[$bucket] = [];
        }

        for ($i = 0; $i < $this->maxRetries; $i++) {
            $value = $this->shim->{$method}(...$arguments);
            $hash = is_scalar($value) ? (string) $value : md5(serialize($value));
            if (! isset($this->seen[$bucket][$hash])) {
                $this->seen[$bucket][$hash] = true;

                return $value;
            }
        }

        throw new OverflowException(
            "UniqueShim: '{$method}' 에서 {$this->maxRetries} 회 재시도 후에도 고유 값을 생성하지 못했습니다. ".
            '데이터셋 풀이 너무 작거나 요청 수가 데이터셋보다 많은지 확인하세요.'
        );
    }
}
