<?php

namespace App\Contracts\Repositories;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

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
     * @param  User  $user  잠글 사용자
     * @param  int  $minutes  잠금 유지 시간(분)
     * @return \Illuminate\Support\Carbon 잠금 해제 시각
     */
    public function lockAccount(User $user, int $minutes): \Illuminate\Support\Carbon;

    /**
     * 사용자의 모든 로그인 시도 추적 컬럼을 초기화합니다.
     *
     * 정상 로그인 성공 시 호출됩니다 (`failed_login_attempts=0`,
     * `locked_until=null`, `last_failed_login_at=null`).
     *
     * @param  User  $user  대상 사용자
     * @return void
     */
    public function resetLoginAttempts(User $user): void;

    /**
     * 사용자의 계정이 현재 시점에 잠금 상태인지 판정합니다.
     *
     * `locked_until` 이 NULL 이거나 현재 시각보다 과거이면 false 를 반환합니다.
     *
     * @param  User  $user  대상 사용자
     * @return bool 잠금 여부
     */
    public function isLocked(User $user): bool;
}