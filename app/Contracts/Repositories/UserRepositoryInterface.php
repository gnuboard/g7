<?php

namespace App\Contracts\Repositories;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

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
     * 회원 이메일을 인증된 것으로 기록합니다 (`email_verified_at` 은 일괄 대입 대상이 아니라 따로 둔다).
     *
     * 이미 기록돼 있으면 처음 시각을 유지합니다.
     *
     * @param  User  $user  회원
     * @return void 반환값 없음
     */
    public function markEmailVerified(User $user): void;

    /**
     * 사용자를 삭제합니다.
     *
     * @param  User  $user  삭제할 사용자 모델
     * @return bool 삭제 성공 여부
     */
    public function delete(User $user): bool;

    /**
     * 회원에게 직접 준 메뉴 권한(`menu_permissions.user_id`)을 지웁니다 — 회원을 지우기 직전에 부른다(DB CASCADE 비의존).
     *
     * @param  User  $user  대상 회원
     * @return int 지운 행 수
     */
    public function deleteMenuPermissions(User $user): int;

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
     * @param  array<int, callable(Builder, array): void>  $extensions  확장이 더한 쿼리 조건 클로저 목록
     * @return LengthAwarePaginator 페이지네이션된 사용자 목록
     */
    public function getPaginatedUsers(array $filters = [], array $extensions = []): LengthAwarePaginator;

    /**
     * 사용자 관련 통계 정보를 조회합니다.
     *
     * 기본은 현재 사용자의 `core.users.read` 조회 스코프 안에서 셉니다. 사용자와 무관하게
     * 캐시하는 사이트 전체 집계만 `false` 로 스코프를 끕니다.
     *
     * @param  bool  $applyPermissionScope  현재 사용자의 조회 스코프 적용 여부
     * @return array 사용자 통계 데이터 배열
     */
    public function getStatistics(bool $applyPermissionScope = true): array;

    /**
     * 키워드로 사용자를 검색합니다. (이름, 닉네임, 이메일)
     *
     * @param  string  $keyword  검색할 키워드
     * @param  bool  $excludeWithdrawn  탈퇴 회원을 뺄지 여부 — 알림 수신자처럼 고르는 자리는 true, 기록을 찾는 목록 필터는 false(기본)
     * @return Collection 검색된 사용자 컬렉션
     */
    public function searchByKeyword(string $keyword, bool $excludeWithdrawn = false): Collection;

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
     * 사용자 번호 목록으로 사용자들을 조회하고 번호 키 맵으로 반환합니다.
     *
     * 번호만 들고 있는 목록(방문자 통계의 원장 행 등)이 한 쪽에 실린 회원을 한 번에
     * 읽을 때 씁니다 — 행마다 읽으면 한 쪽에 쪽 크기만큼 질의가 납니다.
     * 읽는 칸은 표시 이름 계산에 필요한 `User::DISPLAY_COLUMNS` 입니다.
     *
     * @param  array<int, int>  $ids  사용자 번호 목록
     * @return Collection<int, User> id => User 매핑
     */
    public function findManyByIdsKeyed(array $ids): Collection;

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
     * 탈퇴하지 않은 최고관리자 전원을 가입 순으로 조회합니다.
     *
     * 터미널 비밀번호 재설정이 이메일 없이 대상(최고관리자)을 고를 때 쓴다 — 최고관리자는 여럿일 수 있다.
     *
     * @return Collection<int, User> 최고관리자 목록 (없으면 빈 컬렉션)
     */
    public function getSuperAdmins(): Collection;

    /**
     * 특정 권한 identifier 를 가진 역할에 소속된 모든 사용자를 조회합니다.
     *
     * @param  string  $permissionIdentifier  권한 identifier
     * @return Collection<int, User> 권한 보유 사용자 컬렉션
     */
    public function findManyByPermissionIdentifier(string $permissionIdentifier): Collection;

    /**
     * 특정 권한을 가진 역할에 소속된 사용자의 id 만 오름차순으로 조회합니다 (#139).
     *
     * @param  string  $permissionIdentifier  권한 identifier
     * @return array<int, int> 사용자 id (오름차순, 중복 없음)
     */
    public function findIdsByPermissionIdentifier(string $permissionIdentifier): array;

    /**
     * UUID 목록에 해당하는 사용자의 id 만 오름차순으로 조회합니다 (#139).
     *
     * @param  array<int, string>  $uuids  사용자 UUID 목록
     * @return array<int, int> 사용자 id (오름차순)
     */
    public function findIdsByUuids(array $uuids): array;

    /**
     * 알림 발송에 쓸 사용자들을 id 순으로 다시 싣습니다 (#139).
     *
     * 알림 대량 발송의 자식 잡이 묶음(기본 100명)마다 부른다. 역할·권한은 권한 판정이 읽는 컬럼만
     * 싣는다(RoleRepository::getUsersByIdentifiers() 와 같은 좁힌 적재) — 수신자마다 「이 화면을 열 수 있는가」
     * 를 물을 때 쿼리가 수신자 수에 비례해 늘지 않게 한다. 사라진 id 는 결과에서 빠진다.
     *
     * @param  array<int, int>  $ids  사용자 id 목록
     * @return Collection<int, User> id 오름차순 사용자
     */
    public function findNotifiablesByIds(array $ids): Collection;

    /**
     * 사용자의 연속 로그인 실패 카운터를 1 증가시킵니다.
     *
     * `last_failed_login_at` 도 현재 시각으로 갱신하며 새 카운트를 반환합니다.
     * `$windowMinutes` 가 양수이고 직전 실패가 그보다 오래됐으면 카운터를 1 부터 다시 센다 —
     * "차단 시간 안의 연속 실패" 만 잠금으로 이어지게 한다. null·0 이하는 기간 제한 없이 누적한다.
     *
     * @param  User  $user  대상 사용자
     * @param  int|null  $windowMinutes  실패를 연속으로 인정하는 기간(분). null·0 이하는 무기한
     * @return int 증가 후 카운트
     */
    public function incrementFailedAttempts(User $user, ?int $windowMinutes = null): int;

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
     * @return void 없음
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
     * 사용자 ID 목록에 해당하는 이름(원본 실명)을 ID 로 색인해 반환합니다.
     *
     * 화면에 보일 이름을 만드는 용도가 아닙니다. 응답에 이름을 실을 때는 `findManyByIdsKeyed()` 로 회원을 읽어
     * `App\DisplayName\DisplayNameResolver::resolveMany()` 로 표시 이름을 계산합니다(7.2.0).
     * 이름을 받지 않는 사이트의 회원은 빈 문자열입니다.
     *
     * @param  array  $ids  사용자 ID 배열
     * @return array<int, string> 사용자 ID => 이름 (이름이 없으면 빈 문자열)
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

    /**
     * 회원의 인증 토큰 하나를 ID 로 찾습니다 (다른 회원의 토큰이면 null).
     *
     * @param  User  $user  회원
     * @param  int|string  $tokenId  토큰 ID
     * @return PersonalAccessToken|null 토큰
     *
     * @since 7.2.0
     */
    public function findAccessTokenFor(User $user, int|string $tokenId): ?PersonalAccessToken;

    /**
     * 인증 토큰의 만료 시각을 바꿉니다.
     *
     * @param  PersonalAccessToken  $token  토큰
     * @param  \DateTimeInterface  $expiresAt  새 만료 시각
     * @return void 반환값 없음
     *
     * @since 7.2.0
     */
    public function updateAccessTokenExpiry(PersonalAccessToken $token, \DateTimeInterface $expiresAt): void;

    /**
     * 인증 토큰에 로그인 수단과 절대 만료를 기록합니다.
     *
     * @param  PersonalAccessToken  $token  토큰
     * @param  string|null  $loginMethod  로그인 수단 이름 (`password`, `social:kakao` 등)
     * @param  \DateTimeInterface|null  $absoluteExpiresAt  절대 만료 (null 이면 상한 없음)
     * @return void 반환값 없음
     *
     * @since 7.2.0
     */
    public function updateAccessTokenSessionMeta(PersonalAccessToken $token, ?string $loginMethod, ?\DateTimeInterface $absoluteExpiresAt): void;

    /**
     * 회원의 인증 토큰을 전부 지웁니다.
     *
     * @param  User  $user  회원
     * @return int 지운 토큰 수
     *
     * @since 7.2.0
     */
    public function deleteAllAccessTokens(User $user): int;

    /**
     * 인증 토큰 하나를 삭제합니다.
     *
     * @param  PersonalAccessToken  $token  토큰
     * @return void 반환값 없음
     *
     * @since 7.2.0
     */
    public function deleteAccessToken(PersonalAccessToken $token): void;

    /**
     * 인증 토큰의 만료 시각을 조건부 UPDATE 로 늦춥니다.
     *
     * 토큰 행이 이미 지워졌으면(로그아웃·갱신 직후) 아무것도 하지 않는다 — INSERT 경로가 없어
     * 폐기한 토큰을 되살리지 않는다.
     *
     * @param  int  $tokenId  토큰 ID
     * @param  \DateTimeInterface  $expiresAt  새 만료 시각
     * @return bool 행이 있어 갱신했으면 true
     *
     * @since 7.1.6
     */
    public function extendTokenExpiration(int $tokenId, \DateTimeInterface $expiresAt): bool;

    /**
     * 회원들의 web 세션을 지웁니다.
     *
     * 세션 드라이버가 database 일 때만 세션 행을 지울 수 있다. 다른 드라이버(file·redis 등)는 회원별로
     * 세션을 찾을 수 없어 아무것도 하지 않고 0 을 돌려준다.
     *
     * @param  array<int, int>  $ids  회원 ID 목록
     * @param  string|null  $exceptSessionId  남겨 둘 세션 ID (본인 비밀번호 변경 시 지금 쓰는 세션)
     * @return int 지운 세션 수
     *
     * @since 7.1.6
     */
    public function deleteSessionsByUserIds(array $ids, ?string $exceptSessionId = null): int;

    /**
     * 비밀번호 만료 처리 후보 쿼리를 반환합니다 — 활성 회원이면서 관리자 지정 변경 대기가 아닌 회원.
     *
     * 적용 범위·날짜 조건은 호출자(`PasswordExpiryService`)가 덧붙인다.
     *
     * @return Builder<User> 후보 쿼리
     *
     * @since 7.1.0
     */
    public function passwordExpiryCandidateQuery(): Builder;

    /**
     * 회원 ID 목록으로 제한한 쿼리를 반환합니다 (배치 상한으로 고른 대상을 청크 처리할 때).
     *
     * @param  array<int, int>  $ids  회원 ID 배열
     * @return Builder<User> 쿼리
     *
     * @since 7.1.0
     */
    public function queryByIds(array $ids): Builder;

    /**
     * 주어진 컬럼 가운데 하나라도 빈(null 또는 빈 문자열) 회원 수를 반환합니다 (탈퇴 회원 제외).
     *
     * 컬럼 저장 항목을 필수로 바꾸기 전에 「비어 있는 기존 회원 N명」을 보여 주는 데 쓴다.
     *
     * @param  array<int, string>  $columns  users 컬럼명 목록 (호출자가 허용 목록으로 고른 값)
     * @return int 회원 수
     *
     * @since 7.2.0
     */
    public function countMembersWithEmptyColumns(array $columns): int;

    /**
     * 값 테이블 저장 항목에 값이 없는 회원 수를 반환합니다 (탈퇴 회원 제외).
     *
     * @param  int  $definitionId  항목 ID (field_definitions.id)
     * @return int 회원 수
     *
     * @since 7.2.0
     */
    public function countMembersWithoutFieldValue(int $definitionId): int;

    /**
     * 같은 정규화 닉네임을 쓰는 다른 회원이 있는지 반환합니다.
     *
     * @param  string  $normalized  정규화 닉네임
     * @param  int|null  $exceptUserId  비교에서 뺄 회원 ID (자기 자신)
     * @return bool 있으면 true
     *
     * @since 7.2.0
     */
    public function nicknameNormalizedTaken(string $normalized, ?int $exceptUserId = null): bool;

    /**
     * 닉네임이 있는 회원 수를 반환합니다 (닉네임 키 재계산의 전체 수).
     *
     * @return int 회원 수
     *
     * @since 7.2.0
     */
    public function countMembersWithNickname(): int;

    /**
     * 닉네임이 있는 회원을 ID 순으로 한 묶음 읽습니다 (닉네임 키 재계산용).
     *
     * @param  int  $afterId  이 ID 다음부터
     * @param  int  $limit  묶음 크기
     * @return Collection<int, User> id, nickname, nickname_normalized, nickname_unique_key 만 담은 회원
     *
     * @since 7.2.0
     */
    public function nicknameRowsAfter(int $afterId, int $limit): Collection;

    /**
     * 주어진 정규화 닉네임 가운데 두 회원 이상이 쓰는 값을 반환합니다.
     *
     * @param  array<int, string>  $normalizedValues  정규화 닉네임 목록
     * @return array<int, string> 겹치는 값
     *
     * @since 7.2.0
     */
    public function duplicatedNicknameNormalized(array $normalizedValues): array;

    /**
     * 지금 겹치는 닉네임의 종 수와 그 닉네임을 쓰는 회원 수를 반환합니다.
     *
     * @return array{nicknames: int, members: int} 겹치는 닉네임 종 수, 회원 수
     *
     * @since 7.2.0
     */
    public function nicknameDuplicateSummary(): array;

    /**
     * 회원의 닉네임 비교 컬럼을 고칩니다 (모델 이벤트와 수정 시각을 건드리지 않는다).
     *
     * @param  int  $userId  회원 ID
     * @param  array{nickname_normalized?: string|null, nickname_unique_key?: string|null}  $columns  고칠 컬럼
     * @return void
     *
     * @since 7.2.0
     */
    public function updateNicknameKeys(int $userId, array $columns): void;

    /**
     * 모든 회원의 닉네임 중복 키를 비웁니다 (중복 금지를 끌 때).
     *
     * @return int 비운 행 수
     *
     * @since 7.2.0
     */
    public function clearNicknameUniqueKeys(): int;
}
