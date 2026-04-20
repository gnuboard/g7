<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\AttachmentSourceType;
use App\Http\Controllers\Api\Base\AuthBaseController;
use App\Http\Requests\Auth\VerifyPasswordRequest;
use App\Http\Requests\User\ChangePasswordRequest;
use App\Http\Requests\User\UpdateProfileRequest;
use App\Http\Requests\User\UploadAvatarRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AttachmentService;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * 사용자 프로필 관리 컨트롤러
 *
 * 일반 사용자가 자신의 프로필을 관리할 수 있는 기능을 제공합니다.
 */
class ProfileController extends AuthBaseController
{
    public function __construct(
        private UserService $userService,
        private AttachmentService $attachmentService
    ) {
        parent::__construct();
    }

    /**
     * 현재 로그인한 사용자의 프로필 정보를 조회합니다.
     *
     * @return JsonResponse 사용자 프로필 정보를 포함한 JSON 응답
     */
    public function show(): JsonResponse
    {
        try {
            $user = $this->getCurrentUser();

            if (! $user) {
                return $this->unauthorized('auth.unauthenticated');
            }

            $this->logUserActivity('profile.show');

            return $this->success(
                'user.profile_success',
                (new UserResource($user))->toProfileArray()
            );
        } catch (\Exception $e) {
            return $this->error('user.profile_failed', 500, null, ['error' => $e->getMessage()]);
        }
    }

    /**
     * 사용자 프로필을 업데이트합니다.
     *
     * @param  UpdateProfileRequest  $request  프로필 업데이트 요청 데이터
     * @return JsonResponse 업데이트된 사용자 정보를 포함한 JSON 응답
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        try {
            $user = $this->getCurrentUser();

            if (! $user) {
                return $this->unauthorized('auth.unauthenticated');
            }

            $updatedUser = $this->userService->updateUser($user, $request->validated());
            $this->logUserActivity('profile.update', $request->validated());

            return $this->successWithResource(
                'user.update_success',
                new UserResource($updatedUser)
            );
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'user.update_failed');
        } catch (\Exception $e) {
            return $this->error('user.update_failed', 500, $e->getMessage());
        }
    }

    /**
     * 사용자의 언어 설정을 변경합니다.
     *
     * @return JsonResponse 언어 변경 결과와 사용자 정보를 포함한 JSON 응답
     */
    public function updateLanguage(): JsonResponse
    {
        try {
            $user = $this->getCurrentUser();
            $language = request('language');

            if (! $user) {
                return $this->unauthorized('auth.unauthenticated');
            }

            if (! in_array($language, ['ko', 'en'])) {
                return $this->error('user.invalid_language', 400);
            }

            $updatedUser = $this->userService->updateUser($user, ['language' => $language]);
            $this->logUserActivity('profile.update_language', ['language' => $language]);

            return $this->successWithResource(
                'user.language_update_success',
                new UserResource($updatedUser)
            );
        } catch (\Exception $e) {
            return $this->error('user.language_update_failed', 500, $e->getMessage());
        }
    }

    /**
     * 사용자의 활동 기록을 조회합니다.
     *
     * @return JsonResponse 사용자 활동 기록을 포함한 JSON 응답
     */
    public function activityLog(): JsonResponse
    {
        try {
            $user = $this->getCurrentUser();

            if (! $user) {
                return $this->unauthorized('auth.unauthenticated');
            }

            $activities = $this->userService->getUserActivityLogs($user->id);

            $this->logUserActivity('profile.activity_log');

            return $this->success(
                'user.activity_log_success',
                ['activities' => $activities]
            );
        } catch (\Exception $e) {
            return $this->error('user.activity_log_failed', 500, null, ['error' => $e->getMessage()]);
        }
    }

