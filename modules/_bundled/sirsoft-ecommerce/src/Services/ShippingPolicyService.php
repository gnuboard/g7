<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicyClaimAddress;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ShippingPolicyClaimAddressRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ShippingPolicyRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Traits\ReappliesPermissionScope;

/**
 * 배송정책 서비스
 */
class ShippingPolicyService
{
    use ReappliesPermissionScope;

    /**
     * 배송정책 서비스를 만듭니다.
     *
     * @param  ShippingPolicyRepositoryInterface  $repository  배송정책 Repository
     * @param  ShippingPolicyClaimAddressRepositoryInterface  $claimAddressRepository  교환·반품 회수지 Repository
     */
    public function __construct(
        protected ShippingPolicyRepositoryInterface $repository,
        protected ShippingPolicyClaimAddressRepositoryInterface $claimAddressRepository,
    ) {}

    /**
     * 배송정책 목록 조회
     *
     * @param  array  $filters  필터 조건
     * @return LengthAwarePaginator 페이지네이션된 배송정책 목록
     */
    public function getList(array $filters): LengthAwarePaginator
    {
        // 필터 데이터 가공 훅
        $filters = HookManager::applyFilters('sirsoft-ecommerce.shipping_policy.filter_list_params', $filters);

        $perPage = (int) ($filters['per_page'] ?? 20);

        return $this->repository->getListWithFilters($filters, $perPage);
    }

    /**
     * 배송정책 통계 조회
     *
     * @return array 배송정책 통계 데이터
     */
    public function getStatistics(): array
    {
        return $this->repository->getStatistics();
    }

    /**
     * 배송정책 상세 조회
     *
     * @param  int  $id  배송정책 ID
     * @return ShippingPolicy|null 조회된 배송정책 (없으면 null)
     */
    public function getDetail(int $id): ?ShippingPolicy
    {
        $shippingPolicy = $this->repository->find($id);

        if ($shippingPolicy) {
            HookManager::doAction('sirsoft-ecommerce.shipping_policy.after_read', $shippingPolicy);
        }

        return $shippingPolicy;
    }

    /**
     * 배송정책 생성
     *
     * @param  array  $data  배송정책 데이터
     * @return ShippingPolicy 생성된 배송정책
     */
    public function create(array $data): ShippingPolicy
    {
        // 생성 전 훅
        HookManager::doAction('sirsoft-ecommerce.shipping_policy.before_create', $data);

        // 데이터 가공 훅
        $data = HookManager::applyFilters('sirsoft-ecommerce.shipping_policy.filter_create_data', $data);

        // 국가별 설정 분리
        $countrySettingsData = $data['country_settings'] ?? [];
        unset($data['country_settings']);

        // 통화는 상점 기본 통화로 강제 (상품 등록과 동일 정책 — 자유 지정 금지)
        $countrySettingsData = $this->forceDefaultCurrency($countrySettingsData);

        // 클레임 배송비 축의 명시 null 제거: 세 컬럼은 NOT NULL 이라 null 은 저장 가능한
        // 값이 아니다. 폼이 빈 필드를 null 로 실어 보내면 그대로 INSERT 되어 무결성 위반이
        // 되므로, "값 없음" 으로 보고 키를 떨궈 컬럼 기본값이 적용되게 한다. 수정 경로의
        // 승계 판정(preserveClaimFeeSettings)과 같은 기준이다 — 0 은 유효값이라 통과한다.
        $countrySettingsData = $this->dropNullClaimFeeSettings($countrySettingsData);

        // 생성자 정보 추가
        $data['created_by'] = Auth::id();
        $data['updated_by'] = Auth::id();

        $shippingPolicy = DB::transaction(function () use ($data, $countrySettingsData) {
            $policy = $this->repository->create($data);

            // is_default=true로 생성 시 기존 기본 정책 해제
            if ($policy->is_default) {
                $this->repository->clearDefault($policy->id);
            }

            // 국가별 설정 일괄 생성
            foreach ($countrySettingsData as $cs) {
                $policy->countrySettings()->create($cs);
            }

            return $policy->load('countrySettings');
        });

        // 생성 후 훅
        HookManager::doAction('sirsoft-ecommerce.shipping_policy.after_create', $shippingPolicy);

        return $shippingPolicy;
    }

