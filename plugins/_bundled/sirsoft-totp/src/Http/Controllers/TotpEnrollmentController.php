<?php

namespace Plugins\Sirsoft\Totp\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AuthBaseController;
use App\Services\PluginSettingsService;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\Totp\Exceptions\AlreadyEnrolledException;
use Plugins\Sirsoft\Totp\Http\Requests\TotpCodeRequest;
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
     */
    public function __construct(
        private readonly TotpEnrollmentService $enrollmentService,
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
     * @return JsonResponse 평문 시크릿과 otpauth URI (1회 노출)
     */
    public function store(): JsonResponse
    {
        $user = $this->getCurrentUser();

        if ($user === null) {
            return $this->unauthorized();
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

        $backupCodes = $this->enrollmentService->confirm($user, $request->validated()['code']);

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

        if (! $this->enrollmentService->disable($user, $request->validated()['code'])) {
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

        $backupCodes = $this->enrollmentService->regenerateBackupCodes($user, $request->validated()['code']);

        if ($backupCodes === null) {
            return ResponseHelper::error('sirsoft-totp::messages.errors.invalid_code', 422);
        }

        return ResponseHelper::success('sirsoft-totp::messages.enrollment.backup_codes_regenerated', [
            'backup_codes' => $backupCodes,
        ]);
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
