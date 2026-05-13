<?php

namespace Modules\Sirsoft\Ecommerce\Database\Seeders;

use App\Models\User;
use App\Traits\HasSeederCounts;
use Illuminate\Database\Seeder;
use Modules\Sirsoft\Ecommerce\Models\UserAddress;

/**
 * 사용자 배송지 더미 데이터 시더
 *
 * 모듈 설치 시 자동 실행되지 않습니다 (DatabaseSeeder에 미등록).
 * 수동 실행: php artisan module:seed sirsoft-ecommerce --class=UserAddressSeeder
 */
class UserAddressSeeder extends Seeder
{
    use HasSeederCounts;

    /**
     * 배송지를 생성할 기본 사용자 수
     */
    private const USER_COUNT = 10;

    /**
     * 사용자당 배송지 최소 수
     */
    private const MIN_ADDRESSES_PER_USER = 1;

    /**
     * 사용자당 배송지 최대 수
     */
    private const MAX_ADDRESSES_PER_USER = 5;

    /**
     * 사용자당 해외 배송지 생성 확률 (%)
     */
    private const INTERNATIONAL_ADDRESS_CHANCE = 30;

    /**
     * 국내 배송지명 목록
     */
    private const DOMESTIC_NAMES = [
        '집',
        '회사',
        '본가',
        '학교',
        '사무실',
        '친구집',
        '아파트',
        '오피스텔',
        '별장',
        '기숙사',
    ];

    /**
     * 해외 배송지명 목록
     */
    private const INTERNATIONAL_NAMES = [
        'US 사무실',
        '도쿄 집',
        '상하이 사무실',
        '런던 플랫',
        'LA 하우스',
        'NYC 아파트',
        '오사카 스튜디오',
        '베를린 사무실',
    ];

    /**
     * 시더 실행
     */
    public function run(): void
    {
        $this->command->info('사용자 배송지 더미 데이터 생성을 시작합니다.');

        $this->deleteExistingAddresses();
        $this->createAddresses();

        $count = UserAddress::count();
        $this->command->info("사용자 배송지 더미 데이터 {$count}건이 성공적으로 생성되었습니다.");
    }

    /**
     * 기존 배송지 삭제
     */
    private function deleteExistingAddresses(): void
    {
        $deletedCount = UserAddress::count();

        if ($deletedCount > 0) {
            UserAddress::query()->delete();
            $this->command->warn("기존 배송지 데이터 {$deletedCount}건을 삭제했습니다.");
        }
    }

    /**
     * 사용자별 배송지 생성
     */
    private function createAddresses(): void
    {
        $userCount = $this->getSeederCount('address_users', self::USER_COUNT);
        $users = User::take($userCount)->get();

        if ($users->isEmpty()) {
            $this->command->error('사용자가 없습니다. 먼저 사용자를 생성해주세요.');

            return;
        }

        $this->command->line("  - 사용자 {$users->count()}명의 배송지 생성 중...");

        $domesticCount = 0;
        $internationalCount = 0;

        foreach ($users as $user) {
            $addressCount = rand(self::MIN_ADDRESSES_PER_USER, self::MAX_ADDRESSES_PER_USER);
            $usedDomesticIndices = [];
            $usedInternationalIndices = [];

            for ($i = 0; $i < $addressCount; $i++) {
                $isInternational = $i > 0 && rand(1, 100) <= self::INTERNATIONAL_ADDRESS_CHANCE;

                if ($isInternational) {
                    [$index, $name] = $this->pickUniqueName($usedInternationalIndices, self::INTERNATIONAL_NAMES);
                    $usedInternationalIndices[] = $index;

                    UserAddress::factory()
                        ->forUser($user)
                        ->international()
                        ->state([
                            'name' => $name,
                            'is_default' => false,
                        ])
                        ->create();

                    $internationalCount++;
                } else {
                    [$index, $name] = $this->pickUniqueName($usedDomesticIndices, self::DOMESTIC_NAMES);
                    $usedDomesticIndices[] = $index;

                    UserAddress::factory()
                        ->forUser($user)
                        ->state([
                            'name' => $name,
                            'is_default' => $i === 0,
                        ])
                        ->create();

                    $domesticCount++;
                }
            }
        }

        $total = $domesticCount + $internationalCount;
        $this->command->line("    - 배송지 {$total}건 생성 완료 (국내 {$domesticCount}건, 해외 {$internationalCount}건)");
    }

    /**
     * 중복되지 않는 배송지명을 선택합니다.
     *
     * @param array $usedIndices 이미 사용된 배송지명 인덱스 목록
     * @param array $namePool 배송지명 후보 목록
     * @return array [인덱스, 배송지명]
     */
    private function pickUniqueName(array $usedIndices, array $namePool): array
    {
        $availableIndices = array_values(array_diff(array_keys($namePool), $usedIndices));

        if (empty($availableIndices)) {
            $index = array_rand($namePool);
            $suffix = ' '.rand(2, 9);

            return [-1, $namePool[$index].$suffix];
        }

        $index = $availableIndices[array_rand($availableIndices)];

        return [$index, $namePool[$index]];
    }
}
