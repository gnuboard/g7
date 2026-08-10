<?php

namespace App\Contracts\Repositories;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

interface UserRepositoryInterface
{
    /**
     * 이메일로 사용자를 찾습니다.
     *
     * @param  string  $email  찾을 사용자의 이메일
     * @return User|null 찾은 사용자 모델 또는 null
     */
    public function findByEmail(string $email): ?User;

    /**
     * 새로운 사용자를 생성합니다.
     *
     * @param  array  $data  사용자 생성 데이터
     * @return User 생성된 사용자 모델
     */
    public function create(array $data): User;

    /**
     * 기존 사용자를 업데이트합니다.
     *
     * @param  User  $user  업데이트할 사용자 모델
     * @param  array  $data  업데이트할 데이터
     * @return bool 업데이트 성공 여부
     */
    public function update(User $user, array $data): bool;

    /**
     * 사용자를 삭제합니다.
     *
     * @param  User  $user  삭제할 사용자 모델
     * @return bool 삭제 성공 여부
     */
    public function delete(User $user): bool;

    /**
     * 모든 사용자를 조회합니다.
     *
     * @return Collection 사용자 컬렉션
     */
    public function getAll(): Collection;

    /**
     * ID로 사용자를 찾습니다.
     *
     * @param  int  $id  사용자 ID
     * @return User|null 찾은 사용자 모델 또는 null
     */
    public function findById(int $id): ?User;

    /**
     * 필터링 및 페이지네이션이 적용된 사용자 목록을 조회합니다.
     *
     * @param  array  $filters  필터 조건 배열
     * @return LengthAwarePaginator 페이지네이션된 사용자 목록
     */
    public function getPaginatedUsers(array $filters = []): LengthAwarePaginator;

    /**
     * 사용자 관련 통계 정보를 조회합니다.
     *
     * @return array 사용자 통계 데이터 배열
     */
    public function getStatistics(): array;

    /**
     * 키워드로 사용자를 검색합니다. (이름, 닉네임, 이메일)
     *
     * @param  string  $keyword  검색할 키워드
     * @return Collection 검색된 사용자 컬렉션
     */
    public function searchByKeyword(string $keyword): Collection;

    /**
     * 최근 등록된 사용자들을 조회합니다.
     *
     * @param  int  $limit  조회할 사용자 수 (기본값: 10)
     * @return Collection 최근 사용자 컬렉션
     */
    public function getRecentUsers(int $limit = 10): Collection;

    /**
     * 언어별 사용자 수를 조회합니다.
     *
     * @return array 언어별 사용자 수 배열
     */
    public function getUsersByLanguage(): array;

    /**
     * UUID 목록으로 사용자들을 조회하고 UUID 키 맵으로 반환합니다.
     *
     * Bulk activity log 처리 시 N+1 회피용 단일 쿼리 진입점.
     *
     * @param  array<int, string>  $uuids  사용자 UUID 목록
     * @return Collection<string, User> uuid => User 매핑
     */
    public function findManyByUuidsKeyed(array $uuids): Collection;

    /**
     * UUID 목록으로 사용자들을 조회합니다.
     *
     * @param  array<int, string>  $uuids  사용자 UUID 목록
     * @return Collection<int, User> 조회된 사용자 컬렉션
     */
    public function findManyByUuids(array $uuids): Collection;

    /**
     * 슈퍼관리자 1명을 조회합니다.
     *
     * @return User|null 슈퍼관리자 또는 없으면 null
     */
    public function findSuperAdmin(): ?User;

    /**
     * 특정 권한 identifier 를 가진 역할에 소속된 모든 사용자를 조회합니다.
     *
     * @param  string  $permissionIdentifier  권한 identifier
     * @return Collection<int, User> 권한 보유 사용자 컬렉션
     */
    public function findManyByPermissionIdentifier(string $permissionIdentifier): Collection;

    /**
     * 사용자의 연속 로그인 실패 카운터를 1 증가시킵니다.
     *
     * `last_failed_login_at` 도 현재 시각으로 갱신하며 새 카운트를 반환합니다.
     *
     * @param  User  $user  대상 사용자
     * @return int 증가 후 카운트
     */
    public function incrementFailedAttempts(User $user): int;

    /**
     * 사용자의 계정을 지정된 분만큼 잠급니다.
     *
     * `locked_until` 을 현재 시각 + $minutes 로 설정하고 `failed_login_attempts` 를
     * 0 으로 리셋합니다 (다음 잠금 윈도우 시작점). 잠금 해제 시각을 반환합니다.
     *
     * `$minutes <= 0` 은 보안 환경설정의 "0 = 무한대" 규약에 따라 영구 잠금으로 처리하며,
     * 이때 `locked_until` 은 NULL, `locked_permanently` 는 true 가 되고 null 을 반환합니다.
     *
     * @param  User  $user  잠글 사용자
     * @param  int  $minutes  잠금 유지 시간(분). 0 이하는 무기한
     * @return Carbon|null 잠금 해제 시각 (영구 잠금은 null)
     */
    public function lockAccount(User $user, int $minutes): ?Carbon;

    /**
     * 사용자의 모든 로그인 시도 추적 컬럼을 초기화합니다.
     *
     * 정상 로그인 성공 시 또는 관리자 수동 해제 시 호출됩니다
     * (`failed_login_attempts=0`, `locked_until=null`, `locked_permanently=false`,
     * `last_failed_login_at=null`).
     *
     * @param  User  $user  대상 사용자
     */
    public function resetLoginAttempts(User $user): void;

    /**
     * 사용자의 계정이 현재 시점에 잠금 상태인지 판정합니다.
     *
     * `locked_permanently` 가 true 면 항상 true 입니다. 그 외에는 `locked_until` 이
     * NULL 이거나 현재 시각보다 과거이면 false 를 반환합니다.
     *
     * @param  User  $user  대상 사용자
     * @return bool 잠금 여부
     */
    public function isLocked(User $user): bool;

    /**
     * UUID 로 사용자를 찾습니다.
     *
     * @param  string  $uuid  사용자 UUID
     * @return User|null 찾은 사용자 모델 또는 null
     */
    public function findByUuid(string $uuid): ?User;

    /**
     * UUID 목록에 해당하는 사용자의 정수 ID 배열을 반환합니다.
     *
     * @param  array  $uuids  사용자 UUID 배열
     * @return array<int, int> 사용자 ID 배열
     */
    public function getIdsByUuids(array $uuids): array;

    /**
     * 사용자 ID 목록에 해당하는 이름을 ID 로 색인해 반환합니다.
     *
     * 목록 화면이 작성자 이름을 행마다 조회하면 N+1 이 되므로, 표시에 필요한
     * 이름만 한 번에 모아 오기 위한 배치 조회입니다.
     *
     * @param  array  $ids  사용자 ID 배열
     * @return array<int, string> 사용자 ID => 이름
     */
    public function getNamesByIds(array $ids): array;

    /**
     * 사용자 ID 목록의 지정 컬럼을 일괄 갱신합니다.
     *
     * @param  array  $ids  사용자 ID 배열
     * @param  array  $data  갱신할 컬럼 값
     * @return int 갱신된 행 수
     */
    public function updateManyByIds(array $ids, array $data): int;

    /**
     * 사용자 ID 목록의 인증 토큰을 모두 삭제합니다.
     *
     * @param  array  $ids  사용자 ID 배열
     * @return int 삭제된 토큰 수
     */
    public function deleteTokensByUserIds(array $ids): int;
}
