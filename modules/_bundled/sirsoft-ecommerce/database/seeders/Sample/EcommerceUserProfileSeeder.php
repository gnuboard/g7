<?php

namespace Modules\Sirsoft\Ecommerce\Database\Seeders\Sample;

use App\Models\User;
use App\Traits\HasSeederCounts;
use Illuminate\Database\Seeder;
use Modules\Sirsoft\Ecommerce\Models\EcommerceUserProfile;
use Modules\Sirsoft\Ecommerce\Services\SignupCurrencyResolver;

/**
 * 이커머스 사용자 프로필 더미 데이터 시더 (MP08 다통화)
 *
 * 회원별 선호 결제 통화(preferred_currency) · 선호 배송국가(preferred_shipping_country) 를
 * 채워, 다통화·해외배송 검수/데모에서 "회원마다 다른 통화·배송국가" 상태를 재현합니다.
 *
 * 통화 부여는 실제 가입 로직과 동일한 SignupCurrencyResolver(user.language → currencies[].locales 매핑)
 * 를 재사용하므로, 시더 데이터가 프로덕션 통화 결정 규칙과 정합합니다. 미설정(null) 폴백 동작도
 * 함께 재현하기 위해 일부 회원은 의도적으로 프로필을 비웁니다.
 */
class EcommerceUserProfileSeeder extends Seeder
{
    use HasSeederCounts;

    /**
     * 프로필을 생성할 기본 회원 수
     */
    private const PROFILE_USER_COUNT = 40;

    /**
     * 선호 배송국가를 명시 지정할 회원 비율 (%) — 나머지는 null(GeoIP/기본국가 폴백 재현)
     */
    private const SHIPPING_COUNTRY_CHANCE = 40;

    /**
     * 선호 통화를 비워 폴백 동작을 재현할 회원 비율 (%)
     */
    private const NULL_CURRENCY_CHANCE = 15;

    /**
     * 선호 배송국가 후보 (ISO 3166-1 alpha-2)
     */
    private const SHIPPING_COUNTRIES = ['KR', 'US', 'JP', 'CN', 'GB', 'DE'];

    /**
     * 시더 실행
     */
    public function run(): void
    {
        $this->command->info('이커머스 사용자 프로필 더미 데이터 생성을 시작합니다.');

        $this->deleteExistingProfiles();
        $this->createProfiles();

        $count = EcommerceUserProfile::count();
        $this->command->info("이커머스 사용자 프로필 {$count}건이 성공적으로 생성되었습니다.");
    }

    /**
     * 기존 프로필 삭제
     */
    private function deleteExistingProfiles(): void
    {
        $deletedCount = EcommerceUserProfile::count();

        if ($deletedCount > 0) {
            EcommerceUserProfile::query()->delete();
            $this->command->warn("기존 사용자 프로필 데이터 {$deletedCount}건을 삭제했습니다.");
        }
    }

    /**
     * 회원별 프로필 생성
     */
    private function createProfiles(): void
    {
        $userCount = $this->getSeederCount('profile_users', self::PROFILE_USER_COUNT);

        // 관리자 제외 일반 회원 우선 (없으면 전체 회원 폴백)
        $users = User::whereDoesntHave('roles', fn ($q) => $q->where('identifier', 'admin'))
            ->inRandomOrder()
            ->take($userCount)
            ->get();

        if ($users->isEmpty()) {
            $users = User::take($userCount)->get();
        }

        if ($users->isEmpty()) {
            $this->command->warn('회원이 없습니다. 사용자 프로필 생성을 건너뜁니다.');

            return;
        }

        /** @var SignupCurrencyResolver $resolver */
        $resolver = app(SignupCurrencyResolver::class);

        $currencyCount = 0;
        $shippingCount = 0;

        foreach ($users as $user) {
            // 통화: 실제 가입 로직과 동일하게 user.language → 통화 매핑 (일부는 폴백 재현 위해 null)
            $preferredCurrency = null;
            if (rand(1, 100) > self::NULL_CURRENCY_CHANCE) {
                $resolved = $resolver->resolve($user->language);
                $preferredCurrency = $resolver->isRegistered($resolved) ? $resolved : null;
            }

            // 배송국가: 일부 회원만 명시 지정 (나머지는 null → GeoIP/기본국가 폴백 재현)
            $preferredShippingCountry = null;
            if (rand(1, 100) <= self::SHIPPING_COUNTRY_CHANCE) {
                $preferredShippingCountry = self::SHIPPING_COUNTRIES[array_rand(self::SHIPPING_COUNTRIES)];
            }

            EcommerceUserProfile::create([
                'user_id' => $user->id,
                'preferred_currency' => $preferredCurrency,
                'preferred_shipping_country' => $preferredShippingCountry,
            ]);

            if ($preferredCurrency !== null) {
                $currencyCount++;
            }
            if ($preferredShippingCountry !== null) {
                $shippingCount++;
            }
        }

        $this->command->line("  - 프로필 {$users->count()}건 생성 (통화 지정 {$currencyCount}건, 배송국가 지정 {$shippingCount}건)");
    }
}
