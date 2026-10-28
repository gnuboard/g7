<?php

namespace Plugins\Sirsoft\Popup\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Plugins\Sirsoft\Popup\Services\PopupImageService;

/**
 * 플러그인 생애주기 — 데이터까지 삭제할 때 이미지 파일을 비운다.
 *
 * 코어 삭제는 `getDynamicTables()` 테이블만 지우고 플러그인 스토리지 파일은 남기므로, 데이터 삭제를 고른 경우에만
 * 이미지 폴더를 비운다(데이터를 남기는 삭제·비활성화에서는 건드리지 않는다).
 */
class PluginLifecycleListener implements HookListenerInterface
{
    /**
     * 구독할 훅과 메서드 매핑 반환
     *
     * @return array<string, array<string, mixed>> 훅 이름 => 옵션
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.plugins.before_uninstall' => ['method' => 'beforeUninstall', 'priority' => 10, 'sync' => true],
        ];
    }

    /**
     * PluginLifecycleListener 생성자
     *
     * @param  PopupImageService  $images  이미지 서비스
     */
    public function __construct(private PopupImageService $images) {}

    /**
     * 인터페이스 기본 핸들러 (개별 메서드에서 처리)
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 이 플러그인을 데이터까지 지울 때 이미지 폴더를 비웁니다.
     *
     * @param  string  $pluginName  삭제할 플러그인 식별자
     * @param  bool  $deleteData  데이터 삭제 여부
     * @return void 반환값 없음
     */
    public function beforeUninstall(string $pluginName, bool $deleteData = false): void
    {
        if ($pluginName === 'sirsoft-popup' && $deleteData) {
            $this->images->purgeAll();
        }
    }
}