    /**
     * 사용자 아바타를 업로드합니다.
     *
     * attachments 테이블의 다형성 관계를 사용하여 아바타를 저장합니다.
     *
     * @param  UploadAvatarRequest  $request  아바타 업로드 요청 데이터
     * @return JsonResponse 업로드 결과를 포함한 JSON 응답
     */
    public function uploadAvatar(UploadAvatarRequest $request): JsonResponse
    {
        try {
            $user = $this->getCurrentUser();

            if (! $user) {
                return $this->unauthorized('auth.unauthenticated');
            }

            // 기존 아바타 Attachment 삭제
            $existingAttachment = $user->avatarAttachment;
            if ($existingAttachment) {
                $this->attachmentService->delete($existingAttachment->id);
            }

            // 새 Attachment 생성 (다형성 관계)
            $attachment = $this->attachmentService->upload(
                $request->file('avatar'),
                User::class,
                $user->id,
                'avatar',
                AttachmentSourceType::Core
            );

            $this->logUserActivity('profile.avatar.upload', [
                'attachment_id' => $attachment->id,
            ]);

            // 관계 새로고침
            $user->load('avatarAttachment');

            return $this->success(
                'user.avatar_upload_success',
                [
                    'avatar' => $user->getAvatarUrl(),
                    'attachment_id' => $attachment->id,
                ]
            );
        } catch (\Exception $e) {
            return $this->error('user.avatar_upload_failed', 500, $e->getMessage());
        }
    }

    /**
     * 사용자 아바타를 삭제합니다.
     *
     * attachments 테이블의 다형성 관계를 사용하여 아바타를 삭제합니다.
     *
     * @return JsonResponse 삭제 결과를 포함한 JSON 응답
     */
    public function deleteAvatar(): JsonResponse
    {
        try {
            $user = $this->getCurrentUser();

            if (! $user) {
                return $this->unauthorized('auth.unauthenticated');
            }

            // 아바타 Attachment 확인
            $attachment = $user->avatarAttachment;
            if (! $attachment) {
                return $this->error('user.avatar_not_found', 404);
            }

            // Attachment 삭제 (파일도 함께 삭제됨)
            $this->attachmentService->delete($attachment->id);

            $this->logUserActivity('profile.avatar.delete');

            return $this->success('user.avatar_delete_success');
        } catch (\Exception $e) {
            return $this->error('user.avatar_delete_failed', 500, null, ['error' => $e->getMessage()]);
        }
    }

    /**
     * 사용자 비밀번호를 확인합니다.
     *
     * @param  Request  $request  비밀번호 확인 요청 데이터
     * @return JsonResponse 확인 결과를 포함한 JSON 응답
     */
    public function verifyPassword(VerifyPasswordRequest $request): JsonResponse
    {
        try {
            $user = $this->getCurrentUser();

            if (! $user) {
                return $this->unauthorized('auth.unauthenticated');
            }

            if (! Hash::check($request->password, $user->password)) {
                return $this->error('user.password_incorrect', 401);
            }

            $this->logUserActivity('profile.verify_password');

            return $this->success('user.password_verified');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'user.password_verify_failed');
        } catch (\Exception $e) {
            return $this->error('user.password_verify_failed', 500, null, ['error' => $e->getMessage()]);
        }
    }

    /**
     * 사용자 비밀번호를 변경합니다.
     *
     * @param  ChangePasswordRequest  $request  비밀번호 변경 요청 데이터
     * @return JsonResponse 비밀번호 변경 결과를 포함한 JSON 응답
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        try {
            $user = $this->getCurrentUser();

            if (! $user) {
                return $this->unauthorized('auth.unauthenticated');
            }

            // 새 비밀번호로 업데이트 (UserService에서 해싱 처리)
            $this->userService->updateUser($user, [
                'password' => $request->password,
            ]);

            $this->logUserActivity('profile.change_password');

            return $this->success('user.password_change_success');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'user.password_change_failed');
        } catch (\Exception $e) {
            return $this->error('user.password_change_failed', 500, null, ['error' => $e->getMessage()]);
        }
    }

    /**
     * 사용자 계정을 탈퇴 처리합니다.
     *
     * @return JsonResponse 탈퇴 처리 결과를 포함한 JSON 응답
     */
    public function destroy(): JsonResponse
    {
        try {
            $user = $this->getCurrentUser();

            if (! $user) {
                return $this->unauthorized('auth.unauthenticated');
            }

            $this->logUserActivity('profile.withdraw');

            // 사용자 탈퇴 처리 (아바타, 토큰 삭제 및 익명화)
            $this->userService->withdrawUser($user);

            return $this->success('user.withdraw_success');
        } catch (\Exception $e) {
            return $this->error('user.withdraw_failed', 500, null, ['error' => $e->getMessage()]);
        }
    }
}