    /**
     * 배송정책 수정
     *
     * @param  ShippingPolicy  $shippingPolicy  배송정책 모델
     * @param  array  $data  수정 데이터
     * @return ShippingPolicy 수정된 배송정책
     */
    public function update(ShippingPolicy $shippingPolicy, array $data): ShippingPolicy
    {
        $this->assertWithinScope($shippingPolicy, 'sirsoft-ecommerce.shipping-policies.update');

        // 수정 전 훅
        HookManager::doAction('sirsoft-ecommerce.shipping_policy.before_update', $shippingPolicy, $data);

        // 수정 전 스냅샷 캡처 (after_update 훅에 전달)
        $snapshot = $shippingPolicy->toArray();

        // 데이터 가공 훅
        $data = HookManager::applyFilters('sirsoft-ecommerce.shipping_policy.filter_update_data', $data, $shippingPolicy);

        // 국가별 설정 분리
        $countrySettingsData = $data['country_settings'] ?? [];
        unset($data['country_settings']);

        // 통화는 상점 기본 통화로 강제 (상품 등록과 동일 정책 — 자유 지정 금지)
        $countrySettingsData = $this->forceDefaultCurrency($countrySettingsData);

        // 계산 API 인증 토큰 재전송 유지: 폼이 마스킹된 빈 토큰을 보낸 경우
        // 기존 country_setting(동일 country_code)의 토큰을 복원한다 (재입력 강제 방지).
        $countrySettingsData = $this->preserveApiAuthTokens($shippingPolicy, $countrySettingsData);

        // 클레임 배송비 축 유지: 국가별 설정은 삭제 후 재생성(sync)이므로, 클레임 키를
        // 보내지 않은 항목은 기존 값(동일 country_code)을 승계한다 — 승계하지 않으면
        // 클레임 탭에서 저장한 값이 본 폼 저장 한 번에 기본값으로 조용히 초기화된다.
        $countrySettingsData = $this->preserveClaimFeeSettings($shippingPolicy, $countrySettingsData);

        // 승계 대상이 없는 신규 국가는 명시 null 이 그대로 남는다 — 생성 경로와 같은 기준으로
        // 떨궈 컬럼 기본값이 적용되게 한다 (승계는 기존 국가, 기본값은 신규 국가).
        $countrySettingsData = $this->dropNullClaimFeeSettings($countrySettingsData);

        // 수정자 정보 추가
        $data['updated_by'] = Auth::id();

        $shippingPolicy = DB::transaction(function () use ($shippingPolicy, $data, $countrySettingsData) {
            $policy = $this->repository->update($shippingPolicy, $data);

            // is_default=true로 변경된 경우 기존 기본 정책 해제
            if ($policy->is_default) {
                $this->repository->clearDefault($policy->id);
            }

            // 국가별 설정: 삭제 후 재생성 (sync 패턴)
            $policy->countrySettings()->delete();
            foreach ($countrySettingsData as $cs) {
                $policy->countrySettings()->create($cs);
            }

            return $policy->fresh()->load('countrySettings');
        });

        // 수정 후 훅
        HookManager::doAction('sirsoft-ecommerce.shipping_policy.after_update', $shippingPolicy, $snapshot);

        return $shippingPolicy;
    }

    /**
     * 배송정책 클레임 설정(회수지 + 국가별 클레임 배송비)을 조회합니다.
     *
     * @param  ShippingPolicy  $shippingPolicy  배송정책 모델
     * @return array{addresses: array<string, ShippingPolicyClaimAddress|null>, country_settings: Collection} 클레임 설정
     */
    public function getClaimSettings(ShippingPolicy $shippingPolicy): array
    {
        return [
            'addresses' => [
                ClaimTypeEnum::EXCHANGE->value => $this->claimAddressRepository->findByPolicyAndType($shippingPolicy->id, ClaimTypeEnum::EXCHANGE->value),
                ClaimTypeEnum::RETURN->value => $this->claimAddressRepository->findByPolicyAndType($shippingPolicy->id, ClaimTypeEnum::RETURN->value),
            ],
            'country_settings' => $shippingPolicy->countrySettings()->orderBy('id')->get(),
        ];
    }

