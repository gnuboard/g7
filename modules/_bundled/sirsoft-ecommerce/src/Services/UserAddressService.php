<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Modules\Sirsoft\Ecommerce\Exceptions\DuplicateAddressException;
use Modules\Sirsoft\Ecommerce\Models\UserAddress;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\UserAddressRepositoryInterface;

/**
 * 사용자 배송지 관리 서비스
 */
class UserAddressService
{
    /**
     * UserAddressService 생성자
     *
     * @param  UserAddressRepositoryInterface  $addressRepository  배송지 리포지토리
     * @param  UserRepositoryInterface  $userRepository  코어 사용자 리포지토리 (관리자 목록 회원 필터 uuid 해석)
     */
    public function __construct(
        protected UserAddressRepositoryInterface $addressRepository,
        protected UserRepositoryInterface $userRepository,
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
     *
     * @throws DuplicateAddressException 배송지명 중복 시 (force_overwrite 없으면 409)
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

    /**
     * 관리자용 전 회원 배송지 목록을 조회합니다.
     *
     * @param  array<string, mixed>  $filters  필터 (검색 대상·검색어·회원·기간·정렬)
     * @param  int  $perPage  페이지당 개수
     * @return LengthAwarePaginator 페이지네이션된 목록
     */
    public function paginateForAdmin(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return $this->addressRepository->paginateForAdmin($filters, $perPage);
    }

    /**
     * 관리자 목록의 회원 한정 필터를 해석해 목록과 한정 회원을 함께 돌려줍니다.
     *
     * 회원 소유 데이터라 회원 상세에서 `user_uuid` 를 달고 넘어오는 경로가 있고, 화면의 전용 회원
     * 필터는 여러 명(`user_uuids[]`)을 싣는다. 두 경우 모두 **없는 uuid 는 빈 결과**로 답한다 —
     * 필터를 무시하고 전체를 보여주면 "이 회원들" 을 요청한 화면에 남의 데이터가 뜬다. 한정 회원을
     * 함께 돌려주는 것은 화면이 uuid 만으로는 칩에 이름을 세울 수 없기 때문이다(코어 회원 검색은 uuid 를
     * 한 건씩만 받는다).
     *
     * 해석 규칙은 예전에 컨트롤러에 있었다 — 같은 목록을 부르는 다른 경로(내보내기 등)가 생기면
     * 규칙을 복제하게 되고, 사본 하나만 필터를 풀어 남의 데이터를 낸다.
     *
     * @param  array<string, mixed>  $filters  검증된 필터
     * @param  int  $perPage  페이지당 개수
     * @return array{rows: LengthAwarePaginator, members: SupportCollection} 목록과 한정 회원
     */
    public function paginateForAdminWithMembers(array $filters, int $perPage = 20): array
    {
        $members = $this->resolveAdminFilterMembers($filters);

        if (! empty($filters['user_uuids'])) {
            // 고른 uuid 가 전부 사라진 회원이어도 빈 배열을 그대로 싣는다 — 필터가 풀리면 안 된다.
            $filters['user_ids'] = $members->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        $filters['user_id'] = $this->resolveAdminFilterUserId($filters);

        return [
            'rows' => $this->paginateForAdmin($filters, $perPage),
            'members' => $members,
        ];
    }

    /**
     * 단건 회원 필터(`user_id` / `user_uuid`)를 회원 ID 로 해석합니다.
     *
     * user_uuid 가 왔는데 그런 회원이 없으면 -1 을 돌려 빈 결과를 만든다. 다중 필터(`user_uuids`)가
     * 이미 회원을 잡았으면 단건 해석은 건너뛴다 — 둘을 함께 걸면 교집합이 비어 "고른 회원이 있는데
     * 결과가 0건" 이 된다.
     *
     * @param  array<string, mixed>  $filters  검증된 필터
     * @return int|null 회원 ID (필터 없으면 null)
     */
    protected function resolveAdminFilterUserId(array $filters): ?int
    {
        if (! empty($filters['user_id'])) {
            return (int) $filters['user_id'];
        }

        if (! empty($filters['user_uuids']) || empty($filters['user_uuid'])) {
            return null;
        }

        return (int) ($this->userRepository->findByUuid((string) $filters['user_uuid'])?->id ?? -1);
    }

    /**
     * 회원 한정 필터(uuid 목록)를 회원으로 해석합니다. (없는 uuid 는 조용히 빠진다)
     *
     * @param  array<string, mixed>  $filters  검증된 필터
     * @return SupportCollection<int, User> 해석된 회원 (필터가 없으면 빈 컬렉션)
     */
    protected function resolveAdminFilterMembers(array $filters): SupportCollection
    {
        $uuids = array_values(array_unique(array_filter((array) ($filters['user_uuids'] ?? []))));

        if ($uuids === []) {
            return collect();
        }

        return $this->userRepository->findManyByUuids($uuids);
    }

    /**
     * 관리자가 배송지 한 건을 삭제합니다 (소유자 무관).
     *
     * @param  int  $id  행 ID
     * @return bool 삭제 성공 여부
     */
    public function deleteByAdmin(int $id): bool
    {
        return $this->addressRepository->deleteById($id);
    }

    /**
     * 관리자가 배송지를 일괄 삭제합니다 (소유자 무관).
     *
     * 훅을 루프 앞뒤로 한 번씩만 발화한다 — 항목마다 발화하면 활동 로그가 폭주하고,
     * IDV 같은 before 훅 소비자가 루프 중간에 끼어들어 앞부분만 지워진 상태가 된다.
     *
     * @param  array<int, int>  $ids  행 ID 목록
     * @return int 실제로 삭제된 건수
     */
    public function bulkDeleteByAdmin(array $ids): int
    {
        // 스냅샷은 삭제 «전» 에 뜬다 — 배송지는 소프트 삭제가 아니라 지우고 나면 행이
        // 사라져 활동 로그에 남은 id 로는 「누구의 무엇」을 되짚을 수 없다.
        $snapshots = $this->addressRepository->findByIds($ids)
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'user_id' => $row->user_id !== null ? (int) $row->user_id : null,
                'user_uuid' => $row->user?->uuid,
                'user_name' => $row->user?->name,
                'label' => $row->name,
                'recipient_name' => $row->recipient_name,
            ])
            ->all();

        HookManager::doAction('sirsoft-ecommerce.user_address.before_bulk_delete', $ids);

        $count = $this->addressRepository->bulkDelete($ids);

        HookManager::doAction('sirsoft-ecommerce.user_address.after_bulk_delete', $ids, $count, $snapshots);

        return $count;
    }

