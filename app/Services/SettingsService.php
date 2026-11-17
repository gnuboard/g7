<?php

namespace App\Services;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\AttachmentRepositoryInterface;
use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Exceptions\IdentityVerificationRequiredException;
use App\Extension\ExtensionManager;
use App\Extension\HookManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\Traits\ClearsTemplateCaches;
use App\Helpers\TimezoneHelper;
use App\Http\Resources\AttachmentResource;
use App\Jobs\PurgeRetiredThumbnailDiskJob;
use App\Jobs\PurgeThumbnailsOnReenableJob;
use App\Models\User;
use App\Rules\EmbedCustomProviderRule;
use App\Seo\Contracts\SeoCacheManagerInterface;
use App\Services\Auth\ReauthenticationService;
use App\Services\Queue\QueueWorkerRegistry;
use App\Support\ConfigCacheHelper;
use App\Support\Content\ContentHtmlPolicy;
use App\Support\Content\EmbedProviderRegistry;
use App\Support\EnvPriority;
use App\Support\ExecutionEnvironment;
use App\Support\ExtensionSettingsMirror;
use App\Support\Favicon\FaviconPolicy;
use App\Support\OpcacheStatus;
use App\Support\PhpExtensionProbe;
use App\Support\PhpExtensionRequirements;
use App\Support\PrivateAttachmentDisk;
use App\Support\ProcessOutputEncoding;
use App\Support\RouteCacheHelper;
use App\Support\RuntimeRole;
use App\Support\SearchScopes;
use App\Support\Settings\ComputedSettingFields;
use App\Support\SettingsVersion;
use App\Support\ThumbnailDiskOptions;
use App\Support\TrustedProxyDiagnostic;
use App\Support\TrustedProxySetting;
use App\Thumbnails\ThumbnailConfig;
use App\Thumbnails\ThumbnailPresetRegistry;
use App\Thumbnails\ThumbnailPresetSettings;
use App\Thumbnails\ThumbnailStore;
use App\Thumbnails\ThumbnailUrl;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * 시스템 설정 서비스
 *
 * JSON 파일 기반 설정 관리를 담당합니다.
 */
class SettingsService
{
    // 자산 URL 방식 변경 시 확장 캐시 버전 bump 용. 주입된 `$this->cache` 로 직접 put 하지
    // 않는다 — 트레이트가 고정 `CoreCacheDriver` + 메모이즈 스토어를 쓰는 이유가 그 안에 있다.
    use ClearsTemplateCaches;

    /**
     * 설정 서비스를 생성합니다.
     *
     * @param  ConfigRepositoryInterface  $configRepository  설정 카테고리 저장소
     * @param  AttachmentRepositoryInterface  $attachmentRepository  설정 첨부 저장소
     * @param  CacheInterface  $cache  설정 캐시
     * @param  AttachmentService  $attachmentService  첨부 파기·정리 서비스
     */
    public function __construct(
        private ConfigRepositoryInterface $configRepository,
        private AttachmentRepositoryInterface $attachmentRepository,
        private CacheInterface $cache,
        private AttachmentService $attachmentService
    ) {}

    /**
     * 시스템 설정 캐시를 무효화합니다.
     *
     * 설정 저장 시 4곳에서 중복되던 로직을 단일 메서드로 통합했습니다.
     * config 캐시는 clear 후 즉시 재생성한다(ConfigCacheHelper). config:clear 만
     * 하면 config:cache 가 비워진 채 재생성되지 않아 이후 모든 요청이 config 파일을
     * 재파싱하는 성능 손실이 발생하기 때문이다.
     *
     * 저장소에 직접 쓰는 경로(`settings:install`)도 쓰기를 마친 뒤 이 메서드를 부른다 — 부르지 않으면
     * 캐시·미러·설정 버전이 옛 값에 머문다.
     *
     * @return void 반환값 없음
     */
    public function invalidateSettingsCache(): void
    {
        $this->cache->forget('settings.system');
        ConfigCacheHelper::rebuild();

        // 같은 프로세스의 in-memory 미러도 즉시 다시 채운다 (공개이슈 #109).
        // 이 호출이 없으면 상주 프로세스(큐 워커·schedule:work·Reverb)는 저장 후에도
        // 부팅 시점의 옛 값을 영원히 읽는다 — FPM 에서만 드러나지 않는 결함이다.
        app(ExtensionSettingsMirror::class)->refreshCore();

        // 다른 상주 프로세스(큐 워커)가 바뀐 것을 알 수 있도록 설정 버전을 올린다 — 워커는 작업 시작 전에
        // 비교해 다시 읽는다(#724).
        SettingsVersion::bump();
    }

