<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Providers;

use App\Extension\BasePluginServiceProvider;
use Illuminate\Notifications\ChannelManager;
use Plugins\Sirsoft\MessageBizppurio\Repositories\BizppurioDispatchRepository;
use Plugins\Sirsoft\MessageBizppurio\Repositories\BizppurioNotificationBindingRepository;
use Plugins\Sirsoft\MessageBizppurio\Repositories\Contracts\BizppurioDispatchRepositoryInterface;
use Plugins\Sirsoft\MessageBizppurio\Repositories\Contracts\BizppurioNotificationBindingRepositoryInterface;
use Plugins\Sirsoft\MessageBizppurio\Services\AlimtalkChannelDriver;
use Plugins\Sirsoft\MessageBizppurio\Services\BizppurioTokenService;
use Plugins\Sirsoft\MessageBizppurio\Services\DispatchLinkContext;
use Plugins\Sirsoft\MessageBizppurio\Services\KakaoTemplateContentResolver;
use Plugins\Sirsoft\MessageBizppurio\Services\SmsChannelDriver;
use Plugins\Sirsoft\MessageBizppurio\Services\WebhookReportService;

/**
 * 비즈뿌리오 메시징 플러그인 서비스 프로바이더.
 *
 * BasePluginServiceProvider 가 Repository / Storage / Cache 자동 바인딩과
 * 다국어 로드를 처리한다. 아래 배열에 등록만 하면 contextual binding 이 적용된다.
 */
class MessageBizppurioServiceProvider extends BasePluginServiceProvider
{
    /** 플러그인 식별자 (manifest 와 일치) */
    protected string $pluginIdentifier = 'sirsoft-message_bizppurio';

    /**
     * Repository 인터페이스 ↔ 구현체 매핑.
     *
     * Phase 4 에서 발송 이력·이벤트 연동 Repository 를 등록한다.
     *
     * @var array<class-string, class-string>
     */
    protected array $repositories = [
        BizppurioDispatchRepositoryInterface::class => BizppurioDispatchRepository::class,
        BizppurioNotificationBindingRepositoryInterface::class => BizppurioNotificationBindingRepository::class,
    ];

    /**
     * CacheInterface 가 필요한 서비스 (contextual binding).
     *
     * - BizppurioTokenService(Phase 2): 발송 토큰 캐시
     * - WebhookReportService(Phase 4): 잔액부족 알림 쿨다운(D3 중복 방지)
     *
     * @var array<int, class-string>
     */
    protected array $cacheServices = [
        BizppurioTokenService::class,
        WebhookReportService::class,
        KakaoTemplateContentResolver::class,
    ];

    /**
     * 서비스 컨테이너 바인딩을 등록합니다.
     *
     * DispatchLinkContext 는 한 발송 사이클(HTTP 요청/큐 잡) 안에서 refkey↔코어 로그 연결을
     * 잇기 위해 상태를 공유해야 하므로 scoped(요청 단위 싱글턴)로 바인딩한다. 채널 드라이버와
     * LinkNotificationLogListener 가 같은 인스턴스를 주입받아 refkey 를 주고받는다(A-2).
     */
    public function register(): void
    {
        parent::register();

        $this->app->scoped(DispatchLinkContext::class);
    }

    /**
     * 플러그인 루트 lang 디렉토리를 로드합니다.
     *
     * 백엔드 다국어는 lang/{ko,en}/*.php 에 두고 `$this->translationNamespace()`
     * (= 플러그인 식별자) 네임스페이스로 로드한다.
     */
    protected function loadExtensionTranslations(): void
    {
        $langPath = dirname($this->getProviderPath(), 2).'/lang';

        if (is_dir($langPath)) {
            $this->loadTranslationsFrom($langPath, $this->translationNamespace());
        }
    }

    /**
     * 부팅 — 코어 알림 ChannelManager 에 sms 채널 드라이버를 등록합니다.
     *
     * 코어 GenericNotification::via() 가 반환하는 'sms' 채널 문자열은 코어에 드라이버가
     * 없어 그대로 두면 발송 시 "Driver [sms] not supported" 예외가 난다. 코어를 수정하지
     * 않고 ChannelManager::extend() 로 드라이버를 런타임 등록해, 'sms' 채널 발송이
     * SmsChannelDriver::send() 로 위임되게 한다. 플러그인 비활성/삭제 시 이 등록도
     * 사라져 코어가 안전하게 원복된다.
     *
     * 알림톡('alimtalk')도 동일하게 AlimtalkChannelDriver 로 위임한다(Phase 6). 관리자가
     * 알림톡 탭에서 연결한 승인 템플릿(binding)이 있을 때만 실제 발송되고, 미연결 알림은
     * 드라이버가 조용히 skip 한다.
     */
    public function boot(): void
    {
        parent::boot();

        // ChannelManager 는 지연 싱글턴(첫 알림 발송 시 해석)이므로 resolving 콜백으로 등록한다.
        // 다만 다른 코드가 이미 해석해 둔 경우 resolving 이 발화하지 않으므로, 이미 해석돼
        // 있으면 즉시 등록하여 어느 순서에서도 누락되지 않게 한다.
        $this->app->resolving(
            ChannelManager::class,
            fn (ChannelManager $manager) => $this->registerChannelDrivers($manager),
        );

        if ($this->app->resolved(ChannelManager::class)) {
            $this->registerChannelDrivers($this->app->make(ChannelManager::class));
        }
    }

    /**
     * 코어 알림 ChannelManager 에 이 플러그인의 채널 드라이버를 등록합니다.
     *
     * - sms: SmsChannelDriver 로 실제 발송 위임(Phase 3).
     * - alimtalk: AlimtalkChannelDriver 로 실제 발송 위임(Phase 6). 연결된 승인 템플릿
     *   (binding)이 있을 때만 발송하고 미연결 알림은 드라이버가 skip 한다.
     *
     * @param  ChannelManager  $manager  코어 알림 채널 매니저
     */
    private function registerChannelDrivers(ChannelManager $manager): void
    {
        $manager->extend('sms', fn ($app) => $app->make(SmsChannelDriver::class));
        $manager->extend('alimtalk', fn ($app) => $app->make(AlimtalkChannelDriver::class));
    }
}
