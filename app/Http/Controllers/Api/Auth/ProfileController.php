<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\AttachmentSourceType;
use App\Exceptions\Auth\PasswordAuthDisallowedException;
use App\Exceptions\Auth\PasswordReconfirmThrottledException;
use App\Exceptions\CannotDeleteSuperAdminException;
use App\Exceptions\IdentityVerificationRequiredException;
use App\Fields\FieldContext;
use App\Http\Controllers\Api\Base\AuthBaseController;
use App\Http\Requests\Auth\VerifyPasswordRequest;
use App\Http\Requests\User\ChangePasswordRequest;
use App\Http\Requests\User\CompleteRequiredFieldsRequest;
use App\Http\Requests\User\ProfileFieldsRequest;
use App\Http\Requests\User\UpdateProfileRequest;
use App\Http\Requests\User\UploadAvatarRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AttachmentService;
use App\Services\Auth\ReauthenticationService;
use App\Services\IdentityPolicyService;
use App\Services\MemberFieldSchemaService;
use App\Services\PasswordReconfirmationService;
use App\Services\UserLockedFieldsService;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * 사용자 프로필 관리 컨트롤러
 *
 * 일반 사용자가 자신의 프로필을 관리할 수 있는 기능을 제공합니다.
 */
class ProfileController extends AuthBaseController
{
    /**
     * 프로필 컨트롤러를 생성합니다.
     *
     * @param  UserService  $userService  회원 서비스
     * @param  AttachmentService  $attachmentService  첨부파일 서비스 (아바타)
     * @param  IdentityPolicyService  $identityPolicyService  본인인증 정책 서비스 (정보수정 API 의 비밀번호 변경)
     * @param  ReauthenticationService  $reauthenticationService  민감 작업 재확인 서비스 (비밀번호 또는 이메일 인증번호)
     * @param  UserLockedFieldsService  $lockedFields  회원 칸 수정 잠금 (언어 변경 경로)
     * @param  MemberFieldSchemaService  $memberFieldSchema  회원 항목 화면 계약 서비스
     * @param  PasswordReconfirmationService  $passwordReconfirmation  현재 비밀번호 재확인(회원 단위 시도 제한) 서비스 — 7.1.6
     */
    public function __construct(
        private UserService $userService,
        private AttachmentService $attachmentService,
        private IdentityPolicyService $identityPolicyService,
        private ReauthenticationService $reauthenticationService,
        private UserLockedFieldsService $lockedFields,
        private MemberFieldSchemaService $memberFieldSchema,
        private PasswordReconfirmationService $passwordReconfirmation,
    ) {
        parent::__construct();
    }

    /**
     * 내 정보 수정·보기 화면이 그릴 회원 항목과 정의 버전을 조회합니다.
     *
     * @param  ProfileFieldsRequest  $request  검증된 요청
     * @return JsonResponse 항목 계약 목록과 정의 버전
     */
    public function fields(ProfileFieldsRequest $request): JsonResponse
    {
        $user = $this->getCurrentUser();
        if (! $user) {
            return $this->unauthorized('auth.unauthenticated');
        }

        // 추가 정보 입력 화면 — 비어 있는 필수 항목만 준다
        if ($request->validated('context') === ProfileFieldsRequest::CONTEXT_REQUIRED) {
            return $this->success('common.success', $this->memberFieldSchema->forRequiredInfo($user));
        }

        return $this->success('common.success', $this->memberFieldSchema->forProfile($user, $request->validated()));
    }