    /**
     * 배송정책 클레임 설정을 수정합니다. (부분 수정 — 보낸 축만 반영)
     *
     * 회수지는 유형 키(exchange/return)가 있을 때만 다룬다 — 값이 null 이면 삭제,
     * 배열이면 upsert. 국가별 클레임 배송비는 나열된 국가만 갱신한다 (국가 코드의 정책
     * 소속은 FormRequest 의 상위 스코프 Rule::exists 가 보증한다). 본 폼 저장(sync 재생성)
     * 과 달리 국가별 설정 행을 지우지 않는다.
     *
     * @param  ShippingPolicy  $shippingPolicy  배송정책 모델
     * @param  array  $data  수정 데이터 (`addresses` + `country_settings`)
     * @return ShippingPolicy 수정된 배송정책
     */
    public function updateClaimSettings(ShippingPolicy $shippingPolicy, array $data): ShippingPolicy
    {
        $this->assertWithinScope($shippingPolicy, 'sirsoft-ecommerce.shipping-policies.update');

        // 수정 전 훅 + 스냅샷 — 본 폼 수정과 같은 훅 계약을 공유한다 (활동 로그 리스너 승계)
        HookManager::doAction('sirsoft-ecommerce.shipping_policy.before_update', $shippingPolicy, $data);
        $snapshot = $shippingPolicy->toArray();

        $shippingPolicy = DB::transaction(function () use ($shippingPolicy, $data) {
            foreach ([ClaimTypeEnum::EXCHANGE->value, ClaimTypeEnum::RETURN->value] as $type) {
                if (! is_array($data['addresses'] ?? null) || ! array_key_exists($type, $data['addresses'])) {
                    continue;
                }

                $address = $data['addresses'][$type];

                if ($address === null) {
                    $this->claimAddressRepository->deleteByPolicyAndType($shippingPolicy->id, $type);

                    continue;
                }

                $this->claimAddressRepository->upsert($shippingPolicy->id, $type, [
                    'recipient_name' => $address['recipient_name'],
                    'phone' => $address['phone'] ?? null,
                    'zipcode' => $address['zipcode'] ?? null,
                    'address1' => $address['address1'],
                    'address2' => $address['address2'] ?? null,
                    'country_code' => $address['country_code'] ?? 'KR',
                ]);
            }

            foreach ($data['country_settings'] ?? [] as $entry) {
                // 부분 수정 계약 — 값이 실린 축만 반영한다. 고정액 입력란이 비활성인 모드로
                // 바꾸면서 화면이 fee 키를 빼고 보내는 것은 자연스러운 페이로드인데, 그때
                // 0 으로 덮으면 운영자가 저장해 둔 고정 배송비가 조용히 사라진다. 판정 기준은
                // 본 폼 승계(preserveClaimFeeSettings)와 같다: null 은 "값 없음", 0 은 유효값.
                $payload = ['claim_fee_mode' => $entry['claim_fee_mode']];

                foreach (['return_fixed_fee', 'exchange_fixed_fee'] as $feeKey) {
                    if (($entry[$feeKey] ?? null) !== null) {
                        $payload[$feeKey] = $entry[$feeKey];
                    }
                }

                $shippingPolicy->countrySettings()
                    ->where('country_code', $entry['country_code'])
                    ->update($payload);
            }

            return $this->repository->update($shippingPolicy, ['updated_by' => Auth::id()]);
        });

        $shippingPolicy = $shippingPolicy->fresh()->load('countrySettings');

        // 수정 후 훅
        HookManager::doAction('sirsoft-ecommerce.shipping_policy.after_update', $shippingPolicy, $snapshot);

        return $shippingPolicy;
    }

