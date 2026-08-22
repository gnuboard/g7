<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Tosspayments\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Contracts\Repositories\LayoutRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Enums\LayoutExtensionType;
use App\Enums\LayoutSourceType;
use App\Services\LayoutExtensionService;
use Illuminate\Support\Facades\Log;

/**
 * 플러그인 업데이트 후 레이아웃 확장(오버레이) 복원 리스너
 *
 * 업데이트 과정에서 오버레이 주입이 소실되면 주문상세의 영수증 링크·가상계좌 정보가
 * 조용히 사라진다. 업데이트 완료 훅에서 resources/extensions/*.json 을 다시 등록한다.
 */
class RestoreLayoutExtensionsAfterUpdateListener implements HookListenerInterface
{
    private const PLUGIN_IDENTIFIER = 'sirsoft-tosspayments';

    /**
     * 구독할 훅 매핑 반환
     *
     * @return array<string, array<string, mixed>> 훅 구독 설정
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.plugins.updated' => [
                'method' => 'restoreCurrentExtensionsAfterUpdate',
                'type' => 'action',
                'priority' => 20,
                // 인프라 부수효과 — 업데이트 트랜잭션 안에서 동기 실행되어야 한다.
                'sync' => true,
            ],
        ];
    }

    /**
     * 기본 핸들러 (미사용 — 개별 메서드에서 처리)
     *
     * @param  mixed  ...$args  훅 인수
     */
    public function handle(...$args): void {}

    /**
     * 업데이트된 플러그인이 자신이면 오버레이 확장을 재등록합니다.
     *
     * @param  string  $identifier  업데이트된 플러그인 식별자
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
     * resources/extensions 의 오버레이 확장 정의를 모두 읽습니다.
     *
     * @return array<int, array<string, mixed>> 확장 정의 목록
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

    /**
     * 확장 정의 디렉토리 경로를 반환합니다.
     */
    private function extensionsDirectory(): string
    {
        return dirname(__DIR__, 2).'/resources/extensions';
    }

    /**
     * 확장 JSON 을 읽어 배열로 반환합니다.
     *
     * @param  string  $path  파일 경로
     * @return array<string, mixed>|null 파싱 결과 (실패 시 null)
     */
    private function readExtensionJson(string $path): ?array
    {
        $content = file_get_contents($path);
        if ($content === false) {
            Log::warning('토스페이먼츠 레이아웃 확장 파일을 읽을 수 없습니다.', [
                'path' => $path,
            ]);

            return null;
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($data)) {
            Log::warning('토스페이먼츠 레이아웃 확장 JSON 파싱 실패', [
                'path' => $path,
                'error' => json_last_error_msg(),
            ]);

            return null;
        }

        return $data;
    }

    /**
     * 논리 레이아웃명을 가진 템플릿 ID 목록을 반환합니다.
     *
     * @param  string  $layoutName  논리 레이아웃명
     * @return array<int, int> 템플릿 ID 목록
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

    /**
     * 저장된 레이아웃명이 논리 레이아웃명과 일치하는지 확인합니다.
     *
     * @param  string  $storedName  저장된 레이아웃명
     * @param  string  $layoutName  논리 레이아웃명
     * @return bool 일치 여부
     */
    private function matchesLogicalLayout(string $storedName, string $layoutName): bool
    {
        return $storedName === $layoutName || str_ends_with($storedName, '.'.$layoutName);
    }
}
