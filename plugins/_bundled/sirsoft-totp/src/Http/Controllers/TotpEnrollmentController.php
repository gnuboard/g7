<?php

namespace Plugins\Sirsoft\Totp\Http\Controllers;

use App\Exceptions\Auth\PasswordReconfirmThrottledException;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AuthBaseController;
use App\Services\PasswordReconfirmationService;
use App\Services\PluginSettingsService;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\Totp\Exceptions\AlreadyEnrolledException;
use Plugins\Sirsoft\Totp\Exceptions\EnrollmentConflictException;
use Plugins\Sirsoft\Totp\Exceptions\OwnershipThrottledException;
use Plugins\Sirsoft\Totp\Http\Requests\TotpCodeRequest;
use Plugins\Sirsoft\Totp\Http\Requests\TotpStartEnrollmentRequest;
use Plugins\Sirsoft\Totp\Services\TotpEnrollmentService;

/**
 * 본인의 인증 앱 자격증명을 등록·확정·해제하는 컨트롤러.
 *
 * - GET    /api/plugins/sirsoft-totp/enrollment          : 현재 등록 상태
 * - POST   /api/plugins/sirsoft-totp/enrollment          : 시크릿 발급 (1회 노출)
 * - POST   /api/plugins/sirsoft-totp/enrollment/confirm  : 첫 코드 확인 + 백업 코드 발급
 * - DELETE /api/plugins/sirsoft-totp/enrollment          : 해제
 * - POST   /api/plugins/sirsoft-totp/backup-codes/regenerate : 백업 코드 재발급
 *
 * 평문 시크릿·백업 코드는 발급 응답에만 실린다 — 조회로는 절대 다시 내려가지 않는다.
 *
 * @since 1.0.0
 */
class TotpEnrollmentController extends AuthBaseController
{
    /** 본 플러그인 식별자 */
    private const IDENTIFIER = 'sirsoft-totp';

    /**
     * TotpEnrollmentController 생성자.
     *
     * @param  TotpEnrollmentService  $enrollmentService  등록 서비스
     * @param  PasswordReconfirmationService  $passwordReconfirmation  코어 현재 비밀번호 재확인(회원 단위 시도 제한) 서비스
     */
    public function __construct(
        private readonly TotpEnrollmentService $enrollmentService,
        private readonly PasswordReconfirmationService $passwordReconfirmation,
    ) {
        parent::__construct();
    }

    /**
     * 본인의 등록 상태를 반환합니다.
     *
     * @return JsonResponse 등록·확정 여부와 남은 백업 코드 수
     */
    public function show(): JsonResponse
    {
        $user = $this->getCurrentUser();

        if ($user === null) {
            return $this->unauthorized();
        }

        $credential = $this->enrollmentService->findFor($user);

        return ResponseHelper::success('common.success', [
            'enrolled' => $credential !== null && $credential->isConfirmed(),
            'pending' => $credential !== null && ! $credential->isConfirmed(),
            'confirmed_at' => $credential?->confirmed_at?->toIso8601String(),
            'backup_codes_remaining' => count($credential?->backup_codes ?? []),
        ]);
    }

    /**
     * 새 시크릿을 발급합니다 (등록 시작).
     *
     * 현재 비밀번호를 다시 확인한다(KVE-2026-3194) — 틀리면 422, 회원 단위 시도 기준을 넘으면 429.
     * 시도 제한은 마이페이지 비밀번호 확인과 같은 키를 공유한다(코어 `PasswordReconfirmationService`).
     *
     * @param  TotpStartEnrollmentRequest  $request  현재 비밀번호를 담은 요청
     * @return JsonResponse 평문 시크릿과 otpauth URI (1회 노출)
     */
    public function store(TotpStartEnrollmentRequest $request): JsonResponse
    {
        $user = $this->getCurrentUser();

        if ($user === null) {
            return $this->unauthorized();
        }

        try {
            if (! $this->passwordReconfirmation->attempt($user, (string) $request->validated()['current_password'])) {
                return ResponseHelper::error('sirsoft-totp::messages.errors.password_incorrect', 422, [
                    'current_password' => [__('sirsoft-totp::messages.errors.password_incorrect')],
                ]);
            }
        } catch (PasswordReconfirmThrottledException $e) {
            // 남은 대기 초를 본문에도 싣는다 — 설정 화면이 그동안 [등록 시작] 버튼을 잠근다(헤더는 화면 동작이 읽지 못함)
            return ResponseHelper::error('auth.password_reconfirm_throttled', 429, ['retry_after' => $e->retryAfterSeconds], ['seconds' => $e->retryAfterSeconds])
                ->header('Retry-After', (string) $e->retryAfterSeconds);
        }

        try {
            $payload = $this->enrollmentService->start($user, $this->resolveIssuer());
        } catch (AlreadyEnrolledException) {
            // 이미 확정된 자격증명이 있다 — 덮어쓰지 않고 해제를 먼저 요구한다.
            return ResponseHelper::error('sirsoft-totp::messages.errors.already_enrolled', 409);
        }

        return ResponseHelper::success('sirsoft-totp::messages.enrollment.started', $payload);
    }

