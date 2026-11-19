<?php

namespace Plugins\Sirsoft\SocialLogin\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\SocialLogin\Exceptions\SocialFlowException;
use Plugins\Sirsoft\SocialLogin\Http\Controllers\Concerns\AttachesHandoffCookie;
use Plugins\Sirsoft\SocialLogin\Http\Controllers\Concerns\RespondsWithFlowErrors;
use Plugins\Sirsoft\SocialLogin\Http\Requests\CompleteSignupRequest;
use Plugins\Sirsoft\SocialLogin\Http\Requests\PendingSignupRequest;
use Plugins\Sirsoft\SocialLogin\Http\Requests\SignupEmailRequest;
use Plugins\Sirsoft\SocialLogin\Http\Requests\VerifyCodeRequest;
use Plugins\Sirsoft\SocialLogin\Services\SignupFlowService;

/**
 * 소셜 가입 모달 API — 가입 대기 조회, 이메일 인증번호, 가입 완료.
 *
 * - GET  /api/plugins/sirsoft-social_login/signup/pending
 * - POST /api/plugins/sirsoft-social_login/signup/email/request
 * - POST /api/plugins/sirsoft-social_login/signup/email/verify
 * - POST /api/plugins/sirsoft-social_login/signup/complete
 *
 * 게스트 진입점이다. 가입 대기 토큰이 곧 권한이고, 대기는 제공자 확인을 마친 브라우저만 받는다.
 *
 * @since 1.0.0
 */
class SignupController extends PublicBaseController
{
    use AttachesHandoffCookie;
    use RespondsWithFlowErrors;

    /**
     * @param  SignupFlowService  $signup  가입 흐름
     */
    public function __construct(private readonly SignupFlowService $signup)
    {
        parent::__construct();
    }

    /**
     * 가입 대기를 조회합니다.
     *
     * @param  PendingSignupRequest  $request  요청 (pending_token)
     * @return JsonResponse 화면 데이터
     */
    public function pending(PendingSignupRequest $request): JsonResponse
    {
        try {
            return ResponseHelper::pluginSuccess('sirsoft-social_login', 'messages.signup.pending', $this->signup->view($request->pendingToken()));
        } catch (SocialFlowException $e) {
            return $this->flowError($e);
        }
    }

    /**
     * 가입 이메일 인증번호를 보냅니다.
     *
     * @param  SignupEmailRequest  $request  요청 (pending_token, email)
     * @return JsonResponse 인증 요청 ID·만료 시각
     */
    public function requestEmailCode(SignupEmailRequest $request): JsonResponse
    {
        try {
            $data = $this->signup->requestEmailCode($request->pendingToken(), (string) $request->validated('email'));

            return ResponseHelper::pluginSuccess('sirsoft-social_login', 'messages.signup.code_sent', $data);
        } catch (SocialFlowException $e) {
            return $this->flowError($e);
        }
    }

    /**
     * 가입 이메일 인증번호를 확인합니다.
     *
     * @param  VerifyCodeRequest  $request  요청 (pending_token, challenge_id, code)
     * @return JsonResponse 확인된 이메일
     */
    public function verifyEmailCode(VerifyCodeRequest $request): JsonResponse
    {
        try {
            $data = $this->signup->verifyEmailCode($request->pendingToken(), (string) $request->validated('challenge_id'), (string) $request->validated('code'));

            return ResponseHelper::pluginSuccess('sirsoft-social_login', 'messages.signup.email_verified', $data);
        } catch (SocialFlowException $e) {
            return $this->flowError($e);
        }
    }

    /**
     * 가입을 마칩니다.
     *
     * @param  CompleteSignupRequest  $request  요청
     * @return JsonResponse 다음 주소 (`handoff_url` 또는 `redirect_url`)
     */
    public function complete(CompleteSignupRequest $request): JsonResponse
    {
        try {
            $data = $this->signup->complete($request->pendingToken(), $request->validated());

            return $this->successWithHandoffCookie('messages.signup.completed', $data);
        } catch (SocialFlowException $e) {
            return $this->flowError($e);
        }
    }
}
