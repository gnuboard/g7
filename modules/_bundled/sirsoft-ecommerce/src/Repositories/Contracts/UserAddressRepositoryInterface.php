<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Modules\Sirsoft\Ecommerce\Models\UserAddress;

/**
 * 사용자 배송지 Repository 인터페이스
 */
interface UserAddressRepositoryInterface
{
    /**
     * ID로 배송지 조회
     *
     * @param  int  $id  배송지 ID
     * @return UserAddress|null
     */
    public function find(int $id): ?UserAddress;

    /**
     * 배송지 생성
     *
     * @param  array  $data  배송지 데이터
     * @return UserAddress
     */
    public function create(array $data): UserAddress;

    /**
     * 배송지 수정
     *
     * @param  UserAddress  $address  배송지 모델
     * @param  array  $data  수정 데이터
     * @return UserAddress
     */
    public function update(UserAddress $address, array $data): UserAddress;

    /**
     * 배송지 삭제
     *
     * @param  UserAddress  $address  배송지 모델
     * @return bool
     */
    public function delete(UserAddress $address): bool;

    /**
     * 회원의 배송지 목록 조회
     *
     * @param  int  $userId  회원 ID
     * @return Collection
     */
    public function findByUserId(int $userId): Collection;

    /**
     * 회원의 기본 배송지 조회
     *
     * @param  int  $userId  회원 ID
     * @return UserAddress|null
     */
    public function findDefaultByUserId(int $userId): ?UserAddress;

    /**
     * 기본 배송지 설정
     *
     * @param  int  $userId  회원 ID
     * @param  int  $addressId  배송지 ID
     * @return bool
     */
    public function setDefault(int $userId, int $addressId): bool;

    /**
     * 회원의 배송지 개수 조회
     *
     * @param  int  $userId  회원 ID
     * @return int 배송지 개수
     */
    public function countByUserId(int $userId): int;

    /**
     * 회원의 특정 배송지 조회 (소유권 확인)
     *
     * @param  int  $userId  회원 ID
     * @param  int  $addressId  배송지 ID
     * @return UserAddress|null
     */
    public function findByUserIdAndId(int $userId, int $addressId): ?UserAddress;

    /**
     * 사용자 ID와 배송지명으로 배송지 조회
     *
     * @param  int  $userId  사용자 ID
     * @param  string  $name  배송지명
     * @return UserAddress|null
     */
    public function findByUserIdAndName(int $userId, string $name): ?UserAddress;

    /**
     * 관리자용 전 회원 배송지 목록을 페이지네이션합니다.
     *
     * @param  array<string, mixed>  $filters  필터 (search_field, search_keyword, user_id, user_ids, 기간, 정렬)
     * @param  int  $perPage  페이지당 개수
     * @return LengthAwarePaginator 페이지네이션된 목록
     */
    public function paginateForAdmin(array $filters, int $perPage = 20): LengthAwarePaginator;

    /**
     * 배송지 행을 ID 로 삭제합니다 (소유자 무관 — 관리자 전용).
     *
     * @param  int  $id  행 ID
     * @return bool 삭제 성공 여부
     */
    public function deleteById(int $id): bool;

    /**
     * 배송지 행을 ID 목록으로 조회합니다 (일괄 삭제 전 스냅샷용).
     *
     * 배송지는 소프트 삭제가 아니라 지우면 행이 사라진다 — 활동 로그가 「누구의 무엇을
     * 지웠는가」를 남기려면 삭제 «전» 에 회원을 함께 읽어 둬야 한다.
     *
     * @param  array<int, int>  $ids  행 ID 목록
     * @return Collection<int, UserAddress>
     */
    public function findByIds(array $ids): Collection;

    /**
     * 배송지 행을 ID 목록으로 일괄 삭제합니다 (소유자 무관 — 관리자 전용).
     *
     * @param  array<int, int>  $ids  행 ID 목록
     * @return int 실제로 삭제된 건수
     */
    public function bulkDelete(array $ids): int;

    /**
     * 회원의 배송지 건수를 조회합니다 (회원 상세 요약용).
     *
     * @param  int  $userId  회원 ID
     * @return int 건수
     */
    public function countByUser(int $userId): int;

    /**
     * 배송지를 가진 회원 ID 서브쿼리를 만듭니다 (회원 목록 확장 필터용, #705).
     *
     * @param  string|null  $countryCode  국가 코드 (null 이면 국가 무관 — 배송지가 하나라도 있는 회원)
     * @return Builder user_id 한 열을 고르는 서브쿼리
     */
    public function userIdsWithAddressQuery(?string $countryCode = null): Builder;
}
