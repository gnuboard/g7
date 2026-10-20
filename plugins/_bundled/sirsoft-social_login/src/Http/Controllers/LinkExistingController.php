<?php

namespace Plugins\Sirsoft\SocialLogin\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\SocialLogin\Exceptions\SocialFlowException;
use Plugins\Sirsoft\SocialLogin\Http\Controllers\Concerns\RespondsWithFlowErrors;
use Plugins\Sirsoft\SocialLogin\Http\Requests\LinkExistingRequest;
use Plugins\Sirsoft\SocialLogin\Http\Requests\PendingSignupRequest;
use Plugins\Sirsoft\SocialLogin\Http\Requests\VerifyCodeRequest;
use Plugins\Sirsoft\SocialLogin\Services\LinkExistingService;
use Plugins\Sirsoft\SocialLogin\Services\SignupFlowService;

/**
 * 「이미 가입된 이메일」 모달 API.
 *
 * - POST /api/plugins/sirsoft-social_login/signup/link-existing/request — 비밀번호 확인(바로 연결) 또는 인증번호 받기
 * - POST /api/plugins/sirsoft-social_login/signup/link-existing/verify — 인증번호 확인 뒤 연결
 * - POST /api/plugins/sirsoft-social_login/signup/discard-existing — [새 계정으로 가입]
 *
 * @since 1.0.0
 */
class LinkExistingController extends PublicBaseController
{
    use RespondsWithFlowErrors;

    /**
     * @param  LinkExistingService  $linkExisting  기존 계정 연결
     * @param  SignupFlowService  $signup  가입 흐름
     */
    public function __construct(
        private readonly LinkExistingService $linkExisting,
        private readonly SignupFlowService $signup,
    ) {
        parent::__construct();
    }

    /**
     * 비밀번호로 확인하거나 인증번호를 보냅니다.
     *
     * @param  LinkExistingRequest  $request  요청 (method: password|email)
     * @return JsonResponse 비밀번호면 `handoff_url`, 이메일이면 인증 요청 ID
     */
    public function request(LinkExistingRequest $request): JsonResponse
    {
        try {
            if ($request->validated('method') === 'password') {
                $data = $this->linkExisting->confirmWithPassword($request->pendingToken(), (string) $request->validated('password'));

                return ResponseHelper::pluginSuccess('sirsoft-social_login', 'messages.link_existing.linked', $data);
            }

            $data = $this->linkExisting->requestEmailCode($request->pendingToken());

            return ResponseHelper::pluginSuccess('sirsoft-social_login', 'messages.signup.code_sent', $data);
        } catch (SocialFlowException $e) {
            return $this->flowError($e);
        }
    }

    /**
     * 인증번호로 확인하고 연결합니다.
     *
     * @param  VerifyCodeRequest  $request  요청
     * @return JsonResponse `handoff_url`
     */
    public function verify(VerifyCodeRequest $request): JsonResponse
    {
        try {
            $data = $this->linkExisting->confirmWithEmailCode($request->pendingToken(), (string) $request->validated('challenge_id'), (string) $request->validated('code'));

            return ResponseHelper::pluginSuccess('sirsoft-social_login', 'messages.link_existing.linked', $data);
        } catch (SocialFlowException $e) {
            return $this->flowError($e);
        }
    }

    /**
     * 기존 계정에 연결하지 않고 새 계정으로 가입합니다.
     *
     * @param  PendingSignupRequest  $request  요청
     * @return JsonResponse 새 화면 데이터
     */
    public function discard(PendingSignupRequest $request): JsonResponse
    {
        try {
            return ResponseHelper::pluginSuccess('sirsoft-social_login', 'messages.signup.pending', $this->signup->discardExisting($request->pendingToken()));
        } catch (SocialFlowException $e) {
            return $this->flowError($e);
        }
    }
}
