<?php

namespace App\Broadcasting;

use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use Illuminate\Contracts\Broadcasting\Factory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;

/**
 * 브로드캐스트 채널 인가 콜백을 필요할 때 한 번만 등록합니다 (#860).
 *
 * 채널 인가 콜백은 `/api/broadcasting/auth`(`api.broadcasting.auth`) 처리에서만 쓰인다. 부팅마다
 * `Broadcast::channel()` 을 부르면 그때마다 드라이버(reverb 면 Pusher·HTTP 클라이언트)가 만들어지므로,
 * 인가 라우트가 `Broadcast::auth()` 직전에 `ensureRegistered()` 를 부른다. 라우트 처리 안에서 부르므로
 * 라우트 캐시 상태에서도 빠지지 않는다.
 *
 * 등록 대상: `routes/channels.php`(코어 채널) + 활성 모듈·플러그인의 `getChannels()`.
 * 등록 여부는 브로드캐스터 객체별로 기억한다 — 드라이버를 비우고 다시 만들면 새 드라이버에 다시 등록한다.
 */
class BroadcastChannelRegistrar
{
    /**
     * 채널을 등록한 브로드캐스터 집합.
     *
     * @var \WeakMap<object, true>
     */
    private \WeakMap $registered;

    /**
     * @param  Application  $app  앱 컨테이너
     */
    public function __construct(private readonly Application $app)
    {
        $this->registered = new \WeakMap;
    }

    /**
     * 기본 브로드캐스터에 코어·확장 채널을 아직 등록하지 않았으면 등록합니다.
     *
     * 웹소켓 사용 OFF(`broadcasting.default === 'null'`)면 아무것도 하지 않는다.
     *
     * @return void 반환값 없음
     */
    public function ensureRegistered(): void
    {
        if (config('broadcasting.default') === 'null') {
            return;
        }

        $broadcaster = $this->app->make(Factory::class)->connection();
        if (isset($this->registered[$broadcaster])) {
            return;
        }
        $this->registered[$broadcaster] = true;

        $channels = base_path('routes/channels.php');
        if (is_file($channels)) {
            require $channels;
        }

        foreach ($this->app->make(ModuleManager::class)->getActiveModules() as $module) {
            $this->registerExtensionChannels($broadcaster, $module, 'module');
        }

        foreach ($this->app->make(PluginManager::class)->getActivePlugins() as $plugin) {
            $this->registerExtensionChannels($broadcaster, $plugin, 'plugin');
        }
    }

    /**
     * 확장의 `getChannels()` 선언을 브로드캐스터에 등록합니다.
     *
     * 선언은 `채널명 => ['permission' => 권한 식별자|null]` 이고, 권한이 있으면 사용자가 그 권한을 가져야 통과한다.
     * 한 확장의 선언 오류가 다른 채널 인가를 막지 않도록 확장 단위로 격리한다.
     *
     * @param  object  $broadcaster  브로드캐스터
     * @param  object  $extension  모듈 또는 플러그인 인스턴스
     * @param  string  $type  확장 유형 (module|plugin, 로그용)
     * @return void 반환값 없음
     */
    private function registerExtensionChannels(object $broadcaster, object $extension, string $type): void
    {
        if (! method_exists($extension, 'getChannels')) {
            return;
        }

        try {
            foreach ($extension->getChannels() as $channelName => $config) {
                $permission = $config['permission'] ?? null;

                $broadcaster->channel($channelName, function ($user, ...$params) use ($permission) {
                    if ($permission) {
                        return $user->hasPermission($permission);
                    }

                    return true;
                });
            }
        } catch (\Throwable $e) {
            Log::warning('확장 브로드캐스트 채널 등록 실패', [
                'type' => $type,
                'identifier' => method_exists($extension, 'getIdentifier') ? $extension->getIdentifier() : get_class($extension),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
