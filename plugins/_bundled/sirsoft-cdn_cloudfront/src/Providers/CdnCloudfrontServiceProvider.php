<?php

namespace Plugins\Sirsoft\CdnCloudfront\Providers;

use App\Extension\BasePluginServiceProvider;
use App\Extension\PluginManager;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\CdnCloudfront\Console\Commands\RetryPurgesCommand;
use Plugins\Sirsoft\CdnCloudfront\Repositories\Contracts\PurgeQueueRepositoryInterface;
use Plugins\Sirsoft\CdnCloudfront\Repositories\PurgeQueueRepository;
use Plugins\Sirsoft\CdnCloudfront\Services\CloudFrontClientFactory;
use Plugins\Sirsoft\CdnCloudfront\Services\PurgeService;
use Plugins\Sirsoft\CdnCloudfront\Support\CloudFrontDisk;
use Plugins\Sirsoft\CdnCloudfront\Support\CloudFrontSettings;

/**
 * CloudFront CDN 플러그인 서비스 프로바이더
 *
 * 디스크 `cloudfront` 를 config 에 정의한다. 플러그인 서비스 프로바이더는 활성 여부와 무관하게 디렉터리만
 * 있으면 등록되므로 활성 플러그인일 때만 정의한다 — 꺼진 플러그인의 디스크가 남아 있으면 운영자가 고른
 * 공개 디스크가 기본값으로 돌아가지 않고 계속 CDN 주소를 내보낸다.
 *
 * 정의는 모든 프로바이더 부팅이 끝난 뒤(booted) 한다. 코어 S3 디스크 설정은 SettingsServiceProvider 가
 * 부팅 때 환경설정 값으로 채우므로, 그보다 먼저 복제하면 빈 버킷·자격을 베끼게 된다.
 *
 * 정의는 프로세스마다 부팅 때 한 번이다. 설정 저장·비활성화 뒤 상주 큐 워커가 옛 정의를 들고 있지 않게
 * SyncCloudFrontDiskListener 가 재시작 신호를 보낸다.
 */
class CdnCloudfrontServiceProvider extends BasePluginServiceProvider
{
    protected string $pluginIdentifier = CloudFrontSettings::IDENTIFIER;

    protected array $repositories = [
        PurgeQueueRepositoryInterface::class => PurgeQueueRepository::class,
    ];

    /**
     * 플러그인 캐시(CacheInterface)를 주입받는 서비스 — 마지막 무효화 성공 시각을 둔다.
     *
     * @var array<int, class-string>
     */
    protected array $cacheServices = [
        PurgeService::class,
    ];

    /**
     * 서비스 등록 — API 클라이언트 생성기는 하나만 둔다(시험이 가짜 처리기를 넣은 인스턴스로 바꾼다).
     *
     * @return void 반환값 없음
     */
    public function register(): void
    {
        parent::register();

        $this->app->singleton(CloudFrontClientFactory::class, fn () => new CloudFrontClientFactory);
    }

    /**
     * 부팅 — 명령 등록과 디스크 정의.
     *
     * @return void 반환값 없음
     */
    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([RetryPurgesCommand::class]);
        }

        $this->app->booted(function () {
            if (! $this->isActive()) {
                return;
            }

            $this->app->make(CloudFrontDisk::class)->register();
        });
    }

    /**
     * 이 플러그인이 활성 상태인지 (설치 전·플러그인 표 준비 전에는 false)
     *
     * @return bool 활성이면 true
     */
    private function isActive(): bool
    {
        try {
            return in_array(CloudFrontSettings::IDENTIFIER, PluginManager::getActivePluginIdentifiers(), true);
        } catch (\Throwable $e) {
            Log::warning('CloudFront 플러그인 활성 여부를 확인하지 못해 디스크를 정의하지 않았습니다', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
