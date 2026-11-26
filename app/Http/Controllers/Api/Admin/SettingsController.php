<?php

namespace App\Http\Controllers\Api\Admin;

use App\Contracts\Extension\ConsentProviderInterface;
use App\Exceptions\PhpExtensionUnavailableException;
use App\Extension\Consent\ConsentManager;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Settings\EmbedProviderPreviewRequest;
use App\Http\Requests\Settings\PasswordExpiryPreviewRequest;
use App\Http\Requests\Settings\PrivacyPreviewRequest;
use App\Http\Requests\Settings\RegenerateAppKeyRequest;
use App\Http\Requests\Settings\RestoreSettingsRequest;
use App\Http\Requests\Settings\SaveSettingsRequest;
use App\Http\Requests\Settings\TestDriverConnectionRequest;
use App\Http\Requests\Settings\TestMailRequest;
use App\Http\Requests\Settings\TestOutboundProxyRequest;
use App\Http\Requests\Settings\TestTrustedProxyRequest;
use App\Http\Requests\Settings\UpdateSettingRequest;
use App\Http\Resources\SettingsResource;
use App\Http\Responses\DelegatedFileResponse;
use App\Privacy\DormantAccountService;
use App\Privacy\PrivacyRetentionManager;
use App\Services\DriverConnectionTester;
use App\Services\DriverRegistryService;
use App\Services\ExtensionStaticCacheService;
use App\Services\FaviconService;
use App\Services\OutboundProxyTester;
use App\Services\PasswordExpiryService;
use App\Services\Queue\QueueWorkerRegistry;
use App\Services\SearchHistoryService;
use App\Services\SettingsService;
use App\Services\TrustedProxyProbe;
use App\Support\Csp\CspPolicyBuilder;
use App\Support\EnvPriority;
use App\Support\FileServing\FileDelegation;
use App\Support\PublicDiskHardeningProbe;
use App\Support\RuntimeRole;
use App\Support\SearchScopes;
use App\Support\ThumbnailDiskOptions;
use App\Support\TrustedProxyDiagnostic;
use App\Thumbnails\ImageProcessorResolver;
use App\Thumbnails\ThumbnailPresetSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * 관리자용 시스템 설정 컨트롤러
 *
 * 관리자가 시스템 설정을 관리할 수 있는 기능을 제공합니다.
 */
class SettingsController extends AdminBaseController
{
    /**
     * 설정 컨트롤러를 생성합니다.
     *
     * @param  SettingsService  $settingsService  설정 조회·저장 서비스
     * @param  DriverConnectionTester  $driverConnectionTester  드라이버 연결 테스트
     * @param  DriverRegistryService  $driverRegistryService  사용 가능 드라이버 카탈로그
     * @param  OutboundProxyTester  $outboundProxyTester  아웃바운드 프록시 테스트
     * @param  ExtensionStaticCacheService  $staticCacheService  초기 화면 정적 게시
     * @param  PrivacyRetentionManager  $privacyRetentionManager  개인정보 보유기간 처리 진입점
     * @param  DormantAccountService  $dormantAccountService  휴면 계정 생명주기 서비스
     * @param  PasswordExpiryService  $passwordExpiryService  비밀번호 만료 정책 서비스
     * @param  QueueWorkerRegistry  $queueWorkerRegistry  큐 워커 등록·상태 (#139)
     * @param  TrustedProxyProbe  $trustedProxyProbe  신뢰 프록시 사전 검사
     * @param  SearchHistoryService  $searchHistoryService  최근 검색어 서비스 (개인정보 탭 범위 메타, 7.1.5)
     */
    public function __construct(
        private SettingsService $settingsService,
        private DriverConnectionTester $driverConnectionTester,
        private DriverRegistryService $driverRegistryService,
        private OutboundProxyTester $outboundProxyTester,
        private ExtensionStaticCacheService $staticCacheService,
        private PrivacyRetentionManager $privacyRetentionManager,
        private DormantAccountService $dormantAccountService,
        private PasswordExpiryService $passwordExpiryService,
        private QueueWorkerRegistry $queueWorkerRegistry,
        private TrustedProxyProbe $trustedProxyProbe,
        private SearchHistoryService $searchHistoryService
    ) {
        parent::__construct();
    }