    /**
     * 추가 정보 입력 화면의 값을 저장합니다 (비어 있던 필수 항목만).
     *
     * 받는 것이 비어 있던 필수 칸뿐이라 기존 값을 바꾸지 못한다 — 재확인을 요구하지 않는다.
     * 응답의 `pending_required_fields` 가 빈 배열이면 화면은 원래 가려던 곳으로 보낸다.
     * 파일 항목처럼 이 요청으로 채우지 못하는 항목이 남아 있으면 그 키가 그대로 온다.
     *
     * @param  CompleteRequiredFieldsRequest  $request  검증된 요청
     * @return JsonResponse 본인 정보와 남은 미입력 항목 키
     */
    public function completeRequiredFields(CompleteRequiredFieldsRequest $request): JsonResponse
    {
        try {
            $user = $this->getCurrentUser();

            if (! $user) {
                return $this->unauthorized('auth.unauthenticated');
            }

            $validated = $request->validated();
            $updatedUser = $this->userService->completeRequiredFields($user, $validated);

            $this->logUserActivity('profile.complete_required_fields', ['keys' => array_keys($validated)]);

            $data = (new UserResource($updatedUser))->toAuthArray($request);

            return $this->success('user.required_fields_saved', [
                'user' => $data,
                'pending_required_fields' => $data['pending_required_fields'] ?? [],
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'user.required_fields_save_failed');
        } catch (\Exception $e) {
            return $this->error('user.required_fields_save_failed', 500, $e->getMessage());
        }
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

            $validated = $request->validated();

            // 이 경로로 비밀번호를 함께 바꾸면 전용 변경 경로(`PUT /api/me/password`)와 같은 본인인증 정책을
            // 건다(D18) — 그러지 않으면 정책을 켠 사이트에서도 이 경로가 우회로가 된다. 판정은 라우트
            // 미들웨어와 같은 한 곳(`enforceForRequest`)이고, 위반이면 428 로 끊겨 아래 저장에 닿지 않는다.
            if (! empty($validated['password'])) {
                $this->identityPolicyService->enforceForRequest('core.profile.password_change', $request);
            }

            // 회원 항목은 본인이 고칠 수 있는 항목만 저장한다(관리자만 수정·열람 항목은 보내도 버린다).
            $updatedUser = $this->userService->updateUser($user, $validated, ['field_surface' => FieldContext::SURFACE_SELF_UPDATE]);

            // 비밀번호 3종(새 값·확인·현재 값)은 원문이라 로그에 남기지 않는다.
            $this->logUserActivity('profile.update', Arr::except($validated, ['password', 'password_confirmation', 'current_password']));

            return $this->successWithResource(
                'user.update_success',
                new UserResource($updatedUser)
            );
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'user.update_failed');
        } catch (PasswordAuthDisallowedException $e) {
            // 비밀번호 인증 정책 거절 — 전역 렌더러가 403 + 「회사 계정으로 로그인」 안내 데이터로 답한다
            throw $e;
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

            if (! in_array($language, config('app.supported_locales', ['ko', 'en']), true)) {
                return $this->error('user.invalid_language', 400);
            }

            // 외부(회사 계정)가 관리하는 언어는 바꾸지 못한다(7.2.0) — 정보 수정 요청과 같은 칸 잠금
            if ($language !== $user->language
                && in_array('language', $this->lockedFields->lockedFor($user, UserLockedFieldsService::SURFACE_PROFILE), true)) {
                return $this->validationError(['language' => [__('user.field_locked')]], 'user.field_locked');
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
     *
     * @throws IdentityVerificationRequiredException 비밀번호 없는 회원이 비밀번호로 확인하려 할 때 (428)
     */
    public function verifyPassword(VerifyPasswordRequest $request): JsonResponse
    {
        try {
            $user = $this->getCurrentUser();

            if (! $user) {
                return $this->unauthorized('auth.unauthenticated');
            }

            // 비밀번호가 있는 회원은 비밀번호로, 없는 회원은 이메일 인증번호로 받은 토큰으로 확인한다.
            // 비밀번호로 확인하는 갈래는 회원 단위 시도 제한과 함께 확인한다 — 열린 세션으로 비밀번호를 계속 맞혀 보지
            // 못하게(7.1.6, KVE 동형 L2). 인증번호 토큰·비밀번호 없는 회원 갈래는 재확인 서비스가 그대로 판정한다.
            $validated = $request->validated();
            $verificationToken = $validated['verification_token'] ?? null;
            $password = $validated['password'] ?? null;

            if ((! is_string($verificationToken) || $verificationToken === '')
                && is_string($password) && $password !== '' && $user->hasPassword()) {
                $result = $this->passwordReconfirmation->attempt($user, $password)
                    ? ReauthenticationService::PASSED
                    : ReauthenticationService::PASSWORD_MISMATCH;
            } else {
                $result = $this->reauthenticationService->verify($user, $validated);
            }

            // 비밀번호 없는 회원이 비밀번호 칸으로 왔다 = 그 회원을 모르는 옛 화면(7.1.x)이다. 보낸 값은 쓰지 않고 본인인증을
            // 요구한다(428) — 엔진이 인증 창을 열고, 확인이 끝나면 토큰을 붙여 이 요청을 다시 보낸다(위 verify 가 소비).
            // 예외는 \Error 계열이라 아래 catch(\Exception) 에 잡히지 않고 전역 렌더러가 428 로 답한다.
            // 아무것도 없이 온 비밀번호 없는 회원(옛 화면에 코어 호환 심이 붙인 「본인 확인 계속하기」)도 같다.
            if ($result === ReauthenticationService::PASSWORD_NOT_SET
                || ($result === ReauthenticationService::MISSING && ! $user->hasPassword())) {
                throw $this->reauthenticationService->challengeFor($request);
            }

            if ($result === ReauthenticationService::TOKEN_INVALID) {
                return $this->error('user.reauthentication_invalid', 422, ['verification_token' => [__('user.reauthentication_invalid')]]);
            }

            if ($result !== ReauthenticationService::PASSED) {
                return $this->error('user.password_incorrect', 401);
            }

            $this->logUserActivity('profile.verify_password');

            return $this->success('user.password_verified');
        } catch (PasswordReconfirmThrottledException $e) {
            return $this->error('auth.password_reconfirm_throttled', 429, null, ['seconds' => $e->retryAfterSeconds])
                ->header('Retry-After', (string) $e->retryAfterSeconds);
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
        } catch (PasswordAuthDisallowedException $e) {
            // 비밀번호 인증 정책 거절 — 전역 렌더러가 403 + 「회사 계정으로 로그인」 안내 데이터로 답한다
            throw $e;
        } catch (\Exception $e) {
            // 주의: IdentityVerificationRequiredException 은 \Error 자식이라 이 catch 에 잡히지 않음.
            // 글로벌 핸들러가 자동으로 428 매핑 — 모든 컨트롤러 (코어/모듈/플러그인) 에 동일 보장.
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

            // 사용자 탈퇴 처리 (아바타, 토큰 삭제 및 익명화)
            $this->userService->withdrawUser($user);

            // 활동 로그는 성공한 뒤에만 남긴다 — 실패한 탈퇴가 기록으로 남으면
            // 이력만 보고는 탈퇴한 것으로 읽힌다.
            $this->logUserActivity('profile.withdraw');

            return $this->success('user.withdraw_success');
        } catch (ValidationException $e) {
            // 관리자/수퍼관리자 탈퇴 차단은 요청이 잘못된 것이지 서버 오류가 아니다.
            return $this->validationError($e->errors(), 'user.withdraw_failed');
        } catch (CannotDeleteSuperAdminException $e) {
            // 이 예외는 ValidationException 을 상속하지 않으므로 별도 매핑이 필요하다.
            return $this->validationError(
                ['general' => [$e->getMessage()]],
                'user.withdraw_failed'
            );
        } catch (\Exception $e) {
            // 원본 예외는 로그로만 남기고, 사용자 응답에는 원문을 싣지 않는다.
            Log::error('User withdraw failed (profile)', ['exception' => $e]);

            return $this->error('user.withdraw_failed', 500);
        }
    }
}
