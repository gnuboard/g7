<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Services\DriverRegistryService;
use App\Services\NotificationChannelService;
use App\Services\NotificationDefinitionService;
use Exception;
use Illuminate\Support\Arr;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ShippingApiAuthType;
use Modules\Sirsoft\Ecommerce\Enums\ShippingApiHttpMethod;
use Modules\Sirsoft\Ecommerce\Enums\ShippingApiRequestField;
use Modules\Sirsoft\Ecommerce\Enums\ShippingApiResponseType;
use Modules\Sirsoft\Ecommerce\Exceptions\ClaimReasonException;
use Modules\Sirsoft\Ecommerce\Exceptions\ShippingCarrierOperationException;
use Modules\Sirsoft\Ecommerce\Exceptions\ShippingTypeOperationException;

/**
 * 관리자 환경설정 화면의 저장·조회 조립 서비스
 *
 * 설정 JSON(EcommerceSettingsService)과 DB 카탈로그(배송사·배송유형·클레임 사유)를
 * 한 화면의 설정으로 묶어 읽고, 저장할 때는 둘로 나눠 각 저장소에 보냅니다.
 *
 * EcommerceSettingsService 에 두지 않는 이유: 그 서비스는 PaymentMethodResolver 가 의존하는
 * 싱글톤이라, 거기에 PaymentMethodResolver·카탈로그 서비스를 주입하면 순환 의존이 된다.
 *
 * 훅(`sirsoft-ecommerce.settings.after_save`·`core.module_settings.after_save`)은 여기서
 * 발화하지 않습니다 — 훅 의미가 "관리자가 설정을 저장했다" 라 발화 지점은 관리자 컨트롤러다.
 * 서비스에서 발화하면 내부 저장 호출 전부가 활동로그·캐시 무효화를 유발하고, 그 훅을 구독하는
 * 환율 제공자 플러그인과 무한 재귀한다. 그래서 저장 메서드는 컨트롤러가 훅에 실을 값을 돌려준다.
 */
class EcommerceSettingsAdminService
{
    /**
     * 환불 자동 승인 결제수단의 운영자 결정 이력 키 (claim 섹션 하위, 서버 전용)
     */
    public const REFUND_AUTO_APPROVE_DECIDED_KEY = 'auto_approve_refund_payment_methods_decided';

    /**
     * 설정 화면 조립에 필요한 서비스를 주입합니다.
     *
     * @param  EcommerceSettingsService  $settingsService  환경설정 서비스
     * @param  ShippingCarrierService  $carrierService  배송사 서비스
     * @param  ShippingTypeService  $shippingTypeService  배송유형 서비스
     * @param  ClaimReasonService  $claimReasonService  클레임 사유 서비스
     * @param  PaymentMethodResolver  $paymentMethodResolver  결제수단 카탈로그 해석기
     * @param  DriverRegistryService  $driverRegistry  코어 드라이버 카탈로그 서비스
     * @param  NotificationDefinitionService  $notificationDefinitionService  알림 정의 서비스
     * @param  NotificationChannelService  $notificationChannelService  알림 채널 서비스
     */
    public function __construct(
        private EcommerceSettingsService $settingsService,
        private ShippingCarrierService $carrierService,
        private ShippingTypeService $shippingTypeService,
        private ClaimReasonService $claimReasonService,
        private PaymentMethodResolver $paymentMethodResolver,
        private DriverRegistryService $driverRegistry,
        private NotificationDefinitionService $notificationDefinitionService,
        private NotificationChannelService $notificationChannelService,
    ) {}