    /**
     * 설정 응답에 동봉할 `_meta` 를 만듭니다.
     *
     * 조회와 저장 두 응답이 같은 모양이어야 화면이 저장 직후에도 같은 판정을 이어갑니다 —
     * 한쪽만 필드가 늘면 저장 후 잠금 표시가 조용히 사라집니다.
     *
     * - `limits`: 입력 한계값 (core.settings_limits)
     * - `env_priority_enabled`: `.env` 우선 모드 활성 여부 (안내 배너 표시 판정)
     * - `env_locked`: `.env` 로 잠긴 필드 목록 (프론트엔드 키 기준)
     * - `consent_providers`: 등록된 동의 관리 제공자 `[{id, label}]` (개인정보 탭의 선택 목록, 수집을 꺼 둔 제공자 포함)
     * - `consent_selection`: 지금 선택 상태 `{state, reason, provider_id, provider_label}`
     * - `queue_connection_options`: 큐 워커로 등록할 수 있는 연결 `[{name, driver}]` (sync·null 제외, #139)
     * - `csp`: 콘텐츠 보안 정책 섹션 메타 `{override, preview, auto_hosts, style_override, style_preview, style_auto_hosts}` (#158, 스타일 3키는 7.1.6)
     * - `thumbnail_engine_available`: 이 서버에 쓸 수 있는 이미지 처리 엔진이 있는지 (없으면 업로드 탭이 「썸네일 없이 원본」 안내)
     * - `thumbnail_disk_options`: 썸네일 저장 위치 선택지 `{public: [{id, label}], gated: [{id, label}]}` — label 은 로케일 맵
     * - `thumbnail_server_defaults`: 업로드 탭 「고급」 칸(썸네일 서버 보호 값)을 비웠을 때 쓸 값 — `.env` G7_THUMBNAIL_* 가 있으면 그 값,
     *   없으면 기본값. 화면은 이 값을 칸의 placeholder 로 보인다
     * - `search_scopes`: 최근 검색어 범위 `[{scope, owner, owner_label, label, default_max_count, default_retention_days}]` (개인정보 탭, 7.1.5)
     * - `embed_extension_providers`: 확장이 제공한 외부 영상 서비스 `[{key, extension, id, name}]` (본문 콘텐츠 탭, 7.1.5)
     * - `file_delegation`: 웹서버 파일 위임 요약 `{mode, base_path, accel_prefix, accel_mapping, nginx_alias_path}` — 지금 적용 중인 값이다(저장값이
     *   아니라 `.env` 잠금까지 반영한 config). 드라이버 탭이 웹서버 설정 안내를 실제 경로로 채운다
     * - `public_disk_hardening`: `/storage` 직접 주소 보호 헤더 점검 결과 `{ok, nosniff, disposition, status, checked_at, rules_written}` 또는 null (드라이버 탭 경고, 7.1.6 — `rules_written=false` 는 규칙 파일을 지금 설정으로 쓰지 못함)
     *
     * @return array<string, mixed> 응답 메타 배열
     */
    private function buildSettingsMeta(): array
    {
        $consent = app(ConsentManager::class);

        return [
            'limits' => config('core.settings_limits', []),
            'env_priority_enabled' => EnvPriority::enabled(),
            // 키-값 맵이다 — 잠긴 키가 없으면 `[]` 가 아니라 `{}` 로 낸다(settings.md 의 약속)
            'env_locked' => (object) $this->settingsService->envLockedMeta(),
            'consent_providers' => array_values(array_map(
                static fn (ConsentProviderInterface $provider): array => [
                    'id' => $provider->getId(),
                    'label' => $provider->getLabel(),
                ],
                $consent->registered(),
            )),
            'consent_selection' => $consent->selection()->toArray(),
            'queue_connection_options' => $this->queueWorkerRegistry->registrableConnections(),
            'csp' => $this->buildCspMeta(),
            // 파비콘 우선순위 안내 — 일반 탭 파비콘 칸 아래 「현재 ○○ 화면은 △△ 템플릿 설정의 파비콘이 우선 적용됩니다」
            'favicon' => $this->buildFaviconMeta(),
            'thumbnail_engine_available' => app(ImageProcessorResolver::class)->resolve() !== null,
            'thumbnail_disk_options' => app(ThumbnailDiskOptions::class)->all(),
            'thumbnail_server_defaults' => array_map('intval', (array) config('core.thumbnail_server_defaults', [])),
            'search_scopes' => $this->searchHistoryService->settingsMeta(),
            'embed_extension_providers' => $this->settingsService->embedExtensionProviderOptions(),
            'file_delegation' => FileDelegation::describe(),
            // 비공개 첨부 저장소 카드 — .env 로 고정된 주체, 저장된 선택이 이 서버에 없는 디스크인지
            'private_attachment' => $this->settingsService->privateAttachmentMeta(),
            'public_disk_hardening' => PublicDiskHardeningProbe::cached(),
        ];
    }

