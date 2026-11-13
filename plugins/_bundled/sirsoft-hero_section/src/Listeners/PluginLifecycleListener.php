<?php

namespace Plugins\Sirsoft\HeroSection\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Plugins\Sirsoft\HeroSection\Services\BannerImageService;

/**
 * 플러그인 생애주기 — 데이터까지 삭제하는 제거가 시작될 때 이미지가 흩어진 디스크 목록을 읽어 둔다.
 *
 * `core.plugins.before_uninstall` 은 제거 가드보다 먼저 발화하므로 여기서 지우면 제거가 막혀도 파일이 사라진다(#874).
 * 지우기는 가드·마이그레이션 롤백을 지난 `Plugin::uninstall()` 이 하고, 그때는 테이블이 없어 행의 disk 를 읽을 수 없으므로
 * 공개 자산 디스크를 켰다 끈 사이트의 다른 디스크까지 비우도록(#878) 목록만 미리 넘긴다.
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
     * @param  BannerImageService  $images  이미지 서비스
     */
    public function __construct(private BannerImageService $images) {}

    /**
     * 인터페이스 기본 핸들러 (개별 메서드에서 처리)
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 이 플러그인을 데이터까지 지울 때 행이 기록한 디스크 목록을 읽어 둡니다 — 파일은 지우지 않는다.
     *
     * @param  string  $pluginName  삭제할 플러그인 식별자
     * @param  bool  $deleteData  데이터 삭제 여부
     * @return void 반환값 없음
     */
    public function beforeUninstall(string $pluginName, bool $deleteData = false): void
    {
        if ($pluginName === 'sirsoft-hero_section' && $deleteData) {
            $this->images->rememberDisksForUninstall();
        }
    }
}
