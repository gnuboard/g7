<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use App\Helpers\ResponseHelper;
use App\Services\PluginSettingsService;
use Illuminate\Http\JsonResponse;

class AdminSettingsStatusController
{
    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_kginicis';

    public function __construct(private readonly PluginSettingsService $settingsService) {}

    /**
     * 테스트 모드 활성 여부만 반환한다.
     *
     * @return JsonResponse
     */
    public function testMode(): JsonResponse
    {
        return ResponseHelper::success('messages.success', [
            'is_test_mode' => (bool) $this->settingsService->get(self::PLUGIN_IDENTIFIER, 'is_test_mode', true),
        ]);
    }
}
