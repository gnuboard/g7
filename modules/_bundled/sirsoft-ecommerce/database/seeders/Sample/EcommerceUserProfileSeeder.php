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
        $this->command->info(__('sirsoft-ecommerce::messages.seeders.ecommerce_user_profile.start'));

        $this->deleteExistingProfiles();
        $this->createProfiles();

        $count = EcommerceUserProfile::count();
        $this->command->info(__('sirsoft-ecommerce::messages.seeders.ecommerce_user_profile.done', ['count' => $count]));
    }

    /**
     * 기존 프로필 삭제
     */
    private function deleteExistingProfiles(): void
    {
        $deletedCount = EcommerceUserProfile::count();

        if ($deletedCount > 0) {
            EcommerceUserProfile::query()->delete();
            $this->command->warn(__('sirsoft-ecommerce::messages.seeders.ecommerce_user_profile.purged', ['count' => $deletedCount]));
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
            $this->command->warn(__('sirsoft-ecommerce::messages.seeders.ecommerce_user_profile.skip_no_users'));

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

        $this->command->line('  - '.__('sirsoft-ecommerce::messages.seeders.ecommerce_user_profile.created', ['count' => $users->count(), 'currency' => $currencyCount, 'shipping' => $shippingCount]));
    }
}
