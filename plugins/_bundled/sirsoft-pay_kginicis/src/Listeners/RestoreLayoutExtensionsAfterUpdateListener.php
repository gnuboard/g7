<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Contracts\Repositories\LayoutRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Enums\LayoutExtensionType;
use App\Enums\LayoutSourceType;
use App\Extension\Helpers\LayoutFragmentSourceHelper;
use App\Services\LayoutExtensionService;

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
     * 기본 핸들러 — 이 리스너는 구독마다 전용 메서드를 쓰므로 사용하지 않습니다.
     *
     * @param  mixed  ...$args  훅 페이로드 (미사용)
     */
    public function handle(...$args): void {}

    /**
     * 플러그인 업데이트 직후 이 플러그인이 소유한 overlay 확장을 다시 등록합니다.
     *
     * @param  string  $identifier  업데이트된 확장 식별자 (자기 것이 아니면 아무것도 하지 않는다)
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
     * 현재 플러그인이 소유한 overlay 확장 정의를 읽습니다.
     *
     * 수집·짝 판별·읽기는 코어가 맡는다 ({@see LayoutFragmentSourceHelper::readDirectory()}) —
     * 여기서 `glob('*.json')` 하면 소스(`.g7.html`)이 통째로 보이지 않아, 소스만 고친 확장점은
     * 업데이트 복원에서 조용히 빠진다.
     *
     * @return array<int, array<string, mixed>>
     */
    private function currentOverlayExtensions(): array
    {
        return array_values(array_filter(
            LayoutFragmentSourceHelper::readDirectory(dirname(__DIR__, 2).'/resources/extensions'),
            static fn (array $extensionData): bool => isset($extensionData['target_layout'])
        ));
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