    /**
     * 큐 워커에 정상 종료 후 재시작 신호를 보냅니다.
     *
     * drivers 카테고리(queue/broadcasting/cache 등)는 long-running worker 에 영향을 준다.
     * SettingsServiceProvider 는 worker boot 시점에 한 번만 config 를 적용하므로, 재시작
     * 신호가 없으면 워커가 부팅 시점의 옛 드라이버로 계속 동작한다.
     *
     * 신호 전송 실패가 설정 저장을 되돌리지는 않는다 (경고 로깅 후 계속).
     *
     * @return void 반환값 없음
     */
    private function restartQueueWorkers(): void
    {
        // 검수 서버의 재시작 신호는 공유 캐시를 거쳐 운영 서버 워커까지 재시작시킨다
        if (RuntimeRole::isStage()) {
            return;
        }

        try {
            Artisan::call('queue:restart');
        } catch (\Throwable $e) {
            Log::warning('queue:restart 실행 실패', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 자산 URL 방식 변경에 따라 SEO 프리렌더 캐시를 비웁니다.
     *
     * SEO 캐시에는 생성 시점의 자산 URL 이 문자열로 구워져 있어, 모드가 바뀌면
     * 그 URL 들이 전부 어긋난다. 사람 방문자는 브라우저 자가 복구가 살리지만
     * 검색엔진 봇은 JavaScript 를 실행하지 않으므로 캐시를 비워 재생성시켜야 한다.
     *
     * 캐시 삭제 실패가 설정 저장 자체를 되돌리지는 않는다 — 설정은 이미 저장됐고,
     * 캐시는 TTL 만료나 `seo:clear` 로도 회복 가능한 부수 상태다.
     *
     * @return void 반환값 없음
     */
    private function clearSeoCacheForAssetUrlMode(): void
    {
        try {
            app(SeoCacheManagerInterface::class)->clearAll();
        } catch (\Throwable $e) {
            Log::warning('자산 URL 방식 변경 후 SEO 캐시 삭제 실패 — seo:clear 로 수동 삭제 필요', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 모든 시스템 설정을 조회합니다.
     *
     * nested 구조로 반환합니다:
     * { general: { site_name: '...' }, mail: { ... }, ... }
     *
     * 프론트엔드 스키마의 frontend_key 매핑을 적용하여
     * JSON 레이아웃에서 사용하는 필드명으로 변환합니다.
     *
     * 스키마의 frontend_name/merge_into 설정에 따라 카테고리를 자동 병합합니다.
     * (예: cache, debug → advanced)
     *
     * 공개 설정의 화면용 계산 값(`computed: true` 필드)도 함께 싣습니다 — appendComputedFields() 참조.
     *
     * @return array 시스템 설정 배열
     */
    public function getAllSettings(): array
    {
        $settings = [];
        $schema = $this->configRepository->getFrontendSchema();

        // 모든 카테고리 설정 로드 (nested 구조)
        foreach ($this->configRepository->all() as $category => $categorySettings) {
            $categorySchema = $schema[$category] ?? null;
            $fields = $categorySchema['fields'] ?? [];

            // 스키마의 frontend_name 또는 merge_into에 따라 타겟 카테고리 결정
            $targetCategory = $categorySchema['frontend_name'] ?? $categorySchema['merge_into'] ?? $category;

            // 타겟 카테고리 초기화
            if (! isset($settings[$targetCategory])) {
                $settings[$targetCategory] = [];
            }

            // 원본 카테고리도 유지 (debug, cache 등 개별 접근용)
            if ($targetCategory !== $category && ! isset($settings[$category])) {
                $settings[$category] = [];
            }

            foreach ($categorySettings as $key => $value) {
                // frontend_key가 정의되어 있으면 해당 키로 변환
                $outputKey = $fields[$key]['frontend_key'] ?? $key;

                // 타겟 카테고리에 병합
                $settings[$targetCategory][$outputKey] = $value;

                // 원본 카테고리에도 저장 (개별 접근용)
                if ($targetCategory !== $category) {
                    $settings[$category][$outputKey] = $value;
                }
            }
        }

        // 첨부파일 정보 추가 (general 카테고리에)
        $settings['general']['site_logo'] = $this->getSiteLogoAttachment();

        // 사이트 파비콘 첨부 정보 (#434 — site_logo 와 동형, 저장값이 없던 옛 설치는 빈 배열)
        $settings['general']['site_favicon'] = $this->getAttachmentListSetting('general.site_favicon');

        // 사이트 기본 OG 이미지 첨부 정보 (seo 카테고리에 — site_logo 와 동형)
        $settings['seo']['og_image_default'] = $this->getAttachmentListSetting('seo.og_image_default');

        // 화면용 계산 값(frontend_schema 의 computed 필드)을 공개 설정과 같은 계산으로 싣는다(#887).
        // 저장 응답도 이 경로로 다시 조회하므로, 화면이 저장 응답으로 전역 설정을 병합하면 같은 탭에서도 저장 뒤 계산 값을 쓴다.
        $settings = $this->appendComputedFields($settings, $schema);

        // GeoIP 최근 갱신 — 저장값은 ISO8601 그대로, 화면 표시용 문자열은 보는 사람 시간대.
        // 저장 경로(saveAdvancedSettings)는 스키마에 없는 이 키를 버린다.
        $settings['advanced']['geoip_last_updated_at_formatted'] = $this->formatIsoForViewer($settings['advanced']['geoip_last_updated_at'] ?? null);

        return $this->overlayEnvLockedValues($this->seedTrustedProxiesFromRuntime($settings));
    }

    /**
     * 신뢰 프록시 저장값이 비어 있으면 **현재 적용 중인 값**으로 채웁니다.
     *
     * 저장값 null 은 "화면에서 한 번도 정한 적 없음" 이고, 그 설치의 실제 권위는
     * `.env` 의 `TRUSTED_PROXIES` 입니다. 그 상태에서 화면이 「신뢰하지 않음」을 보여주면,
     * 운영자가 고급 탭의 **무관한** 항목을 저장하는 순간 폼이 그 빈 값을 함께 제출해
     * 프록시 설정이 사라집니다 — 오류도 로그도 없이 방문자 IP 만 전부 프록시 주소가 됩니다.
     *
     * 그래서 화면에는 적용 중인 값을 그대로 보여주고, 그대로 저장되면 값이 같아 동작도
     * 바뀌지 않습니다. `.env` 로 잠긴 설치는 이 뒤에 도는 잠금 표시값 덮기가 다시 맡습니다.
     *
     * @param  array<string, mixed>  $settings  frontend 키 변환이 끝난 설정 배열
     * @return array<string, mixed> 유효값이 채워진 설정 배열
     */
    private function seedTrustedProxiesFromRuntime(array $settings): array
    {
        foreach ($this->resolveFrontendKeyTargets('debug', 'trusted_proxies') as [$targetCategory, $outputKey]) {
            if (! isset($settings[$targetCategory]) || ! is_array($settings[$targetCategory])) {
                continue;
            }

            $stored = $settings[$targetCategory][$outputKey] ?? null;

            $settings[$targetCategory][$outputKey] = $stored === null
                ? TrustedProxySetting::toList(TrustedProxyDiagnostic::configuredProxies())
                : TrustedProxySetting::toList($stored);
        }

        return $settings;
    }

    /**
     * 화면용 계산 값을 관리자 설정 묶음에 덧붙입니다 (#887).
     *
     * 대상은 `frontend_schema` 의 `computed: true` 필드 전부이고, 계산은 공개 설정(formatCategorySettings)과 같은
     * computeFieldValue() 를 씁니다 — 계산 필드가 늘어도 스키마와 그 변환 한 곳만 고치면 관리자 응답도 따라옵니다.
     * 범주 공개 여부(`expose`)는 보지 않습니다. 관리자 응답은 원본을 이미 담고 있고 계산 값은 그 원본에서 나온 값입니다.
     * `sensitive` 계산 필드는 공개 설정과 같이 뺍니다.
     *
     * 화면은 저장 응답(`data.settings`)으로 전역 설정을 병합하므로, 이 값이 없으면 저장 뒤 같은 탭에서 페이지 로드 때 받은
     * 옛 계산 값이 남습니다(예: 동영상 업로드 허용을 켜고 저장해도 `content.render_policy` 가 그대로라 본문 동영상이 지워짐).
     * 화면이 이 값을 저장 본문에 되돌려 보내도 저장하지 않습니다 — rejectComputedForSave() 참조.
     *
     * @param  array<string, mixed>  $settings  frontend 키 변환이 끝난 설정 배열
     * @param  array<string, mixed>  $schema  프론트엔드 스키마
     * @return array<string, mixed> 계산 값이 더해진 설정 배열
     */
    private function appendComputedFields(array $settings, array $schema): array
    {
        foreach ($schema as $category => $categorySchema) {
            if (str_starts_with((string) $category, '_') || ! is_array($categorySchema)) {
                continue;
            }

            $fields = $this->computedFieldSchemas($categorySchema);

            if ($fields === []) {
                continue;
            }

            // 공개 설정과 같은 입력 — 저장소의 범주 값(기본값 병합). 저장 직후에는 saveCategory() 가 비운 캐시 뒤의 새 값이다.
            $source = $this->getSettings((string) $category);

            foreach ($fields as $fieldName => $fieldSchema) {
                if ($fieldSchema['sensitive'] ?? false) {
                    continue;
                }

                $value = $this->computeFieldValue($source, $fieldSchema);

                foreach ($this->resolveFrontendKeyTargets((string) $category, (string) $fieldName) as [$targetCategory, $outputKey]) {
                    if (! isset($settings[$targetCategory]) || ! is_array($settings[$targetCategory])) {
                        $settings[$targetCategory] = [];
                    }

                    $settings[$targetCategory][$outputKey] = $value;
                }
            }
        }

        return $settings;
    }

    /**
     * 범주 스키마에서 계산 필드(`computed: true`)만 고릅니다 — 판정은 터미널 설정 명령과 같은 `ComputedSettingFields` 한 곳.
     *
     * @param  array<string, mixed>  $categorySchema  범주 스키마
     * @return array<string, array<string, mixed>> 필드명 => 필드 스키마
     */
    private function computedFieldSchemas(array $categorySchema): array
    {
        return ComputedSettingFields::schemas($categorySchema);
    }

    /**
     * 저장 본문에서 계산 필드를 뺍니다 (#887).
     *
     * 관리자 응답이 계산 값을 함께 싣고 화면은 응답으로 만든 탭 값 전체를 저장 본문에 되돌려 보내므로, 걸러내지 않으면
     * 계산 값이 원본 설정처럼 저장소에 굳습니다. 그 탭에 합쳐지는 범주(`frontend_name`·`merge_into`)의 계산 필드도 뺍니다.
     *
     * @param  string  $tab  저장 탭
     * @param  array<string, mixed>  $settings  원본 키로 역변환한 탭 값
     * @return array<string, mixed> 계산 필드를 뺀 탭 값
     */
    private function rejectComputedForSave(string $tab, array $settings): array
    {
        foreach ($this->configRepository->getFrontendSchema() as $category => $categorySchema) {
            if (str_starts_with((string) $category, '_') || ! is_array($categorySchema)) {
                continue;
            }

            $targetCategory = $categorySchema['frontend_name'] ?? $categorySchema['merge_into'] ?? $category;

            if ($category !== $tab && $targetCategory !== $tab) {
                continue;
            }

            foreach ($this->computedFieldSchemas($categorySchema) as $fieldName => $fieldSchema) {
                unset($settings[$fieldName], $settings[$fieldSchema['frontend_key'] ?? $fieldName]);
            }
        }

        return $settings;
    }

    /**
     * 저장된 ISO8601 시각을 보는 사람 시간대 표시 문자열로 바꿉니다.
     *
     * @param  mixed  $iso  저장된 ISO8601 시각
     * @return string|null 표시용 일시 (값이 없거나 해석할 수 없으면 null)
     */
    private function formatIsoForViewer(mixed $iso): ?string
    {
        if (! is_string($iso) || $iso === '') {
            return null;
        }

        try {
            return TimezoneHelper::toUserDateTimeString(Carbon::parse($iso));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * `.env` 로 잠긴 키의 표시값을 유효값(런타임 config)으로 덮어씁니다.
     *
     * 잠긴 필드에 사문화된 저장값이 남아 있으면 운영자는 적용되지 않는 값을 읽게 됩니다.
     * 화면이 보여줄 진실은 실제로 적용 중인 값이므로, 그 값으로 대체합니다.
     *
     * 민감 키는 제외합니다 — `.env` 의 비밀값을 관리자 화면 응답에 실어 보내지 않기
     * 위해서입니다(잠금 표시만 하고 값은 저장값 그대로 둡니다).
     *
     * 스위치가 꺼져 있으면 아무 것도 하지 않습니다.
     *
     * @param  array<string, mixed>  $settings  frontend 키 변환이 끝난 설정 배열
     * @return array<string, mixed> 유효값이 덮인 설정 배열
     */
    private function overlayEnvLockedValues(array $settings): array
    {
        if (! EnvPriority::enabled()) {
            return $settings;
        }

        foreach (array_keys(EnvPriority::lockedKeys()) as $storageKey) {
            if (EnvPriority::isSensitive($storageKey)) {
                continue;
            }

            [$category, $key] = explode('.', $storageKey, 2);
            $value = EnvPriority::effectiveValue($storageKey);

            foreach ($this->resolveFrontendKeyTargets($category, $key) as [$targetCategory, $outputKey]) {
                if (isset($settings[$targetCategory]) && is_array($settings[$targetCategory])) {
                    $settings[$targetCategory][$outputKey] = $value;
                }
            }
        }

        return $settings;
    }

    /**
     * 환경설정 > 드라이버 「비공개 첨부 저장소」 카드가 읽는 메타를 만듭니다 (#916).
     *
     * - `pins`: `.env` 로 비공개 원본 디스크를 고정해 관리자 선택을 따르지 않는 주체(코어 `ATTACHMENT_DISK`, 확장별 env).
     * - `orphaned`: 저장된 선택이 이 서버에 정의되지 않은 디스크(디스크를 등록한 플러그인이 꺼짐)라 「기본」으로 동작 중인지.
     *
     * @return array{pins: list<array{owner: string, env: string, disk: string}>, orphaned: bool} 메타
     */
    public function privateAttachmentMeta(): array
    {
        $pins = [];

        $coreExplicit = config('attachment.disk_explicit');
        if (is_string($coreExplicit) && $coreExplicit !== '') {
            $pins[] = ['owner' => 'core', 'env' => 'ATTACHMENT_DISK', 'disk' => $coreExplicit];
        }

        $extensions = [];
        try {
            $extensions = [
                ...array_values(app(ModuleManager::class)->getActiveModules()),
                ...array_values(app(PluginManager::class)->getActivePlugins()),
            ];
        } catch (\Throwable) {
            // 확장 목록을 못 읽어도 화면 메타 전체를 깨지 않는다 — 고정 안내만 빠진다
        }

        foreach ($extensions as $extension) {
            if (! method_exists($extension, 'getPrivateAttachmentDiskPin')) {
                continue;
            }
            $pin = $extension->getPrivateAttachmentDiskPin();
            if (is_array($pin) && is_string($pin['env'] ?? null) && is_string($pin['disk'] ?? null)) {
                $pins[] = ['owner' => $extension->getIdentifier(), 'env' => $pin['env'], 'disk' => $pin['disk']];
            }
        }

        return ['pins' => $pins, 'orphaned' => PrivateAttachmentDisk::isOrphaned()];
    }

    /**
     * 저장은 허용하지만 운영자에게 알려야 할 저장소 조합 경고를 만듭니다 (#916 D5).
     *
     * 비공개 첨부 저장소를 공개 자산 디스크와 같은 디스크로 고르면 같은 버킷의 공개 정책이 비공개 원본에도 걸릴 수 있다 —
     * 서버 서빙 경로가 권한을 확인해도 버킷 주소로 직접 읽힐 수 있다.
     *
     * 권한이 걸린 이미지 썸네일 디스크도 같다 — 코어 `s3` 는 공개 자산 디스크로도 고를 수 있어 권한 썸네일 디스크로 허용하되,
     * 공개 자산 디스크도 `s3` 이면 같은 버킷이라 경고한다(`ThumbnailDiskOptions::SHARED_BUCKET_DISK`).
     *
     * @param  array<string, mixed>  $drivers  저장된 drivers 카테고리 값
     * @param  array<string, mixed>  $upload  저장된 upload 카테고리 값 (권한 썸네일 디스크 판정용)
     * @return list<array{key: string, message: string}> 경고 목록(필드 키, 문구)
     */
    public function storageWarnings(array $drivers, array $upload = []): array
    {
        $warnings = [];
        $private = (string) ($drivers['private_attachment_disk'] ?? '');
        $public = (string) ($drivers['public_asset_disk'] ?? '');

        if (! in_array($private, ['', PrivateAttachmentDisk::DEFAULT], true) && ! in_array($public, ['', 'none'], true) && $private === $public) {
            $warnings[] = [
                'key' => 'drivers.private_attachment_disk',
                'message' => __('settings.warnings.private_attachment_same_as_public_asset', ['disk' => $private]),
            ];
        }

        $gatedThumbnail = (string) ($upload['thumbnail_disk_gated'] ?? '');

        if ($gatedThumbnail === ThumbnailDiskOptions::SHARED_BUCKET_DISK && $public === ThumbnailDiskOptions::SHARED_BUCKET_DISK) {
            $warnings[] = [
                'key' => 'upload.thumbnail_disk_gated',
                'message' => __('settings.warnings.gated_thumbnail_same_as_public_asset', ['disk' => $gatedThumbnail]),
            ];
        }

        return $warnings;
    }

    /**
     * `.env` 로 잠긴 키 목록을 프론트엔드 키 형태로 반환합니다.
     *
     * 화면(레이아웃 JSON)이 참조하는 키는 `frontend_key`/`merge_into` 변환을 거친 이름
     * (예: `advanced.debug_mode`)이므로, 저장소 키(`debug.mode`)를 그대로 내보내면 화면의
     * 잠금 표시가 조용히 미발동합니다.
     *
     * `getAllSettings()` 가 값을 두 위치(병합 대상 + 원본 카테고리)에 싣는 것과 동일하게
     * 잠금 표시도 두 위치를 모두 담습니다.
     *
     * @return array<string, bool> 프론트엔드 키(`카테고리.키`) => true
     */
    public function envLockedMeta(): array
    {
        $meta = [];

        foreach (array_keys(EnvPriority::lockedKeys()) as $storageKey) {
            [$category, $key] = explode('.', $storageKey, 2);

            foreach ($this->resolveFrontendKeyTargets($category, $key) as [$targetCategory, $outputKey]) {
                $meta[$targetCategory.'.'.$outputKey] = true;
            }
        }

        return $meta;
    }

    /**
     * 저장소 키가 응답에서 실리는 (카테고리, 키) 쌍 목록을 반환합니다.
     *
     * `getAllSettings()` 의 변환 규칙과 같은 규칙을 씁니다 — 갈라지면 잠금 표시가 값과
     * 다른 자리를 가리키게 되고, 그 어긋남은 화면에서 "잠금이 안 걸린 것"과 구분되지 않습니다.
     *
     * @param  string  $category  저장소 카테고리명
     * @param  string  $key  저장소 키
     * @return array<int, array{0: string, 1: string}> (카테고리, 출력 키) 쌍 목록
     */
    private function resolveFrontendKeyTargets(string $category, string $key): array
    {
        $schema = $this->configRepository->getFrontendSchema();
        $categorySchema = $schema[$category] ?? [];
        $fields = $categorySchema['fields'] ?? [];

        $outputKey = $fields[$key]['frontend_key'] ?? $key;
        $targetCategory = $categorySchema['frontend_name'] ?? $categorySchema['merge_into'] ?? $category;

        $targets = [[$targetCategory, $outputKey]];

        if ($targetCategory !== $category) {
            $targets[] = [$category, $outputKey];
        }

        return $targets;
    }

    /**
     * 특정 카테고리의 설정을 조회합니다.
     *
     * @param  string  $category  카테고리명
     * @return array 설정 배열
     */
    public function getSettings(string $category): array
    {
        return $this->configRepository->getCategory($category);
    }

    /**
     * 프론트엔드에서 사용할 전체 설정을 조회합니다.
     *
     * config/settings/defaults.json의 frontend_schema를 기반으로
     * 각 카테고리별 설정을 동적으로 구조화하여 반환합니다.
     * 민감한 정보(sensitive: true)는 제외합니다.
     *
     * @return array 카테고리별 설정 배열
     */
    public function getFrontendSettings(): array
    {
        $schema = $this->configRepository->getFrontendSchema();
        $result = [];

        foreach ($schema as $category => $categorySchema) {
            // _description 등 메타 키 건너뛰기
            if (str_starts_with($category, '_')) {
                continue;
            }

            // expose가 false이면 건너뛰기
            if (! ($categorySchema['expose'] ?? false)) {
                continue;
            }

            // merge_into가 있으면 해당 카테고리에 병합
            $targetCategory = $categorySchema['frontend_name'] ?? $categorySchema['merge_into'] ?? $category;

            // 설정값 조회
            $settings = $this->getSettings($category);
            $formattedSettings = $this->formatCategorySettings($settings, $categorySchema);

            // 타겟 카테고리에 병합
            if (isset($result[$targetCategory])) {
                $result[$targetCategory] = array_merge($result[$targetCategory], $formattedSettings);
            } else {
                $result[$targetCategory] = $formattedSettings;
            }
        }

        return $result;
    }

    /**
     * 프론트엔드에 노출할 app config 값을 반환합니다.
     *
     * config/frontend.php의 app_config 스키마를 기반으로
     * config() 함수에서 값을 조회하여 반환합니다.
     *
     * @return array<string, mixed> 프론트엔드용 앱 설정 배열
     */
    public function getAppConfigForFrontend(): array
    {
        $fields = config('frontend.app_config', []);
        $result = [];

        foreach ($fields as $frontendKey => $fieldSchema) {
            $configKey = $fieldSchema['config_key'] ?? null;
            if (! $configKey) {
                continue;
            }

            $value = config($configKey);

            if ($frontendKey === 'supportedTimezones' && is_array($value)) {
                $result[$frontendKey] = $this->buildTimezoneOptions($value);

                continue;
            }

            // 언어 셀렉터에 노출할 활성 로케일은 config 의 허용 집합과
            // 활성 코어 언어팩의 합집합. 언어팩 설치/활성화 직후 SSR 결과도
            // 즉시 반영되도록 LanguagePackService 를 통해 동적 산출한다.
            if ($frontendKey === 'supportedLocales' && is_array($value)) {
                try {
                    $result[$frontendKey] = app(LanguagePackService::class)->getActiveLocales();
                } catch (\Throwable $e) {
                    // 부트 초기/마이그레이션 미수행 환경에서는 config 값으로 폴백
                    $result[$frontendKey] = $this->castValue($value, $fieldSchema);
                }

                continue;
            }

            $result[$frontendKey] = $this->castValue($value, $fieldSchema);
        }

        // 확장이 요청 컨텍스트 기반 값(예: 기기 유형)을 appConfig 에 주입할 수 있도록 필터 훅 제공.
        // config/frontend.php 는 정적 config 값만 담으므로, 요청별로 달라지는 값은 이 훅으로 확장이 채운다.
        // 프론트는 window.G7Config.appConfig → _global.appConfig 로 그대로 받는다.
        return HookManager::applyFilters('core.frontend.filter_app_config', $result);
    }

    /**
     * IANA 타임존 배열을 프론트엔드 Select 옵션 형식으로 변환합니다.
     *
     * 각 타임존의 현재 시점 UTC 오프셋을 계산하여
     * "(UTC±HH:MM) Asia/Seoul" 형식의 라벨을 생성합니다.
     * DST 전환 시점에 자동으로 정확한 오프셋이 반영됩니다.
     *
     * 정렬 순서: 오프셋 오름차순 → 식별자 알파벳순
     *
     * @param  array<int, string>  $timezones  IANA 타임존 식별자 배열
     * @return array<int, array{value: string, label: string}> Select 옵션 배열
     */
    private function buildTimezoneOptions(array $timezones): array
    {
        $now = new \DateTime('now', new \DateTimeZone('UTC'));

        $items = [];
        foreach ($timezones as $tz) {
            if (! is_string($tz) || $tz === '') {
                continue;
            }

            try {
                $offsetSec = (new \DateTimeZone($tz))->getOffset($now);
            } catch (\Exception) {
                continue;
            }

            $sign = $offsetSec >= 0 ? '+' : '-';
            $h = intdiv(abs($offsetSec), 3600);
            $m = intdiv(abs($offsetSec) % 3600, 60);

            $items[] = [
                'value' => $tz,
                'label' => sprintf('(UTC%s%02d:%02d) %s', $sign, $h, $m, $tz),
                'offset' => $offsetSec,
            ];
        }

        usort($items, fn ($a, $b) => [$a['offset'], $a['value']] <=> [$b['offset'], $b['value']]);

        return array_map(
            fn ($i) => ['value' => $i['value'], 'label' => $i['label']],
            $items
        );
    }

    /**
     * 스키마에 따라 카테고리 설정을 포맷팅합니다.
     *
     * @param  array  $settings  원본 설정 배열
     * @param  array  $categorySchema  카테고리 스키마
     * @return array 포맷팅된 설정 배열
     */
    private function formatCategorySettings(array $settings, array $categorySchema): array
    {
        $fields = $categorySchema['fields'] ?? [];
        $result = [];

        foreach ($fields as $fieldName => $fieldSchema) {
            // 민감 정보는 건너뛰기
            if ($fieldSchema['sensitive'] ?? false) {
                continue;
            }

            // 필드 레벨 expose: false는 건너뛰기
            if (isset($fieldSchema['expose']) && $fieldSchema['expose'] === false) {
                continue;
            }

            // 출력 키 결정 (frontend_key가 있으면 사용)
            $outputKey = $fieldSchema['frontend_key'] ?? $fieldName;

            // computed 필드는 별도 처리
            if ($fieldSchema['computed'] ?? false) {
                $result[$outputKey] = $this->computeFieldValue($settings, $fieldSchema);

                continue;
            }

            // 값 조회 및 타입 캐스팅
            $value = $settings[$fieldName] ?? null;
            $result[$outputKey] = $this->castValue($value, $fieldSchema);
        }

        return $result;
    }

    /**
     * computed 필드의 값을 계산합니다.
     *
     * @param  array  $settings  설정 배열
     * @param  array  $fieldSchema  필드 스키마
     * @return mixed 계산된 값
     */
    private function computeFieldValue(array $settings, array $fieldSchema): mixed
    {
        $sourceField = $fieldSchema['source'] ?? null;
        $transform = $fieldSchema['transform'] ?? null;

        if (! $sourceField || ! $transform) {
            return null;
        }

        $sourceValue = $settings[$sourceField] ?? null;

        return match ($transform) {
            'format_extensions' => $this->formatExtensions($sourceValue),
            'first_image_url' => $this->getFirstImageUrl($sourceValue),
            'favicon_url' => $this->getFaviconUrl($sourceValue),
            // 프론트 URL 유틸·편집기 규격 선택이 읽는 값 — 저장 행이 아니라 레지스트리가 합친 결과(고정·og·확장·운영자)다.
            'thumbnail_presets' => app(ThumbnailPresetRegistry::class)->forFrontend(),
            // `size` 를 붙여도 되는 서빙 경로 — 기능이 꺼져 있으면 빈 목록(프론트는 아무 주소도 바꾸지 않는다).
            'thumbnail_url_patterns' => (bool) ($sourceValue ?? true) ? ThumbnailUrl::patterns() : [],
            // 본문 콘텐츠 규칙 — 표시기·편집기용 계산값(허용 태그·속성·미디어 출처·외부 영상 컴파일 정규식, #883)
            'content_render_policy' => ContentHtmlPolicy::fromSettings($settings)->toFrontendArray(),
            default => $sourceValue,
        };
    }

    /**
     * 외부 영상 서비스 예시 주소 확인 — 저장 전 입력값으로 꺼낸 id 와 만들어질 플레이어 주소 (#883).
     *
     * 저장하지 않는다. 형식이 틀리면 필드별 문구를 함께 돌려 창이 저장 전에 알린다(저장 때와 같은 검증·컴파일 규칙).
     *
     * @param  array<string, mixed>  $entry  서비스 입력값(`url_patterns`·`player_url`·`id_rule`)
     * @param  string  $sampleUrl  확인할 예시 주소
     * @return array{matched: bool, id: ?string, player_url: ?string, errors: array<string, string>} 확인 결과
     */
    public function previewEmbedProvider(array $entry, string $sampleUrl): array
    {
        $errors = array_map(fn (string $key) => __($key, ['index' => 1, 'field' => '']), EmbedCustomProviderRule::validateEntry($entry + ['id' => 'preview', 'name' => 'preview'], false));
        $preview = $errors === [] ? EmbedProviderRegistry::preview($entry, $sampleUrl) : ['matched' => false, 'id' => null, 'player_url' => null];

        return ['matched' => $preview['matched'], 'id' => $preview['id'], 'player_url' => $preview['player_url'], 'errors' => $errors];
    }

    /**
     * 확장이 제공한 외부 영상 서비스의 켜기 선택지 — 「본문 콘텐츠」 탭이 그린다 (#883).
     *
     * @return array<int, array{key: string, extension: string, id: string, name: string}> 선택지
     */
    public function embedExtensionProviderOptions(): array
    {
        return EmbedProviderRegistry::extensionProviderOptions();
    }

    /**
     * 확장자 배열/문자열을 .jpg,.png 형식으로 변환합니다.
     *
     * @param  mixed  $extensions  확장자 배열 또는 문자열
     * @return string 포맷팅된 확장자 문자열
     */
    private function formatExtensions(mixed $extensions): string
    {
        if (empty($extensions)) {
            return '';
        }

        // 배열인 경우 문자열로 변환
        if (is_array($extensions)) {
            $extensions = implode(',', $extensions);
        }

        // 확장자 변환: 'jpg,jpeg,png' → '.jpg,.jpeg,.png'
        $extArray = array_map('trim', explode(',', $extensions));
        $formatted = array_map(fn ($ext) => '.'.ltrim($ext, '.'), $extArray);

        return implode(',', $formatted);
    }

    /**
     * 스키마 타입에 따라 값을 캐스팅합니다.
     *
     * @param  mixed  $value  원본 값
     * @param  array  $fieldSchema  필드 스키마
     * @return mixed 캐스팅된 값
     */
    private function castValue(mixed $value, array $fieldSchema): mixed
    {
        $type = $fieldSchema['type'] ?? 'string';
        $transform = $fieldSchema['transform'] ?? null;

        // transform이 있으면 먼저 처리
        if ($transform === 'join_comma' && is_array($value)) {
            $value = implode(',', $value);
        }

        // null이면 타입별 기본값 반환
        if ($value === null) {
            return match ($type) {
                'boolean' => false,
                'integer' => 0,
                'string' => '',
                'array' => [],
                default => null,
            };
        }

        return match ($type) {
            'boolean' => (bool) $value,
            'integer' => (int) $value,
            'string' => (string) $value,
            'array' => (array) $value,
            default => $value,
        };
    }

    /**
     * site_logo 설정의 첨부파일 목록을 조회합니다.
     *
     * JSON 설정에 저장된 attachment ID 배열을 기반으로 첨부파일 정보를 조회합니다.
     *
     * @return array 첨부파일 정보 배열
     */
    private function getSiteLogoAttachment(): array
    {
        return $this->getAttachmentListSetting('general.site_logo');
    }

    /**
     * 첨부 ID 배열 설정을 첨부 목록(리소스 배열)으로 해석합니다.
     *
     * site_logo / og_image_default 처럼 "첨부 ID 배열 + 화면은 첨부 객체" 패턴의
     * 설정이 공유하는 로드 경로입니다.
     *
     * @param  string  $configKey  설정 키 (예: 'general.site_logo')
     * @return array 첨부 목록 (없으면 빈 배열)
     */
    private function getAttachmentListSetting(string $configKey): array
    {
        // JSON 설정에서 첨부 ID 배열 조회
        $ids = $this->configRepository->get($configKey, []);

        // 빈 배열이거나 배열이 아닌 경우
        if (empty($ids) || ! is_array($ids)) {
            return [];
        }

        // ID 배열로 첨부파일 조회 (DB order 기준 정렬)
        $attachments = $this->attachmentRepository->findByIds($ids);

        if ($attachments->isEmpty()) {
            return [];
        }

        return $attachments->map(fn ($attachment) => (new AttachmentResource($attachment))->toListArray())->toArray();
    }

    /**
     * 사이트 기본 OG 이미지 URL 을 반환합니다.
     *
     * SeoMetaResolver 가 레이아웃 og.image 선언(도메인 캐시 포함)이 빈 값일 때
     * 마지막 폴백으로 사용합니다 (공개 이슈 #22 — 사이트 기본 공유 이미지).
     *
     * @return string|null 첫 번째 이미지 첨부의 다운로드 URL (미설정 시 null)
     */
    public function getOgDefaultImageUrl(): ?string
    {
        $ids = $this->configRepository->get('seo.og_image_default', []);

        return $this->getFirstImageUrl(is_array($ids) ? $ids : []);
    }

    /**
     * 첨부파일 ID 배열에서 첫 번째 이미지의 URL을 반환합니다.
     *
     * @param  mixed  $attachmentIds  첨부파일 ID 배열
     * @return string|null 첫 번째 이미지의 다운로드 URL 또는 null
     */
    private function getFirstImageUrl(mixed $attachmentIds): ?string
    {
        if (empty($attachmentIds) || ! is_array($attachmentIds)) {
            return null;
        }

        // ID 배열로 첨부파일 조회
        $attachments = $this->attachmentRepository->findByIds($attachmentIds);

        if ($attachments->isEmpty()) {
            return null;
        }

        // 첫 번째 이미지 파일 찾기
        $firstImage = $attachments->first(fn ($attachment) => $attachment->is_image);

        return $firstImage?->download_url;
    }

    /**
     * 파비콘 첨부 ID 배열에서 첫 유효 첨부의 파비콘 경로를 반환합니다 (#434).
     *
     * 이미지 판정(`is_image`)은 ico·svg 를 이미지로 보지 않으므로 파비콘은 파비콘 컬렉션·MIME 목록으로 따로 고른다.
     * 경로도 일반 다운로드가 아니라 파비콘 전용 경로(`/api/favicon/{hash}`)다.
     *
     * @param  mixed  $attachmentIds  첨부파일 ID 배열
     * @return string|null 파비콘 경로 또는 null
     */
    private function getFaviconUrl(mixed $attachmentIds): ?string
    {
        if (empty($attachmentIds) || ! is_array($attachmentIds)) {
            return null;
        }

        $attachment = $this->attachmentRepository->findByIds($this->extractAttachmentIds($attachmentIds))->first(
            fn ($attachment) => $attachment->collection === FaviconPolicy::CORE_COLLECTION
                && FaviconPolicy::responseMime($attachment->mime_type) !== null
        );

        return $attachment === null ? null : FaviconPolicy::url((string) $attachment->hash);
    }

    /**
     * 환경설정 값을 저장합니다.
     *
     * @param  array  $settings  카테고리별 설정 데이터
     * @return bool 저장 성공 여부
     */
    public function saveSettings(array $settings): bool
    {
        // Before 훅
        HookManager::doAction('core.settings.before_save', $settings);

        // 필터 훅 - 설정 데이터 변형
        $settings = HookManager::applyFilters('core.settings.filter_save_data', $settings);

        try {
            // _tab 키로 카테고리 결정
            $tab = $settings['_tab'] ?? 'general';
            unset($settings['_tab']);

            // nested 구조에서 해당 탭의 설정 추출
            // 예: { general: { site_name: '...' } } → { site_name: '...' }
            $tabSettings = $settings[$tab] ?? $settings;

            // frontend_key를 원본 키로 역변환
            $tabSettings = $this->reverseFrontendKeys($tabSettings);

            // 응답에 실려 나간 화면용 계산 값이 되돌아와도 저장하지 않는다(#887)
            $tabSettings = $this->rejectComputedForSave($tab, $tabSettings);

            // `.env` 가 소유권을 가져간 키는 저장 대상에서 제거한다. 화면의 disabled 는
            // 게이트가 아니다 — 저장 API 를 직접 호출하는 경로가 남으므로 실질 차단은 여기다.
            // (advanced 탭은 아직 카테고리가 갈리지 않았으므로 saveAdvancedSettings 가 분리 후 적용한다.)
            if ($tab !== 'advanced') {
                $tabSettings = EnvPriority::rejectLockedForSave($tab, $tabSettings);
            }

            // advanced 탭은 cache와 debug 두 카테고리로 분리
            if ($tab === 'advanced') {
                $result = $this->saveAdvancedSettings($tabSettings);

                // After 훅
                HookManager::doAction('core.settings.after_save', $tab, $tabSettings, $result);

                return $result;
            }

            // general 탭인 경우 site_logo 첨부파일 연결.
            // site_logo 를 제출하지 않은 저장(다른 필드만 변경)은 기존 저장값을 그대로 둔다 —
            // 이때 컬렉션을 다시 훑으면 미참조 첨부가 설정으로 딸려 들어온다.
            $removedSiteLogoIds = [];

            if ($tab === 'general' && is_array($tabSettings['site_logo'] ?? null)) {
                // 파기 대상 판정은 저장 **전에** 한다 — 저장 후에는 직전 저장값을 알 수 없다.
                // 실제 파기는 저장이 성공한 뒤에 수행한다: 저장이 실패했는데 파일만 사라지면
                // 설정에는 이미 없는 첨부 id 가 남아 로고가 깨진다.
                $removedSiteLogoIds = $this->resolveRemovedSiteLogoIds($tabSettings['site_logo']);

                $tabSettings['site_logo'] = $this->resolveSiteLogoIds($tabSettings['site_logo']);
            }

            // 사이트 파비콘(#434)도 site_logo 와 동형 — 제출하지 않은 저장은 기존값 유지, 뺀 첨부는 저장 성공 후 파기
            $removedFaviconIds = [];

            if ($tab === 'general' && is_array($tabSettings['site_favicon'] ?? null)) {
                $removedFaviconIds = $this->resolveRemovedAttachmentIds('general', 'site_favicon', $tabSettings['site_favicon']);

                $tabSettings['site_favicon'] = $this->resolveSubmittedAttachmentIds(FaviconPolicy::CORE_COLLECTION, $tabSettings['site_favicon']);
            }

            // 사이트 기본 OG 이미지도 site_logo 와 동형으로 처리 (공개 이슈 #22)
            $removedOgImageIds = [];

            if ($tab === 'seo' && is_array($tabSettings['og_image_default'] ?? null)) {
                $removedOgImageIds = $this->resolveRemovedAttachmentIds(
                    'seo',
                    'og_image_default',
                    $tabSettings['og_image_default']
                );

                $tabSettings['og_image_default'] = $this->resolveSubmittedAttachmentIds(
                    'og_image_default',
                    $tabSettings['og_image_default']
                );
            }

            // 큐 워커 등록(#139) — 빈 연결 목록은 현재 드라이버 연결 하나로, 큐 목록이 없는 연결은 기본 큐로 채우고,
            // 등록하지 않은 연결의 큐 목록은 버린다. 검증(V1~V9)은 FormRequest 가 이미 끝냈다.
            if ($tab === 'drivers') {
                $tabSettings = app(QueueWorkerRegistry::class)->normalizeForSave($tabSettings);
            }

            // 기존 설정과 병합 (탭별로 일부 필드만 전송되어도 기존 설정 유지)
            $existingSettings = $this->configRepository->getCategory($tab);

            // 이미지 썸네일 규격 표(#869) — 저장소는 카테고리 1단계만 병합해 목록을 통째로 바꾸므로, 화면이 보낸 표를
            // 그대로 두면 고정 슬롯이 빠지거나 꺼진 확장의 수정값이 사라진다. 저장 목록으로 바꿔 넣는다.
            if ($tab === 'upload') {
                $tabSettings = $this->prepareUploadThumbnailSettings($tabSettings, $existingSettings);
            }

            // 최근 검색어 범위 행(7.1.5) — 화면에는 지금 선언된 범위만 나오므로, 끈 확장의 기존 행은 보존해 다시 켤 때
            // 운영자 값이 돌아오게 한다. 형식 검증은 FormRequest 가 마쳤다.
            if ($tab === 'privacy' && is_array($tabSettings[SearchScopes::SETTINGS_ROWS_KEY] ?? null)) {
                $tabSettings[SearchScopes::SETTINGS_ROWS_KEY] = SearchScopes::mergeForSave(
                    $tabSettings[SearchScopes::SETTINGS_ROWS_KEY],
                    $existingSettings[SearchScopes::SETTINGS_ROWS_KEY] ?? []
                );
            }
            $mergedSettings = array_merge($existingSettings, $tabSettings);

            // 자산 URL 방식이 바뀌는지 저장 **전에** 판정한다 (이슈 #486).
            // 저장 후에는 이전 값을 알 수 없어 변경 여부를 판별할 수 없다.
            $assetUrlModeChanged = $tab === 'general'
                && array_key_exists('asset_url_mode', $tabSettings)
                && ($existingSettings['asset_url_mode'] ?? null) !== $tabSettings['asset_url_mode'];

            // 썸네일 설정이 실제로 바뀌는지도 저장 전에 판정한다 — 바뀌었을 때만 큐 워커 재시작 신호를 보낸다.
            $thumbnailSettingsChanged = $tab === 'upload'
                && $this->thumbnailSettingsDiffer($existingSettings, $mergedSettings);

            // 해당 카테고리 설정 저장
            $result = $this->configRepository->saveCategory($tab, $mergedSettings);

            if ($result) {
                $this->invalidateSettingsCache();

                // 저장이 확정된 뒤에야 파일을 파기한다 (위 판정 시점 주석 참조).
                $this->purgeSiteLogoAttachments($removedSiteLogoIds);
                $this->purgeRemovedAttachments($removedFaviconIds, '사이트 파비콘');
                $this->purgeRemovedAttachments($removedOgImageIds, '기본 OG 이미지');

                // SEO 프리렌더 캐시에는 생성 시점의 자산 URL 이 그대로 구워져 있다.
                // 모드가 바뀌면 그 URL 들이 전부 어긋나는데, 봇은 JavaScript 를 실행하지
                // 않아 브라우저 자가 복구가 닿지 않는다 → 캐시를 비워 재생성시킨다.
                // CLI(`g7:asset-url-mode`)와 동일한 처리 (계획서 §알려진 한계).
                if ($assetUrlModeChanged) {
                    $this->clearSeoCacheForAssetUrlMode();
                    $this->bumpExtensionCacheForAssetUrlMode();
                }

                // drivers 탭은 queue/broadcasting/cache 등 long-running worker에 영향
                // SettingsServiceProvider는 worker boot 시점에 한 번만 config 적용하므로
                // 워커가 정상 종료 후 재시작되도록 신호 전송 (cache 기반, 즉시 종료 X)
                // 썸네일 미리 만들기·옛 디스크 비우기는 큐 작업이다 — 썸네일 설정(업로드 탭)이 바뀌면 워커가 옛 값으로
                // 계속 돌지 않게 같은 신호를 보낸다. 다른 업로드 키만 바뀌었거나 같은 값이면 보내지 않는다.
                if ($tab === 'drivers' || $thumbnailSettingsChanged) {
                    $this->restartQueueWorkers();
                }

                if ($tab === 'upload' || $tab === 'drivers') {
                    $this->applyThumbnailConfigInProcess($tab, $mergedSettings);
                }
            }

            // After 훅
            HookManager::doAction('core.settings.after_save', $tab, $mergedSettings, $result);

            return $result;
        } catch (\Exception $e) {
            throw ValidationException::withMessages([
                'settings' => [__('settings.save_failed').': '.$e->getMessage()],
            ]);
        }
    }

    /**
     * 두 업로드 설정의 썸네일 값이 다른지 — 워커가 실제로 읽는 config 값(`ThumbnailConfig::fromUploadSettings()`)으로 비교한다.
     *
     * 화면은 켜고 끄는 값을 '1'·'0' 문자열로, API 는 bool 로 보낸다. 저장 원형으로 비교하면 같은 값을 다시 저장해도 바뀐
     * 것으로 보이므로 config 로 바꾼 뒤 비교한다. 썸네일 키가 아닌 업로드 키는 보지 않는다.
     *
     * @param  array<string, mixed>  $before  지금 저장된 업로드 설정
     * @param  array<string, mixed>  $after  저장할 업로드 설정
     * @return bool 썸네일 값이 하나라도 다르면 true
     */
    private function thumbnailSettingsDiffer(array $before, array $after): bool
    {
        return ThumbnailConfig::fromUploadSettings($before) != ThumbnailConfig::fromUploadSettings($after);
    }

    /**
     * 업로드 탭 저장분의 썸네일 값을 저장 형태로 바꿉니다 (#869).
     *
     *  - 응답 전용 값(`thumbnail_presets_rows`, `thumbnail_presets_public`, `thumbnail_url_patterns`)은 저장하지 않는다.
     *    화면은 응답으로 받은 업로드 탭 객체를 통째로 되돌려 보내므로, 거르지 않으면 계산 결과가 upload.json 에 쌓이고
     *    다음 조회 때 계산 값 대신 그 낡은 값이 보인다.
     *  - `thumbnail_presets` 는 `ThumbnailPresetSettings::mergeForSave()` 를 거친다. 고정 슬롯을 늘 보충하고, og 행은 빼고
     *    (크기는 SEO 탭 소유), 지금 꺼진 확장의 수정값은 기존 저장본에서 이어 붙인다. null 은 「보내지 않음」으로 본다.
     *
     * @param  array<string, mixed>  $tabSettings  업로드 탭 저장분 (원본 키)
     * @param  array<string, mixed>  $existingSettings  지금 저장된 업로드 설정
     * @return array<string, mixed> 저장할 업로드 탭 값
     */
    private function prepareUploadThumbnailSettings(array $tabSettings, array $existingSettings): array
    {
        unset(
            $tabSettings['thumbnail_presets_rows'],
            $tabSettings['thumbnail_presets_public'],
            $tabSettings['thumbnail_url_patterns'],
        );

        // 서버 보호 값(「고급」 칸)은 정수로 저장한다 — 화면은 숫자 칸 값을 문자열로 보내기도 한다. 빈 칸은 null(서버 기본값).
        foreach (ThumbnailConfig::SERVER_LIMIT_KEYS as $limitKey) {
            $settingKey = 'thumbnail_'.$limitKey;

            if (array_key_exists($settingKey, $tabSettings)) {
                $tabSettings[$settingKey] = is_numeric($tabSettings[$settingKey]) ? (int) $tabSettings[$settingKey] : null;
            }
        }

        if (! array_key_exists('thumbnail_presets', $tabSettings)) {
            return $tabSettings;
        }

        if (! is_array($tabSettings['thumbnail_presets'])) {
            unset($tabSettings['thumbnail_presets']);

            return $tabSettings;
        }

        $previous = is_array($existingSettings['thumbnail_presets'] ?? null) ? array_values($existingSettings['thumbnail_presets']) : [];

        $tabSettings['thumbnail_presets'] = app(ThumbnailPresetSettings::class)->mergeForSave(
            array_values($tabSettings['thumbnail_presets']),
            $previous
        );

        return $tabSettings;
    }

    /**
     * 저장한 썸네일 설정과 이미지 엔진 선택을 이 프로세스의 config 에도 반영합니다 (#869).
     *
     * `SettingsServiceProvider` 의 주입(applyUploadConfig, applyDriverConfig)은 다음 부팅에서야 일어난다.
     * 그런데 저장 응답(`SettingsController::store`)이 같은 요청 안에서 규격 표(`presentRows`)와 엔진 유무를 다시 만들므로,
     * 여기서 넣지 않으면 저장 직후 화면이 옛 표로 되돌아간다. 키 대응은 Provider 와 같게 둔다 — 업로드 탭의 서버 보호 값은
     * `.env` 대응 키라 Provider 처럼 잠긴 키를 빼고 넣는다(저장본에 남은 옛 값이 `.env` 값을 덮지 않게). 레지스트리의 요청 안
     * 캐시도 비운다.
     *
     * @param  string  $tab  저장한 탭 (upload 또는 drivers)
     * @param  array<string, mixed>  $mergedSettings  저장한 카테고리 전체 값
     * @return void 반환값 없음
     */
    private function applyThumbnailConfigInProcess(string $tab, array $mergedSettings): void
    {
        if ($tab === 'drivers') {
            if (! empty($mergedSettings['image_driver'])) {
                Config::set('core.image.driver', (string) $mergedSettings['image_driver']);
            }
        } else {
            $store = app(ThumbnailStore::class);
            $previousDisks = $store->disks();
            $wasEnabled = (bool) config('core.thumbnail.enabled', true);

            foreach (ThumbnailConfig::fromUploadSettings(EnvPriority::filterLocked('upload', $mergedSettings)) as $configKey => $value) {
                Config::set($configKey, $value);
            }

            // 끈 동안 저장소 계층은 원본 put 의 파생본 무효화를 건너뛰었다(업로드마다 원격 확인 요청을 내지 않게).
            // 그 사이 덮어쓴 원본의 옛 파생본이 다시 켜자마자 나가지 않도록 지금 쓰는 썸네일 디스크를 비운다(큐 또는 응답 뒤).
            if (! $wasEnabled && (bool) config('core.thumbnail.enabled', true)) {
                PurgeThumbnailsOnReenableJob::schedule();
            }

            // 저장 위치를 바꾸면 옛 디스크의 파생본은 어떤 경로로도 지워지지 않는다(원본 삭제·정리·비우기가 모두
            // 「지금 설정된」 디스크만 본다). 썸네일은 캐시이므로 옛 디스크의 `thumbnails/` 를 비우되, 파일 수만큼
            // 걸리는 삭제가 저장 요청을 붙잡지 않게 큐로 넘긴다(큐가 없으면 응답을 보낸 뒤).
            foreach (array_diff($previousDisks, $store->disks()) as $retired) {
                PurgeRetiredThumbnailDiskJob::schedule($retired);
            }
        }

        app(ThumbnailPresetRegistry::class)->flush();
    }

    /**
     * frontend_key를 원본 저장소 키로 역변환합니다.
     *
     * @param  array  $settings  프론트엔드 키 기반 설정
     * @return array 원본 키 기반 설정
     */
    private function reverseFrontendKeys(array $settings): array
    {
        $schema = $this->configRepository->getFrontendSchema();
        $reverseMap = $this->buildReverseKeyMap($schema);

        $result = [];
        foreach ($settings as $key => $value) {
            $originalKey = $reverseMap[$key] ?? $key;
            $result[$originalKey] = $value;
        }

        return $result;
    }

    /**
     * frontend_key → 원본 키 역변환 맵을 생성합니다.
     *
     * @param  array  $schema  프론트엔드 스키마
     * @return array 역변환 맵
     */
    private function buildReverseKeyMap(array $schema): array
    {
        $map = [];
        foreach ($schema as $category => $categorySchema) {
            if (str_starts_with($category, '_')) {
                continue;
            }

            $fields = $categorySchema['fields'] ?? [];
            foreach ($fields as $fieldName => $fieldSchema) {
                $frontendKey = $fieldSchema['frontend_key'] ?? null;
                if ($frontendKey) {
                    $map[$frontendKey] = $fieldName;
                }
            }
        }

        return $map;
    }

    /**
     * advanced 탭 설정의 카테고리별 필드 분류표를 만듭니다.
     *
     * 분류표는 스키마(`frontend_schema.*.merge_into === 'advanced'`)에서 도출합니다.
     * 손으로 열거하면 고급 탭에 카테고리가 새로 합류할 때 분류표가 뒤처지고, 그 값은
     * 어느 카테고리에도 담기지 않은 채 조용히 버려집니다(저장은 성공으로 보고됨).
     *
     * 아래 기본 목록은 스키마가 노출하지 않지만 고급 탭이 계속 저장해 온 레거시 필드
     * (cache 카테고리 전반, debug.log_level)를 보존하기 위한 것입니다.
     *
     * @return array<string, array<int, string>> 카테고리 → 원본 필드명 목록
     */
    private function buildAdvancedCategoryFieldMap(): array
    {
        // (frontend_key → 원본 키 역변환 후의 키 기준)
        $map = [
            'cache' => ['enabled', 'layout_enabled', 'layout_ttl', 'stats_enabled', 'stats_ttl', 'seo_enabled', 'seo_ttl', 'seo_sitemap_ttl'],
            'debug' => ['mode', 'sql_query_log', 'log_level'],
        ];

        foreach ($this->configRepository->getFrontendSchema() as $category => $categorySchema) {
            if (str_starts_with($category, '_') || ($categorySchema['merge_into'] ?? null) !== 'advanced') {
                continue;
            }

            $fields = array_keys($categorySchema['fields'] ?? []);
            $map[$category] = array_values(array_unique(array_merge($map[$category] ?? [], $fields)));
        }

        return $map;
    }

    /**
     * advanced 탭 설정을 소속 카테고리로 분리하여 저장합니다.
     *
     * @param  array  $settings  저장할 설정 배열
     * @return bool 저장 성공 여부
     */
    private function saveAdvancedSettings(array $settings): bool
    {
        $categoryFieldMap = $this->buildAdvancedCategoryFieldMap();

        // 설정을 카테고리별로 분류
        $categorized = array_fill_keys(array_keys($categoryFieldMap), []);

        foreach ($settings as $key => $value) {
            foreach ($categoryFieldMap as $category => $fields) {
                if (in_array($key, $fields)) {
                    $categorized[$category][$key] = $value;
                    break;
                }
            }
        }

        $result = true;

        // 각 카테고리별 병합 저장
        foreach ($categorized as $category => $categorySettings) {
            // 고급 탭은 여러 카테고리가 한 폼에 섞여 오므로 잠금 필터를 분리 후에 적용한다
            // (탭 이름 'advanced' 는 저장소 카테고리가 아니라 매핑이 걸리지 않는다).
            $categorySettings = EnvPriority::rejectLockedForSave($category, $categorySettings);

            if (empty($categorySettings)) {
                continue;
            }

            $existing = $this->configRepository->getCategory($category);
            $merged = array_merge($existing, $categorySettings);
            $result = $result && $this->configRepository->saveCategory($category, $merged);
        }

        if ($result) {
            $this->invalidateSettingsCache();
        }

        return $result;
    }

    /**
     * 저장할 사이트 로고 첨부 ID 목록을 결정합니다.
     *
     * 기준은 **이번 저장 요청이 제출한 목록**입니다. 컬렉션 전체를 다시 훑으면, 저장에 실패했거나
     * 작성 중 이탈해 남은 미참조 첨부까지 설정에 다시 편입되어(운영자가 올린 적 없는 로고가
     * 되살아나는) 누적이 발생합니다.
     *
     * 제출값에 있더라도 실제로 존재하지 않는 첨부(다른 경로로 이미 삭제된 id)는 걸러냅니다.
     *
     * @param  array<int, mixed>  $submitted  제출된 site_logo 값 (첨부 객체 배열 또는 ID 배열)
     * @return array<int, int> 저장할 첨부파일 ID 배열
     */
    private function resolveSiteLogoIds(array $submitted): array
    {
        return $this->resolveSubmittedAttachmentIds('site_logo', $submitted);
    }

    /**
     * 제출된 첨부 목록에서 해당 컬렉션에 실재하는 첨부 ID 만 남깁니다.
     *
     * @param  string  $collection  첨부 컬렉션명 (예: 'site_logo', 'og_image_default')
     * @param  array  $submitted  제출값 (첨부 객체 배열 또는 ID 배열)
     * @return array<int, int> 저장할 첨부 ID 목록
     */
    private function resolveSubmittedAttachmentIds(string $collection, array $submitted): array
    {
        $submittedIds = $this->extractAttachmentIds($submitted);

        if ($submittedIds === []) {
            return [];
        }

        $existingIds = $this->attachmentRepository->getByCollection($collection)
            ->pluck('id')
            ->all();

        return array_values(array_intersect($submittedIds, $existingIds));
    }

    /**
     * 저장 요청에서 빠진(= 운영자가 화면에서 제거한) 첨부 설정 ID 를 가려냅니다.
     *
     * 판정 기준·시점 규율은 resolveRemovedSiteLogoIds 와 동일합니다 — 직전 저장값에
     * 있었는데 이번 제출에서 빠진 id 만 파기 대상이며, 저장으로 값이 덮이기 전에
     * 판정해야 합니다.
     *
     * @param  string  $category  설정 카테고리 (예: 'seo')
     * @param  string  $key  설정 키 (예: 'og_image_default')
     * @param  mixed  $submitted  제출값 (미제출이면 null)
     * @return array<int, int> 파기 대상 첨부 ID 목록
     */
    private function resolveRemovedAttachmentIds(string $category, string $key, mixed $submitted): array
    {
        if (! is_array($submitted)) {
            return [];
        }

        $previousIds = $this->extractAttachmentIds(
            $this->configRepository->getCategory($category)[$key] ?? []
        );

        if ($previousIds === []) {
            return [];
        }

        $keptIds = $this->extractAttachmentIds($submitted);

        return array_values(array_diff($previousIds, $keptIds));
    }

    /**
     * 제거가 확정된 설정 첨부를 파일까지 파기합니다 (저장 성공 후에만 호출).
     *
     * @param  array<int, int>  $removedIds  파기 대상 첨부 ID 목록
     * @param  string  $label  로그 표기용 설정 이름
     * @return void 반환값 없음
     */
    private function purgeRemovedAttachments(array $removedIds, string $label): void
    {
        if ($removedIds === []) {
            return;
        }

        foreach ($removedIds as $removedId) {
            // 설정 저장은 이미 확정된 뒤다 — 파기 실패가 저장 실패(422)로 위장되면
            // 운영자는 성공한 저장을 실패로 오인한다. 실패 파일은 로그로만 남긴다.
            try {
                $this->attachmentService->delete($removedId);
            } catch (\Exception $e) {
                Log::warning($label.' 첨부 파기 실패 — 저장은 확정됨', [
                    'attachment_id' => $removedId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info($label.' 첨부 제거', ['attachment_ids' => $removedIds]);
    }

    /**
     * 저장 요청에서 빠진(= 운영자가 화면에서 제거한) 사이트 로고 첨부 ID 를 가려냅니다.
     *
     * 판정 기준은 **직전 저장값**입니다. 직전에 저장돼 있었는데 이번 제출에서 빠진 id 만
     * 운영자가 명시적으로 뺀 것이고, 직전 저장값에도 없던 id 는 이번에 새로 올라온 첨부입니다.
     * 그래서 이 판정은 저장으로 값이 덮이기 **전에** 수행해야 합니다.
     *
     * 저장할 목록 자체는 제출값이 정합니다(resolveSiteLogoIds) — 컬렉션 전체를 훑으면 저장에
     * 실패했거나 이탈로 남은 미참조 첨부가 설정에 되살아납니다.
     *
     * @param  mixed  $submitted  제출된 site_logo 값 (첨부 객체 배열 또는 ID 배열, 미제출이면 null)
     * @return array<int, int> 파기 대상 첨부 ID 목록
     */
    private function resolveRemovedSiteLogoIds(mixed $submitted): array
    {
        // site_logo 를 아예 제출하지 않은 저장(다른 필드만 변경)은 판정 대상이 아니다.
        if (! is_array($submitted)) {
            return [];
        }

        $previousIds = $this->extractAttachmentIds(
            $this->configRepository->getCategory('general')['site_logo'] ?? []
        );

        if ($previousIds === []) {
            return [];
        }

        $keptIds = $this->extractAttachmentIds($submitted);

        return array_values(array_diff($previousIds, $keptIds));
    }

    /**
     * 제거가 확정된 사이트 로고 첨부를 파일까지 파기합니다.
     *
     * 설정 저장이 성공한 뒤에만 호출합니다 — 저장이 실패했는데 파일이 먼저 사라지면 설정에는
     * 이미 없는 첨부 id 가 남아 로고가 깨집니다.
     *
     * @param  array<int, int>  $removedIds  파기 대상 첨부 ID 목록
     * @return void 반환값 없음
     */
    private function purgeSiteLogoAttachments(array $removedIds): void
    {
        if ($removedIds === []) {
            return;
        }

        foreach ($removedIds as $removedId) {
            // 설정 저장은 이미 확정된 뒤다 — 파기 실패가 저장 실패(422)로 위장되면
            // 운영자는 성공한 저장을 실패로 오인한다. 실패 파일은 로그로만 남긴다.
            try {
                $this->attachmentService->delete($removedId);
            } catch (\Exception $e) {
                Log::warning('사이트 로고 첨부 파기 실패 — 저장은 확정됨', [
                    'attachment_id' => $removedId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('사이트 로고 첨부 제거', ['attachment_ids' => $removedIds]);
    }

    /**
     * 첨부 목록 값에서 첨부 ID 만 추출합니다.
     *
     * 저장값은 ID 배열이지만 화면 제출값은 첨부 객체 배열이라 두 형태를 모두 받습니다.
     *
     * @param  mixed  $value  첨부 목록 값
     * @return array<int, int> 첨부 ID 목록
     */
    private function extractAttachmentIds(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $ids = [];

        foreach ($value as $item) {
            $id = is_array($item) ? ($item['id'] ?? null) : $item;

            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * 특정 키의 설정값을 조회합니다.
     *
     * @param  string  $key  설정 키 (도트 노테이션 지원)
     * @param  mixed  $default  기본값
     * @return mixed 설정값
     */
    public function getSetting(string $key, mixed $default = null): mixed
    {
        return $this->configRepository->get($key, $default);
    }

    /**
     * 단일 설정 값을 저장합니다.
     *
     * 벌크 저장(saveSettings)이 수행하는 부수효과 중 저장 키에 해당하는 것을 함께 수행한다
     * (공개 #114 동종). 예전에는 값만 쓰고 SEO 프리렌더 캐시 삭제·큐 워커 재시작 신호를
     * 건너뛰어, 같은 값을 어느 경로로 바꾸느냐에 따라 시스템 상태가 달라졌다.
     *
     * 키는 **원본 저장소 키**로 받는다 — 벌크 저장의 `reverseFrontendKeys()`(화면 키 → 저장소
     * 키 역변환)를 적용하지 않는다. 이 경로의 프로그램 호출자(본인인증 플러그인 설치/삭제의
     * `identity.purpose_providers.*`)가 저장소 키를 직접 넘기고 있어, 역변환을 끼우면 그
     * 호출들이 엉뚱한 키에 저장된다.
     *
     * 벌크 위임도 하지 않는다 — 벌크의 shallow `array_merge` 로는 깊은 키를 저장할 때
     * 형제 매핑이 통째로 소실된다.
     *
     * @param  string  $key  설정 키 (예: 'general.site_name')
     * @param  mixed  $value  저장할 값
     * @return bool 저장 성공 여부
     */
    public function setSetting(string $key, mixed $value): bool
    {
        // Before 훅
        HookManager::doAction('core.settings.before_set', $key, $value);

        try {
            // 자산 URL 방식이 바뀌는지 저장 **전에** 판정한다 (이슈 #486 동형).
            // 저장 후에는 이전 값을 알 수 없어 변경 여부를 판별할 수 없다.
            $assetUrlModeChanged = $key === 'general.asset_url_mode'
                && $this->configRepository->get($key) !== $value;

            $thumbnailSettingsChanged = $this->singleKeyChangesThumbnailSettings($key, $value);

            $result = $this->configRepository->set($key, $value);

            if ($result) {
                // 인라인 무효화 대신 공통 경로를 탄다 — saveSettings/saveAdvancedSettings 와
                // 같은 처리를 받아야 미러 재채움·디스크 config 캐시 재생성이 빠지지 않는다.
                $this->invalidateSettingsCache();

                if ($assetUrlModeChanged) {
                    $this->clearSeoCacheForAssetUrlMode();
                    $this->bumpExtensionCacheForAssetUrlMode();
                }

                if (str_starts_with($key, 'drivers.') || $key === 'drivers' || $thumbnailSettingsChanged) {
                    $this->restartQueueWorkers();
                }
            }

            // After 훅
            HookManager::doAction('core.settings.after_set', $key, $value, $result);

            return $result;
        } catch (\Exception $e) {
            throw ValidationException::withMessages([
                'setting' => [__('settings.save_individual_failed', ['error' => $e->getMessage()])],
            ]);
        }
    }

    /**
     * 단건 저장이 썸네일 설정 값을 바꾸는지 — 큐 워커 재시작 신호 판정 (벌크 저장의 `thumbnailSettingsDiffer()` 와 같은 기준).
     *
     * `upload` 카테고리 통째, `upload.{썸네일 키}`, 그 아래 깊은 키(`upload.thumbnail_presets.0.width` 등)를 본다.
     *
     * @param  string  $key  설정 키 (예: 'upload.thumbnail_enabled')
     * @param  mixed  $value  저장할 값
     * @return bool 썸네일 값이 바뀌면 true
     */
    private function singleKeyChangesThumbnailSettings(string $key, mixed $value): bool
    {
        if ($key === 'upload') {
            return is_array($value) && $this->thumbnailSettingsDiffer((array) $this->configRepository->getCategory('upload'), $value);
        }

        $segments = explode('.', $key, 3);

        if ($segments[0] !== 'upload' || ! isset($segments[1]) || ! array_key_exists($segments[1], ThumbnailConfig::SETTING_KEYS)) {
            return false;
        }

        $before = $this->configRepository->get($key);

        if (isset($segments[2])) {
            return $before != $value;
        }

        return $this->thumbnailSettingsDiffer([$segments[1] => $before], [$segments[1] => $value]);
    }

    /**
     * 설정을 백업합니다.
     *
     * @return string 백업 파일 경로
     */
    public function backupSettings(): string
    {
        return $this->configRepository->backup();
    }

    /**
     * 백업에서 설정을 복원합니다.
     *
     * @param  string  $backupPath  백업 파일 경로
     * @return bool 복원 성공 여부
     */
    public function restoreSettings(string $backupPath): bool
    {
        // 자산 URL 방식은 복원 **전**에 읽어 둔다 — 복원 뒤에는 이전 값을 알 수 없어
        // 변경 여부를 판별할 수 없다 (saveSettings/setSetting 과 같은 사유).
        $previousAssetUrlMode = $this->configRepository->get('general.asset_url_mode');

        $result = $this->configRepository->restore($backupPath);

        if ($result) {
            // 복원도 설정 전체를 갈아엎는 쓰기다 — 캐시만 비우고 미러를 두면
            // 같은 프로세스가 복원 전 값을 계속 읽는다 (저장 경로와 동일 결함, 공개이슈 #109).
            $this->invalidateSettingsCache();

            // 복원으로 자산 URL 방식이 바뀌었으면 두 저장 경로와 동일하게 처리한다 —
            // SEO 프리렌더와 병합 번들 CSS 양쪽에 구워진 URL 형태가 어긋난다.
            if ($this->configRepository->get('general.asset_url_mode') !== $previousAssetUrlMode) {
                $this->clearSeoCacheForAssetUrlMode();
                $this->bumpExtensionCacheForAssetUrlMode();
            }
        }

        return $result;
    }

    /**
     * 자산 URL 방식이 바뀌었을 때 확장 캐시 버전을 올립니다 (정적 게시본 재생성).
     *
     * 병합 번들 CSS 는 내부 `url()` 참조가 그 시점의 자산 URL **형태**(확장자 / `?file=`)로
     * 본문에 구워진다(`ExtensionBundleService` 의 `AssetCssUrlRewriter`). 디스크 번들과 정적
     * 게시본은 캐시 버전으로 키드되어 있어, 버전이 오르지 않으면 모드를 바꿔도 옛 형태의
     * URL 이 남고 그 참조(글꼴·이미지)가 서버에서 404 가 된다(#651 F5). bump 단일 지점이
     * 번들 재병합과 정적 재게시를 함께 예약한다.
     *
     * @return void 반환값 없음
     */
    private function bumpExtensionCacheForAssetUrlMode(): void
    {
        $this->incrementExtensionCacheVersion();
    }

    /**
     * 테스트 메일을 발송합니다.
     *
     * @param  string  $toEmail  수신자 이메일
     * @param  array  $overrideSettings  폼에서 전달된 메일 설정 (저장된 값보다 우선)
     * @return array 발송 결과
     */
    public function sendTestMail(string $toEmail, array $overrideSettings = []): array
    {
        try {
            $savedSettings = $this->configRepository->getCategory('mail');
            $mailSettings = array_merge($savedSettings, $overrideSettings);

            // `.env` 로 잠긴 키는 저장값·요청값이 아니라 `.env` 유효값으로 보낸다 — 잠긴 민감 키는
            // 화면에 값이 실리지 않아 요청이 빈 칸으로 오고, 저장값은 적용되지 않는 옛 값이다.
            // 아래에서 config 를 덮기 **전에** 읽어야 런타임 유효값(=`.env`)을 얻는다.
            foreach (array_keys(EnvPriority::lockedKeys()) as $storageKey) {
                if (str_starts_with($storageKey, 'mail.')) {
                    $mailSettings[substr($storageKey, 5)] = EnvPriority::effectiveValue($storageKey);
                }
            }

            // 메일 설정 임시 적용 (드라이버별)
            $mailer = $mailSettings['mailer'] ?? 'smtp';
            config(['mail.default' => $mailer]);

            match ($mailer) {
                'smtp' => config([
                    'mail.mailers.smtp.host' => $mailSettings['host'] ?? '',
                    'mail.mailers.smtp.port' => (int) ($mailSettings['port'] ?? 587),
                    'mail.mailers.smtp.username' => $mailSettings['username'] ?? '',
                    'mail.mailers.smtp.password' => $mailSettings['password'] ?? '',
                    'mail.mailers.smtp.encryption' => $mailSettings['encryption'] ?? 'tls',
                ]),
                'mailgun' => config([
                    'mail.mailers.mailgun.transport' => 'mailgun',
                    'services.mailgun.domain' => $mailSettings['mailgun_domain'] ?? '',
                    'services.mailgun.secret' => $mailSettings['mailgun_secret'] ?? '',
                    'services.mailgun.endpoint' => ! empty($mailSettings['mailgun_endpoint']) ? $mailSettings['mailgun_endpoint'] : 'api.mailgun.net',
                ]),
                'ses' => config([
                    'mail.mailers.ses.transport' => 'ses',
                    'services.ses.key' => $mailSettings['ses_key'] ?? '',
                    'services.ses.secret' => $mailSettings['ses_secret'] ?? '',
                    'services.ses.region' => $mailSettings['ses_region'] ?? 'ap-northeast-2',
                ]),
                default => null,
            };

            config([
                'mail.from.address' => $mailSettings['from_address'] ?? '',
                'mail.from.name' => $mailSettings['from_name'] ?? '그누보드7',
            ]);

            $subject = __('settings.test_mail_subject', ['app_name' => config('app.name')]);
            $body = __('settings.test_mail_body');

            // audit:allow mail-send-must-go-through-notification-dispatcher reason: 입력 중인 SMTP 설정 자체를 시험하는 메일 — 알림 채널이 꺼져 있어도, 저장 전 설정으로도 보내야 의미가 있다(notification-system.md 「예외」 표)
            Mail::raw($body, function ($message) use ($toEmail, $subject) {
                $message->to($toEmail)
                    ->subject($subject);

                // X-G7 헤더 추가 (발송 이력 로깅용)
                $message->getHeaders()->addTextHeader('X-G7-Source', 'test_mail');
                $message->getHeaders()->addTextHeader('X-G7-Extension-Type', 'core');
                $message->getHeaders()->addTextHeader('X-G7-Extension-Id', 'core');
            });

            HookManager::doAction('core.mail.after_send', [
                'recipientEmail' => $toEmail,
                'senderEmail' => $mailSettings['from_address'] ?? config('mail.from.address'),
                'senderName' => $mailSettings['from_name'] ?? config('mail.from.name'),
                'subject' => $subject,
                'body' => $body,
                'extensionType' => 'core',
                'extensionIdentifier' => 'core',
                'source' => 'test_mail',
            ]);

            return [
                'success' => true,
                'message' => __('settings.test_mail_sent'),
                'subject' => $subject,
                'body' => $body,
            ];
        } catch (\Exception $e) {
            HookManager::doAction('core.mail.send_failed', [
                'recipientEmail' => $toEmail,
                'senderEmail' => $mailSettings['from_address'] ?? config('mail.from.address'),
                'senderName' => $mailSettings['from_name'] ?? config('mail.from.name'),
                'subject' => $subject ?? null,
                'body' => $body ?? null,
                'extensionType' => 'core',
                'extensionIdentifier' => 'core',
                'source' => 'test_mail',
                'errorMessage' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => __('settings.test_mail_failed'),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * 시스템 캐시 키 접두사.
     *
     * clearCache() 및 getSystemInfo() 에서 공유하여 사용합니다.
     */
    private const SYSTEM_INFO_CACHE_PREFIX = 'settings.system_info';

    /**
     * 시스템 캐시 TTL (초).
     *
     * CPU/메모리 총량 등 거의 변하지 않는 하드웨어 정보가 대부분이고,
     * Windows 환경에서 PowerShell(CIM) 호출이 수백 ms ~ 수 초 걸려
     * 탭 전환 UX를 저해하므로 1시간 동안 재사용한다.
     */
    private const SYSTEM_INFO_CACHE_TTL = 3600;

    /**
     * 시스템 환경 정보를 조회합니다.
     *
     * 하드웨어/환경 정보는 1시간 캐시하며, 실시간성이 요구되는
     * server_time 은 매 호출마다 갱신합니다.
     *
     * 확장이 필터 훅 `core.settings.filter_system_info_sections` 로 더한 카드(`extra_sections`)도 캐시 밖에서 매 요청
     * 새로 모읍니다 — 확장 정보는 캐시 수명과 무관하게 최신이어야 합니다. 검증·리스너 예외 격리는
     * {@see SystemInfoSectionService} 가 맡습니다 (#888).
     *
     * @return array 시스템 정보 배열
     */
    public function getSystemInfo(): array
    {
        $locale = app()->getLocale();
        $key = self::SYSTEM_INFO_CACHE_PREFIX.'.'.$locale;

        $info = $this->cache->remember(
            $key,
            fn () => $this->buildSystemInfo(),
            self::SYSTEM_INFO_CACHE_TTL
        );

        // audit:allow datetime-display-user-timezone reason: 서버 시각 진단값 — 의도된 서버 기준
        $info['server_time'] = now()->format('Y-m-d H:i:s');

        // 확장 카드 — 캐시된 응답에서도 매 요청 새로 계산한다. 리스너 하나가 던져도 이 응답은 계속 200 이다.
        $info['extra_sections'] = app(SystemInfoSectionService::class)->collect();

        return $info;
    }

    /**
     * 시스템 환경 정보 페이로드를 실제로 수집합니다.
     *
     * PowerShell/파일 I/O 가 포함되어 비용이 크므로 getSystemInfo() 에서
     * 캐시로 감싸 호출합니다.
     *
     * @return array 시스템 정보 배열 (server_time 제외)
     */
    private function buildSystemInfo(): array
    {
        return [
            // php_uname / ini_get 은 disable_functions 로 차단될 수 있어 개별 격리한다.
            // audit:allow hosting-blocked-function-requires-guard safeSystemProbe 가 Throwable 을 잡아 「확인 불가」로 접는다 — 룰은 try/catch 를 보지 못한다
            'os_info' => $this->safeSystemProbe('os_info', fn () => php_uname('s').' '.php_uname('r'), __('common.unknown')),
            'web_server' => $_SERVER['SERVER_SOFTWARE'] ?? __('common.unknown'),
            'php_version' => PHP_VERSION,
            'mysql_version' => $this->safeSystemProbe('mysql_version', fn () => $this->getDatabaseInfo(), __('common.unknown')),
            'g7_version' => config('app.version', '1.0.0'),
            'g7_release_year' => config('app.release_year', '2026'),
            'laravel_version' => app()->version(),
            'environment' => app()->environment(),
            'cpu_info' => $this->safeSystemProbe('cpu_info', fn () => $this->getCpuInfo(), __('common.unknown')),
            'memory_usage' => $this->safeSystemProbe('memory_usage', fn () => $this->getMemoryUsage(), $this->unknownUsage()),
            'disk_usage' => $this->safeSystemProbe('disk_usage', fn () => $this->getDiskUsage(), $this->unknownUsage()),
            // audit:allow hosting-blocked-function-requires-guard safeSystemProbe 가 Throwable 을 잡아 「확인 불가」로 떨어뜨린다 (아래 두 줄 동일)
            'php_memory_limit' => $this->safeSystemProbe('php_memory_limit', fn () => ini_get('memory_limit'), __('common.unknown')),
            'max_execution_time' => $this->safeSystemProbe('max_execution_time', fn () => ini_get('max_execution_time').__('settings.seconds'), __('common.unknown')),
            'upload_max_filesize' => $this->safeSystemProbe('upload_max_filesize', fn () => ini_get('upload_max_filesize'), __('common.unknown')),
            // 판정은 App\Support\OpcacheStatus 가 SSoT — 인스톨러 요구사항 화면과 같은 답을 낸다.
            // enabled 가 null 이면 "확인 불가"(ini_get 차단 환경).
            'opcache' => $this->safeSystemProbe('opcache', fn () => $this->getOpcacheStatus(), ['loaded' => false, 'enabled' => null]),
            'install_path' => base_path(),
            'config_path' => storage_path('app/settings'),
            'log_path' => storage_path('logs'),
            'upload_path' => storage_path('app/public'),
            'php_extensions' => $this->safeSystemProbe('php_extensions', fn () => $this->getPhpExtensions(), ['required' => [], 'optional' => [], 'requested' => [], 'requested_by' => []]),
            'database_config' => $this->safeSystemProbe('database_config', fn () => $this->getDatabaseConfig(), ['has_read_write_split' => false, 'write' => [], 'read' => []]),
            'timezone' => config('app.timezone'),
        ];
    }

    /**
     * OPcache 활성화 상태를 조회합니다.
     *
     * 판정은 App\Support\OpcacheStatus 가 SSoT 이며, 인스톨러 요구사항 화면과
     * 같은 답을 냅니다. `enabled` 가 null 이면 ini_get 이 차단된 "확인 불가" 상태입니다.
     *
     * @return array{loaded: bool, enabled: bool|null} OPcache 상태
     */
    protected function getOpcacheStatus(): array
    {
        return OpcacheStatus::probe();
    }

    /**
     * 시스템 정보 probe 를 안전하게 실행합니다.
     *
     * probe 가 예외(ErrorException·Error·disable_functions 로 인한 Error 포함)를
     * 던지면 전파하지 않고 폴백값을 반환하고 경고 로그를 남깁니다. 개별 항목 수집
     * 실패가 system-info API 전체를 500 으로 만들지 않도록 격리합니다.
     *
     * @param  string  $label  실패 로그 식별용 항목명 (예: 'cpu_info')
     * @param  callable  $cb  실행할 probe 콜백
     * @param  mixed  $fallback  실패 시 반환할 폴백 값
     * @return mixed probe 결과 또는 폴백
     */
    private function safeSystemProbe(string $label, callable $cb, mixed $fallback): mixed
    {
        try {
            return $cb();
        } catch (\Throwable $e) {
            Log::warning('시스템 정보 수집 실패', [
                'item' => $label,
                'error' => $e->getMessage(),
            ]);

            return $fallback;
        }
    }

    /**
     * 메모리/디스크 사용량 조회 실패 시 사용할 폴백 배열을 반환합니다.
     *
     * total/used/free/percentage 형식을 memory_usage·disk_usage 와 동일하게 유지합니다.
     *
     * @return array 사용량 폴백 배열
     */
    private function unknownUsage(): array
    {
        // percentage null = 측정 불가 — 화면은 0% 가 아니라 "측정 불가" 로 표기한다 (A-8)
        return [
            'total' => __('common.unknown'),
            'used' => __('common.unknown'),
            'free' => __('common.unknown'),
            'percentage' => null,
        ];
    }

    /**
     * 시스템 캐시를 정리합니다.
     *
     * @return bool 캐시 정리 성공 여부
     */
    public function clearCache(): bool
    {
        try {
            foreach (config('app.supported_locales', ['ko', 'en']) as $locale) {
                $this->cache->forget(self::SYSTEM_INFO_CACHE_PREFIX.'.'.$locale);
            }

            Artisan::call('cache:clear');
            Artisan::call('view:clear');

            // 라우트·config 는 지우는 대신 다시 구워 바꿔 끼운다 — 비운 채 두면 이후 모든 요청이 재파싱하고,
            // 지우는 순간 들어온 요청은 500(`routes-v7.php: No such file`)을 받았다(#882).
            RouteCacheHelper::rebuild();
            ConfigCacheHelper::rebuild();

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 시스템을 최적화합니다 (캐시 생성).
     *
     * @return bool 최적화 성공 여부
     */
    public function optimizeSystem(): bool
    {
        try {
            // config·라우트 캐시는 두 헬퍼로 굽는다 — 새 Application 을 부팅하는 명령을 보존 래퍼(withPreservedContainer)로
            // 감싸고(후속 `app()->terminating()` 예약 보존), 임시 파일에 다 구운 뒤 바꿔 끼운다. 명령을 직접 부르면 명령이
            // 먼저 지운 뒤 다시 써서 그 사이 들어온 요청이 500 을 받았다(#882).
            ConfigCacheHelper::rebuild();
            RouteCacheHelper::rebuild();
            Artisan::call('view:cache');

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 어플리케이션 키를 재생성합니다.
     *
     * 실행 직전에 요청 관리자 본인을 다시 확인한다 — 비밀번호가 있으면 비밀번호, 없으면(외부 인증 전용) 이메일 인증번호로
     * 받은 `verification_token`.
     *
     * @param  array{password?: string|null, verification_token?: string|null}  $credentials  재확인 입력
     * @return array 결과 (success, app_key 또는 error·status)
     *
     * @throws IdentityVerificationRequiredException 비밀번호 없는 관리자가 비밀번호로 확인하려 할 때 (428)
     */
    public function regenerateAppKey(array $credentials): array
    {
        $admin = Auth::user();
        $check = $admin instanceof User
            ? app(ReauthenticationService::class)->verify($admin, $credentials)
            : ReauthenticationService::MISSING;

        // 비밀번호 없는 관리자가 비밀번호 칸으로 왔다 = 그 회원을 모르는 옛 화면(7.1.x)이다. 본인인증을 요구한다(428) —
        // 엔진이 인증 창을 열고 확인이 끝나면 토큰을 붙여 이 요청을 다시 보낸다. \Error 계열이라 호출자 catch 를 지나친다.
        if ($check === ReauthenticationService::PASSWORD_NOT_SET) {
            throw app(ReauthenticationService::class)->challengeFor(request());
        }

        if ($check === ReauthenticationService::TOKEN_INVALID) {
            return ['success' => false, 'error' => __('user.reauthentication_invalid'), 'status' => 422];
        }

        if ($check !== ReauthenticationService::PASSED) {
            return ['success' => false, 'error' => __('settings.invalid_password'), 'status' => 401];
        }

        try {
            $newKey = 'base64:'.base64_encode(random_bytes(32));

            $envPath = base_path('.env');
            $envContent = @file_get_contents($envPath);

            if ($envContent === false) {
                return ['success' => false, 'error' => __('settings.app_key_env_write_failed')];
            }

            $envContent = preg_replace('/^APP_KEY=.*/m', 'APP_KEY='.$newKey, $envContent);

            // PCRE 실패(백트랙 한계 등)는 `null` 이다 — 그것을 쓰면 `.env` 가 빈 파일이 된다.
            if ($envContent === null) {
                return ['success' => false, 'error' => __('settings.app_key_env_write_failed')];
            }

            // 부분 쓰기는 `false` 가 아니라 **쓴 바이트 수**다(디스크 full·quota). 반환값을
            // 버리면 잘린 `.env` 가 「재생성 완료」로 보고되고, 그 파일에는 APP_KEY 도
            // DB 자격증명도 온전히 남아 있지 않아 사이트가 다음 부팅에서 뜨지 않는다.
            // 되돌릴 사본도 없다(제자리 쓰기). 형제 축(인스톨러 `installerWroteFully()` ·
            // `AbstractRootMarkerFileUpdater` · `CoreUpdateService`)이 같은 규율을 쓴다.
            $written = @file_put_contents($envPath, $envContent, LOCK_EX);

            if ($written === false || $written !== strlen($envContent)) {
                return ['success' => false, 'error' => __('settings.app_key_env_write_failed')];
            }

            // .env 변경 반영 + config 캐시 재생성 (clear 만 하면 캐시 비활성 상태로 잔존).
            ConfigCacheHelper::rebuild();

            return [
                'success' => true,
                'app_key' => $this->maskAppKey($newKey),
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => __('settings.app_key_regenerate_failed')];
        }
    }

    /**
     * 앱 키를 마스킹하여 반환합니다.
     *
     * @param  string|null  $key  마스킹할 키
     * @return string 마스킹된 키 문자열
     */
    public function maskAppKey(?string $key = null): string
    {
        $key = $key ?? config('app.key');
        if (empty($key)) {
            return '';
        }

        if (strlen($key) <= 20) {
            return str_repeat('*', strlen($key));
        }

        return substr($key, 0, 12).str_repeat('*', 32);
    }

    /**
     * 데이터베이스 연결 정보를 조회합니다.
     *
     * @return string 데이터베이스 정보 문자열
     */
    private function getDatabaseInfo(): string
    {
        try {
            $connection = DB::connection();
            $driver = $connection->getDriverName();
            $version = $connection->select('SELECT VERSION() as version')[0]->version ?? __('common.unknown');

            return ucfirst($driver).' '.$version;
        } catch (\Throwable $e) {
            Log::warning('시스템 정보 수집 실패', [
                'item' => 'mysql_version',
                'error' => $e->getMessage(),
            ]);

            return __('common.unknown');
        }
    }

    /**
     * 서버의 물리 메모리 사용량을 조회합니다.
     *
     * 디스크 사용량과 동일한 형식(total/used/free/percentage)으로 반환합니다.
     * OS 별 조회 방식:
     * - Linux: /proc/meminfo 파싱 (MemTotal, MemAvailable)
     * - Windows: PowerShell (Get-CimInstance Win32_OperatingSystem)
     *
     * @return array 메모리 사용량 정보 배열
     */
    private function getMemoryUsage(): array
    {
        $total = 0;
        $free = 0;

        if (PHP_OS_FAMILY === 'Linux' && is_readable('/proc/meminfo')) {
            $memInfo = @file_get_contents('/proc/meminfo');
            if ($memInfo !== false) {
                if (preg_match('/MemTotal:\s+(\d+)\s*kB/i', $memInfo, $m)) {
                    $total = (int) $m[1] * 1024;
                }
                if (preg_match('/MemAvailable:\s+(\d+)\s*kB/i', $memInfo, $m)) {
                    $free = (int) $m[1] * 1024;
                } elseif (preg_match('/MemFree:\s+(\d+)\s*kB/i', $memInfo, $m)) {
                    $free = (int) $m[1] * 1024;
                }
            }
        } elseif (PHP_OS_FAMILY === 'Windows' && ExecutionEnvironment::hasFunction('shell_exec')) {
            // disable_functions 차단 시 호출 자체가 fatal Error — 가드 필수 (A-8)
            $script = '$os = Get-CimInstance Win32_OperatingSystem; '
                .'Write-Output $os.TotalVisibleMemorySize; '
                .'Write-Output $os.FreePhysicalMemory';
            $output = @shell_exec('powershell -NoProfile -NonInteractive -Command "'.$script.'" 2>&1');
            if ($output) {
                $lines = array_values(array_filter(array_map('trim', explode("\n", $output)), fn ($l) => $l !== ''));
                if (isset($lines[0], $lines[1]) && is_numeric($lines[0]) && is_numeric($lines[1])) {
                    $total = (int) $lines[0] * 1024;
                    $free = (int) $lines[1] * 1024;
                }
            }
        }

        if ($total <= 0) {
            return $this->unknownUsage();
        }

        $used = max(0, $total - $free);

        return [
            'total' => $this->formatBytes($total),
            'used' => $this->formatBytes($used),
            'free' => $this->formatBytes($free),
            'percentage' => round(($used / $total) * 100, 2),
        ];
    }

    /**
     * 디스크 사용량 정보를 조회합니다.
     *
     * @return array 디스크 사용량 정보 배열
     */
    private function getDiskUsage(): array
    {
        $path = PHP_OS_FAMILY === 'Windows' ? 'C:' : '/';

        // open_basedir 제한 환경에서 disk_*_space 가 warning → ErrorException 이 되지 않도록
        // @ 억제 + numeric 체크 (공개#40, getMemoryUsage 폴백 패턴 재사용).
        // `disable_functions` 축은 호출부의 `safeSystemProbe` 가 `\Throwable` 로 접는다
        // (PHP 8 의 차단 함수 호출은 `Error`). audit 이관 원장의 「조치 불필요」 판정과 같다.
        // audit:allow hosting-blocked-function-requires-guard reason: 차단 축은 호출부 safeSystemProbe 가 \Throwable 로 접는다 (위 주석 참조 — audit 이관 원장의 「조치 불필요」 판정)
        $totalRaw = @disk_total_space($path);
        $freeRaw = @disk_free_space($path);
        $total = is_numeric($totalRaw) ? (int) $totalRaw : 0;
        $free = is_numeric($freeRaw) ? (int) $freeRaw : 0;

        if ($total <= 0) {
            return $this->unknownUsage();
        }

        $used = max(0, $total - $free);

        return [
            'total' => $this->formatBytes($total),
            'used' => $this->formatBytes($used),
            'free' => $this->formatBytes($free),
            'percentage' => round(($used / $total) * 100, 2),
        ];
    }

    /**
     * 바이트 단위를 읽기 쉬운 형태로 변환합니다.
     *
     * @param  int  $bytes  변환할 바이트 수
     * @return string 형식화된 문자열
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2).' '.$units[$i];
    }

    /**
     * CPU 정보를 조회합니다.
     *
     * Windows 11/Server 2025 이상에서는 `wmic`이 제거되어 PowerShell(CIM)을 우선 사용하고,
     * 레거시 환경(wmic 존재)을 위해 폴백도 유지합니다.
     *
     * @return string CPU 정보 문자열
     */
    protected function getCpuInfo(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // disable_functions 차단 시 호출 자체가 fatal Error — 가드 필수 (A-8)
            if (! ExecutionEnvironment::hasFunction('shell_exec')) {
                return __('common.unknown');
            }

            $output = @shell_exec('powershell -NoProfile -NonInteractive -Command "(Get-CimInstance Win32_Processor | Select-Object -First 1).Name" 2>&1');
            if ($output) {
                // 2>&1 로 합쳐진 오류 문장은 시스템 코드페이지(한국어 Windows = CP949)로 출력된다.
                // 정규화하지 않으면 이 값이 시스템 정보 API 응답에 실려 JsonResponse 직렬화가
                // Malformed UTF-8 로 500 을 낸다 (gnuboard/g7#62 와 동형).
                $name = trim(ProcessOutputEncoding::normalize($output));
                if ($name !== '' && ! str_contains(strtolower($name), 'error')) {
                    return $name;
                }
            }

            $output = @shell_exec('wmic cpu get name 2>&1');
            if ($output) {
                $lines = explode("\n", trim(ProcessOutputEncoding::normalize($output)));
                if (isset($lines[1]) && trim($lines[1]) !== '') {
                    return trim($lines[1]);
                }
            }

            return __('common.unknown');
        }

        if (file_exists('/proc/cpuinfo')) {
            $cpuInfo = file_get_contents('/proc/cpuinfo');
            if (preg_match('/model name\s*:\s*(.+)/i', $cpuInfo, $matches)) {
                return trim($matches[1]);
            }
        }

        return __('common.unknown');
    }

    /**
     * 데이터베이스 연결 구성 정보를 조회합니다.
     *
     * Read/Write 분리 구성 여부를 확인하고 민감 정보(비밀번호)를 제외한
     * 연결 정보를 반환합니다.
     *
     * @return array 데이터베이스 구성 정보 배열
     */
    public function getDatabaseConfig(): array
    {
        $mysqlConfig = config('database.connections.mysql', []);

        // Write 설정 (기본 연결 또는 write 키)
        $writeConfig = $this->extractConnectionConfig($mysqlConfig, 'write');

        // Read 설정 (다중 replica 지원)
        $readConfigs = $this->extractReadConfigs($mysqlConfig);

        // Read와 Write가 동일한 경우 Write Only로 취급
        $readConfigs = $this->filterDuplicateReadConfigs($readConfigs, $writeConfig);

        return [
            'has_read_write_split' => ! empty($readConfigs),
            'write' => $writeConfig,
            'read' => $readConfigs,
        ];
    }

    /**
     * Read 설정 중 Write와 동일한 설정을 필터링합니다.
     *
     * Read와 Write의 host, port, database, username이 모두 동일하면
     * 실질적으로 Read-Write 분리가 아니므로 해당 Read 설정을 제외합니다.
     *
     * @param  array  $readConfigs  Read replica 설정 배열
     * @param  array  $writeConfig  Write 설정
     * @return array 중복 제거된 Read 설정 배열
     */
    private function filterDuplicateReadConfigs(array $readConfigs, array $writeConfig): array
    {
        return array_values(array_filter($readConfigs, function ($read) use ($writeConfig) {
            // host, port, database, username이 모두 동일하면 중복으로 간주
            return ! (
                $read['host'] === $writeConfig['host'] &&
                $read['port'] === $writeConfig['port'] &&
                $read['database'] === $writeConfig['database'] &&
                $read['username'] === $writeConfig['username']
            );
        }));
    }

    /**
     * 연결 설정에서 표시할 정보를 추출합니다.
     *
     * @param  array  $config  전체 MySQL 설정
     * @param  string  $type  연결 타입 (write, read)
     * @return array 표시할 연결 정보
     */
    private function extractConnectionConfig(array $config, string $type): array
    {
        // write/read 키가 있으면 해당 설정 사용, 없으면 기본 설정 사용
        $connectionConfig = $config[$type] ?? [];

        // 배열의 첫 번째 요소인 경우 (다중 연결)
        if (isset($connectionConfig[0]) && is_array($connectionConfig[0])) {
            $connectionConfig = $connectionConfig[0];
        }

        // host 값 추출 (배열인 경우 첫 번째 요소 사용)
        $host = $connectionConfig['host'] ?? $config['host'] ?? 'localhost';
        if (is_array($host)) {
            $host = $host[0] ?? 'localhost';
        }

        // port 값 추출 (배열인 경우 첫 번째 요소 사용)
        $port = $connectionConfig['port'] ?? $config['port'] ?? 3306;
        if (is_array($port)) {
            $port = $port[0] ?? 3306;
        }

        // database 값 추출 (배열인 경우 첫 번째 요소 사용)
        $database = $connectionConfig['database'] ?? $config['database'] ?? '';
        if (is_array($database)) {
            $database = $database[0] ?? '';
        }

        // username 값 추출 (배열인 경우 첫 번째 요소 사용)
        $username = $connectionConfig['username'] ?? $config['username'] ?? '';
        if (is_array($username)) {
            $username = $username[0] ?? '';
        }

        return [
            'host' => $host,
            'port' => (int) $port,
            'database' => $database,
            'username' => $username,
            // 비밀번호는 절대 포함하지 않음
        ];
    }

    /**
     * Read replica 설정을 추출합니다.
     *
     * 다중 Read replica를 지원하며, 각 replica의 민감 정보를 제외하고 반환합니다.
     *
     * @param  array  $config  전체 MySQL 설정
     * @return array Read replica 정보 배열 (없으면 빈 배열)
     */
    private function extractReadConfigs(array $config): array
    {
        $readConfig = $config['read'] ?? null;

        if (empty($readConfig)) {
            return [];
        }

        // 단일 read 설정인 경우 (host만 있는 경우)
        if (isset($readConfig['host']) && ! is_array($readConfig['host'])) {
            return [
                [
                    'host' => $readConfig['host'],
                    'port' => (int) ($readConfig['port'] ?? $config['port'] ?? 3306),
                    'database' => $readConfig['database'] ?? $config['database'] ?? '',
                    'username' => $readConfig['username'] ?? $config['username'] ?? '',
                ],
            ];
        }

        // 다중 read replica 설정인 경우 (배열)
        if (isset($readConfig[0]) && is_array($readConfig[0])) {
            return array_map(function ($replica) use ($config) {
                return [
                    'host' => $replica['host'] ?? $config['host'] ?? 'localhost',
                    'port' => (int) ($replica['port'] ?? $config['port'] ?? 3306),
                    'database' => $replica['database'] ?? $config['database'] ?? '',
                    'username' => $replica['username'] ?? $config['username'] ?? '',
                ];
            }, $readConfig);
        }

        // host가 배열인 경우 (Laravel의 sticky read 방식)
        if (isset($readConfig['host']) && is_array($readConfig['host'])) {
            return array_map(function ($host) use ($readConfig, $config) {
                return [
                    'host' => $host,
                    'port' => (int) ($readConfig['port'] ?? $config['port'] ?? 3306),
                    'database' => $readConfig['database'] ?? $config['database'] ?? '',
                    'username' => $readConfig['username'] ?? $config['username'] ?? '',
                ];
            }, $readConfig['host']);
        }

        return [];
    }

    /**
     * PHP 확장 모듈 상태를 조회합니다.
     *
     * 필수·선택 목록은 코어 SSoT(`App\Support\PhpExtensionRequirements`)에서 읽는다 — 인스톨러·코어 업데이트
     * 사전점검·문서와 같은 목록이다. 설치된 모듈·플러그인이 composer.json 으로 요구한 확장은 `requested_by`
     * (확장 → 요구한 확장 목록)로, 코어 목록 밖의 요구 확장(soap 등)의 로드 상태는 `requested` 로 싣는다.
     *
     * @return array{required: array<string, bool>, optional: array<string, bool>, requested: array<string, bool>, requested_by: array<string, list<array{type: string, identifier: string, name: string, level: string}>>} PHP 확장 모듈 상태
     */
    public function getPhpExtensions(): array
    {
        $requirements = PhpExtensionRequirements::load();
        $probe = app(PhpExtensionProbe::class);

        $extensions = [
            'required' => [],
            'optional' => [],
            'requested' => [],
            'requested_by' => $this->collectExtensionPhpRequirements(),
        ];

        foreach ($requirements['required'] as $ext) {
            $extensions['required'][$ext] = $probe->loaded($ext);
        }

        foreach ($requirements['optional'] as $ext) {
            $extensions['optional'][$ext] = $probe->loaded($ext);
        }

        foreach (array_keys($extensions['requested_by']) as $ext) {
            if (! isset($extensions['required'][$ext]) && ! isset($extensions['optional'][$ext])) {
                $extensions['requested'][$ext] = $probe->loaded($ext);
            }
        }

        return $extensions;
    }

    /**
     * 설치된 모듈·플러그인이 composer.json 으로 요구한 PHP 확장을 모읍니다.
     *
     * @return array<string, list<array{type: string, identifier: string, name: string, level: string}>> PHP 확장 이름 => 요구한 확장 목록 (level: require|suggest)
     */
    protected function collectExtensionPhpRequirements(): array
    {
        $extensionManager = app(ExtensionManager::class);
        $requestedBy = [];

        $sources = [
            ['module', 'modules', app(ModuleRepositoryInterface::class)->getAll()],
            ['plugin', 'plugins', app(PluginRepositoryInterface::class)->getAll()],
        ];

        foreach ($sources as [$type, $directory, $records]) {
            foreach ($records as $record) {
                $declared = $extensionManager->getPhpExtensionRequirements($directory, $record->identifier);
                $requester = ['type' => $type, 'identifier' => $record->identifier, 'name' => $record->getLocalizedName()];

                foreach ($declared['required'] as $ext) {
                    $requestedBy[$ext][] = $requester + ['level' => 'require'];
                }

                foreach (array_keys($declared['suggested']) as $ext) {
                    $requestedBy[$ext][] = $requester + ['level' => 'suggest'];
                }
            }
        }

        ksort($requestedBy);

        return $requestedBy;
    }
}