    /**
     * 파비콘 우선순위 요약 — 해석 실패(템플릿 행 없음 등)는 안내를 숨기는 null 로.
     *
     * @return array<string, mixed>|null 화면 종류별 요약
     */
    private function buildFaviconMeta(): ?array
    {
        try {
            return app(FaviconService::class)->describe();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 보안 탭 「콘텐츠 보안 정책」 섹션이 읽는 메타를 만듭니다 (#158).
     *
     * - `override`: `.env G7_CSP_MODE` 탈출구 값 (없으면 null) — 있으면 화면의 모드 선택을 잠근다
     * - `preview`: 지금 저장값으로 붙을 정책 문자열 (모드가 끔이어도 미리 보여 준다)
     * - `auto_hosts`: 활성 확장 선언에서 자동 합산된 호스트
     * - `style_override`: `.env G7_CSP_STYLE_MODE` 탈출구 값 (없으면 null) — 있으면 스타일 모드 선택을 잠근다 (7.1.6)
     * - `style_preview`: 지금 저장값으로 붙을 스타일 정책 문자열 (모드가 끔이어도 미리 보여 준다)
     * - `style_auto_hosts`: 활성 템플릿의 외부 스타일시트와 켜진 확장의 `trusted_style_hosts` 선언에서 자동 합산된 호스트
     *
     * @return array{override: string|null, preview: string, auto_hosts: array<int, string>, style_override: string|null, style_preview: string, style_auto_hosts: array<int, string>} CSP 메타
     */
    private function buildCspMeta(): array
    {
        $builder = app(CspPolicyBuilder::class);

        return [
            'override' => $builder->modeOverride(),
            'preview' => $builder->build(),
            'auto_hosts' => $builder->autoHosts(),
            'style_override' => $builder->styleModeOverride(),
            'style_preview' => $builder->buildStyle(),
            'style_auto_hosts' => $builder->styleAutoHosts(),
        ];
    }

    /**
     * 응답에 실을 설정을 다듬습니다.
     *
     * `drivers.queue_names` 는 연결 => 큐 목록 맵이다 — 비었을 때 PHP 배열 그대로 내면 `[]` 가 돼 화면이 키를
     * 붙여도 저장 본문에서 사라진다(빈 객체가 빈 배열로 바뀌는 것과 같은 결함군). 그래서 늘 객체로 낸다.
     *
     * 업로드 탭 썸네일 규격 표는 저장 행 그대로가 아니라 레지스트리가 합친 표(고정·og·켜진 확장·운영자, 행마다
     * `kind`)로 낸다. 저장 행에는 og 와 확장 기본값이 없고, 키가 없는 기존 설치는 아예 비어 있기 때문이다.
     * `thumbnail_presets` 와 `thumbnail_presets_rows` 에 같은 표를 싣는다 — 화면이 어느 쪽에 묶어도 저장 본문
     * (`upload.thumbnail_presets`)과 오류 키(`upload.thumbnail_presets.{i}.name`)의 행 번호가 표와 같다.
     * `thumbnail_presets_rows` 는 응답 전용이라 저장 때 버린다(SettingsService::prepareUploadThumbnailSettings).
     *
     * @param  array<string, mixed>  $settings  getAllSettings() 결과
     * @return array<string, mixed> 응답용 설정
     */
    private function presentSettings(array $settings): array
    {
        if (isset($settings['drivers']) && is_array($settings['drivers'])) {
            $settings['drivers']['queue_names'] = (object) (is_array($settings['drivers']['queue_names'] ?? null) ? $settings['drivers']['queue_names'] : []);
        }

        if (! isset($settings['upload']) || ! is_array($settings['upload'])) {
            $settings['upload'] = [];
        }

        $thumbnailRows = app(ThumbnailPresetSettings::class)->presentRows();
        $settings['upload']['thumbnail_presets'] = $thumbnailRows;
        $settings['upload']['thumbnail_presets_rows'] = $thumbnailRows;

        // 최근 검색어 범위 행(7.1.5) — 지금 선언된 범위마다 한 행, 선언 순서, 적용값으로 채운다(새로 켠 확장은 선언값).
        if (isset($settings['privacy']) && is_array($settings['privacy'])) {
            $settings['privacy'][SearchScopes::SETTINGS_ROWS_KEY] = SearchScopes::presentRows($settings['privacy'][SearchScopes::SETTINGS_ROWS_KEY] ?? []);
        }

        $settings['available_drivers'] = $this->driverRegistryService->getAllAvailableDrivers();
        $settings['_meta'] = $this->buildSettingsMeta();

        return $settings;
    }

    /**
     * 모든 시스템 설정을 조회합니다.
     *
     * @return JsonResponse 시스템 설정 목록을 포함한 JSON 응답
     */
    public function index(): JsonResponse
    {
        try {
            $settings = $this->presentSettings($this->settingsService->getAllSettings());

            return $this->success('settings.fetch_success',
                (new SettingsResource($settings))->toArray(request()) + [
                    // 이 요청을 처리한 서버의 값 — 공유 설정이 아니다. 여러 서버라면 스케줄을 돌리는 서버를 확인하라는 안내에 쓴다
                    'scheduler_enabled_on_this_server' => RuntimeRole::schedulerEnabled(),
                    'runtime_role' => RuntimeRole::current(),
                ]
            );
        } catch (\Exception $e) {
            return $this->error('settings.fetch_failed', 500, $e->getMessage());
        }
    }

    /**
     * 여러 시스템 설정을 일괄 저장합니다.
     *
     * 저장 성공 시 전체 settings를 응답에 포함하여
     * 프론트엔드에서 새로고침 없이 전역 상태를 업데이트할 수 있도록 합니다.
     *
     * @param  SaveSettingsRequest  $request  설정 저장 요청 데이터
     * @return JsonResponse 저장 결과 JSON 응답
     */
    public function store(SaveSettingsRequest $request): JsonResponse
    {
        try {
            $result = $this->settingsService->saveSettings($request->validated());

            if ($result) {
                // 저장 후 전체 설정 반환 (관리자 UI 상태 업데이트용)
                $allSettings = $this->presentSettings($this->settingsService->getAllSettings());

                return $this->success('settings.save_success', [
                    'settings' => $allSettings,
                    // 전역 상태(`_global.settings`)용 공개 설정 — 계산 필드(site_logo_url·site_favicon_url)가 실려 있다.
                    // 화면이 저장 직후 `settings`(관리자 형태, 계산 필드 없음)로 전역을 덮으면 상단 로고가 새로고침 전까지 사라졌다.
                    'frontend_settings' => $this->settingsService->getFrontendSettings(),
                    // 저장은 했지만 알려야 할 조합
                    'warnings' => $this->settingsService->storageWarnings(
                        is_array($allSettings['drivers'] ?? null) ? $allSettings['drivers'] : [],
                        is_array($allSettings['upload'] ?? null) ? $allSettings['upload'] : [],
                    ),
                ]);
            } else {
                return $this->error('settings.save_failed');
            }
        } catch (ValidationException $e) {
            return $this->error('settings.save_failed', 422, $e->errors());
        } catch (\Exception $e) {
            return $this->error('settings.save_error', 500, $e->getMessage());
        }
    }

    /**
     * 특정 키의 시스템 설정을 조회합니다.
     *
     * @param  string  $key  조회할 설정 키
     * @return JsonResponse 설정 값을 포함한 JSON 응답
     */
    public function show(string $key): JsonResponse
    {
        try {
            $value = $this->settingsService->getSetting($key);

            return $this->success('settings.fetch_success', [
                'key' => $key,
                'value' => $value,
            ]);
        } catch (\Exception $e) {
            return $this->error('settings.fetch_failed', 500, $e->getMessage());
        }
    }

    /**
     * 특정 키의 시스템 설정을 업데이트합니다.
     *
     * @param  string  $key  업데이트할 설정 키
     * @param  UpdateSettingRequest  $request  설정 업데이트 요청 데이터
     * @return JsonResponse 업데이트 결과 JSON 응답
     */
    public function update(string $key, UpdateSettingRequest $request): JsonResponse
    {
        try {
            $result = $this->settingsService->setSetting($key, $request->validated()['value']);

            if ($result) {
                return $this->success('settings.update_success');
            } else {
                return $this->error('settings.update_failed');
            }
        } catch (ValidationException $e) {
            return $this->error('settings.update_failed', 422, $e->errors());
        } catch (\Exception $e) {
            return $this->error('settings.update_error', 500, $e->getMessage());
        }
    }

    /**
     * 시스템 환경 정보를 조회합니다.
     *
     * @return JsonResponse 시스템 정보를 포함한 JSON 응답
     */
    public function systemInfo(): JsonResponse
    {
        try {
            $systemInfo = $this->settingsService->getSystemInfo();

            return $this->success('common.success', $systemInfo);
        } catch (\Throwable $e) {
            Log::error('시스템 정보 조회 실패', ['error' => $e->getMessage()]);

            return $this->error('common.error_occurred', 500, $e->getMessage());
        }
    }

    /**
     * 신뢰 프록시(리버스 프록시) 설정 진단 결과를 조회합니다 (#124).
     *
     * 이 엔드포인트는 진단만 돌려준다. 값은 환경설정 > 고급의 신뢰 프록시 카드에서
     * 저장하고(`debug.trusted_proxies`), 저장 전 확인은 {@see testTrustedProxy()} 가 맡는다.
     *
     * **편집을 화면에 연 이유**: 원래 이 값은 `.env` 전용이었다. 그 전제는 셸로
     * `.env` 를 고칠 수 있다는 것인데, 무CLI·고정 docroot 설치에는 그 통로가 없어 진단이
     * "고칠 수 없는 경고" 로 끝났다. 남는 위험 — 관리자 계정이 탈취되면 공격자가 신뢰
     * 프록시를 열어 X-Forwarded-For 를 위조할 수 있다 — 은 사라지지 않았다. 다만 그 시점의
     * 공격자는 이미 `core.settings.update` 를 쥐고 있어 메일·디버그·드라이버 설정까지
     * 바꿀 수 있으므로, 이 키 하나를 잠가서 얻는 실익이 통로 부재의 대가보다 작다고 봤다.
     * `.env` 를 배포 기준값으로 쓰는 설치는 `G7_ENV_PRIORITY` 로 이 키를 잠글 수 있다.
     *
     * 판정 대상은 관리자 브라우저의 **실제 요청**이므로 현재 요청을 그대로 쓴다.
     * 입력을 받지 않는 읽기 전용 조회라 FormRequest 를 두지 않는다.
     *
     * @return JsonResponse 진단 결과 JSON 응답
     */
    public function trustedProxy(): JsonResponse
    {
        return $this->success('common.success', TrustedProxyDiagnostic::forRequest(request()));
    }

    /**
     * 웹서버 파일 위임이 실제로 동작하는지 확인할 작은 파일을 내보냅니다 (2단계 프로브).
     *
     * 코어 파일 헬퍼(`fileResponse()`)를 그대로 지나므로 **저장된(적용 중인) 위임 설정**으로 나간다 — 위임이 켜져 있으면
     * 앱은 본문 대신 `X-Sendfile`/`X-Accel-Redirect` 헤더만 세우고, 웹서버가 그 헤더를 소비해 파일을 보내야 본문이 도착한다.
     * 화면은 받은 본문의 `data.probe` 값으로 판정한다 — 본문이 비었거나(웹서버가 헤더를 무시) 404(nginx internal 위치 없음)면
     * 미구성이다. 서버가 자기 자신에게 요청을 보내는 판정은 아웃바운드 정책·공유 호스팅 제약 때문에 쓰지 않는다.
     *
     * 이 응답이 위임되지 않으면(적용 중인 방식이 「사용 안 함」이거나 파일이 매핑 밖) 파일을 PHP 가 보내게 되므로 본문을
     * 내지 않고 `data.reason`(`inactive`·`skipped`)으로 알린다 — 본문이 도착하면 화면이 「웹서버가 보냈다」로 오판한다.
     *
     * 판정이 매번 새 응답이어야 하므로 `Cache-Control: no-store` 로 덮는다. 입력을 받지 않는 읽기 전용 조회다.
     *
     * @return BinaryFileResponse|Response|JsonResponse 프로브 파일 응답, 304 응답, 또는 위임 없음 결과
     */
    public function fileDelegationProbe(): BinaryFileResponse|Response|JsonResponse
    {
        $response = $this->fileResponse(
            resource_path('probes/file-delegation-probe.json'),
            'application/json',
            0,
            immutable: false,
            skipCompression: true
        );

        if ($response instanceof DelegatedFileResponse
            && ($outcome = $response->delegationOutcome(request())) !== DelegatedFileResponse::OUTCOME_APPLIED) {
            $response = $this->success('common.success', ['probe' => null, 'delegated' => false, 'reason' => $outcome]);
        }

        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * 신뢰 프록시 후보값을 저장 전에 검사합니다.
     *
     * 저장하지 않고, 이번 요청에 그 값을 적용했을 때 방문자 IP·HTTPS 인식이 어떻게 해석되는지
     * 돌려준다. 이 설정은 틀려도 오류를 내지 않으므로(방문자 IP 만 조용히 어긋난다) 저장 후에
     * 알아차릴 단서가 없고, HTTPS 종단 구성에서 잘못 저장하면 관리자 화면 자체에 못 들어가
     * 되돌릴 통로가 사라진다. 그 사고를 막는 자리다.
     *
     * 검사 대상은 저장된 설정이 아니라 이번 요청이 제출한 값이다.
     *
     * @param  TestTrustedProxyRequest  $request  검증된 요청
     * @return JsonResponse 검사 결과
     */
    public function testTrustedProxy(TestTrustedProxyRequest $request): JsonResponse
    {
        $result = $this->trustedProxyProbe->probe(
            $request,
            (array) ($request->validated()['trusted_proxies'] ?? [])
        );

        // 후보값이 기대와 다른 결과를 내는 것은 요청 처리 실패가 아니라 진단 결과다 —
        // 200 으로 결과를 돌려주고 판정은 페이로드의 `verdict` 가 말한다
        // (아웃바운드 프록시 연결 테스트와 같은 규약).
        return $this->success('settings.trusted_proxy_probe_'.$result['verdict'], $result);
    }

    /**
     * 외부 영상 서비스 추가 창의 예시 주소 확인 — 꺼낸 id 와 만들어질 플레이어 주소.
     *
     * 저장하지 않는다. 형식이 틀리면(검증 위반) 필드별 문구도 함께 돌려줘 창이 저장 전에 알려 줄 수 있게 한다.
     *
     * @param  EmbedProviderPreviewRequest  $request  검증된 요청
     * @return JsonResponse `{matched, id, player_url, errors}`
     */
    public function embedProviderPreview(EmbedProviderPreviewRequest $request): JsonResponse
    {
        $preview = $this->settingsService->previewEmbedProvider((array) $request->validated('provider'), (string) $request->validated('sample_url'));

        return $this->success('common.success', [
            'matched' => $preview['matched'],
            'id' => $preview['id'],
            'player_url' => $preview['player_url'],
            'errors' => (object) $preview['errors'],
        ]);
    }

    /**
     * 개인정보 보유기간·휴면의 처리 대상 건수를 미리 조회합니다.
     *
     * 화면의 「대상 N건」 배지가 쓰는 값이며, 커맨드의 `--dry-run` 과 **같은 조건식**을 지납니다 —
     * 화면에서 본 숫자와 실제 처리 건수가 어긋나면 운영자가 규모를 오판한 채 켜게 됩니다.
     *
     * **기능이 꺼져 있어도 건수를 돌려줍니다** — 켜기 전에 규모와 명단을 확인하는 자리이기
     * 때문입니다(2026-09-23 확정). "꺼짐" 과 "대상 0건" 은 `enabled` 로 구분합니다.
     * `can_view_targets` 는 요청 관리자가 「더보기」 목록을 볼 권한(설정 읽기 + 도메인 읽기)을
     * 가졌는지다 — 없으면 화면이 버튼을 그리지 않는다.
     *
     * 읽기 전용이며 입력은 없습니다 — FormRequest 는 요청 관리자를 얻는 자리입니다.
     *
     * @param  PrivacyPreviewRequest  $request  현재 요청 (목록 권한 판정용, 입력 없음)
     * @return JsonResponse 핸들러별·휴면 단계별 대상 건수 JSON 응답
     */
    public function privacyPreview(PrivacyPreviewRequest $request): JsonResponse
    {
        $user = $request->user();
        $retention = [];

        foreach ($this->privacyRetentionManager->preview() as $id => $row) {
            $handler = $this->privacyRetentionManager->handler($id);
            $retention[$id] = $row + [
                'can_view_targets' => $handler !== null && $this->privacyRetentionManager->canViewTargets($handler, $user),
            ];
        }

        return $this->success('common.success', [
            'retention' => $retention,
            'dormant' => [
                'enabled' => $this->dormantAccountService->isEnabled(),
                'withdraw_enabled' => $this->dormantAccountService->isWithdrawEnabled(),
                'can_view_targets' => $this->privacyRetentionManager->canViewTargetList(DormantAccountService::TARGET_PERMISSIONS, $user),
                ...$this->dormantAccountService->preview(),
            ],
        ]);
    }

    /**
     * 비밀번호 만료 처리 대상 건수를 미리 봅니다 (데이터를 바꾸지 않음).
     *
     * 배치가 실제로 쓰는 것과 **같은 쿼리**로 사전 안내·만료 처리 대상을 센다. 기능이 꺼져 있어도
     * 저장값으로 센다 — 켜기 전에 규모를 보는 자리다. "꺼짐" 과 "대상 0건" 은 `enabled` 로 구분하고,
     * `can_view_targets` 는 요청 관리자가 명단(설정 읽기 + 회원 읽기, 전체 범위)을 볼 수 있는지다.
     *
     * @param  PasswordExpiryPreviewRequest  $request  현재 요청 (명단 권한 판정용, 입력 없음)
     * @return JsonResponse {enabled, started_at, notice, expire, batch_size, can_view_targets}
     */
    public function passwordExpiryPreview(PasswordExpiryPreviewRequest $request): JsonResponse
    {
        return $this->success('common.success', $this->passwordExpiryService->preview($request->user()));
    }

    /**
     * 초기 화면 정적 파일(부트스트랩 리소스 정적 게시) 상태를 조회합니다.
     *
     * CLI `ext-static:status` 와 **같은 판정**(`ExtensionStaticCacheService::statusReport`)을 돌려준다.
     * 읽기 전용이며 입력이 없어 FormRequest 를 두지 않는다 (`trustedProxy` 와 동형).
     *
     * @return JsonResponse 상태 보고서 JSON 응답
     */
    public function staticCacheStatus(): JsonResponse
    {
        return $this->success('settings.static_cache_status_loaded', $this->staticCacheService->statusReport());
    }

    /**
     * 큐 워커 상태(연결·큐별 대기 건수·마지막 워커 신호·경고)를 조회합니다 (#139).
     *
     * 드라이버 탭 「큐 워커」 구역이 쓴다. 읽기 전용이며 입력이 없어 FormRequest 를 두지 않는다
     * (`staticCacheStatus` 와 동형). 판정은 등록 목록으로 하고 하트비트는 표시만 한다.
     *
     * @return JsonResponse 상태 보고서 JSON 응답
     */
    public function queueStatus(): JsonResponse
    {
        return $this->success('settings.queue_status_loaded', $this->queueWorkerRegistry->statusReport());
    }

    /**
     * 초기 화면 정적 파일을 지금 다시 만듭니다 (관리자 수동 복구).
     *
     * 캐시 버전을 올리고 현재 버전을 강제 재게시한다. 게시 실패·게시가 쓰이지 않는 환경은
     * 요청 처리 실패가 아니라 **진단 결과**라 HTTP 200 으로 돌려주고 성공 여부는 페이로드
     * (`republished`)가 말한다 — 연결 테스트·드라이버 테스트와 같은 규약. 사이트는 어느 경우에도
     * API 폴백으로 정상이다.
     *
     * @return JsonResponse 재게시 결과 + 상태 보고서 JSON 응답
     */
    public function republishStaticCache(): JsonResponse
    {
        $result = $this->staticCacheService->republish();

        return $this->success(
            ($result['republished'] ?? false) ? 'settings.static_cache_republished' : 'settings.static_cache_republish_failed',
            $result
        );
    }

    /**
     * 시스템 캐시를 정리합니다.
     *
     * @return JsonResponse 캐시 정리 결과 JSON 응답
     */
    public function clearCache(): JsonResponse
    {
        try {
            $result = $this->settingsService->clearCache();

            if ($result) {
                return $this->success('settings.cache_clear_success');
            } else {
                return $this->error('settings.cache_clear_failed');
            }
        } catch (\Exception $e) {
            return $this->error('settings.cache_clear_error', 500, $e->getMessage());
        }
    }

    /**
     * 시스템을 최적화합니다 (캐시 생성).
     *
     * @return JsonResponse 최적화 결과 JSON 응답
     */
    public function optimizeSystem(): JsonResponse
    {
        try {
            $result = $this->settingsService->optimizeSystem();

            if ($result) {
                return $this->success('settings.optimize_success');
            } else {
                return $this->error('settings.optimize_failed');
            }
        } catch (\Exception $e) {
            return $this->error('settings.optimize_error', 500, $e->getMessage());
        }
    }

    /**
     * 데이터베이스 백업 — 아직 제공하지 않는 기능임을 알립니다.
     *
     * 코어에는 DB 덤프 수단이 없어 이 엔드포인트는 구현된 적이 없다.
     * 종전에는 존재하지 않는 `SettingsService::backupDatabase()` 를 호출했고,
     * PHP 가 던지는 `Error` 는 `catch (\Exception)` 에 걸리지 않아 그대로 500 이 되면서
     * 내부 메서드 이름까지 응답에 실려 나갔다 (공개 #115 부록 B3).
     *
     * 기능 부재는 서버 고장이 아니므로 501(Not Implemented)로 답한다.
     * 설정 파일 백업은 `POST /api/admin/settings/backup` 이 담당한다.
     *
     * @return JsonResponse 미제공 안내 JSON 응답 (501)
     */
    public function backupDatabase(): JsonResponse
    {
        return $this->error('settings.database_backup_unavailable', 501);
    }

    /**
     * 현재 앱 키를 조회합니다 (마스킹된 형태).
     *
     * @return JsonResponse 마스킹된 앱 키 JSON 응답
     */
    public function getAppKey(): JsonResponse
    {
        try {
            $maskedKey = $this->settingsService->maskAppKey();

            return $this->success('common.success', [
                'app_key' => $maskedKey,
            ]);
        } catch (\Exception $e) {
            return $this->error('common.error_occurred', 500, $e->getMessage());
        }
    }

    /**
     * 앱 키를 재생성합니다.
     *
     * @param  RegenerateAppKeyRequest  $request  앱 키 재생성 요청 데이터
     * @return JsonResponse 재생성된 앱 키 JSON 응답
     */
    public function regenerateAppKey(RegenerateAppKeyRequest $request): JsonResponse
    {
        try {
            $result = $this->settingsService->regenerateAppKey($request->validated());

            if (! $result['success']) {
                return $this->error($result['error'], $result['status'] ?? 401);
            }

            return $this->success('settings.app_key_regenerated', [
                'app_key' => $result['app_key'],
            ]);
        } catch (\Exception $e) {
            return $this->error('settings.app_key_regenerate_failed', 500, $e->getMessage());
        }
    }

    /**
     * 설정을 백업합니다.
     *
     * @return JsonResponse 백업 결과 JSON 응답
     */
    public function backup(): JsonResponse
    {
        try {
            $backupPath = $this->settingsService->backupSettings();

            return $this->success('settings.backup_success', [
                'backup_path' => $backupPath,
            ]);
        } catch (PhpExtensionUnavailableException $e) {
            // 선택 확장(zip) 부재는 서버 장애가 아니라 환경 조건이다 — 원인을 알리는 422 (#151)
            return $this->error($e->messageKey, 422, null, ['extension' => $e->extension]);
        } catch (\Exception $e) {
            return $this->error('settings.backup_failed', 500, $e->getMessage());
        }
    }

    /**
     * 백업에서 설정을 복원합니다.
     *
     * @param  RestoreSettingsRequest  $request  복원 요청 데이터 (백업 경로)
     * @return JsonResponse 복원 결과 JSON 응답
     */
    public function restore(RestoreSettingsRequest $request): JsonResponse
    {
        try {
            $backupPath = $request->validated('backup_path');

            $result = $this->settingsService->restoreSettings($backupPath);

            if ($result) {
                return $this->success('settings.restore_success');
            }

            return $this->error('settings.restore_failed');
        } catch (PhpExtensionUnavailableException $e) {
            return $this->error($e->messageKey, 422, null, ['extension' => $e->extension]);
        } catch (\Exception $e) {
            return $this->error('settings.restore_error', 500, $e->getMessage());
        }
    }

    /**
     * 테스트 메일을 발송합니다.
     *
     * @param  TestMailRequest  $request  테스트 메일 발송 요청 데이터
     * @return JsonResponse 발송 결과 JSON 응답
     */
    public function testMail(TestMailRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $toEmail = $validated['to_email'];
            $mailSettings = collect($validated)->except('to_email')->filter(fn ($v) => $v !== null)->toArray();

            $result = $this->settingsService->sendTestMail($toEmail, $mailSettings);

            if ($result['success']) {
                return $this->success($result['message'], [
                    'subject' => $result['subject'],
                    'body' => $result['body'],
                ]);
            }

            return $this->error($result['message'], 500, $result['error'] ?? null);
        } catch (\Exception $e) {
            return $this->error('settings.test_mail_error', 500, $e->getMessage());
        }
    }

    /**
     * 드라이버 연결을 테스트합니다.
     *
     * S3, Redis, Memcached, Websocket 등 외부 서비스 드라이버의
     * 연결 상태를 테스트합니다.
     *
     * @param  TestDriverConnectionRequest  $request  드라이버 테스트 요청 데이터
     * @return JsonResponse 테스트 결과 JSON 응답
     */
    public function testDriverConnection(TestDriverConnectionRequest $request): JsonResponse
    {
        try {
            $settings = $request->validated();
            $result = $this->driverConnectionTester->testAll($settings);

            if ($result['all_passed']) {
                return $this->success('settings.driver_test_success', $result);
            }

            // 일부 테스트 실패 시에도 결과 반환 (성공 상태지만 all_passed가 false)
            return $this->success('settings.driver_test_partial', $result);
        } catch (\Exception $e) {
            return $this->error('settings.driver_test_error', 500, $e->getMessage());
        }
    }

    /**
     * 아웃바운드 프록시 연결을 테스트합니다.
     *
     * 저장하기 전에 프록시가 실제로 동작하는지, 그리고 그 프록시를 거쳐 나갔을 때 상대편에
     * 어떤 IP 로 보이는지 확인합니다. 출발지 IP 는 운영자가 결제사·외부 서비스에 등록해야
     * 하는 값이라 결과의 핵심입니다.
     *
     * 검사 대상은 저장된 설정이 아니라 이번 요청이 제출한 값입니다.
     *
     * @param  TestOutboundProxyRequest  $request  검증된 요청
     * @return JsonResponse 검사 결과
     */
    public function testOutboundProxy(TestOutboundProxyRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $result = $this->outboundProxyTester->test(
            (string) $validated['outbound_proxy'],
            (array) ($validated['outbound_proxy_bypass'] ?? [])
        );

        // 연결 실패는 요청 처리 실패가 아니라 진단 결과다 — 200 으로 결과를 돌려주고
        // 성공 여부는 페이로드가 말한다 (드라이버 연결 테스트와 같은 규약).
        return $this->success($result['message_key'], $result);
    }
}
