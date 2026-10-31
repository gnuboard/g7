<?php

namespace Database\Factories\Analytics;

use App\Models\Analytics\AnalyticsBotHit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalyticsBotHit>
 */
class AnalyticsBotHitFactory extends Factory
{
    /**
     * 모델 클래스
     *
     * @var class-string<AnalyticsBotHit>
     */
    protected $model = AnalyticsBotHit::class;

    /**
     * 기본 상태를 정의합니다.
     *
     * @return array<string, mixed> 속성 배열
     */
    public function definition(): array
    {
        $url = '/'.$this->faker->slug();

        return [
            'bot_name' => $this->faker->randomElement(['Googlebot', 'bingbot', 'YandexBot', 'DuckDuckBot']),
            'user_agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
            'url' => $url,
            'url_hash' => md5($url),
            'ip' => $this->faker->ipv4(),
            'cache_status' => $this->faker->randomElement(['hit', 'miss', 'bypass', 'none']),
            'occurred_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
            'created_at' => now(),
        ];
    }

    /**
     * 특정 봇의 요청 상태
     *
     * @param  string  $name  봇 이름
     * @return static 팩토리 인스턴스
     */
    public function bot(string $name): static
    {
        return $this->state(fn (array $attributes) => [
            'bot_name' => $name,
        ]);
    }

    /**
     * SEO 캐시가 쓰인 요청 상태
     *
     * @return static 팩토리 인스턴스
     */
    public function cacheHit(): static
    {
        return $this->state(fn (array $attributes) => [
            'cache_status' => 'hit',
        ]);
    }
}
