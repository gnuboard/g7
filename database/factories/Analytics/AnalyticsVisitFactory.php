<?php

namespace Database\Factories\Analytics;

use App\Enums\Analytics\DeviceType;
use App\Enums\Analytics\PrivacyMode;
use App\Enums\Analytics\ReferrerType;
use App\Enums\Analytics\VisitorType;
use App\Enums\Analytics\VisitSource;
use App\Models\Analytics\AnalyticsVisit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalyticsVisit>
 */
class AnalyticsVisitFactory extends Factory
{
    /**
     * 모델 클래스
     *
     * @var class-string<AnalyticsVisit>
     */
    protected $model = AnalyticsVisit::class;

    /**
     * 기본 상태를 정의합니다.
     *
     * @return array<string, mixed> 속성 배열
     */
    public function definition(): array
    {
        $firstAt = $this->faker->dateTimeBetween('-30 days', 'now');
        $lastAt = (clone $firstAt)->modify('+'.$this->faker->numberBetween(0, 25).' minutes');
        $pageviews = $this->faker->numberBetween(1, 8);

        return [
            'visitor_key' => bin2hex(random_bytes(16)),
            'visitor_key_type' => 'cookie',
            'visitor_type' => VisitorType::Guest,
            'user_id' => null,
            'user_hash' => null,
            'visit_date' => $firstAt->format('Y-m-d'),
            'first_action_at' => $firstAt,
            'last_action_at' => $lastAt,
            'actions_count' => $pageviews,
            'pageviews_count' => $pageviews,
            'total_time_seconds' => $this->faker->numberBetween(0, 900),
            'is_bounce' => $pageviews === 1,
            'entry_action_name_id' => null,
            'exit_action_name_id' => null,
            'source' => VisitSource::Beacon,
            'ip' => $this->faker->ipv4(),
            'ip_hash' => null,
            'country_code' => 'KR',
            'region_code' => null,
            'city' => null,
            'browser_name' => $this->faker->randomElement(['Chrome', 'Edge', 'Firefox', 'Safari', 'Whale']),
            'browser_version' => (string) $this->faker->numberBetween(100, 130),
            'os_name' => $this->faker->randomElement(['Windows', 'macOS', 'Android', 'iOS']),
            'os_version' => null,
            'device_type' => DeviceType::Desktop,
            'screen_resolution' => '1920x1080',
            'browser_language' => 'ko-KR',
            'locale' => 'ko',
            'referrer_type' => ReferrerType::Direct,
            'referrer_name' => null,
            'referrer_url' => null,
            'referrer_keyword' => null,
            'campaign_name' => null,
            'campaign_source' => null,
            'campaign_medium' => null,
            'is_returning' => false,
            'custom_dimensions' => null,
        ];
    }

    /**
     * 회원 방문 상태
     *
     * @param  int  $userId  회원 ID
     * @return static 팩토리 인스턴스
     */
    public function member(int $userId): static
    {
        return $this->state(fn (array $attributes) => [
            'visitor_type' => VisitorType::Member,
            'user_id' => $userId,
        ]);
    }

    /**
     * 관리자 방문 상태
     *
     * @param  int  $userId  회원 ID
     * @return static 팩토리 인스턴스
     */
    public function admin(int $userId): static
    {
        return $this->state(fn (array $attributes) => [
            'visitor_type' => VisitorType::Admin,
            'user_id' => $userId,
        ]);
    }

    /**
     * 익명 모드 방문 상태
     *
     * 쿠키를 심지 않으므로 방문자 키가 일자별 해시이고 IP 대신 해시만 남으며
     * 재방문 여부를 판정할 수 없습니다.
     *
     * @return static 팩토리 인스턴스
     */
    public function anonymousMode(): static
    {
        return $this->state(fn (array $attributes) => [
            'visitor_key_type' => 'config',
            'ip' => null,
            'ip_hash' => hash('sha256', (string) $this->faker->ipv4()),
            'is_returning' => null,
        ]);
    }

    /**
     * 서버 폴백으로만 잡힌 방문 상태
     *
     * @return static 팩토리 인스턴스
     */
    public function serverFallback(): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => VisitSource::Server,
        ]);
    }

    /**
     * 특정 날짜의 방문 상태
     *
     * @param  string  $date  사이트 표준시 기준 일자 (Y-m-d)
     * @return static 팩토리 인스턴스
     */
    public function onDate(string $date): static
    {
        return $this->state(function (array $attributes) use ($date) {
            $firstAt = $date.' 10:00:00';

            return [
                'visit_date' => $date,
                'first_action_at' => $firstAt,
                'last_action_at' => $date.' 10:05:00',
            ];
        });
    }

    /**
     * 개인정보 모드에 맞춘 방문 상태
     *
     * @param  PrivacyMode  $mode  개인정보 수집 모드
     * @return static 팩토리 인스턴스
     */
    public function privacyMode(PrivacyMode $mode): static
    {
        return $mode === PrivacyMode::Anonymous ? $this->anonymousMode() : $this;
    }
}