    /**
     * 국가별 설정의 클레임 배송비 축(모드·반품비·교환비)을 승계합니다.
     *
     * 본 폼 저장은 국가별 설정을 삭제 후 재생성하므로, 폼이 클레임 값을 보내지 않으면
     * 클레임 탭에서 저장한 값이 기본값으로 초기화된다. `preserveApiAuthTokens()` 와 같은
     * 이유로 기존 값(동일 country_code)을 승계한다 — 폼이 값을 명시로 보낸 경우에만 그
     * 값을 쓴다. 판정 기준은 토큰 축과 다르다: 토큰은 빈 문자열이 마스킹 표식이라
     * `empty()` 로 보지만, 클레임 축은 0 이 유효값이므로 null 만 "값 없음" 으로 본다.
     *
     * @param  ShippingPolicy  $policy  기존 배송정책 (수정 대상)
     * @param  array  $countrySettingsData  신규 country_settings 입력
     * @return array 클레임 축이 승계된 country_settings
     */
    private function preserveClaimFeeSettings(ShippingPolicy $policy, array $countrySettingsData): array
    {
        $existingByCode = $policy->countrySettings()
            ->get()
            ->keyBy('country_code');

        foreach ($countrySettingsData as $i => $cs) {
            $existing = $existingByCode->get($cs['country_code'] ?? null);

            if (! $existing) {
                continue;
            }

            // 승계 판정은 "키 부재" 가 아니라 "값 없음"(키 부재 + 명시 null)이다. 세 컬럼 모두
            // NOT NULL 이라 null 은 애초에 저장 가능한 값이 아니므로, 폼이 마스킹된 null 을
            // 실어 보내도 기존 값이 살아남는다. 0 은 유효값이므로 그대로 통과한다(0 !== null).
            if (($cs['claim_fee_mode'] ?? null) === null) {
                $countrySettingsData[$i]['claim_fee_mode'] = $existing->claim_fee_mode?->value;
            }
            if (($cs['return_fixed_fee'] ?? null) === null) {
                $countrySettingsData[$i]['return_fixed_fee'] = $existing->return_fixed_fee;
            }
            if (($cs['exchange_fixed_fee'] ?? null) === null) {
                $countrySettingsData[$i]['exchange_fixed_fee'] = $existing->exchange_fixed_fee;
            }
        }

        return $countrySettingsData;
    }

    /**
     * 국가별 설정에서 값이 없는(명시 null) 클레임 배송비 축 키를 제거합니다.
     *
     * `claim_fee_mode`·`return_fixed_fee`·`exchange_fixed_fee` 는 모두 NOT NULL + DB 기본값
     * 컬럼이라 null 은 저장 가능한 값이 아니다. 키가 실려 있으면 Eloquent 가 명시 NULL 을
     * 쓰므로 기본값이 적용되지 않고 무결성 위반이 된다. 키를 떨궈야 컬럼 기본값 경로로
     * 떨어진다. `0` 은 유효값이므로 남긴다.
     *
     * @param  array  $countrySettingsData  국가별 설정 입력
     * @return array 명시 null 클레임 키가 제거된 국가별 설정
     */
    private function dropNullClaimFeeSettings(array $countrySettingsData): array
    {
        foreach ($countrySettingsData as $i => $cs) {
            foreach (['claim_fee_mode', 'return_fixed_fee', 'exchange_fixed_fee'] as $key) {
                if (array_key_exists($key, $cs) && $cs[$key] === null) {
                    unset($countrySettingsData[$i][$key]);
                }
            }
        }

        return $countrySettingsData;
    }

    /**
     * 국가별 설정의 통화를 상점 기본 통화로 강제합니다.
     *
     * 배송정책 통화는 상품 등록(ProductService)과 동일하게 관리자 입력값을 신뢰하지 않고
     * 서버가 상점 기본 통화(language_currency.default_currency)로 고정한다. 배송비는
     * 기본 통화 정수로 합산되므로 정책마다 다른 통화를 허용하면 합계 단위가 섞여 정합성이
     * 깨진다. 폼은 통화를 읽기전용으로만 표시한다.
     *
     * @param  array  $countrySettingsData  국가별 설정 입력
     * @return array 통화가 기본 통화로 고정된 국가별 설정
     */
    private function forceDefaultCurrency(array $countrySettingsData): array
    {
        $defaultCurrency = $this->getDefaultCurrency();

        foreach ($countrySettingsData as $i => $cs) {
            $countrySettingsData[$i]['currency_code'] = $defaultCurrency;
        }

        return $countrySettingsData;
    }

