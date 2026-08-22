<?php

namespace App\Services;

use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Enums\UserStatus;
use App\Exceptions\CannotDeleteSuperAdminException;
use App\Exceptions\PermissionEscalationException;
use App\Extension\HookManager;
use App\Helpers\PermissionHelper;
use App\Helpers\TimezoneHelper;
use App\Models\ActivityLog;
use App\Models\Attachment;
use App\Models\User;
use App\Support\PermissionEscalationGuard;
use App\Support\UserGradeGuard;
use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class UserService
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private RoleRepositoryInterface $roleRepository,
        private AttachmentService $attachmentService,
        private PermissionEscalationGuard $escalationGuard
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

            // 역할 할당: 요청 역할이 없으면 기본 역할('user')을 자동 배정하고, 요청 역할이
            // 있으면 액터 상한(ceiling) 검사만 받는다.
            //
            // 역할 부여는 "사용자 관리"(core.users.create — 이 경로는 라우트에서 이미 강제됨)의
            // 일부이지, "역할 정의 수정"(core.permissions.update — 역할에 권한을 가감하는 권한)을
            // 요구하지 않는다. 권한 상승 방지는 오직 상한(ceiling)이 담당한다: 액터가 보유하지
            // 않았거나 자신의 범위보다 넓은 권한을 담은 역할은 부여할 수 없다(KVE-2026-1919).
            // 신규 사용자는 기존 역할이 없으므로 요청 역할 전부가 새로 부여되는 역할이다.
            if ($roleIds === null || count($roleIds) === 0) {
                $defaultRoleId = $this->roleRepository->findByIdentifier('user')?->id;
                $roleIds = $defaultRoleId ? [$defaultRoleId] : null;
            } else {
                $this->escalationGuard->assertRoleAssignmentWithinActorCeiling($roleIds);
            }

            $user = $this->userRepository->create($data);

            // 역할 동기화
            if ($roleIds !== null && count($roleIds) > 0) {
                $user->roles()->sync($roleIds);
                $user->flushPermissionCaches();
            }

            // After 훅: 사용자 객체와 원본 데이터 전달
            HookManager::doAction('core.user.after_create', $user, $originalData);

            return $user->fresh(['modules', 'plugins', 'menus', 'roles']);
        } catch (Exception $e) {
            if ($e instanceof ValidationException) {
                throw $e;
            }

            // 권한 상승 상한 위반은 그대로 전파해 컨트롤러가 403 으로 매핑하도록 한다.
            if ($e instanceof PermissionEscalationException) {
                throw $e;
            }

            // 원본 예외는 로그로만 남기고, 사용자 응답에는 원문을 싣지 않는다.
            Log::error('User create failed', ['exception' => $e]);

            throw ValidationException::withMessages([
                'general' => [__('user.create_failed')],
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
        // 등급 상한 가드: 비-슈퍼관리자 액터는 슈퍼 관리자 계정을 수정할 수 없다.
        // (비밀번호·status(withdrawn/blocked)·email 등 모든 수정 경로를 한 지점에서 차단)
        UserGradeGuard::assertActorMayModify($user);

        try {
            // 원본 데이터 보관 (after_update 훅에서 사용)
            $originalData = $data;

            // Before 훅: 데이터 검증/전처리
            HookManager::doAction('core.user.before_update', $user, $data);

            // Filter 훅: 모듈이 자신의 데이터를 추출하고 코어 데이터에서 제거
            $data = HookManager::applyFilters('core.user.filter_update_data', $data, $user);

            // 비밀번호 처리
            $passwordChanged = ! empty($data['password']);
            if ($passwordChanged) {
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

            // 역할 할당
            if ($roleIds !== null) {
                $authUser = Auth::user();

                // 역할 조작 상한(ceiling): 이번 변경으로 붙거나 떨어지는 역할이 액터가 전부
                // 부여할 수 있는 권한만 담고 있는지 확인한다(KVE-2026-1919). 역할 부여는
                // "사용자 관리"(core.users.update — 이 경로는 라우트에서 이미 강제됨)의 일부이며,
                // "역할 정의 수정"(core.permissions.update — 역할에 권한을 가감하는 권한)을
                // 요구하지 않는다. 권한 상승 방지는 오직 상한이 담당한다: 액터가 보유하지
                // 않았거나 자신의 범위보다 넓은 권한을 담은 역할은 부여할 수 없고, 위반 시
                // PermissionEscalationException 이 전파되어 403 으로 명시 거부된다(과거처럼 조용히
                // 무시하지 않는다).
                //
                // 검사 대상은 **추가·제거 양방향의 변경분**이다. 추가는 그 역할의 권한을
                // 부여하는 것이고, 제거는 상위 역할의 권한 구성을 박탈하는 하향 조작이므로 —
                // 액터가 스스로 부여할 수 없는(상한 밖) 역할은 붙이지도 떼지도 못한다. 추가만
                // 검사하면 core.users.update 만 가진 하위 관리자가 다른 관리자의 상위 역할을
                // 박탈하는 경로가 상한 없이 뚫린다. 기존 유지 역할은 변경이 아니므로 제외한다.
                $currentRoleIds = $user->roles->pluck('id')->all();
                $changedRoleIds = array_values(array_unique(array_merge(
                    array_diff($roleIds, $currentRoleIds),        // 추가되는 역할
                    array_diff($currentRoleIds, $roleIds),        // 제거되는 역할
                )));
                if (! empty($changedRoleIds)) {
                    $this->escalationGuard->assertRoleAssignmentWithinActorCeiling($changedRoleIds);
                }

                // 자기잠금 방지: 마지막 admin 역할 사용자가 자기 admin 역할을 제거하려는 경우 차단
                if ($authUser && $authUser->id === $user->id) {
                    $adminRole = $this->roleRepository->findByIdentifier('admin');
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

            // 관리자가 상태만 '탈퇴'로 바꾸는 경로도 정식 탈퇴 로직으로 통일한다.
            //
            // 여기서 status/withdrawn_at 을 먼저 저장하면 withdraw() 의 멱등 가드가
            // 이미 탈퇴한 계정으로 판정해 익명화와 훅이 조용히 생략된다.
            // 따라서 상태 필드는 제거하고, 다른 필드 갱신을 마친 뒤 withdrawUser() 에 위임한다.
            $withdrawViaStatus = isset($data['status'])
                && $data['status'] === UserStatus::Withdrawn->value
                && ! $user->isWithdrawn();

            if ($withdrawViaStatus) {
                unset($data['status']);

                // 차단 대상(관리자/수퍼관리자)이면 다른 필드도 저장되지 않아야 하므로
                // 갱신 전에 가드를 평가한다.
                $this->assertWithdrawable($user);
            }

            // 상태 변경 감지 및 타임스탬프 자동 설정
            $oldStatus = $user->status;
            $newStatus = $data['status'] ?? null;

            if ($newStatus && $newStatus !== $oldStatus) {
                $newStatusEnum = UserStatus::from($newStatus);
                $data = match ($newStatusEnum) {
                    UserStatus::Blocked => array_merge($data, ['blocked_at' => now()]),
                    // 탈퇴는 위 위임 경로(withdrawUser)가 전담하므로 여기로는 도달하지 않는다.
                    UserStatus::Withdrawn => $data,
                    UserStatus::Active => array_merge($data, ['blocked_at' => null, 'withdrawn_at' => null]),
                    UserStatus::Inactive => $data,
                };
            }

            // 스냅샷 캡처 (ChangeDetector용)
            $snapshot = $user->toArray();

            // 탈퇴 위임 시 아바타는 커밋 후에 지운다 (트랜잭션 안에서 지우면 롤백돼도 파일은 안 돌아온다).
            $avatarAttachment = $withdrawViaStatus ? $user->avatarAttachment : null;

            // 프로필 갱신·토큰 정리·역할 동기화가 서로 어긋나지 않도록 하나로 묶는다.
            //
            // 상태 변경 경유 탈퇴는 **탈퇴까지 같은 트랜잭션**에 넣는다 — 프로필 갱신을
            // 먼저 커밋하고 탈퇴를 따로 커밋하면, 탈퇴가 실패했을 때 이름·이메일 변경만
            // 남아 "전부 성공하거나 전부 취소" 가 그 경로에서만 깨진다.
            DB::transaction(function () use ($user, $data, $newStatus, $oldStatus, $roleIds, $withdrawViaStatus) {
                $this->userRepository->update($user, $data);

                // 상태가 Active 외로 변경되었으면 토큰 삭제 (즉시 로그아웃)
                if ($newStatus && $newStatus !== $oldStatus && $newStatus !== UserStatus::Active->value) {
                    $user->tokens()->delete();
                }

                // 역할 동기화
                if ($roleIds !== null) {
                    $user->roles()->sync($roleIds);
                }

                // 익명화 + before_withdraw 훅 + 연계 데이터 정리 (본인 탈퇴와 동일 코드)
                if ($withdrawViaStatus) {
                    $this->performWithdrawWrites($user);
                }
            });

            // 커밋 후 부수효과 (캐시는 롤백 대상이 아니다)
            if ($roleIds !== null) {
                $user->flushPermissionCaches();
            }

            // After 훅: 사용자 객체와 원본 데이터, 스냅샷 전달
            HookManager::doAction('core.user.after_update', $user, $originalData, $snapshot);

            if ($withdrawViaStatus) {
                $this->runWithdrawAfterEffects($user, $avatarAttachment);
            }

            // 비밀번호 변경 시 알림 훅 발화 — 발송은 NotificationHookListener가 처리
            if ($passwordChanged) {
                HookManager::doAction('core.auth.after_password_changed', $user);
            }

            return $user->fresh(['modules', 'plugins', 'menus', 'roles']);
        } catch (Exception $e) {
            if ($e instanceof ValidationException) {
                throw $e;
            }

            if ($e instanceof CannotDeleteSuperAdminException) {
                throw $e;
            }

            // 권한 상승 상한 위반은 그대로 전파해 컨트롤러가 403 으로 매핑하도록 한다
            // (일반 실패로 감싸면 422 로 잘못 내려간다).
            if ($e instanceof PermissionEscalationException) {
                throw $e;
            }

            // 원본 예외는 로그로만 남기고, 사용자 응답에는 원문을 싣지 않는다.
            Log::error('User update failed', ['user_id' => $user->id, 'exception' => $e]);

            throw ValidationException::withMessages([
                'general' => [__('user.update_failed')],
            ]);
        }
    }

    /**
     * 탈퇴 가능한 계정인지 검사합니다.
     *
     * 탈퇴 경로(본인 탈퇴 / 관리자 상태 변경 / 일괄 상태 변경)가 모두 같은 기준을
     * 쓰도록 한 곳에 둡니다 — 한쪽만 느슨하면 그 경로가 우회로가 됩니다.
     *
     * @param  User  $user  검사 대상 사용자
     *
     * @throws CannotDeleteSuperAdminException 슈퍼 관리자인 경우
     * @throws ValidationException 관리자 역할을 가진 경우
     */
    private function assertWithdrawable(User $user): void
    {
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
    }

    /**
     * 탈퇴의 DB 변경만 수행합니다 (호출자가 연 트랜잭션 안에서 실행).
     *
     * 관리자가 상태 변경으로 탈퇴시키는 경로는 프로필 갱신과 탈퇴를 **한 트랜잭션**으로
     * 묶어야 한다 — 두 번에 나눠 커밋하면 탈퇴가 실패했을 때 프로필 변경만 남는다.
     * 그래서 DB 변경분을 이 메서드로 떼어 두 경로가 같은 코드를 공유한다.
     *
     * @param  User  $user  탈퇴 처리할 사용자 모델
     * @return bool 탈퇴 처리 성공 여부
     */
    private function performWithdrawWrites(User $user): bool
    {
        // 훅 실행 (탈퇴 전)
        HookManager::doAction('core.user.before_withdraw', $user);

        // 약관 동의 이력 삭제 (명시적 삭제 - CASCADE 의존 금지)
        $user->consents()->delete();

        // 토큰 삭제 (로그아웃 처리)
        $user->tokens()->delete();

        // 탈퇴 처리 (suffix 추가 및 상태 변경)
        return $user->withdraw();
    }

    /**
     * 탈퇴 커밋 후 부수효과를 수행합니다.
     *
     * @param  User  $user  탈퇴 처리된 사용자 모델
     * @param  Attachment|null  $avatarAttachment  탈퇴 전 캡처한 아바타 첨부
     */
    private function runWithdrawAfterEffects(User $user, $avatarAttachment): void
    {
        // 아바타 파일 삭제는 실패해도 탈퇴를 되돌리지 않는다
        // (고아 파일은 비파괴이고, 탈퇴를 되살리는 쪽이 훨씬 해롭다).
        if ($avatarAttachment) {
            try {
                $this->attachmentService->delete($avatarAttachment->id);
            } catch (Exception $e) {
                Log::warning('탈퇴 아바타 삭제 실패', [
                    'user_id' => $user->id,
                    'attachment_id' => $avatarAttachment->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 훅 실행 (탈퇴 후)
        HookManager::doAction('core.user.after_withdraw', $user);
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
            // 슈퍼 관리자 / 관리자 탈퇴 차단 (전 탈퇴 경로 공통 기준)
            $this->assertWithdrawable($user);

            // 아바타는 파일 삭제를 동반하므로 트랜잭션 밖(커밋 후)에서 처리한다 —
            // 트랜잭션 안에서 지우면 롤백되어도 파일은 돌아오지 않는다.
            $avatarAttachment = $user->avatarAttachment;

            // DB 변경은 전부 성공하거나 전부 취소된다 (공개이슈 #112).
            // 마지막 단계인 withdraw() 가 실패하면 동의 이력·토큰 삭제도 함께 되돌아간다.
            $result = DB::transaction(fn () => $this->performWithdrawWrites($user));

            if ($result) {
                $this->runWithdrawAfterEffects($user, $avatarAttachment);
            }

            return $result;
        } catch (Exception $e) {
            if ($e instanceof ValidationException) {
                throw $e;
            }

            if ($e instanceof CannotDeleteSuperAdminException) {
                throw $e;
            }

            // 원본 예외는 로그로만 남기고, 사용자 응답에는 원문(SQL 상태코드·경로 등)을 싣지 않는다.
            Log::error('User withdraw failed', ['user_id' => $user->id, 'exception' => $e]);

            throw ValidationException::withMessages([
                'general' => [__('user.withdraw_failed')],
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
            // 슈퍼 관리자는 시스템 불변식으로 삭제 불가 (관리 anchor 보호)
            if ($user->isSuperAdmin()) {
                throw new CannotDeleteSuperAdminException;
            }

            // 관리자 계정 삭제 가능 여부는 G7 역할/퍼미션/스코프 시스템에 위임
            // (라우트 단계의 PermissionMiddleware 가 core.users.delete 권한 + scope_type 검증).
            // UserService 는 비-HTTP 경로(Artisan, 내부 호출) 에서도 호출되므로 별도 하드코딩하지 않는다.

            // 삭제 전 사용자 데이터 보관 (after_delete 훅에서 사용)
            $userData = $user->only(['id', 'uuid', 'name', 'email']);

            // 아바타는 파일 삭제를 동반하므로 커밋 후에 처리한다 (롤백되어도 파일은 안 돌아온다).
            $avatarAttachment = $user->avatarAttachment;

            // 마지막 delete 가 FK 등으로 실패하면 역할·동의·토큰만 사라지고 계정은 남는
            // 껍데기 활성 계정이 된다 (실회귀 이력 존재). 전 단계를 하나로 묶는다.
            $result = DB::transaction(function () use ($user) {
                // Before 훅
                HookManager::doAction('core.user.before_delete', $user);

                // 역할 연결 해제 (명시적 삭제 - CASCADE 의존 금지)
                $user->roles()->detach();

                // 약관 동의 이력 삭제
                $user->consents()->delete();

                // API 토큰 삭제
                $user->tokens()->delete();

                return $this->userRepository->delete($user);
            });

            // 커밋 성공 후 부수효과
            $user->flushPermissionCaches();

            if ($avatarAttachment) {
                try {
                    $this->attachmentService->delete($avatarAttachment->id);
                } catch (Exception $e) {
                    Log::warning('사용자 삭제 아바타 정리 실패', [
                        'user_id' => $userData['id'] ?? null,
                        'attachment_id' => $avatarAttachment->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

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

            // 원본 예외는 로그로만 남기고, 사용자 응답에는 원문을 싣지 않는다.
            Log::error('User delete failed', ['user_id' => $user->id, 'exception' => $e]);

            throw ValidationException::withMessages([
                'general' => [__('user.delete_failed')],
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
        $user = $this->userRepository->findByUuid($uuid);

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
     * 사용자의 활동 로그를 조회합니다.
     *
     * @param  int  $userId  사용자 ID
     * @param  int  $limit  조회 건수
     * @return \Illuminate\Support\Collection 활동 로그 요약 컬렉션
     */
    public function getUserActivityLogs(int $userId, int $limit = 50): \Illuminate\Support\Collection
    {
        return ActivityLog::byUser($userId)
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'action_label' => $log->action_label,
                'description' => $log->localized_description,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at?->toIso8601String(),
            ]);
    }

    /**
     * 사용자의 계정 잠금을 해제합니다 (관리자 수동 해제).
     *
     * 로그인 시도 추적 컬럼을 모두 초기화합니다. 영구 잠금(`locked_permanently`)도 함께
     * 해제되며, 이 경로가 없으면 잠금 시간 0(무한대) 으로 잠긴 계정은 성공 로그인 자체가
     * 불가하므로 DB 직접 조작 외에 복구 수단이 없습니다.
     *
     * @param  User  $user  잠금을 해제할 사용자
     * @return User 갱신된 사용자 모델
     */
    public function unlockAccount(User $user): User
    {
        // 등급 상한 가드: 비-슈퍼관리자 액터는 슈퍼 관리자 계정을 조작할 수 없다(정합성).
        UserGradeGuard::assertActorMayModify($user);

        HookManager::doAction('core.user.before_unlock', $user);

        $this->userRepository->resetLoginAttempts($user);

        $user = $user->fresh();

        HookManager::doAction('core.auth.account_unlocked', $user, [
            'ip_address' => request()->ip(),
        ]);

        return $user;
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

        // 정적 라우트(`PATCH users/bulk-status`)는 라우트 모델이 없어 PermissionMiddleware 의
        // 스코프 검사가 통째로 건너뛰어진다. 따라서 상세 경로(`PUT users/{user}`)가 미들웨어로
        // 강제하는 두 축을 서비스 계층에서 재적용한다 — 어느 한 축만 막으면 나머지가 우회로다.
        // 탈퇴 분기보다 먼저 적용해야 한다 — 뒤에 두면 탈퇴 경로가 스코프 검사를 건너뛰는
        // 우회로가 된다(KVE-1919).
        $targets = $this->userRepository->findManyByUuids($uuids);

        // ① 등급 축: 비-슈퍼관리자 액터가 포함시킨 슈퍼 관리자 대상은 제외한다
        // (슈퍼 관리자 무력화 차단 — 슈퍼 세션 유지).
        $modifiable = UserGradeGuard::filterModifiable($targets);

        // ② 스코프 축: 액터의 유효 스코프(self/role/글로벌) 밖 대상은 제외한다.
        // 판정은 상세 경로와 동일한 SSoT(PermissionHelper::checkScopeAccess)에 위임한다 —
        // 여기서 재구현하면 role 분기만 빠지는 식으로 두 경로의 강도가 갈린다.
        $modifiable = PermissionHelper::filterByScope($modifiable, 'core.users.update');

        // 일괄 '탈퇴'는 건별 정식 탈퇴로 전환한다 — 상태 컬럼만 바꾸면 익명화와
        // before/after_withdraw 훅이 통째로 생략되어, 본인 탈퇴와 결과가 달라진다.
        // 위에서 등급·스코프로 걸러낸 대상에 한해서만 수행한다.
        if ($statusEnum === UserStatus::Withdrawn) {
            $modifiableUuids = array_map(static fn (User $u): string => $u->uuid, $modifiable);

            return $this->bulkWithdraw($modifiableUuids, $status);
        }

        $userIds = array_map(static fn (User $u): int => $u->id, $modifiable);

        if (empty($userIds)) {
            HookManager::doAction('sirsoft-core.user.after_bulk_update', $uuids, $status, 0);

            return [
                'updated_count' => 0,
                'failed_count' => 0,
                'failed_reasons' => [],
            ];
        }

        // DB 트랜잭션으로 일괄 업데이트
        $updatedCount = DB::transaction(function () use ($userIds, $statusEnum) {
            // 타임스탬프 자동 설정
            $updateData = ['status' => $statusEnum->value];
            $updateData = match ($statusEnum) {
                UserStatus::Blocked => array_merge($updateData, ['blocked_at' => now()]),
                // 탈퇴는 위에서 건별 withdrawUser() 로 분기하므로 여기로는 도달하지 않는다.
                UserStatus::Withdrawn => $updateData,
                UserStatus::Active => array_merge($updateData, ['blocked_at' => null, 'withdrawn_at' => null]),
                UserStatus::Inactive => $updateData,
            };

            $count = $this->userRepository->updateManyByIds($userIds, $updateData);

            // Active 외 상태로 변경 시 해당 사용자들의 토큰 전체 삭제
            if ($statusEnum !== UserStatus::Active) {
                $this->userRepository->deleteTokensByUserIds($userIds);
            }

            return $count;
        });

        // after_bulk_update 훅 실행
        HookManager::doAction('sirsoft-core.user.after_bulk_update', $uuids, $status, $updatedCount);

        return [
            'updated_count' => $updatedCount,
            'failed_count' => 0,
            'failed_reasons' => [],
        ];
    }

    /**
     * 탈퇴 실패 예외를 운영자에게 보여줄 한 줄 사유로 바꿉니다.
     *
     * @param  Exception  $e  탈퇴 시도에서 발생한 예외
     * @return string 운영자에게 보여줄 실패 사유
     */
    private function describeWithdrawFailure(Exception $e): string
    {
        if ($e instanceof ValidationException) {
            $first = collect($e->errors())->flatten()->first();

            if (is_string($first) && $first !== '') {
                return $first;
            }
        }

        if ($e instanceof CannotDeleteSuperAdminException) {
            return __('exceptions.cannot_delete_super_admin');
        }

        // 알 수 없는 예외의 원문 메시지는 사유로 쓰지 않는다 — 내부 사정(SQL·경로·클래스명)이
        // 운영자 화면으로 새고, 화면 문구 조립에서 파라미터 구분자로 쓰이는 문자(`|` `&` `=`)가
        // 섞이면 안내가 잘린다. 사유는 언어 파일이 소유하는 문구로만 구성한다.
        return __('user.withdraw_failed_unknown');
    }

    /**
     * 일괄 탈퇴를 건별 정식 탈퇴로 수행합니다.
     *
     * @param  array<int, string>  $uuids  대상 사용자 UUID 배열
     * @param  string  $status  요청 상태값 (훅 전달용)
     * @return array{updated_count: int, failed_count: int, failed_reasons: array<int, string>} 처리 결과
     */
    private function bulkWithdraw(array $uuids, string $status): array
    {
        $updatedCount = 0;
        $failedCount = 0;
        $failedReasons = [];

        foreach ($uuids as $uuid) {
            $user = $this->userRepository->findByUuid($uuid);

            if (! $user) {
                $failedCount++;
                $failedReasons[] = __('user.not_found');

                continue;
            }

            try {
                $this->withdrawUser($user);
                $updatedCount++;
            } catch (Exception $e) {
                $failedCount++;
                $failedReasons[] = $this->describeWithdrawFailure($e);

                Log::warning('일괄 탈퇴 건 실패', [
                    'uuid' => $uuid,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // after_bulk_update 훅 실행
        HookManager::doAction('sirsoft-core.user.after_bulk_update', $uuids, $status, $updatedCount);

        // 사유를 함께 돌려주지 않으면 화면은 "몇 건 실패" 까지만 말할 수 있다 —
        // 운영자는 왜 안 됐는지 알 방법이 없어 같은 조작을 반복하게 된다.
        return [
            'updated_count' => $updatedCount,
            'failed_count' => $failedCount,
            'failed_reasons' => array_values(array_unique($failedReasons)),
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
