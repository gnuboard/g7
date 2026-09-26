<?php

namespace Plugins\Sirsoft\Gdpr\Providers;

use App\Extension\BasePluginServiceProvider;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Plugins\Sirsoft\Gdpr\Console\Commands\PlaywrightSeedGdprGuestLogin;
use Plugins\Sirsoft\Gdpr\Console\Commands\SendAnnualNotices;
use Plugins\Sirsoft\Gdpr\Repositories\Contracts\GdprPolicyVersionRepositoryInterface;
use Plugins\Sirsoft\Gdpr\Repositories\Contracts\GdprPrivacyNoticeRepositoryInterface;
use Plugins\Sirsoft\Gdpr\Repositories\Contracts\GdprUserConsentHistoryRepositoryInterface;
use Plugins\Sirsoft\Gdpr\Repositories\Contracts\GdprUserConsentRepositoryInterface;
use Plugins\Sirsoft\Gdpr\Repositories\GdprPolicyVersionRepository;
use Plugins\Sirsoft\Gdpr\Repositories\GdprPrivacyNoticeRepository;
use Plugins\Sirsoft\Gdpr\Repositories\GdprUserConsentHistoryRepository;
use Plugins\Sirsoft\Gdpr\Repositories\GdprUserConsentRepository;

/**
 * GDPR 플러그인 서비스 프로바이더.
 *
 * Repository 바인딩은 BasePluginServiceProvider 표준에 위임합니다.
 * functional cookie 게이팅 미들웨어(CookieConsentMiddleware)는 코어 self-gate 방식으로
 * 이관되어 Plugin::getMiddleware() 가 선언하고 코어 ExtensionMiddlewareGate 가 실행합니다.
 * (SP Kernel 직접 조작 제거 — EDPB §16 광역 타게팅은 targets=['everything']/before_core 로 유지)
 */
class GdprServiceProvider extends BasePluginServiceProvider
{
    /**
     * 게스트 동의 식별 쿠키 이름 (`IssuesGuestSessionCookie` 가 서명해 발급)
     */
    public const GUEST_SESSION_COOKIE = 'gdpr_session';

    protected string $pluginIdentifier = 'sirsoft-gdpr';

    protected array $repositories = [
        GdprPrivacyNoticeRepositoryInterface::class => GdprPrivacyNoticeRepository::class,
        GdprUserConsentRepositoryInterface::class => GdprUserConsentRepository::class,
        GdprUserConsentHistoryRepositoryInterface::class => GdprUserConsentHistoryRepository::class,
        GdprPolicyVersionRepositoryInterface::class => GdprPolicyVersionRepository::class,
    ];

    /**
     * 플러그인 서비스를 부팅합니다 — 게스트 동의 쿠키를 암호화 대상에서 빼고 콘솔 명령을 등록합니다.
     *
     * @return void 없음
     */
    public function boot(): void
    {
        parent::boot();

        // 게스트 동의 식별 쿠키는 API 응답이 서명값 그대로(암호화 없이) 심는다. web 그룹의 쿠키
        // 암호화가 그 값을 복호화하다 버리면, 문서 요청에서는 동의한 게스트도 「식별 불가 = 미동의」로
        // 읽힌다 — 방문자 통계의 서버 폴백이 동의자의 진입 화면을 버리던 원인이다. 값의 위변조는
        // 이 플러그인이 서명으로 검증하므로 암호화 대상에서 뺀다(#682 S3 라운드 13).
        EncryptCookies::except(self::GUEST_SESSION_COOKIE);

        if ($this->app->runningInConsole()) {
            $this->commands([
                SendAnnualNotices::class,
                PlaywrightSeedGdprGuestLogin::class,
            ]);
        }
    }
}
