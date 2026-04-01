<?php

namespace App\Services;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Enums\UserStatus;
use App\Exceptions\CannotDeleteAdminException;
use App\Exceptions\CannotDeleteSuperAdminException;
use App\Extension\HookManager;
use App\Helpers\PermissionHelper;
use App\Helpers\TimezoneHelper;
use App\Models\Role;
use App\Models\User;
use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UserService
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private AttachmentService $attachmentService
    ) {}

    /**
     * 필터링된 사용자 목록을 페이지네이션으로 조회합니다.
     *
     * @param  array  $filters  필터 조건 배열
     * @return LengthAwarePaginator 페이지네이션된 사용자 목록
     */
    public function getPaginatedUsers(array $filters = []): LengthAwarePaginator
    {
        $result = $this->userRepository->getPaginatedUsers($filters);

        HookManager::doAction('core.user.after_list', $result->total());

        return $result;
    }

    /**
     * 새로운 사용자를 생성합니다.
     *
     * @param  array  $data  사용자 생성 데이터
     * @return User 생성된 사용자 모델
     *
     * @throws ValidationException 생성 실패 시
     */
    public function createUser(array $data): User
    {
        try {
            // 원본 데이터 보관 (after_create 훅에서 사용)
            $originalData = $data;

            // Before 훅: 데이터 검증/전처리
            HookManager::doAction('core.user.before_create', $data);

            // Filter 훅: 모듈이 자신의 데이터를 추출하고 코어 데이터에서 제거
            $data = HookManager::applyFilters('core.user.filter_create_data', $data);

            // 비밀번호 해싱
            if (isset($data['password'])) {
                $data['password'] = Hash::make($data['password']);
            }

            // 역할 처리: roles 객체 배열이 오면 role_ids로 변환
            $roleIds = null;
            if (isset($data['role_ids'])) {
                $roleIds = $data['role_ids'];
                unset($data['role_ids']);
            } elseif (isset($data['roles']) && is_array($data['roles'])) {
                $roleIds = collect($data['roles'])->pluck('id')->filter()->toArray();
            }
            unset($data['roles']);

            // 역할 할당 권한 체크: core.permissions.update 권한 없으면 기본 역할 자동 할당
            if (! PermissionHelper::check('core.permissions.update')) {
                $defaultRoleId = Role::where('identifier', 'user')->value('id');
                $roleIds = $defaultRoleId ? [$defaultRoleId] : null;
            }

            $user = $this->userRepository->create($data);

            // 역할 동기화
            if ($roleIds !== null && count($roleIds) > 0) {
                $user->roles()->sync($roleIds);
            }

            // After 훅: 사용자 객체와 원본 데이터 전달
            HookManager::doAction('core.user.after_create', $user, $originalData);

            return $user->fresh(['modules', 'plugins', 'menus', 'roles']);
        } catch (Exception $e) {
            if ($e instanceof ValidationException) {
                throw $e;
            }

            throw ValidationException::withMessages([
                'general' => [__('user.create_failed', ['error' => $e->getMessage()])],
            ]);
        }
    }

    /**
     * 기존 사용자 정보를 수정합니다.
     *
     * @param  User  $user  수정할 사용자 모델
     * @param  array  $data  수정할 데이터
     * @return User 수정된 사용자 모델
     *
     * @throws ValidationException 수정 실패 시
     */
    public function updateUser(User $user, array $data): User
    {
        try {
            // 원본 데이터 보관 (after_update 훅에서 사용)
            $originalData = $data;

            // Before 훅: 데이터 검증/전처리
            HookManager::doAction('core.user.before_update', $user, $data);

            // Filter 훅: 모듈이 자신의 데이터를 추출하고 코어 데이터에서 제거
            $data = HookManager::applyFilters('core.user.filter_update_data', $data, $user);

            // 비밀번호 처리
            if (! empty($data['password'])) {
                $data['password'] = Hash::make($data['password']);
            } else {
                // 비밀번호가 비어있으면 업데이트하지 않음
                unset($data['password']);
            }

            // 역할 처리: roles 객체 배열이 오면 role_ids로 변환
            $roleIds = null;
            if (isset($data['role_ids'])) {
                $roleIds = $data['role_ids'];
                unset($data['role_ids']);
            } elseif (isset($data['roles']) && is_array($data['roles'])) {
                $roleIds = collect($data['roles'])->pluck('id')->filter()->toArray();
            }
            unset($data['roles']);

            // 역할 할당 권한 체크
            if ($roleIds !== null) {
                $authUser = Auth::user();

                // core.permissions.update 권한 없으면 역할 변경 불가
                if (! PermissionHelper::check('core.permissions.update', $authUser)) {
                    $roleIds = null;
                }

                // 자기잠금 방지: 마지막 admin 역할 사용자가 자기 admin 역할을 제거하려는 경우 차단
                if ($roleIds !== null && $authUser && $authUser->id === $user->id) {
                    $adminRole = Role::where('identifier', 'admin')->first();
                    if ($adminRole && $user->roles->contains('id', $adminRole->id) && ! in_array($adminRole->id, $roleIds)) {
                        // admin 역할을 가진 다른 사용자가 있는지 확인
                        $otherAdminCount = $adminRole->users()->where('users.id', '!=', $user->id)->count();
                        if ($otherAdminCount === 0) {
                            throw ValidationException::withMessages([
                                'role_ids' => [__('user.last_admin_role_cannot_remove')],
                            ]);
                        }
                    }
                }
            }

            // 상태 변경 감지 및 타임스탬프 자동 설정
            $oldStatus = $user->status;
            $newStatus = $data['status'] ?? null;

            if ($newStatus && $newStatus !== $oldStatus) {
                $newStatusEnum = UserStatus::from($newStatus);
                $data = match ($newStatusEnum) {
                    UserStatus::Blocked => array_merge($data, ['blocked_at' => now()]),
                    UserStatus::Withdrawn => array_merge($data, ['withdrawn_at' => now()]),
                    UserStatus::Active => array_merge($data, ['blocked_at' => null, 'withdrawn_at' => null]),
                    UserStatus::Inactive => $data,
                };
            }

            // 스냅샷 캡처 (ChangeDetector용)
            $snapshot = $user->toArray();

            $this->userRepository->update($user, $data);

            // 상태가 Active 외로 변경되었으면 토큰 삭제 (즉시 로그아웃)
            if ($newStatus && $newStatus !== $oldStatus && $newStatus !== UserStatus::Active->value) {
                $user->tokens()->delete();
            }

            // 역할 동기화
            if ($roleIds !== null) {
                $user->roles()->sync($roleIds);
            }

            // After 훅: 사용자 객체와 원본 데이터, 스냅샷 전달
            HookManager::doAction('core.user.after_update', $user, $originalData, $snapshot);

            return $user->fresh(['modules', 'plugins', 'menus', 'roles']);
        } catch (Exception $e) {
            if ($e instanceof ValidationException) {
                throw $e;
            }

            throw ValidationException::withMessages([
                'general' => [__('user.update_failed', ['error' => $e->getMessage()])],
            ]);
        }
    }

    /**
     * 사용자를 탈퇴 처리합니다.
     *
     * 아바타 파일과 토큰을 삭제하고, 이름/이메일/닉네임에 suffix를 추가하여
     * 익명화한 후 탈퇴 상태로 변경합니다.
     *
     * @param  User  $user  탈퇴 처리할 사용자 모델
     * @return bool 탈퇴 처리 성공 여부
     *
     * @throws CannotDeleteSuperAdminException 슈퍼 관리자 탈퇴 시도 시
     * @throws ValidationException 관리자 계정 탈퇴 시도 또는 탈퇴 실패 시
     */
    public function withdrawUser(User $user): bool
    {
        try {
            // 슈퍼 관리자는 탈퇴 불가
            if ($user->isSuperAdmin()) {
                throw new CannotDeleteSuperAdminException;
            }

            // 관리자 역할을 가진 계정은 탈퇴 방지
            if ($user->isAdmin()) {
                throw ValidationException::withMessages([
                    'general' => [__('user.withdraw_admin_forbidden')],
                ]);
            }

            // 훅 실행 (탈퇴 전)
            HookManager::doAction('core.user.before_withdraw', $user);

            // 약관 동의 이력 삭제 (명시적 삭제 - CASCADE 의존 금지)
            $user->consents()->delete();

            // 아바타 Attachment 삭제 (다형성 관계)
            $avatarAttachment = $user->avatarAttachment;
            if ($avatarAttachment) {
                $this->attachmentService->delete($avatarAttachment->id);
            }

            // 토큰 삭제 (로그아웃 처리)
            $user->tokens()->delete();

            // 탈퇴 처리 (suffix 추가 및 상태 변경)
            $result = $user->withdraw();

            // 훅 실행 (탈퇴 후)
            if ($result) {
                HookManager::doAction('core.user.after_withdraw', $user);
            }

            return $result;
        } catch (Exception $e) {
            if ($e instanceof ValidationException) {
                throw $e;
            }

            if ($e instanceof CannotDeleteSuperAdminException) {
                throw $e;
            }

            throw ValidationException::withMessages([
                'general' => [__('user.withdraw_failed', ['error' => $e->getMessage()])],
            ]);
        }
    }

    /**
     * 사용자를 삭제합니다.
     *
     * @param  User  $user  삭제할 사용자 모델
     * @return bool 삭제 성공 여부
     *
     * @throws CannotDeleteSuperAdminException 슈퍼 관리자 삭제 시도 시
     * @throws ValidationException 관리자 계정 삭제 시도 또는 삭제 실패 시
     */
    public function deleteUser(User $user): bool
    {
        try {
            // 슈퍼 관리자는 삭제 불가
            if ($user->isSuperAdmin()) {
                throw new CannotDeleteSuperAdminException;
            }

            // 관리자 역할을 가진 계정은 삭제 방지
            if ($user->isAdmin()) {
                throw new CannotDeleteAdminException;
            }

            // 삭제 전 사용자 데이터 보관 (after_delete 훅에서 사용)
            $userData = $user->only(['id', 'uuid', 'name', 'email']);

            // Before 훅
            HookManager::doAction('core.user.before_delete', $user);

            // 역할 연결 해제 (명시적 삭제 - CASCADE 의존 금지)
            $user->roles()->detach();

            // 약관 동의 이력 삭제
            $user->consents()->delete();

            // API 토큰 삭제
            $user->tokens()->delete();

            // 아바타 Attachment 삭제 (다형성 관계)
            $avatarAttachment = $user->avatarAttachment;
            if ($avatarAttachment) {
                $this->attachmentService->delete($avatarAttachment->id);
            }

            $result = $this->userRepository->delete($user);

            // After 훅: 삭제된 사용자 데이터 전달
            HookManager::doAction('core.user.after_delete', $userData);

            return $result;
        } catch (Exception $e) {
            if ($e instanceof ValidationException) {
                throw $e;
            }

            if ($e instanceof CannotDeleteSuperAdminException) {
                throw $e;
            }

            if ($e instanceof CannotDeleteAdminException) {
                throw $e;
            }

            throw ValidationException::withMessages([
                'general' => [__('user.delete_failed', ['error' => $e->getMessage()])],
            ]);
        }
    }

    /**
     * ID로 사용자 상세 정보를 조회합니다.
     *
     * @param  int  $id  사용자 ID
     * @return User|null 사용자 모델 또는 null
     */
    public function getUserById(int $id): ?User
    {
        $user = $this->userRepository->findById($id);

        if ($user) {
            HookManager::doAction('core.user.after_show', $user);
        }

        return $user;
    }

    /**
     * UUID로 사용자를 조회합니다.
     *
     * @param  string  $uuid  사용자 UUID
     * @return User|null 사용자 모델 또는 null
     */
    public function getUserByUuid(string $uuid): ?User
    {
        $user = User::where('uuid', $uuid)->first();

        if ($user) {
            HookManager::doAction('core.user.after_show', $user);
        }

        return $user;
    }

    /**
     * 이메일로 사용자를 조회합니다.
     *
     * @param  string  $email  사용자 이메일
     * @return User|null 사용자 모델 또는 null
     */
    public function getUserByEmail(string $email): ?User
    {
        return $this->userRepository->findByEmail($email);
    }

    /**
     * 사용자 관련 통계 정보를 조회합니다.
     *
     * @return array 사용자 통계 데이터
     */
    public function getStatistics(): array
    {
        return $this->userRepository->getStatistics();
    }

    /**
     * 키워드로 사용자를 검색합니다. (이름, 닉네임, 이메일)
     *
     * @param  string  $keyword  검색할 키워드
     * @return Collection 검색된 사용자 컬렉션
     */
    public function searchByKeyword(string $keyword): Collection
    {
        $result = $this->userRepository->searchByKeyword($keyword);

        HookManager::doAction('core.user.after_search', $keyword, $result->count());

        return $result;
    }

    /**
     * 최근 등록된 사용자들을 조회합니다.
     *
     * @param  int  $limit  조회할 사용자 수 (기본값: 10)
     * @return Collection 최근 사용자 컬렉션
     */
    public function getRecentUsers(int $limit = 10): Collection
    {
        return $this->userRepository->getRecentUsers($limit);
    }

    /**
     * 언어별 사용자 분포를 조회합니다.
     *
     * @return array 언어별 사용자 수 배열
     */
    public function getUserLanguageDistribution(): array
    {
        return $this->userRepository->getUsersByLanguage();
    }

    /**
     * 주어진 ID의 사용자 존재 여부를 확인합니다.
     *
     * @param  int  $id  확인할 사용자 ID
     * @return bool 사용자 존재 여부
     */
    public function userExists(int $id): bool
    {
        return $this->userRepository->findById($id) !== null;
    }

    /**
     * 이메일 사용 가능 여부를 확인합니다.
     *
     * @param  string  $email  확인할 이메일
     * @param  string|null  $excludeUserUuid  제외할 사용자 UUID (수정 시 사용)
     * @return bool 이메일 사용 가능 여부
     */
    public function isEmailAvailable(string $email, ?string $excludeUserUuid = null): bool
    {
        $user = $this->userRepository->findByEmail($email);

        if (! $user) {
            return true;
        }

        return $excludeUserUuid && $user->uuid === $excludeUserUuid;
    }

    /**
     * 사용자 활성화 상태를 업데이트합니다.
     * 현재는 구현되지 않음 - 필요시 확장 가능
     *
     * @param  User  $user  대상 사용자 모델
     * @param  bool  $isActive  활성화 상태
     * @return User 사용자 모델
     */
    public function updateUserStatus(User $user, bool $isActive): User
    {
        // 현재 User 모델에 is_active 필드가 없으므로 필요시 추가
        // $this->userRepository->update($user, ['is_active' => $isActive]);

        return $user;
    }

    /**
     * 사용자의 마지막 로그인 시간을 현재 시간으로 업데이트합니다.
     *
     * @param  User  $user  대상 사용자 모델
     * @return User 업데이트된 사용자 모델
     */
    public function updateLastLogin(User $user): User
    {
        $this->userRepository->update($user, ['last_login_at' => now()]);

        return $user->fresh();
    }

    /**
     * 사용자의 언어 설정을 업데이트합니다.
     *
     * @param  User  $user  대상 사용자 모델
     * @param  string  $language  변경할 언어 코드
     * @return User 업데이트된 사용자 모델
     */
    public function updateUserLanguage(User $user, string $language): User
    {
        if ($user->language !== $language) {
            $this->userRepository->update($user, ['language' => $language]);

            return $user->fresh();
        }

        return $user;
    }

    /**
     * 여러 사용자의 상태를 일괄 변경합니다.
     *
     * @param  array<int>  $ids  사용자 ID 배열
     * @param  string  $status  변경할 상태 (active, inactive)
     * @return array{updated_count: int} 업데이트 결과
     */
    public function bulkUpdateStatus(array $uuids, string $status): array
    {
        // before_bulk_update 훅 실행
        HookManager::doAction('sirsoft-core.user.before_bulk_update', $uuids, $status);

        $statusEnum = UserStatus::from($status);

        // UUID → 정수 ID 변환 (내부 쿼리용)
        $userIds = User::whereIn('uuid', $uuids)->pluck('id')->toArray();

        // DB 트랜잭션으로 일괄 업데이트
        $updatedCount = DB::transaction(function () use ($userIds, $statusEnum) {
            // 타임스탬프 자동 설정
            $updateData = ['status' => $statusEnum->value];
            $updateData = match ($statusEnum) {
                UserStatus::Blocked => array_merge($updateData, ['blocked_at' => now()]),
                UserStatus::Withdrawn => array_merge($updateData, ['withdrawn_at' => now()]),
                UserStatus::Active => array_merge($updateData, ['blocked_at' => null, 'withdrawn_at' => null]),
                UserStatus::Inactive => $updateData,
            };

            $count = User::whereIn('id', $userIds)->update($updateData);

            // Active 외 상태로 변경 시 해당 사용자들의 토큰 전체 삭제
            if ($statusEnum !== UserStatus::Active) {
                PersonalAccessToken::where('tokenable_type', User::class)
                    ->whereIn('tokenable_id', $userIds)
                    ->delete();
            }

            return $count;
        });

        // after_bulk_update 훅 실행
        HookManager::doAction('sirsoft-core.user.after_bulk_update', $uuids, $status, $updatedCount);

        return [
            'updated_count' => $updatedCount,
        ];
    }

    // =========================================================================
    // 공개 프로필 관련 메서드
    // =========================================================================

    /**
     * 공개 프로필 정보를 조회합니다 (게시글 정보 제외).
     *
     * 사용자 상태에 따라 차등 데이터를 반환합니다:
     * - active: 전체 정보 (id, name, status, status_label, avatar, bio, created_at)
     * - inactive: 기본 정보만 (bio 제외)
     * - blocked: 최소 정보만 (avatar, bio, created_at 제외)
     * - withdrawn: 익명화된 정보 ("탈퇴한 사용자", 기본 아바타)
     * - 미존재: null 반환
     *
     * @param  int  $userId  사용자 ID
     * @return array|null 프로필 데이터 또는 null (미존재)
     */
    public function getPublicProfile(int $userId): ?array
    {
        $user = $this->userRepository->findById($userId);

        // 미존재 사용자
        if (! $user) {
            return null;
        }

        $status = UserStatus::tryFrom($user->status);

        // 탈퇴한 사용자는 익명화된 정보 반환
        if ($status === UserStatus::Withdrawn) {
            return [
                'uuid' => $user->uuid,
                'name' => __('user.withdrawn_user'),
                'status' => $user->status,
                'status_label' => $status->label(),
                'avatar' => null,
                'bio' => null,
                'created_at' => null,
                'is_withdrawn' => true,
            ];
        }

        // 상태별 표시 필드 결정
        [$showAvatar, $showBio, $showCreatedAt] = match ($status) {
            UserStatus::Active => [true, true, true],
            UserStatus::Inactive => [true, false, true],
            default => [false, false, false], // blocked 등
        };

        return [
            'uuid' => $user->uuid,
            'name' => $user->name,
            'status' => $user->status,
            'status_label' => $status?->label() ?? $user->status,
            'avatar' => $showAvatar ? $user->avatar_url : null,
            'bio' => $showBio ? $user->bio : null,
            'created_at' => $showCreatedAt ? TimezoneHelper::toUserDateString($user->created_at) : null,
            'is_withdrawn' => false,
        ];
    }
}