    /**
     * 첫 코드를 확인해 등록을 확정합니다.
     *
     * @param  TotpCodeRequest  $request  코드 요청
     * @return JsonResponse 평문 백업 코드 10개 (1회 노출)
     */
    public function confirm(TotpCodeRequest $request): JsonResponse
    {
        $user = $this->getCurrentUser();

        if ($user === null) {
            return $this->unauthorized();
        }

        try {
            $backupCodes = $this->enrollmentService->confirm($user, $request->validated()['code'], $this->requestContext());
        } catch (EnrollmentConflictException) {
            // 다른 화면이 먼저 확정했거나 그 사이 시크릿이 새로 발급됐다 — 이 화면의 백업 코드는 저장되지 않았다(1.0.3)
            return ResponseHelper::error('sirsoft-totp::messages.errors.confirm_conflict', 409);
        }

        if ($backupCodes === null) {
            return ResponseHelper::error('sirsoft-totp::messages.errors.invalid_code', 422);
        }

        return ResponseHelper::success('sirsoft-totp::messages.enrollment.confirmed', [
            'backup_codes' => $backupCodes,
        ]);
    }

    /**
     * 자격증명을 해제합니다.
     *
     * @param  TotpCodeRequest  $request  코드 요청
     * @return JsonResponse 해제 결과
     */
    public function destroy(TotpCodeRequest $request): JsonResponse
    {
        $user = $this->getCurrentUser();

        if ($user === null) {
            return $this->unauthorized();
        }

        try {
            $disabled = $this->enrollmentService->disable($user, $request->validated()['code'], $this->requestContext());
        } catch (OwnershipThrottledException $e) {
            return $this->throttledResponse($e);
        }

        if (! $disabled) {
            return ResponseHelper::error('sirsoft-totp::messages.errors.invalid_code', 422);
        }

        return ResponseHelper::success('sirsoft-totp::messages.enrollment.disabled');
    }

    /**
     * 백업 코드를 재발급합니다 (기존 코드 전부 무효).
     *
     * @param  TotpCodeRequest  $request  코드 요청
     * @return JsonResponse 평문 백업 코드 10개 (1회 노출)
     */
    public function regenerateBackupCodes(TotpCodeRequest $request): JsonResponse
    {
        $user = $this->getCurrentUser();

        if ($user === null) {
            return $this->unauthorized();
        }

        try {
            $backupCodes = $this->enrollmentService->regenerateBackupCodes($user, $request->validated()['code'], $this->requestContext());
        } catch (OwnershipThrottledException $e) {
            return $this->throttledResponse($e);
        }

        if ($backupCodes === null) {
            return ResponseHelper::error('sirsoft-totp::messages.errors.invalid_code', 422);
        }

        return ResponseHelper::success('sirsoft-totp::messages.enrollment.backup_codes_regenerated', [
            'backup_codes' => $backupCodes,
        ]);
    }

    /**
     * 알림·감사용 요청 문맥을 만듭니다.
     *
     * @return array{ip_address: string|null, user_agent: string|null} 요청 문맥
     */
    private function requestContext(): array
    {
        return [
            'ip_address' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 512),
        ];
    }

    /**
     * 코드 확인 시도 기준 초과 응답(429 + Retry-After)을 만듭니다.
     *
     * @param  OwnershipThrottledException  $e  시도 제한 예외
     * @return JsonResponse 429 응답
     */
    private function throttledResponse(OwnershipThrottledException $e): JsonResponse
    {
        return ResponseHelper::error('sirsoft-totp::messages.errors.too_many_attempts', 429, null, ['seconds' => $e->retryAfterSeconds])
            ->header('Retry-After', (string) $e->retryAfterSeconds);
    }

    /**
     * otpauth URI 에 실을 발급자 표기를 해석합니다.
     *
     * 플러그인 설정이 비어 있으면 사이트 이름으로 폴백한다 — 인증 앱 목록에서 어느
     * 사이트인지 구분되지 않으면 사용자는 여러 계정을 섞는다.
     *
     * @return string 발급자 표기
     */
    private function resolveIssuer(): string
    {
        $settings = app(PluginSettingsService::class)->get(self::IDENTIFIER);
        $issuer = is_array($settings) ? trim((string) ($settings['issuer'] ?? '')) : '';

        return $issuer !== '' ? $issuer : (string) config('app.name', 'G7');
    }
}
