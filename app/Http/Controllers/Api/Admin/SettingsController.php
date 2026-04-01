<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Settings\RegenerateAppKeyRequest;
use App\Http\Requests\Settings\SaveSettingsRequest;
use App\Http\Requests\Settings\TestDriverConnectionRequest;
use App\Http\Requests\Settings\TestMailRequest;
use App\Http\Requests\Settings\UpdateSettingRequest;
use App\Http\Resources\SettingsResource;
use App\Services\DriverConnectionTester;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * 관리자용 시스템 설정 컨트롤러
 *
 * 관리자가 시스템 설정을 관리할 수 있는 기능을 제공합니다.
 */
class SettingsController extends AdminBaseController
{
    public function __construct(
        private SettingsService $settingsService,
        private DriverConnectionTester $driverConnectionTester
    ) {
        parent::__construct();
    }

    /**
     * 모든 시스템 설정을 조회합니다.
     *
     * @return JsonResponse 시스템 설정 목록을 포함한 JSON 응답
     */
    public function index(): JsonResponse
    {
        try {
            $settings = $this->settingsService->getAllSettings();

            return $this->success('settings.fetch_success',
                (new SettingsResource($settings))->toArray(request())
            );
        } catch (\Exception $e) {
            return $this->error('settings.fetch_failed', 500, $e->getMessage());
        }
    }

    /**
     * 여러 시스템 설정을 일괄 저장합니다.
     *
     * 저장 성공 시 전체 settings를 응답에 포함하여
     * 프론트엔드에서 새로고침 없이 전역 상태를 업데이트할 수 있도록 합니다.
     *
     * @param  SaveSettingsRequest  $request  설정 저장 요청 데이터
     * @return JsonResponse 저장 결과 JSON 응답
     */
    public function store(SaveSettingsRequest $request): JsonResponse
    {
        try {
            $result = $this->settingsService->saveSettings($request->validated());

            if ($result) {
                // 저장 후 전체 설정 반환 (관리자 UI 상태 업데이트용)
                $allSettings = $this->settingsService->getAllSettings();

                return $this->success('settings.save_success', [
                    'settings' => $allSettings,
                ]);
            } else {
                return $this->error('settings.save_failed');
            }
        } catch (ValidationException $e) {
            return $this->error('settings.save_failed', 422, $e->errors());
        } catch (\Exception $e) {
            return $this->error('settings.save_error', 500, $e->getMessage());
        }
    }

    /**
     * 특정 키의 시스템 설정을 조회합니다.
     *
     * @param  string  $key  조회할 설정 키
     * @return JsonResponse 설정 값을 포함한 JSON 응답
     */
    public function show(string $key): JsonResponse
    {
        try {
            $value = $this->settingsService->getSetting($key);

            return $this->success('settings.fetch_success', [
                'key' => $key,
                'value' => $value,
            ]);
        } catch (\Exception $e) {
            return $this->error('settings.fetch_failed', 500, $e->getMessage());
        }
    }

    /**
     * 특정 키의 시스템 설정을 업데이트합니다.
     *
     * @param  string  $key  업데이트할 설정 키
     * @param  UpdateSettingRequest  $request  설정 업데이트 요청 데이터
     * @return JsonResponse 업데이트 결과 JSON 응답
     */
    public function update(string $key, UpdateSettingRequest $request): JsonResponse
    {
        try {
            $result = $this->settingsService->setSetting($key, $request->validated()['value']);

            if ($result) {
                return $this->success('settings.update_success');
            } else {
                return $this->error('settings.update_failed');
            }
        } catch (ValidationException $e) {
            return $this->error('settings.update_failed', 422, $e->errors());
        } catch (\Exception $e) {
            return $this->error('settings.update_error', 500, $e->getMessage());
        }
    }

    /**
     * 시스템 환경 정보를 조회합니다.
     *
     * @return JsonResponse 시스템 정보를 포함한 JSON 응답
     */
    public function systemInfo(): JsonResponse
    {
        try {
            $systemInfo = $this->settingsService->getSystemInfo();

            return $this->success('common.success', $systemInfo);
        } catch (\Exception $e) {
            return $this->error('common.error_occurred', 500, $e->getMessage());
        }
    }

    /**
     * 시스템 캐시를 정리합니다.
     *
     * @return JsonResponse 캐시 정리 결과 JSON 응답
     */
    public function clearCache(): JsonResponse
    {
        try {
            $result = $this->settingsService->clearCache();

            if ($result) {
                return $this->success('settings.cache_clear_success');
            } else {
                return $this->error('settings.cache_clear_failed');
            }
        } catch (\Exception $e) {
            return $this->error('settings.cache_clear_error', 500, $e->getMessage());
        }
    }

