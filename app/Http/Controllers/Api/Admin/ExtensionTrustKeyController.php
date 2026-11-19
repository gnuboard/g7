<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\ExtensionTrustKey\DestroyExtensionTrustKeyRequest;
use App\Http\Requests\ExtensionTrustKey\IndexExtensionTrustKeyRequest;
use App\Http\Requests\ExtensionTrustKey\StoreExtensionTrustKeyRequest;
use App\Http\Resources\ExtensionTrustKeyResource;
use App\Models\User;
use App\Services\Auth\ReauthenticationService;
use App\Services\ExtensionTrustKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * 관리자 — 확장 신뢰 키 (환경설정 > 보안 「확장 신뢰 키」).
 *
 * 추가·삭제는 탭 일괄 저장과 분리된 전용 API 이고, 같은 요청에서 관리자 본인을 다시 확인한다(비밀번호 또는 인증 토큰).
 * 비밀번호 없는 관리자가 비밀번호 칸으로 오면 본인인증(428)을 요구한다 — 엔진이 인증 창을 열고 토큰을 붙여 다시 보낸다.
 *
 * @since 7.2.0
 */
class ExtensionTrustKeyController extends AdminBaseController
{
    /**
     * 컨트롤러를 만듭니다.
     *
     * @param  ExtensionTrustKeyService  $service  신뢰 키 서비스
     * @param  ReauthenticationService  $reauthentication  민감 작업 재확인
     */
    public function __construct(
        private readonly ExtensionTrustKeyService $service,
        private readonly ReauthenticationService $reauthentication,
    ) {
        parent::__construct();
    }

    /**
     * 내장 키(읽기 전용)·제작자 키 목록과 차단 스위치 상태를 돌려줍니다.
     *
     * @param  IndexExtensionTrustKeyRequest  $request  조회 요청
     * @return JsonResponse 목록 응답
     */
    public function index(IndexExtensionTrustKeyRequest $request): JsonResponse
    {
        try {
            $overview = $this->service->overview();

            return $this->success('extension_integrity.trust_keys.fetch_success', [
                'builtin' => $overview['builtin'],
                'keys' => ExtensionTrustKeyResource::collection($overview['keys'])->resolve($request),
                'block_unsigned' => $overview['block_unsigned'],
                'sodium_available' => $overview['sodium_available'],
            ]);
        } catch (\Exception $e) {
            return $this->error('extension_integrity.trust_keys.fetch_failed', 500, $e->getMessage());
        }
    }

    /**
     * 제작자 키를 추가합니다 (본인 재확인).
     *
     * @param  StoreExtensionTrustKeyRequest  $request  검증된 요청
     * @return JsonResponse 201 + 추가한 키
     */
    public function store(StoreExtensionTrustKeyRequest $request): JsonResponse
    {
        $denied = $this->confirmIdentity($request);
        if ($denied !== null) {
            return $denied;
        }

        try {
            $key = $this->service->create($request->safe()->only(['public_key', 'label', 'vendor_scope']), $request->user()?->id);

            return $this->successWithResource('extension_integrity.trust_keys.created', new ExtensionTrustKeyResource($key), 201);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('extension_integrity.trust_keys.create_failed', 500, $e->getMessage());
        }
    }

    /**
     * 제작자 키를 삭제합니다 (본인 재확인).
     *
     * @param  DestroyExtensionTrustKeyRequest  $request  검증된 요청
     * @param  int  $trustKey  키 ID
     * @return JsonResponse 삭제 응답
     */
    public function destroy(DestroyExtensionTrustKeyRequest $request, int $trustKey): JsonResponse
    {
        $key = $this->service->find($trustKey);
        if ($key === null) {
            return $this->notFound('extension_integrity.trust_keys.not_found');
        }

        $denied = $this->confirmIdentity($request);
        if ($denied !== null) {
            return $denied;
        }

        try {
            $this->service->delete($key);

            return $this->success('extension_integrity.trust_keys.deleted', ['id' => $trustKey, 'key_id' => $key->key_id]);
        } catch (\Exception $e) {
            return $this->error('extension_integrity.trust_keys.delete_failed', 500, $e->getMessage());
        }
    }

    /**
     * 요청 관리자 본인을 다시 확인하고, 통과하지 못하면 응답을 돌려줍니다.
     *
     * @param  Request  $request  요청 (password 또는 verification_token 포함)
     * @return JsonResponse|null 거절 응답 (통과하면 null)
     */
    private function confirmIdentity(Request $request): ?JsonResponse
    {
        $admin = $request->user();
        if (! $admin instanceof User) {
            return $this->unauthorized();
        }

        $credentials = $request->only(['password', 'verification_token']);
        $result = $this->service->reauthenticate($admin, $credentials);

        // 비밀번호 없는 관리자 — 본인인증을 요구한다(428, \Error 계열이라 전역 렌더러가 답한다)
        if ($result === ReauthenticationService::PASSWORD_NOT_SET
            || ($result === ReauthenticationService::MISSING && ! $admin->hasPassword())) {
            throw $this->reauthentication->challengeFor($request);
        }

        if ($result === ReauthenticationService::TOKEN_INVALID) {
            return $this->validationError(
                ['verification_token' => [__('extension_integrity.trust_keys.reauth_invalid')]],
                'extension_integrity.trust_keys.reauth_invalid'
            );
        }

        if ($result === ReauthenticationService::MISSING) {
            return $this->validationError(
                ['password' => [__('extension_integrity.validation.password_required')]],
                'extension_integrity.validation.password_required'
            );
        }

        if ($result !== ReauthenticationService::PASSED) {
            return $this->validationError(
                ['password' => [__('extension_integrity.trust_keys.password_invalid')]],
                'extension_integrity.trust_keys.password_invalid'
            );
        }

        return null;
    }
}