    /**
     * 주문서가 보낸 배송지를 주소록에 남깁니다 (갱신 또는 추가).
     *
     * `$addressId` 가 오면 그 행을 «갱신» 한다 — 주문서에서 저장된 배송지를 고른 뒤 그 자리에서
     * 고친 경우다. 갱신을 두지 않으면 고친 주소가 주소록에 남지 않아 다음 주문서도 옛 주소로
     * 열리고, 같은 손질을 매번 반복하게 된다(2026-09-21 요구사항). 배송지명(`name`)은 건드리지
     * 않는다 — 구매자가 붙여 둔 이름("회사", "본가")이 주소를 고칠 때마다 사라지면 목록에서
     * 어느 것이 무엇인지 알 수 없다.
     *
     * 소유자가 아닌 행 ID 면 `updateAddress` 가 null 을 돌린다. 그때 새로 만들지 않는 것은
     * 의도다 — 남의 행을 가리킨 요청이 "그럼 추가" 로 조용히 성공하면, 잘못 실린 ID 가 드러날
     * 자리가 영영 없다.
     *
     * @param  int  $userId  회원 ID
     * @param  array<string, mixed>  $shippingInfo  주문서가 보낸 배송지 정보
     * @param  int|null  $addressId  갱신할 주소록 행 ID (없으면 새로 추가)
     * @return UserAddress|null 저장된 배송지 (갱신 대상이 없으면 null)
     */
    public function storeShippingInfoForUser(int $userId, array $shippingInfo, ?int $addressId = null): ?UserAddress
    {
        if ($addressId !== null && $addressId > 0) {
            $data = $this->mapShippingInfoToAddressData($userId, '', $shippingInfo);
            unset($data['name'], $data['user_id']);

            return $this->updateAddress($userId, $addressId, $data);
        }

        $name = $this->generateUniqueName(
            $userId,
            __('sirsoft-ecommerce::messages.address.auto_saved_label')
        );

        // 국가별 명시 매핑 (B4 — 폴백 혼재 해외 필드 누락/오저장 방지)
        return $this->createAddress($this->mapShippingInfoToAddressData($userId, $name, $shippingInfo));
    }

