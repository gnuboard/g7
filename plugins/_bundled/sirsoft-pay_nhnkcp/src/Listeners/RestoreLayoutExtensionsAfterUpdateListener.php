<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Contracts\Repositories\LayoutRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Enums\LayoutExtensionType;
use App\Enums\LayoutSourceType;
use App\Extension\ExtensionManager;
use App\Services\LayoutExtensionService;
use Illuminate\Support\Facades\Log;

class RestoreLayoutExtensionsAfterUpdateListener implements HookListenerInterface
{
    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_nhnkcp';

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
     * @param  mixed  ...$args
     */
    public function handle(...$args): void {}

    /**
     * @param  string  $identifier
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
        // `glob` 은 하드닝 프로필의 `disable_functions` 목록에 들어간다 — 막힌 호스팅에서
        // PHP 8 은 그 함수를 함수 테이블에서 제거하므로 이 호출은 `Error`(치명)가 되고
        // `?:` 는 그것을 잡지 못한다. 이 리스너는 플러그인 업데이트 트랜잭션 안에서
        // 동기 실행되므로(`sync => true`) 그 자리에서 업데이트가 통째로 죽는다.
        // 코어가 같은 이유로 쓰는 나열 수단을 그대로 공유한다 (13차 G3 라운드).
        $paths = ExtensionManager::listDirectoryFiles($this->extensionsDirectory(), '.json');
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
            Log::warning('NHN KCP layout extension file could not be read.', [
                'path' => $path,
            ]);

            return null;
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($data)) {
            Log::warning('NHN KCP layout extension JSON parse failed.', [
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
