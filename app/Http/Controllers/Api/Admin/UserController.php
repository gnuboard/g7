<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\CannotDeleteAdminException;
use App\Exceptions\CannotDeleteSuperAdminException;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\User\BulkUpdateUserStatusRequest;
use App\Http\Requests\User\CheckEmailRequest;
use App\Http\Requests\User\CreateUserRequest;
use App\Http\Requests\User\DeleteUserRequest;
use App\Http\Requests\User\SearchUserRequest;
use App\Http\Requests\User\UpdateLanguageRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Requests\User\UserListRequest;
use App\Http\Resources\UserCollection;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\UserService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * 관리자용 사용자 관리 컨트롤러
 * 
 * 관리자가 시스템 사용자들을 관리할 수 있는 기능을 제공합니다.
 */
class UserController extends AdminBaseController
{
    public function __construct(
        private UserService $userService
    ) {
        parent::__construct();
    }

    /**
     * 필터링된 사용자 목록을 조회합니다.
     *
     * @param UserListRequest $request 사용자 목록 요청 데이터
     * @return JsonResponse 사용자 목록과 통계 정보를 포함한 JSON 응답
     */
    public function index(UserListRequest $request): JsonResponse
    {
        try {
            $filters = $request->validated();

            $users = $this->userService->getPaginatedUsers($filters);
            $statistics = $this->userService->getStatistics();

            $collection = new UserCollection($users);

            return $this->success(
                'user.fetch_success',
                $collection->withStatistics($statistics)
            );
        } catch (Exception $e) {
            return $this->error('user.fetch_failed', 500, $e->getMessage());
        }
    }

    /**
     * 새로운 사용자를 생성합니다.
     *
     * @param CreateUserRequest $request 사용자 생성 요청 데이터
     * @return JsonResponse 생성된 사용자 정보를 포함한 JSON 응답
     */
    public function store(CreateUserRequest $request): JsonResponse
    {
        try {
            $user = $this->userService->createUser($request->validated());

            return $this->successWithResource(
                'user.create_success',
                new UserResource($user),
                201
            );
        } catch (ValidationException $e) {
            return $this->error('user.create_failed', 422, $e->errors());
        } catch (Exception $e) {
            return $this->error('user.create_failed', 500, $e->getMessage());
        }
    }

    /**
     * 특정 사용자의 상세 정보를 조회합니다.
     *
     * @param User $user 조회할 사용자 모델
     * @return JsonResponse 사용자 상세 정보를 포함한 JSON 응답
     */
    public function show(User $user): JsonResponse
    {
        try {
            // 관계형 데이터 로드
            $user->load(['modules', 'plugins', 'menus', 'roles', 'consents']);
            $user->loadCount(['modules', 'plugins', 'menus']);

            // withAdminInfo()를 호출하여 관리자용 상세 정보 및 모듈 훅 데이터 포함
            $resource = new UserResource($user);

            return $this->success(
                'user.fetch_success',
                $resource->withAdminInfo()
            );
        } catch (Exception $e) {
            return $this->error('user.fetch_failed', 500, $e->getMessage());
        }
    }

    /**
     * 기존 사용자 정보를 수정합니다.
     *
     * @param UpdateUserRequest $request 사용자 수정 요청 데이터
     * @param User $user 수정할 사용자 모델
     * @return JsonResponse 수정된 사용자 정보를 포함한 JSON 응답
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        try {
            $updatedUser = $this->userService->updateUser($user, $request->validated());

            return $this->successWithResource(
                'user.update_success',
                new UserResource($updatedUser)
            );
        } catch (ValidationException $e) {
            return $this->error('user.update_failed', 422, $e->errors());
        } catch (Exception $e) {
            return $this->error('user.update_failed', 500, $e->getMessage());
        }
    }

    /**
     * 사용자를 삭제합니다.
     *
     * @param DeleteUserRequest $request 사용자 삭제 요청 데이터
     * @param User $user 삭제할 사용자 모델
     * @return JsonResponse 삭제 결과 JSON 응답
     */
    public function destroy(DeleteUserRequest $request, User $user): JsonResponse
    {
        try {
            $result = $this->userService->deleteUser($user);

            if ($result) {
                return $this->success('user.delete_success');
            } else {
                return $this->error('user.delete_failed');
            }
        } catch (CannotDeleteSuperAdminException $e) {
            return $this->error('exceptions.cannot_delete_super_admin', 422);
        } catch (CannotDeleteAdminException $e) {
            return $this->error('user.delete_admin_forbidden', 422);
        } catch (ValidationException $e) {
            return $this->error('user.delete_failed', 422, $e->errors());
        } catch (Exception $e) {
            return $this->error('user.delete_failed', 500);
        }
    }

