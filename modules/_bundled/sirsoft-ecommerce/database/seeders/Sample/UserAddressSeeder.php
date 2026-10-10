<?php

namespace Modules\Sirsoft\Ecommerce\Database\Seeders\Sample;

use App\Models\User;
use App\Traits\HasSeederCounts;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\UserAddress;

/**
 * 사용자 배송지 샘플 시더
 *
 * `module:seed sirsoft-ecommerce --sample` 의 샘플 그룹에서 OrderSeeder 다음에 실행됩니다.
 * 주문이 있는 일반 회원(관리자 제외)에게만 배송지를 만들고, 이미 배송지가 있는 회원은 건너뜁니다.
 * 기존 배송지는 지우지 않습니다 — 배송지는 회원이 직접 등록한 운영 데이터라 재시드가 건드리면 안 됩니다.
 */
class UserAddressSeeder extends Seeder
{
    use HasSeederCounts;

    /**
     * 배송지를 만들 기본 회원 수 상한
     */
    private const USER_COUNT = 30;

    /**
     * 회원당 배송지 최소 수
     */
    private const MIN_ADDRESSES_PER_USER = 1;

    /**
     * 회원당 배송지 최대 수
     */
    private const MAX_ADDRESSES_PER_USER = 3;

    /**
     * 두 번째 이후 배송지가 해외일 확률 (%)
     */
    private const INTERNATIONAL_ADDRESS_CHANCE = 30;

    /**
     * 국내 배송지명 목록
     */
    private const DOMESTIC_NAMES = ['집', '회사', '본가', '학교', '사무실', '친구집'];

    /**
     * 해외 배송지명 목록
     */
    private const INTERNATIONAL_NAMES = ['US 사무실', '도쿄 집', '상하이 사무실', '런던 플랫'];

    /**
     * 시더 실행
     *
     * @return void 반환값 없음
     */
    public function run(): void
    {
        $this->command->info('사용자 배송지 샘플 생성을 시작합니다.');

        $users = $this->resolveTargetUsers();

        if ($users->isEmpty()) {
            $this->command->warn('배송지를 만들 회원이 없습니다 (주문이 있고 배송지가 없는 일반 회원 0명). 건너뜁니다.');

            return;
        }

        $created = 0;
        foreach ($users as $user) {
            $created += $this->createAddressesFor($user);
        }

        $this->command->info("사용자 배송지 샘플 {$created}건을 회원 {$users->count()}명에게 만들었습니다 (기존 배송지는 그대로 둡니다).");
    }

    /**
     * 배송지를 만들 회원을 고릅니다 — 주문이 있는 일반 회원 중 배송지가 아직 없는 회원.
     *
     * @return Collection<int, User> 대상 회원 목록
     */
    private function resolveTargetUsers()
    {
        $limit = $this->getSeederCount('address_users', self::USER_COUNT);

        $userIdsWithOrders = Order::query()->whereNotNull('user_id')->distinct()->pluck('user_id');
        $userIdsWithAddresses = UserAddress::query()->distinct()->pluck('user_id');

        return User::query()
            ->whereIn('id', $userIdsWithOrders)
            ->whereNotIn('id', $userIdsWithAddresses)
            ->whereDoesntHave('roles', fn ($q) => $q->where('identifier', 'admin'))
            ->orderBy('id')
            ->take($limit)
            ->get();
    }

    /**
     * 회원 한 명에게 배송지 1~3개를 만듭니다. 첫 배송지가 기본 배송지이며, 최근 국내 주문의
     * 배송지가 있으면 그 주소를 기본 배송지로 씁니다(주문 목록과 배송지 목록이 같은 주소를 보이도록).
     *
     * @param  User  $user  대상 회원
     * @return int 만든 배송지 수
     */
    private function createAddressesFor(User $user): int
    {
        $count = rand(self::MIN_ADDRESSES_PER_USER, self::MAX_ADDRESSES_PER_USER);
        $usedDomestic = [];
        $usedInternational = [];

        for ($i = 0; $i < $count; $i++) {
            $isDefault = $i === 0;
            $isInternational = ! $isDefault && rand(1, 100) <= self::INTERNATIONAL_ADDRESS_CHANCE;

            if ($isInternational) {
                $name = $this->pickUniqueName($usedInternational, self::INTERNATIONAL_NAMES);
                UserAddress::factory()->forUser($user)->international()
                    ->state(['name' => $name, 'is_default' => false])
                    ->create();

                continue;
            }

            $name = $this->pickUniqueName($usedDomestic, self::DOMESTIC_NAMES);
            $state = ['name' => $name, 'is_default' => $isDefault];

            if ($isDefault) {
                $state += $this->latestDomesticOrderAddress($user);
            }

            UserAddress::factory()->forUser($user)->state($state)->create();
        }

        return $count;
    }

    /**
     * 회원의 가장 최근 국내 주문 배송지를 배송지 속성으로 바꿔 돌려줍니다. 없으면 빈 배열.
     *
     * @param  User  $user  대상 회원
     * @return array<string, mixed> 배송지 속성 (없으면 빈 배열)
     */
    private function latestDomesticOrderAddress(User $user): array
    {
        $address = OrderAddress::query()
            ->whereIn('order_id', Order::query()->where('user_id', $user->id)->select('id'))
            ->where('address_type', 'shipping')
            ->where(fn ($q) => $q->whereNull('recipient_country_code')->orWhere('recipient_country_code', 'KR'))
            ->whereNotNull('address')
            ->orderByDesc('id')
            ->first();

        if (! $address) {
            return [];
        }

        return [
            'recipient_name' => $address->recipient_name,
            'recipient_phone' => $address->recipient_phone,
            'country_code' => 'KR',
            'zipcode' => $address->zipcode,
            'address' => $address->address,
            'address_detail' => $address->address_detail,
        ];
    }

    /**
     * 같은 회원 안에서 겹치지 않는 배송지명을 고릅니다.
     *
     * @param  array<int, string>  $used  이미 쓴 이름 (참조로 갱신)
     * @param  array<int, string>  $pool  후보 이름
     * @return string 배송지명
     */
    private function pickUniqueName(array &$used, array $pool): string
    {
        $available = array_values(array_diff($pool, $used));
        $name = empty($available)
            ? $pool[array_rand($pool)].' '.rand(2, 9)
            : $available[array_rand($available)];
        $used[] = $name;

        return $name;
    }
}
