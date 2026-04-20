<?php

namespace App\Support\SampleData;

/**
 * FakerShim::optional() 프록시
 *
 * Faker 와 동일한 API 제공:
 *   $faker->optional()->sentence()        // 50% 확률로 null
 *   $faker->optional(0.7)->name()         // 70% 확률로 name, 30% null
 *   $faker->optional(0.3, 'default')->x() // 30% 확률 x, 70% default
 *
 * weight 해석:
 *   - 0.0 ~ 1.0  : 확률
 *   - 1 초과 값  : Faker 관례상 정수 0-100 퍼센트로 간주
 */
class OptionalShim
{
    public function __construct(
        private FakerShim $shim,
        private float|int $weight,
        private mixed $default = null,
    ) {}

    public function __call(string $method, array $arguments): mixed
    {
        $threshold = $this->weight <= 1
            ? (int) round($this->weight * 100)
            : (int) $this->weight;

        if (random_int(1, 100) <= $threshold) {
            return $this->shim->{$method}(...$arguments);
        }

        return $this->default;
    }
}