    /**
     * 상점 기본 통화 코드를 반환합니다.
     *
     * @return string 기본 통화 코드 (미설정 시 KRW)
     */
    private function getDefaultCurrency(): string
    {
        return g7_module_settings('sirsoft-ecommerce', 'language_currency')['default_currency'] ?? 'KRW';
    }

    /**
     * 계산 API 인증 토큰 재전송 유지 처리.
     *
     * 응답 직렬화 시 토큰은 마스킹되어 폼으로 내려가므로, 사용자가 토큰을 다시 입력하지
     * 않으면 빈 값이 전송된다. 이 경우 기존 country_setting(동일 country_code)의 토큰을
     * 복원하여 재입력을 강제하지 않는다. 사용자가 새 토큰을 입력한 경우에는 그대로 둔다.
     *
     * @param  ShippingPolicy  $policy  기존 배송정책 (수정 대상)
     * @param  array  $countrySettingsData  신규 country_settings 입력
     * @return array 토큰이 보존된 country_settings
     */
    private function preserveApiAuthTokens(ShippingPolicy $policy, array $countrySettingsData): array
    {
        $existingByCode = $policy->countrySettings()
            ->get()
            ->keyBy('country_code');

        foreach ($countrySettingsData as $i => $cs) {
            $config = $cs['api_config'] ?? null;

            if (! is_array($config) || ! empty($config['auth_token'])) {
                continue;
            }

            $existing = $existingByCode->get($cs['country_code'] ?? null);
            $existingToken = $existing?->api_config['auth_token'] ?? null;

            if (! empty($existingToken)) {
                $countrySettingsData[$i]['api_config']['auth_token'] = $existingToken;
            }
        }

        return $countrySettingsData;
    }

    /**
     * 배송정책 삭제
     *
     * @param  ShippingPolicy  $shippingPolicy  배송정책 모델
     * @return bool 삭제 성공 여부
     */
    public function delete(ShippingPolicy $shippingPolicy): bool
    {
        // 라우트가 강제하는 권한(SSoT)과 같은 식별자를 쓴다 — 삭제 경로는 `.delete` 다.
        $this->assertWithinScope($shippingPolicy, 'sirsoft-ecommerce.shipping-policies.delete');

        // 삭제 전 훅
        HookManager::doAction('sirsoft-ecommerce.shipping_policy.before_delete', $shippingPolicy);

        // 삭제 전 ID 캡처 (삭제 후 모델 id가 null이 될 수 있음)
        $shippingPolicyId = $shippingPolicy->id;

        // 자식 행(국가별 설정 · 교환/반품 회수지)을 명시적으로 지운다 — DB CASCADE 에 기대지 않는다.
        // 회수지를 빠뜨리면 FK 가 없는 환경(외래키 비활성 DB·복제본)에서 삭제된 정책의 회수지가
        // 남고, 같은 ID 로 다시 만들어진 정책이 남의 회수지를 물려받는다.
        // 한 트랜잭션으로 묶는다 — 중간에 실패하면 자식만 지워진 정책이 남는다.
        $result = DB::transaction(function () use ($shippingPolicy, $shippingPolicyId) {
            $this->repository->deleteCountrySettingsByPolicyIds([$shippingPolicyId]);
            $this->claimAddressRepository->deleteByPolicyIds([$shippingPolicyId]);

            return $this->repository->delete($shippingPolicy);
        });

        // 삭제 후 훅
        HookManager::doAction('sirsoft-ecommerce.shipping_policy.after_delete', $shippingPolicyId);

        return $result;
    }

    /**
     * 배송정책 사용여부 토글
     *
     * @param  ShippingPolicy  $shippingPolicy  배송정책 모델
     * @return ShippingPolicy 토글된 배송정책
     */
    public function toggleActive(ShippingPolicy $shippingPolicy): ShippingPolicy
    {
        $this->assertWithinScope($shippingPolicy, 'sirsoft-ecommerce.shipping-policies.update');

        // 토글 전 훅
        HookManager::doAction('sirsoft-ecommerce.shipping_policy.before_toggle_active', $shippingPolicy);

        $shippingPolicy = $this->repository->toggleActive($shippingPolicy);

        // 토글 후 훅
        HookManager::doAction('sirsoft-ecommerce.shipping_policy.after_toggle_active', $shippingPolicy);

        return $shippingPolicy;
    }