    /**
     * 전체·카테고리·개별 조회가 공유하는 DB 카탈로그 포함 설정을 돌려줍니다.
     *
     * 환불 자동 승인 결정 이력(`claim.auto_approve_refund_payment_methods_decided`)은 내려주지
     * 않는다. 서버만 쓰는 키라 저장 규칙이 `prohibited` 인데, 관리자 화면은 조회 응답을 폼
     * 상태로 들고 있다가 claim 섹션을 통째로 되보내므로, 내려 주면 이력이 한 번 쌓인 뒤의
     * 클레임 탭 저장이 전부 422 가 된다.
     *
     * @return array 설정과 DB 카탈로그
     */
    public function getSettingsForAdmin(): array
    {
        $settings = $this->settingsService->getAllSettings();

        if (is_array($settings['claim'] ?? null)) {
            unset($settings['claim'][self::REFUND_AUTO_APPROVE_DECIDED_KEY]);
        }

        $settings = $this->appendCarriersToSettings($settings);
        $settings = $this->appendShippingTypesToSettings($settings);
        $settings = $this->appendShippingApiRequestFieldsToSettings($settings);
        $settings = $this->appendClaimReasonsToSettings($settings);

        return $this->appendMileageNotificationChannelsToSettings($settings);
    }

    /**
     * 설정 화면 전체 조회·저장 응답에 쓰는 설정과 제공자 후보 목록을 돌려줍니다.
     *
     * @return array DB 카탈로그와 PG·환율·현금영수증·공개 자산 디스크 후보가 포함된 설정
     */
    public function getSettingsWithProviders(): array
    {
        $settings = $this->getSettingsForAdmin();
        $settings['available_pg_providers'] = $this->settingsService->getRegisteredPgProviders();
        $settings['available_rate_providers'] = $this->settingsService->getRegisteredRateProviders();
        $settings['active_rate_provider'] = $this->settingsService->getActiveRateProvider($settings['available_rate_providers']);
        // 현금영수증 발급 프로바이더 후보 — 결제 플러그인이 훅으로 자신을 등록한다.
        // 발급 PG 와 결제 PG 는 독립 선택이므로 목록도 별도로 내린다 (KG 결제 + 토스 발급 등).
        $settings['available_cash_receipt_providers'] = $this->settingsService->getRegisteredCashReceiptProviders();

        // 공개 자산 디스크 선택지 — 카탈로그 SSoT 는 코어 DriverRegistryService 게터 한 곳.
        // 저장 응답에도 같은 목록을 붙인다 (관리자 UI 상태 갱신용).
        return $this->withPublicAssetCatalog($settings);
    }

    /**
     * 설정 응답에 공개 자산 디스크 카탈로그(available_public_asset_disks)를 붙입니다.
     *
     * 저장된 basic_info.public_asset_disk 가 카탈로그에 없으면(그 디스크를 등록하던 확장이 꺼진 경우)
     * 실제 동작인 스트리밍(`none`)으로 바꿔 싣고, 사라진 디스크 이름을
     * `basic_info.public_asset_disk_unavailable` 로 알립니다. 저장값을 그대로 실으면 화면의 선택 칸이
     * 어떤 선택지와도 맞지 않은 채 보이고, 기본정보 탭 저장이 다른 항목만 고쳐도 매번 카탈로그 검증(422)에
     * 막힙니다. 런타임도 이미 고아 디스크를 스트리밍으로 폴백하므로 표시가 동작과 같아집니다.
     * 안내 키는 저장 요청에서 제외되어 설정에 남지 않습니다(StoreEcommerceSettingsRequest).
     *
     * @param  array<string, mixed>  $settings  응답에 실을 설정
     * @return array<string, mixed> 카탈로그를 붙이고 쓸 수 없게 된 디스크를 정리한 설정
     */
    private function withPublicAssetCatalog(array $settings): array
    {
        $catalog = $this->driverRegistry->getAvailableDrivers('public_asset');
        $settings['available_public_asset_disks'] = $catalog;

        $stored = $settings['basic_info']['public_asset_disk'] ?? '';
        if (is_string($stored) && $stored !== '' && ! in_array($stored, array_column($catalog, 'id'), true)) {
            $settings['basic_info']['public_asset_disk'] = 'none';
            $settings['basic_info']['public_asset_disk_unavailable'] = $stored;
        }

        return $settings;
    }

