<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use Modules\Sirsoft\Ecommerce\Exceptions\CartUnavailableException;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductAdditionalOptionValueRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductRepositoryInterface;

/**
 * 추가옵션 선택 검증·정규화 서비스
 *
 * 담기(CartService) 와 바로구매(TempOrderService) 양 진입점에서 동일한 서버 SSoT
 * 검증을 재사용하기 위한 도메인 서비스입니다.
 */
class AdditionalOptionSelectionService
{
    public function __construct(
        protected ProductAdditionalOptionValueRepositoryInterface $additionalOptionValueRepository,
        protected ProductRepositoryInterface $productRepository
    ) {}

    /**
     * 추가옵션 선택을 검증하고 정규화합니다.
     *
     * - value_id 가 활성·해당 상품 소속이 아니면 422 (D12)
     * - 필수 그룹 미선택 시 422 (D9/D12)
     * - 그룹당 1개 선택(라디오) 고정 — 같은 그룹 중복 선택은 마지막 값으로 정규화
     * - 선택지의 allow_custom_text=true 이면 custom_text 입력 필수 (빈값/공백만이면 422, E4/Q-E1)
     * - allow_custom_text=false 인데 custom_text 전송 시 무시(드롭, E4)
     *
     * @param  int  $productId  상품 ID
     * @param  array|null  $selections  추가옵션 선택 [{additional_option_id, value_id, custom_text?}]
     * @return array 정규화된 선택 배열 (그룹당 1개, 빈 배열 가능)
     *
     * @throws CartUnavailableException 유효하지 않은/필수 미선택 추가옵션인 경우
     */
    public function validateAndNormalize(int $productId, ?array $selections): array
    {
        $selections = $selections ?? [];

        // 상품에 정의된 활성 선택지 lookup (value_id 키)
        $activeValues = $this->additionalOptionValueRepository->getActiveByProductKeyed($productId);

        // 그룹당 1개로 정규화 (라디오) — 값과 직접입력 텍스트를 함께 보관
        $byGroup = [];
        foreach ($selections as $selection) {
            $valueId = (int) ($selection['value_id'] ?? 0);
            if ($valueId <= 0) {
                continue;
            }

            $value = $activeValues->get($valueId);

            // value_id 가 활성·해당 상품 소속이 아니면 422 (D12)
            if (! $value) {
                throw CartUnavailableException::fromItems([[
                    'product_id' => $productId,
                    'value_id' => $valueId,
                    'reason' => 'additional_option_invalid',
                ]]);
            }

            // 직접입력 텍스트 정규화: allow_custom_text 인 선택지만 보존, 그 외엔 드롭 (E4)
            // 빈값 필수 검증은 그룹 is_required 가 필요하므로 아래 그룹 루프에서 수행
            $customText = null;
            $allowCustomText = (bool) $value->allow_custom_text;
            if ($allowCustomText) {
                $customText = trim((string) ($selection['custom_text'] ?? ''));
            }

            $byGroup[(int) $value->additional_option_id] = [
                'value_id' => $valueId,
                'custom_text' => $customText,
                'allow_custom_text' => $allowCustomText,
                'value_name' => $value->getLocalizedName(),
            ];
        }

        // 필수 그룹 미선택 + 필수 그룹의 직접입력 빈값 검증 (D9/D12/Q-E1)
        $product = $this->productRepository->find($productId);
        if ($product) {
            $product->loadMissing('additionalOptions');
            foreach ($product->additionalOptions as $group) {
                // 필수 그룹 미선택 차단
                if ($group->is_required && ! isset($byGroup[$group->id])) {
                    throw CartUnavailableException::fromItems([[
                        'product_id' => $productId,
                        'additional_option_id' => $group->id,
                        'name' => $group->getLocalizedName(),
                        'reason' => 'additional_option_required',
                    ]]);
                }

                // 직접입력 텍스트 필수는 "필수 그룹"에 한해 강제 (비필수 그룹은 빈값 허용)
                $entry = $byGroup[$group->id] ?? null;
                if ($group->is_required && $entry && $entry['allow_custom_text'] && $entry['custom_text'] === '') {
                    throw CartUnavailableException::fromItems([[
                        'product_id' => $productId,
                        'additional_option_id' => $group->id,
                        'value_id' => $entry['value_id'],
                        'name' => $group->getLocalizedName(),
                        'reason' => 'additional_option_custom_text_required',
                    ]]);
                }
            }
        }

        $normalized = [];
        foreach ($byGroup as $additionalOptionId => $entry) {
            $row = [
                'additional_option_id' => $additionalOptionId,
                'value_id' => $entry['value_id'],
            ];

            // 직접입력 텍스트는 존재할 때만 포함 (allow_custom_text 선택지 한정)
            if ($entry['custom_text'] !== null && $entry['custom_text'] !== '') {
                $row['custom_text'] = $entry['custom_text'];
            }

            $normalized[] = $row;
        }

        return $normalized;
    }
}