    /**
     * 사용자 관련 통계 정보를 조회합니다.
     *
     * @return JsonResponse 사용자 통계 데이터를 포함한 JSON 응답
     */
    public function statistics(): JsonResponse
    {
        try {
            $statistics = $this->userService->getStatistics();
            $languageDistribution = $this->userService->getUserLanguageDistribution();

            $data = array_merge($statistics, [
                'language_distribution' => $languageDistribution,
            ]);

            return $this->success('user.statistics_success', $data);
        } catch (Exception $e) {
            return $this->error('user.statistics_failed', 500, $e->getMessage());
        }
    }

    /**
     * 최근 등록된 사용자들을 조회합니다.
     *
     * @return JsonResponse 최근 사용자 목록을 포함한 JSON 응답
     */
    public function recent(): JsonResponse
    {
        try {
            $users = $this->userService->getRecentUsers(10);

            return $this->successWithResource(
                'user.fetch_success',
                UserResource::collection($users)
            );
        } catch (Exception $e) {
            return $this->error('user.fetch_failed', 500, $e->getMessage());
        }
    }

    /**
     * 키워드로 사용자를 검색합니다. (이름, 닉네임, 이메일)
     *
     * @param SearchUserRequest $request 사용자 검색 요청 데이터
     * @return JsonResponse 검색된 사용자 목록을 포함한 JSON 응답
     */
    public function search(SearchUserRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            if (isset($validated['uuid'])) {
                $user = $this->userService->getUserByUuid($validated['uuid']);
                $users = $user ? collect([$user]) : collect();
            } elseif (isset($validated['id'])) {
                $user = $this->userService->getUserById($validated['id']);
                $users = $user ? collect([$user]) : collect();
            } else {
                $users = $this->userService->searchByKeyword($validated['keyword']);
            }

            return $this->successWithResource(
                'user.search_success',
                UserResource::collection($users)
            );
        } catch (Exception $e) {
            return $this->error('user.search_failed', 500, $e->getMessage());
        }
    }

    /**
     * 이메일 주소의 중복 여부를 확인합니다.
     *
     * @param CheckEmailRequest $request 이메일 중복 확인 요청 데이터
     * @return JsonResponse 이메일 사용 가능 여부를 포함한 JSON 응답
     */
    public function checkEmail(CheckEmailRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $email = $validated['email'];
            $excludeUserId = $validated['exclude_user_id'] ?? null;

            $isAvailable = $this->userService->isEmailAvailable($email, $excludeUserId);

            return $this->success(
                $isAvailable ? 'user.email_available' : 'user.email_unavailable',
                ['available' => $isAvailable]
            );
        } catch (Exception $e) {
            return $this->error('user.email_check_failed', 500, $e->getMessage());
        }
    }

    /**
     * 현재 로그인된 사용자의 언어 설정을 업데이트합니다.
     */
    public function updateMyLanguage(UpdateLanguageRequest $request): JsonResponse
    {
        try {
            $user = $request->user();
            $validated = $request->validated();
            $oldLanguage = $user->language;

            $updatedUser = $this->userService->updateUserLanguage($user, $validated['language']);

            return $this->successWithResource(
                'user.language_update_success',
                new UserResource($updatedUser)
            );
        } catch (ValidationException $e) {
            return $this->error('user.language_update_failed', 422, $e->errors());
        } catch (Exception $e) {
            return $this->error('user.language_update_failed', 500, $e->getMessage());
        }
    }

    /**
     * 여러 사용자의 상태를 일괄 변경합니다.
     */
    public function bulkUpdateStatus(BulkUpdateUserStatusRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $result = $this->userService->bulkUpdateStatus($validated['ids'], $validated['status']);

            return $this->success(
                'user.bulk_status_updated',
                $result
            );
        } catch (ValidationException $e) {
            return $this->error('user.bulk_update_status_failed', 422, $e->errors());
        } catch (Exception $e) {
            return $this->error('user.bulk_update_status_failed', 500, $e->getMessage());
        }
    }
}