    /**
     * 검증된 전체·개별 설정을 같은 JSON/DB 저장 경로로 처리합니다.
     *
     * 사용 중 삭제 가드는 파일 쓰기 전에 판정하므로, 도메인 예외가 나면 이 요청의 어떤
     * 설정도 저장되지 않는다.
     *
     * @param  array  $settings  검증된 카테고리별 설정
     * @return array{result: bool, saved_categories: array<int, string>, settings: array} 저장 결과·저장된 카테고리·훅에 실을 설정
     *
     * @throws ClaimReasonException 사용 중인 클레임 사유를 지우거나 코드를 바꾸려 한 경우
     * @throws ShippingCarrierOperationException 사용 중인 배송사를 지우려 한 경우
     * @throws ShippingTypeOperationException 사용 중인 배송유형을 지우거나 코드를 바꾸려 한 경우
     */
    public function saveSettings(array $settings): array
    {
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
        // 삭제는 `{type}_reasons_deleted` 에 운영자가 휴지통으로 지목한 id 로만 일어난다 —
        // 페이로드에 없는 행을 삭제로 읽으면 다른 탭에서 만든 사유가 저장 한 번에 사라진다
        // (#599 S0-D2). 이 키도 DB 전용이라 JSON 에서 뺀다.
        $claimReasons = [];
        $claimReasonDeletions = [];
        foreach (ClaimReasonTypeEnum::cases() as $reasonType) {
            $deletedKey = $reasonType->value.'_reasons_deleted';
            if (array_key_exists($deletedKey, $settings['claim'] ?? [])) {
                $deleted = $settings['claim'][$deletedKey];
                unset($settings['claim'][$deletedKey]);

                if (is_array($deleted)) {
                    $claimReasonDeletions[$reasonType->value] = $deleted;
                }
            }

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

        $settings = $this->withRefundAutoApproveDecisions($settings);

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
            $this->claimReasonService->assertSyncDeletable($reasonType, $rows, $claimReasonDeletions[$reasonType] ?? []);
        }

        $result = $this->settingsService->saveSettings($settings);

        if (! $result) {
            return ['result' => false, 'saved_categories' => [], 'settings' => $settings];
        }

        // carriers DB 동기화
        if ($carriers !== null) {
            $this->carrierService->syncCarriers($carriers);
        }

        // shipping types DB 동기화
        if ($shippingTypes !== null) {
            $this->shippingTypeService->syncShippingTypes($shippingTypes);
        }

        // claim reasons DB 동기화 (유형별)
        foreach ($claimReasons as $reasonType => $rows) {
            $this->claimReasonService->syncReasons($reasonType, $rows, $claimReasonDeletions[$reasonType] ?? []);
        }

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

        return [
            'result' => true,
            'saved_categories' => array_values(array_unique($savedCategories)),
            'settings' => $settings,
        ];
    }

    /**
     * 검증된 개별 설정 한 키를 저장합니다.
     *
     * DB 카탈로그를 포함한 개별 저장은 전체 저장의 사용 중 삭제 검사·DB 동기화를 공유한다.
     * 스칼라만 저장하는 경로는 setSetting 의 부분 갱신 계약을 유지하되, 환불 자동 승인
     * 결제수단을 고치면 전체 저장과 같은 결정 이력(P17)을 남긴다 — 이 경로만 이력을 건너뛰면
     * 여기서 뺀 즉시 환불 수단이 다음 조회의 자동 합류로 되살아난다.
     *
     * @param  string  $key  점 표기 설정 키 (카테고리 통째 지정 허용)
     * @param  mixed  $value  검증된 설정값
     * @return array{result: bool, saved_categories: array<int, string>, settings: array} 저장 결과·저장된 카테고리·훅에 실을 설정
     *
     * @throws ClaimReasonException 사용 중인 클레임 사유를 지우거나 코드를 바꾸려 한 경우
     * @throws ShippingCarrierOperationException 사용 중인 배송사를 지우려 한 경우
     * @throws ShippingTypeOperationException 사용 중인 배송유형을 지우거나 코드를 바꾸려 한 경우
     */
    public function updateSetting(string $key, mixed $value): array
    {
        $payload = [];
        Arr::set($payload, $key, $value);

        if ($this->containsCatalogSettings($payload)) {
            return $this->saveSettings($payload);
        }

        $payload = $this->withRefundAutoApproveDecisions($payload);
        $decidedPath = 'claim.'.self::REFUND_AUTO_APPROVE_DECIDED_KEY;

        if ($key === 'claim') {
            // 카테고리 통째 저장 — 결정 이력을 같은 파일 쓰기에 싣는다.
            $result = $this->settingsService->setSetting('claim', $payload['claim'] ?? []);
        } else {
            $result = $this->settingsService->setSetting($key, $value);

            if ($result && Arr::has($payload, $decidedPath)) {
                $result = $this->settingsService->setSetting($decidedPath, Arr::get($payload, $decidedPath));
            }
        }

        return [
            'result' => $result,
            'saved_categories' => $result ? array_keys($payload) : [],
            'settings' => $payload,
        ];
    }