    /**
     * 배송정책 일괄 삭제
     *
     * @param  array  $ids  배송정책 ID 배열
     * @return int 삭제된 개수
     */
    public function bulkDelete(array $ids): int
    {
        // 스코프 검사와 스냅샷이 같은 조회를 공유한다 — 두 번 부르면 쿼리가 늘고,
        // 그 사이에 대상이 바뀌면 검사한 것과 기록하는 것이 달라진다.
        $targets = $this->repository->findByIdsKeyed($ids);

        $this->assertAllWithinScope($targets, 'sirsoft-ecommerce.shipping-policies.delete');

        // 삭제 전 스냅샷 캡처 (after_bulk_delete 훅에 전달)
        $snapshots = $targets->map->toArray()->all();

        // 일괄 삭제 전 훅
        HookManager::doAction('sirsoft-ecommerce.shipping_policy.before_bulk_delete', $ids);

        // 자식 행(국가별 설정 · 교환/반품 회수지) 명시적 삭제 — DB CASCADE 에 의존하지 않음(delete() 와 같은 이유).
        $count = DB::transaction(function () use ($ids) {
            $this->repository->deleteCountrySettingsByPolicyIds($ids);
            $this->claimAddressRepository->deleteByPolicyIds($ids);

            return $this->repository->bulkDelete($ids);
        });

        // 일괄 삭제 후 훅
        HookManager::doAction('sirsoft-ecommerce.shipping_policy.after_bulk_delete', $ids, $count, $snapshots);

        return $count;
    }

    /**
     * 배송정책 일괄 사용여부 변경
     *
     * @param  array  $ids  배송정책 ID 배열
     * @param  bool  $isActive  사용여부
     * @return int 변경된 개수
     */
    public function bulkToggleActive(array $ids, bool $isActive): int
    {
        // 스코프 검사와 스냅샷이 같은 조회를 공유한다(위 bulkDelete 와 동일 이유).
        $targets = $this->repository->findByIdsKeyed($ids);

        $this->assertAllWithinScope($targets, 'sirsoft-ecommerce.shipping-policies.update');

        // 변경 전 스냅샷 캡처 (after_bulk_toggle_active 훅에 전달)
        $snapshots = $targets->map->toArray()->all();

        // 일괄 변경 전 훅
        HookManager::doAction('sirsoft-ecommerce.shipping_policy.before_bulk_toggle_active', $ids, $isActive);

        $count = $this->repository->bulkToggleActive($ids, $isActive);

        // 일괄 변경 후 훅
        HookManager::doAction('sirsoft-ecommerce.shipping_policy.after_bulk_toggle_active', $ids, $isActive, $count, $snapshots);

        return $count;
    }

    /**
     * 활성화된 배송정책 목록 조회 (Select 옵션용)
     *
     * @return Collection 활성 배송정책 목록
     */
    public function getActiveList(): Collection
    {
        return $this->repository->getActiveList();
    }

    /**
     * 기본 배송정책 설정
     *
     * @param  ShippingPolicy  $shippingPolicy  배송정책 모델
     * @return ShippingPolicy 기본값으로 설정된 배송정책
     */
    public function setDefault(ShippingPolicy $shippingPolicy): ShippingPolicy
    {
        $this->assertWithinScope($shippingPolicy, 'sirsoft-ecommerce.shipping-policies.update');

        // 기본값 설정 전 훅
        HookManager::doAction('sirsoft-ecommerce.shipping_policy.before_set_default', $shippingPolicy);

        $shippingPolicy = DB::transaction(function () use ($shippingPolicy) {
            // 기존 기본값 해제
            $this->repository->clearDefault($shippingPolicy->id);

            // 새 기본값 설정
            return $this->repository->update($shippingPolicy, [
                'is_default' => true,
                'updated_by' => Auth::id(),
            ]);
        });

        // 기본값 설정 후 훅
        HookManager::doAction('sirsoft-ecommerce.shipping_policy.after_set_default', $shippingPolicy);

        return $shippingPolicy;
    }
}