    /**
     * 회원정보 화면이 쓸 배송지 요약 줄을 만듭니다.
     *
     * 관리자 회원 상세와 마이페이지 프로필이 같은 모양을 읽는다 — 건수만 있고 «어디로 보내는
     * 주소인가» 가 없으면, 두 화면 모두 배송지를 확인하려고 다른 화면으로 나가야 한다
     * (2026-09-21 검수 지적). 기본 배송지가 맨 앞이고(리포지토리 정렬), 상한을 두는 것은
     * 회원 상세가 주소록 관리 화면을 대신하지는 않기 때문이다 — 전체는 그 화면이 낸다.
     *
     * @param  int  $userId  회원 ID
     * @param  int  $limit  최대 건수
     * @return array<int, array<string, mixed>> 요약 줄 목록 (기본 배송지 우선)
     */
    public function summarizeForUserInfo(int $userId, int $limit = 5): array
    {
        return $this->addressRepository->findByUserId($userId)
            ->take(max(1, $limit))
            ->map(fn (UserAddress $address) => $this->toSummaryLine($address))
            ->values()
            ->all();
    }

    /**
     * 회원의 기본 배송지를 요약 줄 하나로 만듭니다 (없으면 null).
     *
     * 프로필(/api/me)은 목록 대신 이 한 줄만 읽는다 — 부팅 경로라 질의를 늘리지 않는다.
     *
     * @param  int  $userId  회원 ID
     * @return array<string, mixed>|null 기본 배송지 요약 (없으면 null)
     */
    public function defaultSummaryForUserInfo(int $userId): ?array
    {
        $address = $this->getDefaultAddress($userId);

        return $address !== null ? $this->toSummaryLine($address) : null;
    }

    /**
     * 배송지 한 건을 요약 줄로 접습니다.
     *
     * 주소 문자열은 모델이 만든다(`getFullAddressWithZipcode`) — 국내/해외가 서로 다른 컬럼에
     * 담기므로 화면마다 합치는 규칙을 다시 쓰면 한쪽만 고쳐져 어긋난다.
     *
     * @param  UserAddress  $address  배송지 모델
     * @return array<string, mixed> 요약 줄
     */
    private function toSummaryLine(UserAddress $address): array
    {
        return [
            'id' => (int) $address->id,
            'label' => (string) $address->name,
            'recipient_name' => (string) $address->recipient_name,
            'recipient_phone' => (string) $address->recipient_phone,
            'country_code' => (string) $address->country_code,
            'address_summary' => $address->getFullAddressWithZipcode(),
            'is_default' => (bool) $address->is_default,
        ];
    }

    /**
     * 회원의 배송지 건수를 조회합니다 (회원 상세 요약용).
     *
     * @param  int  $userId  회원 ID
     * @return int 건수
     */
    public function countByUserForAdmin(int $userId): int
    {
        return $this->addressRepository->countByUser($userId);
    }
}
