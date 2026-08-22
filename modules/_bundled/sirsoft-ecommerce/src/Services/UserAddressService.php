<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Exceptions\HttpResponseException;
use Modules\Sirsoft\Ecommerce\Exceptions\DuplicateAddressException;
use Modules\Sirsoft\Ecommerce\Models\UserAddress;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\UserAddressRepositoryInterface;

/**
 * 사용자 배송지 관리 서비스
 */
class UserAddressService
{
    public function __construct(
        protected UserAddressRepositoryInterface $addressRepository
    ) {}

    /**
     * 사용자의 배송지 목록 조회
     *
     * @param  int  $userId  사용자 ID
     * @return Collection 배송지 목록
     */
    public function getUserAddresses(int $userId): Collection
    {
        return $this->addressRepository->findByUserId($userId);
    }

    /**
     * 사용자의 기본 배송지 조회
     *
     * @param  int  $userId  사용자 ID
     * @return UserAddress|null 기본 배송지
     */
    public function getDefaultAddress(int $userId): ?UserAddress
    {
        return $this->addressRepository->findDefaultByUserId($userId);
    }

    /**
     * 사용자의 특정 배송지 조회 (소유권 확인)
     *
     * @param  int  $userId  사용자 ID
     * @param  int  $addressId  배송지 ID
     * @return UserAddress|null 배송지
     */
    public function getAddress(int $userId, int $addressId): ?UserAddress
    {
        return $this->addressRepository->findByUserIdAndId($userId, $addressId);
    }

    /**
     * 배송지 생성
     *
     * @param  array  $data  배송지 데이터 (user_id 포함)
     * @return UserAddress 생성된 배송지
     *
     * @throws HttpResponseException 배송지명 중복 시 (force_overwrite 없으면 409)
     */
    /**
     * 체크아웃/주문 제출 배송지 정보를 UserAddress 컬럼 구조로 국가별 명시 매핑합니다. (B4)
     *
     * 폴백 혼재(zipcode ← intl_postal_code 등)로 인한 해외 필드 누락/오저장을 방지한다.
     * 국내(KR)는 zipcode/address/address_detail 만, 해외는 address_line_1/2·city·state·postal_code 만 채운다.
     * 체크아웃 제출 키(intl_city/intl_state/intl_postal_code) ↔ UserAddress 컬럼(city/state/postal_code) 변환.
     *
     * @param  int  $userId  사용자 ID
     * @param  string  $name  배송지명
     * @param  array<string, mixed>  $shippingInfo  체크아웃 제출 배송지 정보
     * @return array<string, mixed> UserAddress 생성용 데이터
     */
    public function mapShippingInfoToAddressData(int $userId, string $name, array $shippingInfo): array
    {
        $countryCode = strtoupper((string) ($shippingInfo['country_code'] ?? 'KR'));
        if ($countryCode === '') {
            $countryCode = 'KR';
        }
        $isDomestic = $countryCode === 'KR';

        $base = [
            'user_id' => $userId,
            'name' => $name,
            'recipient_name' => $shippingInfo['recipient_name'] ?? '',
            'recipient_phone' => $shippingInfo['recipient_phone'] ?? '',
            'country_code' => $countryCode,
        ];

        if ($isDomestic) {
            return array_merge($base, [
                'zipcode' => $shippingInfo['zipcode'] ?? '',
                'address' => $shippingInfo['address'] ?? '',
                'address_detail' => $shippingInfo['address_detail'] ?? '',
            ]);
        }

        // 해외: 체크아웃 intl_* 키 → UserAddress city/state/postal_code 컬럼 변환
        return array_merge($base, [
            'address_line_1' => $shippingInfo['address_line_1'] ?? '',
            'address_line_2' => $shippingInfo['address_line_2'] ?? '',
            'city' => $shippingInfo['intl_city'] ?? '',
            'state' => $shippingInfo['intl_state'] ?? '',
            'postal_code' => $shippingInfo['intl_postal_code'] ?? '',
        ]);
    }

