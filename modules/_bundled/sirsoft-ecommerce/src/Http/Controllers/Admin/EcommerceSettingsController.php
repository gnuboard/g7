<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Admin;

use App\Extension\HookManager;
use App\Helpers\PermissionHelper;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Seo\Contracts\SeoCacheManagerInterface;
use App\Services\DriverRegistryService;
use App\Services\NotificationChannelService;
use App\Services\NotificationDefinitionService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ShippingApiAuthType;
use Modules\Sirsoft\Ecommerce\Enums\ShippingApiHttpMethod;
use Modules\Sirsoft\Ecommerce\Enums\ShippingApiRequestField;
use Modules\Sirsoft\Ecommerce\Enums\ShippingApiResponseType;
use Modules\Sirsoft\Ecommerce\Exceptions\ClaimReasonException;
use Modules\Sirsoft\Ecommerce\Exceptions\ShippingCarrierOperationException;
use Modules\Sirsoft\Ecommerce\Exceptions\ShippingTypeOperationException;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\GetSettingRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreBanksRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreEcommerceSettingsRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateSettingRequest;
use Modules\Sirsoft\Ecommerce\Services\ClaimReasonService;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\ShippingCarrierService;
use Modules\Sirsoft\Ecommerce\Services\ShippingTypeService;

/**
 * 이커머스 모듈 환경설정 컨트롤러
 *
 * 이커머스 모듈의 환경설정을 관리하는 API를 제공합니다.
 */
class EcommerceSettingsController extends AdminBaseController
{
    /**
     * 설정과 DB 카탈로그를 관리하는 서비스를 주입합니다.
     *
     * @param  EcommerceSettingsService  $settingsService  설정 저장·조회 서비스
     * @param  ShippingCarrierService  $carrierService  배송사 서비스
     * @param  ShippingTypeService  $shippingTypeService  배송 유형 서비스
     * @param  ClaimReasonService  $claimReasonService  클레임 사유 서비스
     */
    public function __construct(
        private EcommerceSettingsService $settingsService,
        private ShippingCarrierService $carrierService,
        private ShippingTypeService $shippingTypeService,
        private ClaimReasonService $claimReasonService
    ) {}

