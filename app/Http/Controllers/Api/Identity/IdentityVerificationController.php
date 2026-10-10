<?php

namespace App\Http\Controllers\Api\Identity;

use App\Enums\IdentityOriginType;
use App\Enums\IdentityVerificationPurpose;
use App\Enums\IdentityVerificationStatus;
use App\Extension\IdentityVerification\IdentityVerificationManager;
use App\Http\Controllers\Api\Base\PublicBaseController;
use App\Http\Requests\Identity\CancelChallengeRequest;
use App\Http\Requests\Identity\ClaimChallengeRequest;
use App\Http\Requests\Identity\IdentityCallbackRequest;
use App\Http\Requests\Identity\ProvidersIndexRequest;
use App\Http\Requests\Identity\PurposesIndexRequest;
use App\Http\Requests\Identity\RequestChallengeRequest;
use App\Http\Requests\Identity\ResolvePolicyRequest;
use App\Http\Requests\Identity\ShowChallengeRequest;
use App\Http\Requests\Identity\VerifyChallengeRequest;
use App\Http\Resources\Identity\ChallengeResource;
use App\Http\Resources\Identity\ProviderResource;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use App\Services\IdentityPolicyService;
use App\Services\IdentityVerificationService;
use App\Support\SiteUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/**
 * 본인인증 공개 API 컨트롤러.
 *
 * 비로그인 가입 플로우(Mode B) 에서도 접근하므로 PublicBaseController 를 상속합니다.
 * 로그인 필요 엔드포인트는 라우트의 permission 미들웨어가 담당합니다.
 */
class IdentityVerificationController extends PublicBaseController
{
    /**
     * @param  IdentityVerificationService  $service  본인인증 유스케이스 Service
     * @param  IdentityVerificationManager  $manager  프로바이더 레지스트리 (Service 역할 겸함)
     * @param  IdentityPolicyService  $policyService  정책 해석 Service (프런트 프리페치용)
     */
    public function __construct(
        protected IdentityVerificationService $service,
        protected IdentityVerificationManager $manager,
        protected IdentityPolicyService $policyService,
    ) {
        parent::__construct();
    }

    /**
     * Challenge 를 요청합니다. POST /api/identity/challenges
     *
     * @param  RequestChallengeRequest  $request  검증된 요청
     * @return JsonResponse 생성된 Challenge 리소스 (201)
     */
    public function request(RequestChallengeRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        $target = $user ?: ($validated['target'] ?? []);
        if (! ($user instanceof User) && empty($target['email']) && empty($target['phone'])) {
            return $this->error('identity.errors.missing_target', 422);
        }

        $providerId = $validated['provider_id'] ?? null;
        if (is_string($providerId) && $providerId === '') {
            $providerId = null;
        }

        // 428 이 알려 준 정책 — 메일 발송부가 그 정책별 문구를 고르고 이력에 출처 정책을 남긴다.
        $originPolicy = $this->policyService->findUsableForChallenge(
            $validated['policy_key'] ?? null,
            (string) $validated['purpose'],
        );

        $challenge = $this->service->start(
            purpose: (string) $validated['purpose'],
            target: $target,
            context: [
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 512),
                'origin_type' => IdentityOriginType::Api->value,
                'origin_identifier' => '/api/identity/challenges',
                'origin_policy_key' => $originPolicy?->key,
            ],
            providerId: $providerId,
        );

        // 발송이 실패로 끝났으면 성공으로 포장하지 않는다.
        //
        // 코드 전달이 실패해도 챌린지 자체는 만들어진다. 그 상태로 201 을 돌려주면 화면은
        // 「전송된 6자리 코드를 입력해주세요」와 카운트다운을 띄우고, 사용자는 **영영 오지 않을
        // 코드**를 기다린다(#599 세션A 실측 2026-09-22 · 회원 탈퇴 경로 — 챌린지가 생성 2초 뒤
        // `failed` 인데 API 는 201 을 냈다. 코드는 해시로만 남아 복구할 수 없어 그 환경에서는
        // 회원이 스스로 탈퇴할 방법이 없었다).
        //
        // 상태는 공개 폴링과 같은 판정을 쓴다 — 두 곳에서 따로 재면 갈린다.
        $status = $this->service->getStatus($challenge->id);
        if (($status['status'] ?? null) === IdentityVerificationStatus::Failed->value) {
            return $this->error('identity.errors.challenge_dispatch_failed', 502);
        }