    /**
     * 시스템을 최적화합니다 (캐시 생성).
     *
     * @return JsonResponse 최적화 결과 JSON 응답
     */
    public function optimizeSystem(): JsonResponse
    {
        try {
            $result = $this->settingsService->optimizeSystem();

            if ($result) {
                return $this->success('settings.optimize_success');
            } else {
                return $this->error('settings.optimize_failed');
            }
        } catch (\Exception $e) {
            return $this->error('settings.optimize_error', 500, $e->getMessage());
        }
    }

    /**
     * 데이터베이스를 백업합니다.
     *
     * @return JsonResponse 백업 결과 JSON 응답
     */
    public function backupDatabase(): JsonResponse
    {
        try {
            $result = $this->settingsService->backupDatabase();

            if ($result) {
                return $this->success('settings.backup_success');
            } else {
                return $this->error('settings.backup_failed');
            }
        } catch (\Exception $e) {
            return $this->error('settings.backup_error', 500, $e->getMessage());
        }
    }

    /**
     * 현재 앱 키를 조회합니다 (마스킹된 형태).
     *
     * @return JsonResponse 마스킹된 앱 키 JSON 응답
     */
    public function getAppKey(): JsonResponse
    {
        try {
            $maskedKey = $this->settingsService->maskAppKey();

            return $this->success('common.success', [
                'app_key' => $maskedKey,
            ]);
        } catch (\Exception $e) {
            return $this->error('common.error_occurred', 500, $e->getMessage());
        }
    }

    /**
     * 앱 키를 재생성합니다.
     *
     * @param  RegenerateAppKeyRequest  $request  앱 키 재생성 요청 데이터
     * @return JsonResponse 재생성된 앱 키 JSON 응답
     */
    public function regenerateAppKey(RegenerateAppKeyRequest $request): JsonResponse
    {
        try {
            $result = $this->settingsService->regenerateAppKey($request->password);

            if (! $result['success']) {
                return $this->error($result['error'], 401);
            }

            return $this->success('settings.app_key_regenerated', [
                'app_key' => $result['app_key'],
            ]);
        } catch (\Exception $e) {
            return $this->error('settings.app_key_regenerate_failed', 500, $e->getMessage());
        }
    }

    /**
     * 설정을 백업합니다.
     *
     * @return JsonResponse 백업 결과 JSON 응답
     */
    public function backup(): JsonResponse
    {
        try {
            $backupPath = $this->settingsService->backupSettings();

            return $this->success('settings.backup_success', [
                'backup_path' => $backupPath,
            ]);
        } catch (\Exception $e) {
            return $this->error('settings.backup_failed', 500, $e->getMessage());
        }
    }

    /**
     * 백업에서 설정을 복원합니다.
     *
     * @return JsonResponse 복원 결과 JSON 응답
     */
    public function restore(Request $request): JsonResponse
    {
        try {
            $backupPath = $request->input('backup_path');

            if (empty($backupPath)) {
                return $this->error('settings.backup_path_required', 422);
            }

            $result = $this->settingsService->restoreSettings($backupPath);

            if ($result) {
                return $this->success('settings.restore_success');
            }

            return $this->error('settings.restore_failed');
        } catch (\Exception $e) {
            return $this->error('settings.restore_error', 500, $e->getMessage());
        }
    }

    /**
     * 테스트 메일을 발송합니다.
     *
     * @param  TestMailRequest  $request  테스트 메일 발송 요청 데이터
     * @return JsonResponse 발송 결과 JSON 응답
     */
    public function testMail(TestMailRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $toEmail = $validated['to_email'];
            $mailSettings = collect($validated)->except('to_email')->filter(fn ($v) => $v !== null)->toArray();

            $result = $this->settingsService->sendTestMail($toEmail, $mailSettings);

            if ($result['success']) {
                return $this->success($result['message'], [
                    'subject' => $result['subject'],
                    'body' => $result['body'],
                ]);
            }

            return $this->error($result['message'], 500, $result['error'] ?? null);
        } catch (\Exception $e) {
            return $this->error('settings.test_mail_error', 500, $e->getMessage());
        }
    }

    /**
     * 드라이버 연결을 테스트합니다.
     *
     * S3, Redis, Memcached, Websocket 등 외부 서비스 드라이버의
     * 연결 상태를 테스트합니다.
     *
     * @param  TestDriverConnectionRequest  $request  드라이버 테스트 요청 데이터
     * @return JsonResponse 테스트 결과 JSON 응답
     */
    public function testDriverConnection(TestDriverConnectionRequest $request): JsonResponse
    {
        try {
            $settings = $request->validated();
            $result = $this->driverConnectionTester->testAll($settings);

            if ($result['all_passed']) {
                return $this->success('settings.driver_test_success', $result);
            }

            // 일부 테스트 실패 시에도 결과 반환 (성공 상태지만 all_passed가 false)
            return $this->success('settings.driver_test_partial', $result);
        } catch (\Exception $e) {
            return $this->error('settings.driver_test_error', 500, $e->getMessage());
        }
    }
}