    /**
     * 모든 이커머스 설정을 조회합니다.
     *
     * @return JsonResponse 설정 목록을 포함한 JSON 응답
     */
    public function index(): JsonResponse
    {
        try {
            $settings = $this->settingsWithCatalogs();
            $settings['available_pg_providers'] = $this->settingsService->getRegisteredPgProviders();
            // 현금영수증 발급 프로바이더 후보 — 결제 플러그인이 훅으로 자신을 등록한다.
            // 발급 PG 와 결제 PG 는 독립 선택이므로 목록도 별도로 내린다 (KG 결제 + 토스 발급 등).
            $settings['available_cash_receipt_providers'] = $this->settingsService->getRegisteredCashReceiptProviders();
            // 공개 자산 디스크 선택지 — 카탈로그 SSoT 는 코어 DriverRegistryService 게터 한 곳
            $settings['available_public_asset_disks'] = app(DriverRegistryService::class)
                ->getAvailableDrivers('public_asset');
            $settings['abilities'] = [
                'can_update' => PermissionHelper::check('sirsoft-ecommerce.settings.update', request()->user()),
            ];

            // 화면의 숫자 입력 경계값 — 저장 규칙과 같은 출처를 내려준다.
            // 화면이 리터럴을 들면 규칙이 바뀔 때 따라오지 못해 "화면은 받는데 저장은 422" 가 된다.
            $settings['_meta'] = ['limits' => config('sirsoft-ecommerce.limits', [])];

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.settings.fetch_success',
                $settings
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.settings.fetch_failed',
                500
            );
        }
    }

    /**
     * 카테고리별 설정을 조회합니다.
     *
     * @param  string  $category  카테고리명
     * @return JsonResponse 카테고리 설정을 포함한 JSON 응답
     */
    public function show(string $category): JsonResponse
    {
        try {
            $settings = $this->settingsWithCatalogs()[$category] ?? [];

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.settings.fetch_success',
                [
                    'category' => $category,
                    'settings' => $settings,
                    'abilities' => [
                        'can_update' => PermissionHelper::check('sirsoft-ecommerce.settings.update', request()->user()),
                    ],
                ]
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.settings.fetch_failed',
                500
            );
        }
    }

    /**
     * 이커머스 설정을 저장합니다.
     *
     * @param  StoreEcommerceSettingsRequest  $request  저장 요청 데이터
     * @return JsonResponse 저장 결과 JSON 응답
     */
    public function store(StoreEcommerceSettingsRequest $request): JsonResponse
    {
        return $this->saveValidatedSettings($request->validatedSettings());
    }

    /**
     * 검증된 전체·개별 설정을 같은 JSON/DB 저장 경로로 처리합니다.
     *
     * @param  array  $settings  저장할 카테고리 데이터
     * @return JsonResponse 저장 결과
     */
    private function saveValidatedSettings(array $settings): JsonResponse
    {
        try {
            // shipping.carriers·shipping.types 는 DB 관리 대상 — JSON 저장에서 제외.
            // 규칙이 nullable 이라 null 로도 도착한다. isset() 으로만 분기하면 null 이 걸러지지
            // 않아 두 가지가 한꺼번에 깨진다 — ① 카탈로그와 같은 이름의 죽은 키가 shipping.json 에
            // 남고 ② 섹션이 [] 가 아니게 되어 아래 빈 섹션 가드가 발화하지 않아, 운영자가 저장해 둔
            // 기본배송국가·무료배송기준·주소 API 등 스칼라 전축이 지워지고 다음 조회에서 기본값으로
            // 되살아난다(오류·로그 없이 "저장됨" 토스트만 뜬다). 아래 claim 카탈로그가 같은 이유로
            // array_key_exists + is_array 를 쓴다 — 배송 축도 같은 판정을 공유한다.
            $carriers = $this->extractShippingCatalog($settings, 'carriers');
            $shippingTypes = $this->extractShippingCatalog($settings, 'types');

            // 클레임 사유 카탈로그 3유형은 DB 관리 대상 — JSON 저장에서 제외.
            // 유형을 손으로 나열하지 않고 ClaimReasonTypeEnum 에서 파생한다 — 유형이 늘 때
            // 한 곳만 고치면 나머지가 JSON 에 이중 저장되어 DB 와 갈린다.
            // 규칙이 nullable 이라 null 로도 도착한다. isset() 으로만 분기하면 null 이
            // 걸러지지 않고 JSON 에 남아, DB 가 단일 출처여야 할 카탈로그와 같은 이름의
            // 죽은 키가 설정 파일에 생긴다 — 나중에 그 키를 믿는 코드는 빈 카탈로그를 본다.
            $claimReasons = [];
            foreach (ClaimReasonTypeEnum::cases() as $reasonType) {
                $key = $reasonType->value.'_reasons';
                if (! array_key_exists($key, $settings['claim'] ?? [])) {
                    continue;
                }

                $rows = $settings['claim'][$key];
                unset($settings['claim'][$key]);

                if (is_array($rows)) {
                    $claimReasons[$reasonType->value] = $rows;
                }
            }

            // 카탈로그만 담긴 페이로드는 `claim` 섹션을 통째로 비운 채 남긴다. 설정 저장은
            // 그 값으로 카테고리 파일을 덮어쓰므로(File::put), 그대로 넘기면 운영자가 저장해 둔
            // 정책 스칼라 13축이 전부 지워지고 다음 조회에서 기본값으로 되살아난다 —
            // 오류도 로그도 없이 "저장됨" 토스트만 뜬다. 담긴 축이 하나도 없으면 이 섹션은
            // 저장 대상이 아니다(카탈로그의 단일 출처는 DB 다).
            if (array_key_exists('claim', $settings) && $settings['claim'] === []) {
                unset($settings['claim']);
            }

            // 배송 섹션도 카탈로그(carriers/types)를 빼고 나면 담긴 축이 없을 수 있다. 그대로
            // 넘기면 `saveCategorySettings('shipping', [])` 가 shipping.json 을 `[]` 로 덮어써
            // 운영자가 저장해 둔 기본 배송국가·무료배송 기준·주소 API 등 스칼라 전축이 사라지고
            // 다음 조회에서 기본값으로 되살아난다 — 오류도 로그도 없이 "저장됨" 토스트만 뜬다.
            // 담긴 축이 하나도 없으면 이 섹션은 저장 대상이 아니다(카탈로그의 단일 출처는 DB 다).
            if (array_key_exists('shipping', $settings) && $settings['shipping'] === []) {
                unset($settings['shipping']);
            }

            // 카탈로그 삭제 가드는 **파일 쓰기 전에** 판정한다. 설정 저장은 JSON 파일 쓰기라
            // 트랜잭션으로 되돌릴 수 없어, 뒤에서 거절되면 스칼라 설정만 반영된 반쪽 상태가 남는다.
            // 클레임 3종뿐 아니라 배송사·배송유형도 같은 자리에서 판정한다 — 종전엔 두 축의 가드가
            // sync 안(=파일 쓰기 뒤)에만 있어, 사용 중인 배송사를 지우는 저장이 400 으로 거절되면서도
            // 배송 스칼라는 이미 파일에 기록된 반쪽 상태를 남겼다.
            if ($carriers !== null) {
                $this->carrierService->assertSyncDeletable($carriers);
            }

            if ($shippingTypes !== null) {
                $this->shippingTypeService->assertSyncDeletable($shippingTypes);
            }

            foreach ($claimReasons as $reasonType => $rows) {
                $this->claimReasonService->assertSyncDeletable($reasonType, $rows);
            }

            $result = $this->settingsService->saveSettings($settings);

            // carriers DB 동기화
            if ($result && $carriers !== null) {
                $this->carrierService->syncCarriers($carriers);
            }

            // shipping types DB 동기화
            if ($result && $shippingTypes !== null) {
                $this->shippingTypeService->syncShippingTypes($shippingTypes);
            }

            // claim reasons DB 동기화 (유형별)
            if ($result) {
                foreach ($claimReasons as $reasonType => $rows) {
                    $this->claimReasonService->syncReasons($reasonType, $rows);
                }
            }

            if ($result) {
                // 저장된 카테고리 목록 — 위 두 가드가 카탈로그만 담긴 섹션을 저장 대상에서
                // 빼므로, `array_keys($settings)` 만 넘기면 배송사·배송유형·사유 카탈로그를
                // 통째로 갈아엎은 저장이 활동로그에 "저장한 카테고리 없음" 으로 남는다
                // (응답은 200 이라 화면에도 로그에도 어긋남이 드러나지 않는다).
                // DB 동기화가 실제로 일어난 섹션을 함께 센다.
                $savedCategories = array_keys($settings);

                if ($carriers !== null || $shippingTypes !== null) {
                    $savedCategories[] = 'shipping';
                }

                if ($claimReasons !== []) {
                    $savedCategories[] = 'claim';
                }

                $savedCategories = array_values(array_unique($savedCategories));

                // 설정 저장 활동로그 (저장된 카테고리 목록 전달)
                HookManager::doAction('sirsoft-ecommerce.settings.after_save', $savedCategories);

                // 코어 모듈 설정 저장 훅 — SEO 캐시 무효화 등 코어/타 확장 리스너가 구독한다.
                // 발화 지점을 서비스가 아니라 관리자 컨트롤러에 두는 이유: 훅 의미가
                // "관리자가 설정을 저장했다" 이고, 서비스에 두면 내부 저장 호출 전부가
                // 활동로그·캐시 무효화를 유발한다.
                HookManager::doAction('core.module_settings.after_save', 'sirsoft-ecommerce', $settings, $result);

                // 저장 후 전체 설정 반환 (관리자 UI 상태 업데이트용)
                $updatedSettings = $this->settingsWithCatalogs();
                $updatedSettings['available_pg_providers'] = $this->settingsService->getRegisteredPgProviders();
                $updatedSettings['available_cash_receipt_providers'] = $this->settingsService->getRegisteredCashReceiptProviders();
                // 공개 자산 디스크 선택지 — 저장 응답에도 재부착 (관리자 UI 상태 갱신용)
                $updatedSettings['available_public_asset_disks'] = app(DriverRegistryService::class)
                    ->getAvailableDrivers('public_asset');

                return ResponseHelper::moduleSuccess(
                    'sirsoft-ecommerce',
                    'messages.settings.save_success',
                    $updatedSettings
                );
            } else {
                return ResponseHelper::moduleError(
                    'sirsoft-ecommerce',
                    'messages.settings.save_failed',
                    400
                );
            }
        } catch (ClaimReasonException $e) {
            // 사용 중인 사유를 지우려 한 것은 운영자가 조치할 수 있는 도메인 실패다.
            // 상세 삭제 경로(ClaimReasonController::destroy)와 같은 400·같은 메시지 키를 쓴다 —
            // 두 경로의 안내가 갈리면 같은 거절이 화면마다 다르게 보인다.
            $messageKey = $e->getMessageKey();

            return ResponseHelper::error(
                $messageKey,
                400,
                null,
                $e->getMessageParams()
            );
        } catch (ShippingTypeOperationException|ShippingCarrierOperationException $e) {
            // 도메인 실패(사용 중이라 삭제 불가 등)는 운영자가 조치할 수 있는 사유다.
            // generic 500 으로 뭉개면 "왜" 저장이 실패했는지 화면에서 알 수 없다.
            // 키가 이미 `sirsoft-ecommerce::` 로 정규화돼 있어 moduleError 가 아닌 error 를 쓴다
            // (ShippingCarrierController::destroy 와 동형).
            $messageKey = $e->getMessageKey();

            return ResponseHelper::error(
                $messageKey,
                400,
                null,
                $e->getMessageParams()
            );
        } catch (Exception $e) {
            Log::error('이커머스 설정 저장 실패', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.settings.save_error',
                500
            );
        }
    }

    /**
     * 은행 목록만 저장합니다.
     *
     * @param  StoreBanksRequest  $request  은행 목록 저장 요청 데이터
     * @return JsonResponse 저장 결과 JSON 응답
     */
    public function storeBanks(StoreBanksRequest $request): JsonResponse
    {
        try {
            $banks = $request->validated('banks') ?? [];

            $result = $this->settingsService->saveBanks($banks);

            if ($result) {
                HookManager::doAction(
                    'core.module_settings.after_save',
                    'sirsoft-ecommerce',
                    ['order_settings' => ['banks' => $banks]],
                    $result
                );

                $updatedSettings = $this->settingsService->getAllSettings();

                return ResponseHelper::moduleSuccess(
                    'sirsoft-ecommerce',
                    'messages.settings.save_success',
                    $updatedSettings
                );
            } else {
                return ResponseHelper::moduleError(
                    'sirsoft-ecommerce',
                    'messages.settings.save_failed',
                    400
                );
            }
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.settings.save_error',
                500
            );
        }
    }

    /**
     * 특정 설정값을 조회합니다.
     *
     * @param  GetSettingRequest  $request  요청 데이터
     * @return JsonResponse 설정값을 포함한 JSON 응답
     */
    public function getSetting(GetSettingRequest $request): JsonResponse
    {
        try {
            $key = $request->validated('key');

            $value = Arr::get($this->settingsWithCatalogs(), $key);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.settings.fetch_success',
                [
                    'key' => $key,
                    'value' => $value,
                ]
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.settings.fetch_failed',
                500
            );
        }
    }

    /**
     * 특정 설정값을 업데이트합니다.
     *
     * @param  UpdateSettingRequest  $request  요청 데이터
     * @return JsonResponse 업데이트 결과 JSON 응답
     */
    public function updateSetting(UpdateSettingRequest $request): JsonResponse
    {
        try {
            $key = $request->validated('key');
            $value = $request->validated('value');

            // DB 카탈로그를 포함한 개별 저장은 전체 저장의 사용 중 삭제 검사·DB 동기화를 공유한다.
            // 스칼라만 저장하는 기존 경로는 setSetting의 부분 갱신 계약을 유지한다.
            $payload = [];
            Arr::set($payload, $key, $value);
            if ($this->containsCatalogSettings($payload)) {
                $response = $this->saveValidatedSettings($payload);
                if ($response->getStatusCode() >= 400) {
                    return $response;
                }

                return ResponseHelper::moduleSuccess(
                    'sirsoft-ecommerce',
                    'messages.settings.update_success',
                    ['updated' => true]
                );
            }

            $result = $this->settingsService->setSetting($key, $value);

            if ($result) {
                // dot-key 를 카테고리 하위 구조로 되돌려 벌크 저장과 같은 payload 형태로 만든다
                $payload = [];
                Arr::set($payload, $key, $value);
                HookManager::doAction('core.module_settings.after_save', 'sirsoft-ecommerce', $payload, $result);

                return ResponseHelper::moduleSuccess(
                    'sirsoft-ecommerce',
                    'messages.settings.update_success',
                    ['updated' => true]
                );
            } else {
                return ResponseHelper::moduleError(
                    'sirsoft-ecommerce',
                    'messages.settings.update_failed',
                    400
                );
            }
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.settings.update_error',
                500
            );
        }
    }

    /**
     * 설정 캐시를 초기화합니다.
     *
     * @return JsonResponse 초기화 결과 JSON 응답
     */
    public function clearCache(): JsonResponse
    {
        try {
            $this->settingsService->clearCache();
            app(SeoCacheManagerInterface::class)->clearAll();

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.settings.cache_clear_success',
                ['cleared' => true]
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.settings.cache_clear_error',
                500
            );
        }
    }

    /**
     * SEO 캐시 정보를 조회합니다.
     *
     * @return JsonResponse 캐시 페이지 수 및 용량을 포함한 JSON 응답
     */
    public function seoCacheInfo(): JsonResponse
    {
        try {
            $cacheManager = app(SeoCacheManagerInterface::class);
            $urls = $cacheManager->getCachedUrls();
            $count = count($urls);

            // 각 캐시 엔트리의 HTML 크기를 합산하여 총 용량 계산
            $totalBytes = 0;
            foreach ($urls as $url) {
                foreach (config('app.supported_locales', ['ko']) as $locale) {
                    $html = $cacheManager->get($url, $locale);
                    if ($html !== null) {
                        $totalBytes += strlen($html);
                    }
                }
            }

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.settings.fetch_success',
                [
                    'count' => $count,
                    'size_bytes' => $totalBytes,
                    'size_formatted' => $this->formatBytes($totalBytes),
                ]
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.settings.fetch_failed',
                500
            );
        }
    }

    /**
     * 바이트를 사람이 읽기 쉬운 형태로 변환합니다.
     *
     * @param  int  $bytes  바이트 수
     * @return string 포맷된 문자열 (예: "1.5 MB")
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $i = (int) floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);

        return round($bytes / (1024 ** $i), 1).' '.$units[$i];
    }

    /**
     * 설정 응답에 배송사 목록을 추가합니다.
     *
     * DB 관리 대상인 carriers를 shipping 섹션에 포함시킵니다.
     *
     * @param  array  $settings  설정 배열
     * @return array carriers가 추가된 설정 배열
     */
    /**
     * 배송 섹션에서 DB 관리 대상 카탈로그를 떼어냅니다.
     *
     * 규칙이 nullable 이라 카탈로그는 배열뿐 아니라 null 로도 도착한다(폼 인코딩의 빈 값도
     * ConvertEmptyStringsToNull 로 null 이 된다). isset() 으로 분기하면 null 이 걸러지지 않아
     * 두 가지가 한꺼번에 깨진다 — ① 단일 출처가 DB 인 카탈로그와 같은 이름의 죽은 키가
     * shipping.json 에 남고 ② 섹션이 [] 가 아니게 되어 호출부의 빈 섹션 가드가 발화하지 않는다.
     * ②가 일어나면 운영자가 저장해 둔 기본배송국가·무료배송기준·주소 API 등 스칼라 전축이
     * shipping.json 에서 사라지고 다음 조회에서 기본값으로 되살아난다 — 오류도 로그도 없이
     * "저장되었습니다" 토스트만 뜬다. 같은 이유로 claim 카탈로그가 array_key_exists + is_array
     * 를 쓰며, 배송 축도 같은 판정을 공유한다.
     *
     * @param  array  $settings  검증된 설정 배열 (참조 — 카탈로그 키가 제거된다)
     * @param  string  $catalogKey  떼어낼 카탈로그 키 (carriers|types)
     * @return array|null 배열로 도착한 경우에만 그 행 목록, 그 밖에는 null (동기화 대상 아님)
     */
    private function extractShippingCatalog(array &$settings, string $catalogKey): ?array
    {
        if (! array_key_exists($catalogKey, $settings['shipping'] ?? [])) {
            return null;
        }

        $rows = $settings['shipping'][$catalogKey];
        unset($settings['shipping'][$catalogKey]);

        return is_array($rows) ? $rows : null;
    }

    /**
     * 전체·카테고리·개별 조회가 공유하는 DB 카탈로그 포함 설정입니다.
     *
     * @return array 설정과 DB 카탈로그
     */
    private function settingsWithCatalogs(): array
    {
        $settings = $this->settingsService->getAllSettings();
        $settings = $this->appendCarriersToSettings($settings);
        $settings = $this->appendShippingTypesToSettings($settings);
        $settings = $this->appendShippingApiRequestFieldsToSettings($settings);
        $settings = $this->appendClaimReasonsToSettings($settings);

        return $this->appendMileageNotificationChannelsToSettings($settings);
    }

    /**
     * JSON 파일에 저장해서는 안 되는 DB 카탈로그가 포함되어 있는지 판정합니다.
     *
     * @param  array  $settings  개별 설정 키를 펼친 데이터
     * @return bool 카탈로그 저장 경로 필요 여부
     */
    private function containsCatalogSettings(array $settings): bool
    {
        foreach (['carriers', 'types'] as $key) {
            if (array_key_exists($key, $settings['shipping'] ?? [])) {
                return true;
            }
        }
        foreach (ClaimReasonTypeEnum::cases() as $type) {
            if (array_key_exists($type->value.'_reasons', $settings['claim'] ?? [])) {
                return true;
            }
        }

        return false;
    }

    /**
     * 설정 응답에 DB 배송사 카탈로그를 덧붙입니다.
     *
     * @param  array  $settings  원본 설정
     * @return array 배송사 목록이 포함된 설정
     */
    private function appendCarriersToSettings(array $settings): array
    {
        $carriers = $this->carrierService->getAllCarriers();

        $settings['shipping']['carriers'] = $carriers->map(fn ($c) => [
            'id' => $c->id,
            'code' => $c->code,
            'name' => $c->name,
            'type' => $c->type,
            'tracking_url' => $c->tracking_url,
            'is_active' => $c->is_active,
            'sort_order' => $c->sort_order,
        ])->values()->toArray();

        return $settings;
    }

    /**
     * 설정 응답에 배송유형 목록을 추가합니다.
     *
     * DB 관리 대상인 shipping types를 shipping 섹션에 포함시킵니다.
     *
     * @param  array  $settings  설정 배열
     * @return array shipping types가 추가된 설정 배열
     */
    private function appendShippingTypesToSettings(array $settings): array
    {
        $settings['shipping']['types'] = $this->shippingTypeService->getTypesForSettings();

        return $settings;
    }

    /**
     * 설정 응답에 배송 계산 API 요청 참고 필드 후보 목록을 추가합니다.
     *
     * 배송정책 폼의 "API 전송 필드" 후보(체크박스)는 프론트 하드코딩이 아니라
     * 백엔드 SSoT enum(ShippingApiRequestField)에서 번역된 {value, label} 로 내려갑니다.
     *
     * @param  array  $settings  설정 배열
     * @return array 후보 목록이 추가된 설정 배열
     */
    private function appendShippingApiRequestFieldsToSettings(array $settings): array
    {
        $settings['shipping']['api_request_fields'] = ShippingApiRequestField::options();
        // 계산 API 고급 설정 옵션 — 프론트 하드코딩이 아닌 백엔드 enum SSoT 에서 번역되어 내려감
        $settings['shipping']['api_http_methods'] = ShippingApiHttpMethod::options();
        $settings['shipping']['api_auth_types'] = ShippingApiAuthType::options();
        $settings['shipping']['api_response_types'] = ShippingApiResponseType::options();

        return $settings;
    }

    /**
     * 설정 응답에 클레임 사유 목록을 추가합니다.
     *
     * DB 관리 대상인 claim reasons(교환/반품/환불)를 claim 섹션에 포함시킵니다.
     *
     * @param  array  $settings  설정 배열
     * @return array claim reasons가 추가된 설정 배열
     */
    private function appendClaimReasonsToSettings(array $settings): array
    {
        foreach (ClaimReasonTypeEnum::cases() as $reasonType) {
            $settings['claim'][$reasonType->value.'_reasons'] = $this->claimReasonService
                ->getReasonsForSettings($reasonType->value);
        }

        return $settings;
    }

    /**
     * 마일리지 소멸 예정 알림의 실제 활성 채널을 설정 응답에 병합합니다.
     *
     * 설정 카드의 "활성 채널" 칩은 선언 고정값이 아니라 관리자가 알림 설정에서 켜고 끈
     * 실제 활성 채널을 보여줍니다. GenericNotification::via() 와 동일하게 도출합니다:
     * definition resolve → notification.channels 필터 → 확장 단위 채널 활성 여부.
     *
     * @param  array  $settings  설정 배열
     * @return array 활성 채널이 병합된 설정 배열
     */
    private function appendMileageNotificationChannelsToSettings(array $settings): array
    {
        $type = 'mileage_expiring_soon';

        try {
            $definition = app(NotificationDefinitionService::class)->resolve($type);
            $channels = $definition?->channels ?? ['mail', 'database'];

            $channels = HookManager::applyFilters(
                'sirsoft-ecommerce.notification.channels',
                $channels,
                $type
            );

            $channelService = app(NotificationChannelService::class);
            $active = array_values(array_filter(
                $channels,
                fn (string $channel) => $channelService->isChannelEnabledForExtension('module', 'sirsoft-ecommerce', $channel)
            ));
        } catch (Exception) {
            $active = [];
        }

        $settings['mileage']['notification_channels'] = $active;

        return $settings;
    }
}