        // 링크 방식은 확인을 메일 링크가 하므로, 시작한 화면이 결과를 받아 갈 일회용 비밀값을 이 응답에만 싣는다.
        $claimSecret = $this->service->issueClaimSecret($challenge->id);

        return $this->success(
            'identity.messages.challenge_requested',
            (new ChallengeResource($challenge))->toArray($request) + array_filter(['claim_secret' => $claimSecret], fn ($v) => $v !== null),
            201,
        );
    }

    /**
     * 링크 방식 challenge 의 확인 결과를 시작한 화면이 받아 갑니다. POST /api/identity/challenges/{challenge}/claim
     *
     * 확인이 끝났고 비밀값이 맞으면 verification_token 을 한 번만 돌려준다(200). 아직이면 202(pending),
     * 비밀값이 틀리면 403, 이미 받아 갔으면 409, 만료·실패·취소면 422.
     *
     * @param  ClaimChallengeRequest  $request  검증된 요청 (claim_secret)
     * @param  IdentityVerificationLog  $challenge  라우트 모델 바인딩으로 resolve 된 challenge 로그
     * @return JsonResponse 결과
     */
    public function claim(ClaimChallengeRequest $request, IdentityVerificationLog $challenge): JsonResponse
    {
        $result = $this->service->claim($challenge->id, (string) $request->validated()['claim_secret']);

        return match ($result['status']) {
            IdentityVerificationStatus::Verified->value => $this->success('identity.messages.challenge_verified', [
                'status' => 'verified',
                'verification_token' => $result['verification_token'],
            ]),
            'pending' => $this->success('identity.messages.challenge_pending', ['status' => 'pending'], 202),
            'denied' => $this->error('identity.errors.claim_denied', 403),
            'claimed' => $this->error('identity.errors.claim_already_used', 409),
            default => $this->error('identity.errors.claim_unavailable', 422, ['status' => $result['status']]),
        };
    }

    /**
     * Challenge 를 검증합니다. POST /api/identity/challenges/{challenge}/verify
     *
     * 라우트는 `permission:user,core.identity.verify` 미들웨어 + Route::model('challenge') 바인딩으로 보호됩니다.
     * 로그인 사용자는 PermissionMiddleware 의 scope=self 가드가 challenge.user_id 일치를 자동 검증합니다.
     * 비로그인 게스트는 guest 역할 권한만 통과하면 진입합니다 (Mode B 가입 흐름).
     *
     * @param  VerifyChallengeRequest  $request  검증된 요청 (code 또는 token 포함)
     * @param  IdentityVerificationLog  $challenge  라우트 모델 바인딩으로 resolve 된 challenge 로그
     * @return JsonResponse 검증 결과 (verification_token 포함)
     */
    public function verify(VerifyChallengeRequest $request, IdentityVerificationLog $challenge): JsonResponse
    {
        // 로그인 challenge 는 이 공개 경로로 다루지 않는다. 여기서 검증·취소되면
        // 바로 뒤의 `auth/login/two-factor` 가 INVALID_STATE 로 거절해, 그 challenge 로는
        // 영영 로그인할 수 없게 된다(자기 DoS). 로그인 전용 엔드포인트만 사용한다.
        if ($challenge->purpose === IdentityVerificationPurpose::Login->value) {
            return $this->error('identity.errors.purpose_not_allowed', 403, [
                'failure_code' => 'PURPOSE_NOT_ALLOWED',
            ]);
        }

        $result = $this->service->verify(
            challengeId: $challenge->id,
            input: $request->validated(),
            context: [
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 512),
            ],
        );

        // verify 실패 시에도 서버 측 시도 횟수를 응답에 포함 — 클라이언트가 자체 카운트를 서버와 동기화하여
        // "남은 시도 횟수" UI 가 다른 탭/세션과 불일치하지 않도록.
        if (! $result->success) {
            $fresh = $challenge->fresh();

            return $this->error(
                $result->failureReason ?: 'identity.errors.generic',
                422,
                [
                    'failure_code' => $result->failureCode,
                    'attempts' => $fresh ? (int) $fresh->attempts : (int) $challenge->attempts,
                    'max_attempts' => $fresh ? (int) $fresh->max_attempts : (int) $challenge->max_attempts,
                ],
            );
        }

        return $this->success('identity.messages.challenge_verified', [
            'challenge_id' => $result->challengeId,
            'provider_id' => $result->providerId,
            'verified_at' => $result->verifiedAt?->toIso8601String(),
            'verification_token' => $result->claims['verification_token'] ?? null,
        ]);
    }

    /**
     * Challenge 를 취소합니다. POST /api/identity/challenges/{challenge}/cancel
     *
     * 라우트는 `permission:user,core.identity.cancel` 미들웨어 + Route::model('challenge') 바인딩으로 보호됩니다.
     * 로그인 사용자는 PermissionMiddleware 의 scope=self 가드가 challenge.user_id 일치를 자동 검증합니다.
     * 비로그인 게스트는 guest 역할 권한만 통과하면 진입합니다 (모달 취소 시 audit trail 정합용).
     *
     * @param  CancelChallengeRequest  $request  검증된 요청
     * @param  IdentityVerificationLog  $challenge  라우트 모델 바인딩으로 resolve 된 challenge 로그
     * @return JsonResponse 취소 결과
     */
    public function cancel(CancelChallengeRequest $request, IdentityVerificationLog $challenge): JsonResponse
    {
        // 로그인 challenge 는 이 공개 경로로 다루지 않는다. 여기서 검증·취소되면
        // 바로 뒤의 `auth/login/two-factor` 가 INVALID_STATE 로 거절해, 그 challenge 로는
        // 영영 로그인할 수 없게 된다(자기 DoS). 로그인 전용 엔드포인트만 사용한다.
        if ($challenge->purpose === IdentityVerificationPurpose::Login->value) {
            return $this->error('identity.errors.purpose_not_allowed', 403, [
                'failure_code' => 'PURPOSE_NOT_ALLOWED',
            ]);
        }

        $ok = $this->service->cancel($challenge->id);

        if (! $ok) {
            return $this->error('identity.errors.challenge_not_found', 404);
        }

        return $this->success('identity.messages.challenge_cancelled');
    }

    /**
     * Challenge 의 공개 상태를 폴링합니다. GET /api/identity/challenges/{challenge}
     *
     * 비동기 검증 흐름(Stripe Identity / 토스인증 push / 외부 redirect 콜백 대기) 에서 클라이언트가
     * verify 즉시 응답을 받지 못할 때 상태를 추적하기 위한 엔드포인트.
     *
     * 노출 필드는 공개 안전 항목만 (시도 횟수·코드 본체·metadata 노출 금지) — Service::getStatus 참조.
     *
     * @param  ShowChallengeRequest  $request  검증된 요청
     * @param  IdentityVerificationLog  $challenge  라우트 모델 바인딩으로 resolve 된 challenge 로그
     * @return JsonResponse 공개 안전 상태 필드
     *
     * @since engine-v1.46.0
     */
    public function show(ShowChallengeRequest $request, IdentityVerificationLog $challenge): JsonResponse
    {
        $status = $this->service->getStatus($challenge->id);

        if ($status === null) {
            return $this->error('identity.errors.challenge_not_found', 404);
        }

        return $this->success('common.success', $status);
    }

    /**
     * 외부 IDV provider 의 redirect 콜백을 수신합니다. POST /api/identity/callback/{providerId}
     *
     * 외부 본인인증 SDK / OAuth-style provider 가 사용자 브라우저를 우리 서버로 다시 보내는 진입점.
     * body/query 에서 challenge_id 를 추출 → Service::handleProviderCallback 위임.
     *
     * 응답 정책 — 클라이언트가 stash 한 페이지(`return` query) 가 있으면 redirect, 없으면 JSON 응답:
     * - 성공 + return 있음: 302 → `{return}?verification_token=...`
     * - 성공 + return 없음: 200 JSON `{ verification_token }`
     * - 실패 + return 있음: 302 → `{return}?identity_error={failure_code}`
     * - 실패 + return 없음: 422 JSON
     *
     * @param  IdentityCallbackRequest  $request  검증된 요청
     * @param  string  $providerId  콜백을 보낸 provider 식별자
     * @return JsonResponse|RedirectResponse
     *
     * @since engine-v1.46.0
     */
    public function callback(IdentityCallbackRequest $request, string $providerId)
    {
        $validated = $request->validated();
        $challengeId = (string) $validated['challenge_id'];
        $returnUrl = (string) $request->query('return', '');

        $result = $this->service->handleProviderCallback(
            providerId: $providerId,
            challengeId: $challengeId,
            input: $validated,
            context: [
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 512),
            ],
        );

        if (! $result->success) {
            if ($returnUrl !== '' && $this->isSafeReturnUrl($returnUrl)) {
                $target = $this->absoluteReturnUrl($returnUrl);
                $sep = str_contains($target, '?') ? '&' : '?';

                return redirect()->away(
                    $target.$sep.'identity_error='.urlencode($result->failureCode ?? 'UNKNOWN'),
                );
            }

            return $this->error(
                $result->failureReason ?: 'identity.errors.generic',
                422,
                ['failure_code' => $result->failureCode],
            );
        }

        $token = $result->claims['verification_token'] ?? '';

        if ($returnUrl !== '' && $this->isSafeReturnUrl($returnUrl)) {
            $target = $this->absoluteReturnUrl($returnUrl);
            $sep = str_contains($target, '?') ? '&' : '?';

            return redirect()->away(
                $target.$sep.'verification_token='.urlencode((string) $token).'&challenge_id='.urlencode($challengeId),
            );
        }

        return $this->success('identity.messages.challenge_verified', [
            'challenge_id' => $result->challengeId,
            'provider_id' => $result->providerId,
            'verified_at' => $result->verifiedAt?->toIso8601String(),
            'verification_token' => $token,
        ]);
    }

    /**
     * `return` 쿼리 URL 이 사이트 주소와 같은 출처인지 검증 — open redirect 차단.
     *
     * 보안: 브라우저와 PHP 가 다르게 해석하는 입력(`/\evil.com`·제어 문자·`https://evil.com\@site/`)과
     * scheme·port·userinfo 가 다른 절대 URL 을 모두 거부한다. 판정은 `SiteUrl::isSameOrigin()` 이 소유한다.
     *
     * @param  string  $url  검사할 복귀 URL
     * @return bool 같은 출처면 true
     */
    private function isSafeReturnUrl(string $url): bool
    {
        return SiteUrl::isSameOrigin($url);
    }

    /**
     * 같은 출처로 판정된 복귀 주소를 사이트 주소 기준 절대 URL 로 만듭니다.
     *
     * 보안: 상대 경로를 그대로 Location 에 실으면 브라우저가 요청 Host 기준으로 해석한다 — 위조 Host 로
     * 시작한 콜백이면 토큰이 그 Host 로 간다.
     *
     * @param  string  $url  같은 출처로 판정된 복귀 주소(상대 경로 또는 사이트 주소 절대 URL)
     * @return string 사이트 주소 기준 절대 URL
     */
    private function absoluteReturnUrl(string $url): string
    {
        return str_starts_with($url, '/') ? SiteUrl::forPath($url) : $url;
    }

    /**
     * 등록된 프로바이더 목록을 반환합니다. GET /api/identity/providers
     *
     * @param  ProvidersIndexRequest  $request  검증된 요청
     * @return JsonResponse 프로바이더 공개 메타데이터 목록
     */
    public function providers(ProvidersIndexRequest $request): JsonResponse
    {
        $providers = array_values($this->manager->all());
        $data = array_map(
            fn ($p) => (new ProviderResource($p))->toArray($request),
            $providers,
        );

        return $this->success('common.success', $data);
    }

    /**
     * 등록된 purpose 목록을 반환합니다 (core.identity.purposes 필터 훅 통과).
     * GET /api/identity/purposes
     *
     * @param  PurposesIndexRequest  $request  검증된 요청
     * @return JsonResponse purpose 키 => 메타 매핑
     */
    public function purposes(PurposesIndexRequest $request): JsonResponse
    {
        // 코어 기본 4종 + 활성 모듈/플러그인 `getIdentityPurposes()` 선언 +
        // `core.identity.purposes` filter 훅 (서드파티 동적 확장) 을 모두 병합
        $purposes = $this->manager->getAllPurposes();

        $data = [];
        foreach ($purposes as $key => $meta) {
            $data[] = [
                'id' => $key,
                'label' => $this->resolvePurposeText($meta['label'] ?? $key),
                'description' => $this->resolvePurposeText($meta['description'] ?? ''),
                'default_provider' => $meta['default_provider'] ?? null,
                'allowed_channels' => $meta['allowed_channels'] ?? [],
                'source_type' => $meta['source_type'] ?? 'core',
                'source_identifier' => $meta['source_identifier'] ?? 'core',
            ];
        }

        return $this->success('common.success', $data);
    }

    /**
     * purpose meta 의 label/description 값을 현재 로케일 문자열로 정규화합니다.
     *
     * 입력 형태 3가지 지원:
     * - i18n 키 문자열 (예: `identity.purposes.signup.label`) → `__()` 로 풀이
     * - 다국어 배열 (`['ko' => ..., 'en' => ...]`) → 현재 로케일 우선, en 폴백
     * - 일반 문자열 → 그대로 반환
     *
     * @param  mixed  $value  i18n 키 문자열 · 다국어 배열 · 일반 문자열
     * @return string 현재 로케일로 풀이한 문구
     */
    private function resolvePurposeText($value): string
    {
        if (is_array($value)) {
            $locale = app()->getLocale();

            return (string) ($value[$locale] ?? $value['en'] ?? reset($value) ?: '');
        }

        if (! is_string($value) || $value === '') {
            return '';
        }

        // i18n 키처럼 보이는 경우 (`identity.*` 또는 `*.purposes.*` 등)
        if (str_contains($value, '.')) {
            $translated = __($value);

            return is_string($translated) ? $translated : $value;
        }

        return $value;
    }

    /**
     * 지정된 scope+target 조합에 대한 정책을 조회합니다 (프론트엔드 프리페치용).
     *
     * GET /api/identity/policies/resolve?scope=route&target=api.me.password.update
     * → 레이아웃 마운트 시 "이 페이지에서 IDV 가 요구될 수 있는 API" 를 미리 파악해
     * UI 힌트(버튼 배지 "확인 필요" 등) 를 표시하기 위한 엔드포인트.
     *
     * @param  ResolvePolicyRequest  $request  검증된 요청 (scope+target query)
     * @return JsonResponse 매칭 정책 요약 또는 null
     */
    public function resolvePolicy(ResolvePolicyRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $scope = (string) $validated['scope'];
        $target = (string) $validated['target'];

        $policy = $this->policyService->resolve($scope, $target);
        if (! $policy || ! $policy->enabled) {
            return $this->success('common.success', null);
        }

        // 민감 필드는 노출하지 않고 UI 힌트에 필요한 최소 필드만 반환
        return $this->success('common.success', [
            'policy_key' => $policy->key,
            'scope' => $policy->scope,
            'target' => $policy->target,
            'purpose' => $policy->purpose,
            // 저장값을 그대로 내보내지 않는다 — 제거된 플러그인의 provider ID 가 공개 응답에
            // 남지 않도록, 428 강제 경로와 같은 게터로 레지스트리 대조·폴백을 거친다 (A6a).
            'provider_id' => $this->policyService->resolveProviderId($policy),
            'grace_minutes' => $policy->grace_minutes,
            'applies_to' => $policy->applies_to,
            'fail_mode' => $policy->fail_mode,
        ]);
    }
}
