<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Contracts\Repositories\LayoutRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Enums\LayoutExtensionType;
use App\Enums\LayoutSourceType;
use App\Services\LayoutExtensionService;
use Illuminate\Support\Facades\Log;

class RestoreLayoutExtensionsAfterUpdateListener implements HookListenerInterface
{
    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_kginicis';

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.plugins.updated' => [
                'method' => 'restoreCurrentExtensionsAfterUpdate',
                'type' => 'action',
                'priority' => 20,
                'sync' => true,
            ],
        ];
    }

    /**
     * 기본 핸들러 (미사용).
     *
     * @param mixed ...$args
     */
    public function handle(...$args): void {}

    /**
     * @param string $identifier
     */
    public function restoreCurrentExtensionsAfterUpdate(string $identifier): void
    {
        if ($identifier !== self::PLUGIN_IDENTIFIER) {
            return;
        }

        $service = app(LayoutExtensionService::class);

        foreach ($this->currentOverlayExtensions() as $extensionData) {
            $targetLayout = $extensionData['target_layout'] ?? null;
            if (! is_string($targetLayout) || $targetLayout === '') {
                continue;
            }

            foreach ($this->templateIdsForLogicalLayout($targetLayout) as $templateId) {
                $result = $service->registerExtension(
                    $extensionData,
                    LayoutSourceType::Plugin,
                    self::PLUGIN_IDENTIFIER,
                    $templateId,
                    true
                );

                if ($result !== null) {
                    $service->invalidateExtensionCache($templateId, $targetLayout, LayoutExtensionType::Overlay);
                }
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function currentOverlayExtensions(): array
    {
        $paths = glob($this->extensionsDirectory().'/*.json') ?: [];
        $extensions = [];

        foreach ($paths as $path) {
            $extensionData = $this->readExtensionJson($path);
            if ($extensionData === null || ! isset($extensionData['target_layout'])) {
                continue;
            }

            $extensions[] = $extensionData;
        }

        return $extensions;
    }

    private function extensionsDirectory(): string
    {
        return dirname(__DIR__, 2).'/resources/extensions';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readExtensionJson(string $path): ?array
    {
        $content = file_get_contents($path);
        if ($content === false) {
            Log::warning('KG 이니시스 레이아웃 확장 파일을 읽을 수 없습니다.', [
                'path' => $path,
            ]);

            return null;
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($data)) {
            Log::warning('KG 이니시스 레이아웃 확장 JSON 파싱 실패', [
                'path' => $path,
                'error' => json_last_error_msg(),
            ]);

            return null;
        }

        return $data;
    }

    /**
     * @return array<int, int>
     */
    private function templateIdsForLogicalLayout(string $layoutName): array
    {
        $templateRepository = app(TemplateRepositoryInterface::class);
        $layoutRepository = app(LayoutRepositoryInterface::class);
        $templateIds = [];

        foreach ($templateRepository->getAll() as $template) {
            $layoutNames = $layoutRepository->getLayoutNamesByTemplateId((int) $template->id);

            foreach ($layoutNames as $storedName) {
                if (! is_string($storedName) || ! $this->matchesLogicalLayout($storedName, $layoutName)) {
                    continue;
                }

                $templateIds[(int) $template->id] = (int) $template->id;

                break;
            }
        }

        return array_values($templateIds);
    }

    private function matchesLogicalLayout(string $storedName, string $layoutName): bool
    {
        return $storedName === $layoutName || str_ends_with($storedName, '.'.$layoutName);
    }
}