    /**
     * 회원 주소록 항목을 생성합니다.
     *
     * @param  array  $data  주소 생성 데이터 (user_id, name, 국내/해외 필드)
     * @return UserAddress 생성된 주소록 모델
     */
    public function createAddress(array $data): UserAddress
    {
        HookManager::doAction('sirsoft-ecommerce.user_address.before_create', $data);

        $userId = $data['user_id'];

        // 배송지명 중복 확인
        $existingAddress = $this->addressRepository->findByUserIdAndName($userId, $data['name'] ?? '');

        if ($existingAddress) {
            if (! empty($data['force_overwrite'])) {
                // 덮어쓰기: 기존 배송지를 업데이트
                unset($data['force_overwrite']);

                return $this->updateAddress($userId, $existingAddress->id, $data);
            }

            throw new DuplicateAddressException($existingAddress->id);
        }

        unset($data['force_overwrite']);

        // 첫 번째 배송지는 자동으로 기본 배송지 설정
        if ($this->addressRepository->countByUserId($userId) === 0) {
            $data['is_default'] = true;
        }

        // 기본 배송지로 설정 요청 시 기존 기본 배송지 해제
        if (! empty($data['is_default']) && $data['is_default']) {
            $this->addressRepository->setDefault($userId, 0); // 기존 기본 해제
        }

        $address = $this->addressRepository->create($data);

        HookManager::doAction('sirsoft-ecommerce.user_address.after_create', $address);

        return $address;
    }

    /**
     * 배송지 수정
     *
     * @param  int  $userId  사용자 ID
     * @param  int  $addressId  배송지 ID
     * @param  array  $data  수정 데이터
     * @return UserAddress|null 수정된 배송지 (없으면 null)
     */
    public function updateAddress(int $userId, int $addressId, array $data): ?UserAddress
    {
        $address = $this->addressRepository->findByUserIdAndId($userId, $addressId);

        if (! $address) {
            return null;
        }

        HookManager::doAction('sirsoft-ecommerce.user_address.before_update', $address, $data);

        // 기본 배송지로 설정 요청 시 기존 기본 배송지 해제
        if (! empty($data['is_default']) && $data['is_default']) {
            $this->addressRepository->setDefault($userId, $addressId);
            unset($data['is_default']);
        }

        $updated = $this->addressRepository->update($address, $data);

        HookManager::doAction('sirsoft-ecommerce.user_address.after_update', $updated);

        return $updated;
    }

    /**
     * 배송지 삭제
     *
     * @param  int  $userId  사용자 ID
     * @param  int  $addressId  배송지 ID
     * @return bool 삭제 성공 여부
     */
    public function deleteAddress(int $userId, int $addressId): bool
    {
        $address = $this->addressRepository->findByUserIdAndId($userId, $addressId);

        if (! $address) {
            return false;
        }

        HookManager::doAction('sirsoft-ecommerce.user_address.before_delete', $address);

        $wasDefault = $address->is_default;

        $result = $this->addressRepository->delete($address);

        // 기본 배송지가 삭제된 경우, 가장 최근 배송지를 기본으로 설정
        if ($result && $wasDefault) {
            $addresses = $this->addressRepository->findByUserId($userId);
            if ($addresses->isNotEmpty()) {
                $this->addressRepository->setDefault($userId, $addresses->first()->id);
            }
        }

        HookManager::doAction('sirsoft-ecommerce.user_address.after_delete', $address->id);

        return $result;
    }

    /**
     * 기본 배송지 설정
     *
     * @param  int  $userId  사용자 ID
     * @param  int  $addressId  배송지 ID
     * @return UserAddress|null 설정된 기본 배송지
     */
    public function setDefaultAddress(int $userId, int $addressId): ?UserAddress
    {
        $address = $this->addressRepository->findByUserIdAndId($userId, $addressId);

        if (! $address) {
            return null;
        }

        HookManager::doAction('sirsoft-ecommerce.user_address.before_set_default', $address);

        $this->addressRepository->setDefault($userId, $addressId);

        $fresh = $address->fresh();

        HookManager::doAction('sirsoft-ecommerce.user_address.after_set_default', $fresh);

        return $fresh;
    }

    /**
     * 고유한 배송지명 생성 (자동 순번 부여)
     *
     * @param  int  $userId  사용자 ID
     * @param  string  $baseName  기본 이름 (예: '새 배송지')
     * @return string 고유한 배송지명 (예: '새 배송지', '새 배송지 (2)', '새 배송지 (3)')
     */
    public function generateUniqueName(int $userId, string $baseName): string
    {
        $existing = $this->addressRepository->findByUserIdAndName($userId, $baseName);
        if (! $existing) {
            return $baseName;
        }

        $suffix = 2;
        do {
            $candidateName = "{$baseName} ({$suffix})";
            $existing = $this->addressRepository->findByUserIdAndName($userId, $candidateName);
            $suffix++;
        } while ($existing);

        return $candidateName;
    }
}
