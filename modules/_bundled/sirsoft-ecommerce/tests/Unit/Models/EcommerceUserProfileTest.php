<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Models;

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Ecommerce\Models\EcommerceUserProfile;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\EcommerceUserProfileRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 이커머스 사용자 프로필 모델/Repository 테스트 (A3)
 */
class EcommerceUserProfileTest extends ModuleTestCase
{
    public function test_table_exists_with_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('ecommerce_user_profiles'));
        $this->assertTrue(Schema::hasColumns('ecommerce_user_profiles', [
            'user_id', 'preferred_currency',
        ]));
    }

    public function test_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $profile = EcommerceUserProfile::create([
            'user_id' => $user->id,
            'preferred_currency' => 'USD',
        ]);

        $this->assertInstanceOf(User::class, $profile->user);
        $this->assertSame($user->id, $profile->user->id);
    }

    public function test_repository_set_and_get_preferred_currency(): void
    {
        $user = User::factory()->create();
        /** @var EcommerceUserProfileRepositoryInterface $repo */
        $repo = app(EcommerceUserProfileRepositoryInterface::class);

        $repo->setPreferredCurrency($user->id, 'JPY');

        $this->assertSame('JPY', $repo->getPreferredCurrency($user->id));
    }

    public function test_repository_set_is_upsert(): void
    {
        $user = User::factory()->create();
        /** @var EcommerceUserProfileRepositoryInterface $repo */
        $repo = app(EcommerceUserProfileRepositoryInterface::class);

        $repo->setPreferredCurrency($user->id, 'USD');
        $repo->setPreferredCurrency($user->id, 'EUR');

        // 두 번째 호출은 update (중복 row 생성 안 함)
        $this->assertSame('EUR', $repo->getPreferredCurrency($user->id));
        $this->assertSame(1, EcommerceUserProfile::where('user_id', $user->id)->count());
    }

    public function test_get_preferred_currency_null_when_no_profile(): void
    {
        $user = User::factory()->create();
        /** @var EcommerceUserProfileRepositoryInterface $repo */
        $repo = app(EcommerceUserProfileRepositoryInterface::class);

        $this->assertNull($repo->getPreferredCurrency($user->id));
    }
}
