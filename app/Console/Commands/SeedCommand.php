<?php

namespace App\Console\Commands;

use Illuminate\Database\Console\Seeds\SeedCommand as BaseSeedCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * db:seed 커맨드 확장
 *
 * Laravel 기본 db:seed 커맨드에 --count 옵션을 추가하여
 * 시더에 데이터 개수를 전달할 수 있게 합니다.
 *
 * 사용 예시:
 * php artisan db:seed --class=SomeSeeder --count=items=50000
 */
class SeedCommand extends BaseSeedCommand
{
    /**
     * 시더 인스턴스를 컨테이너에서 생성합니다.
     *
     * 부모 메서드를 호출한 뒤, --count 옵션이 있으면
     * 시더에 setSeederCounts()로 전달합니다.
     *
     * @return \Illuminate\Database\Seeder
     */
    protected function getSeeder()
    {
        $seeder = parent::getSeeder();

        // --sample 옵션 전파
        if (method_exists($seeder, 'setIncludeSample')) {
            $seeder->setIncludeSample((bool) $this->option('sample'));
        }

        // --count 옵션 전파
        $counts = $this->parseCountOptions();
        if (! empty($counts) && method_exists($seeder, 'setSeederCounts')) {
            $seeder->setSeederCounts($counts);
        }

        return $seeder;
    }

    /**
     * --count 옵션을 파싱하여 연관 배열로 반환합니다.
     *
     * 입력: ['products=1000', 'orders=500']
     * 출력: ['products' => 1000, 'orders' => 500]
     *
     * @return array<string, int>
     */
    public function parseCountOptions(): array
    {
        $countOptions = $this->option('count');
        $counts = [];

        foreach ($countOptions as $option) {
            if (str_contains($option, '=')) {
                [$key, $value] = explode('=', $option, 2);
                $key = trim($key);
                if ($key !== '') {
                    $counts[$key] = (int) trim($value);
                }
            }
        }

        return $counts;
    }

    /**
     * 콘솔 커맨드 옵션을 정의합니다.
     *
     * 부모 옵션에 --count 옵션을 추가합니다.
     *
     * @return array
     */
    protected function getOptions()
    {
        return array_merge(parent::getOptions(), [
            ['count', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, '시더에 전달할 카운트 옵션 (형식: key=value, 예: --count=products=1000)', []],
            ['sample', null, InputOption::VALUE_NONE, '샘플 데이터 시더도 함께 실행'],
        ]);
    }
}