    /**
     * 환불 자동 승인 결제수단 저장에 운영자 결정 이력(P17)을 덧붙입니다.
     *
     * 환불 자동 승인 결제수단을 저장하면 **그 화면이 보여 준 수단 전부**를 운영자 결정
     * 이력에 남긴다(P17). 넣은 것도 뺀 것도 결정이므로, 그 뒤로는 저장값이 플러그인 선언을
     * 이긴다 — 이력이 없으면 운영자가 일부러 뺀 즉시 환불 수단이 다음 조회의 자동 합류로
     * 되살아나고, 운영자는 "분명히 뺐는데" 를 반복하게 된다. 반대로 이력을 남기면 나중에
     * 설치된 PG 플러그인의 새 수단만(이력에 없으므로) 자동 합류한다.
     *
     * 이력은 서버만 쓴다. 저장 규칙이 `prohibited` 라 값이 실린 요청은 422 로 막히지만, 빈
     * 배열은 그 규칙을 통과하므로 여기서 한 번 더 걷어 낸다 — 그대로 두면 `[]` 한 번으로
     * 이력이 지워져 운영자가 뺀 수단이 되살아난다.
     *
     * @param  array  $settings  카테고리별 설정 (claim 섹션이 없으면 그대로 돌려준다)
     * @return array 결정 이력이 반영된 설정
     */
    private function withRefundAutoApproveDecisions(array $settings): array
    {
        if (! is_array($settings['claim'] ?? null)) {
            return $settings;
        }

        unset($settings['claim'][self::REFUND_AUTO_APPROVE_DECIDED_KEY]);

        if (! array_key_exists('auto_approve_refund_payment_methods', $settings['claim'])) {
            return $settings;
        }

        $previous = $this->settingsService->getSetting('claim.'.self::REFUND_AUTO_APPROVE_DECIDED_KEY);

        $settings['claim'][self::REFUND_AUTO_APPROVE_DECIDED_KEY] = array_values(array_unique(array_merge(
            is_array($previous) ? array_filter($previous, 'is_string') : [],
            $this->paymentMethodResolver->declaredAutoApproveRefundIds(),
        )));

        return $settings;
    }

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
     * DB 관리 대상인 carriers 를 shipping 섹션에 포함시킵니다.
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
     * DB 관리 대상인 shipping types 를 shipping 섹션에 포함시킵니다.
     *
     * @param  array  $settings  설정 배열
     * @return array shipping types 가 추가된 설정 배열
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
     * @return array claim reasons 가 추가된 설정 배열
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
            $definition = $this->notificationDefinitionService->resolve($type);
            $channels = $definition?->channels ?? ['mail', 'database'];

            // 채널 후보는 코어 진입점에서 받는다 — 채널 제공 확장(문자 등)이 코어 훅에 더한 후보와
            // 이커머스 자기 훅(sirsoft-ecommerce.notification.channels)을 발송 경로와 같은 순서로 거친다.
            $channels = $this->notificationChannelService->resolveChannelCandidates('sirsoft-ecommerce', $channels, $type);

            $active = array_values(array_filter(
                $channels,
                fn (string $channel) => $this->notificationChannelService->isChannelEnabledForExtension('module', 'sirsoft-ecommerce', $channel)
            ));
        } catch (Exception) {
            $active = [];
        }

        $settings['mileage']['notification_channels'] = $active;

        return $settings;
    }
}
