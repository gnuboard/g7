<?php

namespace App\Traits;

/**
 * 시더 카운트 전달 Trait
 *
 * Artisan 커맨드에서 --count 옵션으로 전달된 데이터 개수를
 * 시더 체인을 통해 전파할 수 있게 합니다.
 *
 * 사용 예시:
 * php artisan module:seed sirsoft-ecommerce --count=products=1000 --count=orders=500
 */
trait HasSeederCounts
{
    /**
     * 시더 카운트 옵션 배열
     *
     * @var array<string, int>
     */
    private array $seederCounts = [];

    /**
     * 시더 카운트 옵션을 설정합니다.
     *
     * @param  array<string, int>  $counts  카운트 옵션 배열 (예: ['products' => 1000, 'orders' => 500])
     * @return void
     */
    public function setSeederCounts(array $counts): void
    {
        $this->seederCounts = $counts;
    }

    /**
     * 시더 카운트 옵션을 반환합니다.
     *
     * @return array<string, int>
     */
    public function getSeederCounts(): array
    {
        return $this->seederCounts;
    }

    /**
     * 특정 키의 카운트 값을 반환합니다.
     *
     * 키가 존재하지 않으면 기본값을 반환하며, 최소값은 1입니다.
     *
     * @param  string  $key  카운트 키 (예: 'products', 'users')
     * @param  int  $default  기본값
     * @return int
     */
    protected function getSeederCount(string $key, int $default): int
    {
        return isset($this->seederCounts[$key])
            ? max(1, (int) $this->seederCounts[$key])
            : $default;
    }
}
