<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\UserAddressRepositoryInterface;

/**
 * 주문 서비스
 */
class OrderService
{
    public function __construct(
        protected OrderRepositoryInterface $repository,
        protected UserAddressRepositoryInterface $userAddressRepository
    ) {}

    /**
     * 주문 목록 조회
     *
     * @param array $filters 필터 조건
     * @return LengthAwarePaginator
     */
    public function getList(array $filters): LengthAwarePaginator
    {
        // 필터 데이터 가공 훅
        $filters = HookManager::applyFilters('sirsoft-ecommerce.order.filter_list_params', $filters);

        $perPage = (int) ($filters['per_page'] ?? 20);

        return $this->repository->getListWithFilters($filters, $perPage);
    }

    /**
     * 주문 통계 조회
     *
     * @return array 주문 통계 데이터 (상태별 건수, 오늘/월 매출 등)
     */
    public function getStatistics(): array
    {
        return $this->repository->getStatistics();
    }

    /**
     * 사용자별 주문상태 통계 조회
     *
     * @param int $userId 회원 ID
     * @return array 상태별 주문 건수
     */
    public function getUserStatistics(int $userId): array
    {
        return $this->repository->getUserStatistics($userId);
    }

    /**
     * 주문 상세 조회 (관계 포함)
     *
     * @param int $id 주문 ID
     * @return Order|null
     */
    public function getDetail(int $id): ?Order
    {
        $order = $this->repository->findWithRelations($id);

        if ($order) {
            HookManager::doAction('sirsoft-ecommerce.order.after_read', $order);
        }

        return $order;
    }

    /**
     * 주문번호로 조회
     *
     * @param string $orderNumber 주문번호
     * @return Order|null
     */
    public function getByOrderNumber(string $orderNumber): ?Order
    {
        $order = $this->repository->findByOrderNumber($orderNumber);

        if ($order) {
            HookManager::doAction('sirsoft-ecommerce.order.after_read', $order);
        }

        return $order;
    }

    /**
     * 주문 수정
     *
     * @param Order $order 주문 모델
     * @param array $data 수정 데이터
     * @return Order
     */
    public function update(Order $order, array $data): Order
    {
        $oldStatus = $order->order_status?->value;

        // 수정 전 훅
        HookManager::doAction('sirsoft-ecommerce.order.before_update', $order, $data);

        // 스냅샷 캡처 (ChangeDetector용)
        $snapshot = $order->toArray();

        // 데이터 가공 훅
        $data = HookManager::applyFilters('sirsoft-ecommerce.order.filter_update_data', $data, $order);

        // 수정자 정보 추가
        $data['updated_by'] = Auth::id();

        // 수취인/배송 관련 필드를 분리하여 shippingAddress 업데이트
        $recipientFields = [
            'recipient_name', 'recipient_phone', 'recipient_tel',
            'recipient_zipcode', 'recipient_address', 'recipient_detail_address', 'delivery_memo',
            'recipient_country_code',
        ];
        $recipientData = array_intersect_key($data, array_flip($recipientFields));
        $orderData = array_diff_key($data, array_flip($recipientFields));

        $order = $this->repository->update($order, $orderData);

        // 수취인 정보가 포함된 경우 배송지 주소 업데이트
        if (! empty($recipientData)) {
            $shippingAddress = $order->shippingAddress;
            if ($shippingAddress) {
                // 프론트엔드 필드명 → DB 필드명 매핑
                $addressData = [];
                if (array_key_exists('recipient_name', $recipientData)) {
                    $addressData['recipient_name'] = $recipientData['recipient_name'];
                }
                if (array_key_exists('recipient_phone', $recipientData)) {
                    $addressData['recipient_phone'] = $recipientData['recipient_phone'];
                }
                if (array_key_exists('recipient_zipcode', $recipientData)) {
                    $addressData['zipcode'] = $recipientData['recipient_zipcode'];
                }
                if (array_key_exists('recipient_address', $recipientData)) {
                    $addressData['address'] = $recipientData['recipient_address'];
                }
                if (array_key_exists('recipient_detail_address', $recipientData)) {
                    $addressData['address_detail'] = $recipientData['recipient_detail_address'];
                }
                if (array_key_exists('delivery_memo', $recipientData)) {
                    $addressData['delivery_memo'] = $recipientData['delivery_memo'];
                }
                if (array_key_exists('recipient_country_code', $recipientData)) {
                    $addressData['recipient_country_code'] = $recipientData['recipient_country_code'];
                }

                if (! empty($addressData)) {
                    $shippingAddress->update($addressData);
                }
            }
        }

        // 수정 후 훅 (스냅샷 전달 — OrderActivityLogListener가 활동 로그 기록)
        HookManager::doAction('sirsoft-ecommerce.order.after_update', $order, $snapshot);

        // 주문 상태 변경 시 알림 훅 발화
        $previousStatus = $snapshot['order_status'] ?? null;
        $currentStatus = $order->order_status;

        if ($currentStatus === OrderStatusEnum::SHIPPING && $previousStatus !== OrderStatusEnum::SHIPPING->value) {
            HookManager::doAction('sirsoft-ecommerce.order.after_ship', $order);
        }

        if ($currentStatus === OrderStatusEnum::CONFIRMED && $previousStatus !== OrderStatusEnum::CONFIRMED->value) {
            HookManager::doAction('sirsoft-ecommerce.order.after_complete', $order);
        }

        return $order;
    }

    /**
     * 주문 삭제 (Soft Delete)
     *
     * @param Order $order 주문 모델
     * @return bool
     */
    public function delete(Order $order): bool
    {
        // 삭제 전 훅
        HookManager::doAction('sirsoft-ecommerce.order.before_delete', $order);

        // 관계 레코드 명시적 삭제 (CASCADE 의존 금지)
        $order->taxInvoices()->delete();
        $order->shippings()->delete();
        $order->addresses()->delete();
        $order->payment()->delete();
        $order->options()->delete();

        $result = $this->repository->delete($order);

        // 삭제 후 훅
        HookManager::doAction('sirsoft-ecommerce.order.after_delete', $order);

        return $result;
    }

    /**
     * 주문 일괄 변경
     *
     * @param array $data 일괄 변경 데이터 (ids, order_status, carrier_id, tracking_number)
     * @return array 변경 결과
     */
    public function bulkUpdate(array $data): array
    {
        $ids = $data['ids'] ?? [];
        $orderStatus = $data['order_status'] ?? null;
        $carrierId = $data['carrier_id'] ?? null;
        $trackingNumber = $data['tracking_number'] ?? null;

        // 스냅샷 캡처 (ChangeDetector용)
        $snapshots = Order::whereIn('id', $ids)->get()->keyBy('id')->map->toArray()->all();

        // before 훅
        HookManager::doAction('sirsoft-ecommerce.order.before_bulk_update', $ids, $data);

        $updatedCount = 0;

        DB::transaction(function () use ($ids, $orderStatus, $carrierId, $trackingNumber, &$updatedCount) {
            // 주문 상태 일괄 변경
            if ($orderStatus !== null) {
                $updatedCount = $this->repository->bulkUpdateStatus($ids, $orderStatus);

                // 주문상품옵션 상태도 동일하게 일괄 변경
                $this->repository->bulkUpdateOptionStatus($ids, $orderStatus);
            }

            // 배송 정보 일괄 변경 (운송장 번호 또는 택배사)
            if ($carrierId !== null || $trackingNumber !== null) {
                $shippingUpdatedCount = $this->repository->bulkUpdateShipping($ids, $carrierId, $trackingNumber);
                $updatedCount = max($updatedCount, $shippingUpdatedCount);
            }
        });

        // after 훅 (스냅샷 전달)
        HookManager::doAction('sirsoft-ecommerce.order.after_bulk_update', $ids, $updatedCount, $snapshots);

        return [
            'updated_count' => $updatedCount,
            'requested_count' => count($ids),
        ];
    }

    /**
     * 주문 일괄 상태 변경
     *
     * @param array $ids 주문 ID 배열
     * @param string $status 변경할 상태
     * @return array 변경 결과
     */
    public function bulkUpdateStatus(array $ids, string $status): array
    {
        // 스냅샷 캡처 (ChangeDetector용)
        $snapshots = Order::whereIn('id', $ids)->get()->keyBy('id')->map->toArray()->all();

        // before 훅
        HookManager::doAction('sirsoft-ecommerce.order.before_bulk_status_update', $ids, $status);

        $updatedCount = $this->repository->bulkUpdateStatus($ids, $status);

        // after 훅 (스냅샷 전달)
        HookManager::doAction('sirsoft-ecommerce.order.after_bulk_status_update', $ids, $updatedCount, $snapshots);

        return [
            'updated_count' => $updatedCount,
            'requested_count' => count($ids),
        ];
    }

    /**
     * 주문 일괄 배송 정보 변경
     *
     * @param array $ids 주문 ID 배열
     * @param int|null $carrierId 택배사 ID
     * @param string|null $trackingNumber 운송장 번호
     * @return array 변경 결과
     */
    public function bulkUpdateShipping(array $ids, ?int $carrierId, ?string $trackingNumber): array
    {
        // 스냅샷 캡처 (ChangeDetector용)
        $snapshots = Order::whereIn('id', $ids)->get()->keyBy('id')->map->toArray()->all();

        // before 훅
        HookManager::doAction('sirsoft-ecommerce.order.before_bulk_shipping_update', $ids, [
            'carrier_id' => $carrierId,
            'tracking_number' => $trackingNumber,
        ]);

        $updatedCount = $this->repository->bulkUpdateShipping($ids, $carrierId, $trackingNumber);

        // after 훅 (스냅샷 전달)
        HookManager::doAction('sirsoft-ecommerce.order.after_bulk_shipping_update', $ids, $updatedCount, $snapshots);

        return [
            'updated_count' => $updatedCount,
            'requested_count' => count($ids),
        ];
    }

    /**
     * 주문 관련 이메일을 발송합니다.
     *
     * @param Order $order 주문 모델
     * @param string $email 수신자 이메일
     * @param string $message 이메일 본문
     * @return void
     */
    public function sendEmail(Order $order, string $email, string $message): void
    {
        $appName = config('app.name');
        $subject = __('sirsoft-ecommerce::messages.orders.email_subject', [
            'app_name' => $appName,
            'order_number' => $order->order_number,
        ]);

        Mail::raw($message, function ($mail) use ($email, $subject) {
            $mail->to($email)->subject($subject);
            $mail->getHeaders()->addTextHeader('X-G7-Source', 'order_email');
            $mail->getHeaders()->addTextHeader('X-G7-Extension-Type', 'module');
            $mail->getHeaders()->addTextHeader('X-G7-Extension-Id', 'sirsoft-ecommerce');
        });

        HookManager::doAction('sirsoft-ecommerce.order.after_send_email', [
            'recipientEmail' => $email,
            'subject' => $subject,
            'body' => $message,
            'orderId' => $order->id,
            'orderNumber' => $order->order_number,
            'extensionType' => 'module',
            'extensionIdentifier' => 'sirsoft-ecommerce',
            'source' => 'order_email',
        ]);
    }

    /**
     * 엑셀 내보내기용 주문 조회
     *
     * @param array $filters 필터 조건
     * @param array $ids 특정 주문 ID 배열 (선택된 항목만)
     * @return Collection
     */
    public function getForExport(array $filters, array $ids = []): Collection
    {
        // 필터 데이터 가공 훅
        $filters = HookManager::applyFilters('sirsoft-ecommerce.order.filter_export_params', $filters);

        return $this->repository->getForExport($filters, $ids);
    }

    /**
     * 주문 배송지 주소를 변경합니다.
     *
     * @param Order $order 주문
     * @param array $data 배송지 변경 데이터
     * @return Order 갱신된 주문
     * @throws \Exception 배송 전 상태가 아닌 경우 또는 배송지를 찾을 수 없는 경우
     */
    public function updateShippingAddress(Order $order, array $data): Order
    {
        $status = $order->order_status;

        if (! $status->isBeforeShipping()) {
            throw new \Exception(__('sirsoft-ecommerce::messages.orders.cannot_modify_address'));
        }

        return DB::transaction(function () use ($order, $data) {
            // 저장된 배송지 선택인 경우 해당 주소 데이터를 로드
            if (! empty($data['address_id'])) {
                $savedAddress = $this->userAddressRepository->find((int) $data['address_id']);

                if (! $savedAddress || $savedAddress->user_id !== $order->user_id) {
                    throw new \Exception(__('sirsoft-ecommerce::messages.address.not_found'));
                }

                $data = [
                    'recipient_name' => $savedAddress->recipient_name,
                    'recipient_phone' => $savedAddress->recipient_phone,
                    'country_code' => $savedAddress->country_code ?? 'KR',
                    'zipcode' => $savedAddress->zipcode,
                    'address' => $savedAddress->address,
                    'address_detail' => $savedAddress->address_detail,
                ];
            }

            // 변경 전 훅
            HookManager::doAction('sirsoft-ecommerce.order.before_update_shipping_address', $order, $data);

            // 배송지 주소 업데이트
            $shippingAddress = $order->addresses()
                ->where('address_type', 'shipping')
                ->first();

            // 변경 전 스냅샷 캡처 (활동 로그용)
            $addressSnapshot = $shippingAddress ? $shippingAddress->toArray() : null;

            if ($shippingAddress) {
                $addressData = [
                    'recipient_name' => $data['recipient_name'],
                    'recipient_phone' => $data['recipient_phone'],
                    'recipient_country_code' => $data['country_code'] ?? 'KR',
                ];

                $isDomestic = ($data['country_code'] ?? 'KR') === 'KR';

                if ($isDomestic) {
                    $addressData['zipcode'] = $data['zipcode'] ?? null;
                    $addressData['address'] = $data['address'] ?? null;
                    $addressData['address_detail'] = $data['address_detail'] ?? null;
                } else {
                    $addressData['address_line_1'] = $data['address_line_1'] ?? null;
                    $addressData['address_line_2'] = $data['address_line_2'] ?? null;
                    $addressData['intl_city'] = $data['intl_city'] ?? null;
                    $addressData['intl_state'] = $data['intl_state'] ?? null;
                    $addressData['intl_postal_code'] = $data['intl_postal_code'] ?? null;
                }

                // 배송 메모 (항상 업데이트)
                if (array_key_exists('delivery_memo', $data)) {
                    $addressData['delivery_memo'] = $data['delivery_memo'];
                }

                $shippingAddress->update($addressData);
            }

            // 변경 후 훅 (배송지 + 스냅샷 전달)
            $freshAddress = $shippingAddress?->fresh();
            HookManager::doAction('sirsoft-ecommerce.order.after_update_shipping_address', $order, $freshAddress, $addressSnapshot);

            return $order->fresh(['addresses']);
        });
    }

    /**
     * 주문 옵션을 구매확정합니다.
     *
     * 모든 비취소 옵션이 확정되면 주문 전체도 확정 상태로 전환합니다.
     *
     * @param Order $order 주문 모델
     * @param OrderOption $option 주문 옵션 모델
     * @return OrderOption 갱신된 옵션 모델
     */
    public function confirmOption(Order $order, OrderOption $option): OrderOption
    {
        return DB::transaction(function () use ($order, $option) {
            HookManager::doAction('sirsoft-ecommerce.order-option.before_confirm', $order, $option);

            $option->update([
                'option_status' => OrderStatusEnum::CONFIRMED,
                'confirmed_at' => now(),
            ]);

            // 모든 비취소 옵션이 확정되면 주문도 CONFIRMED로 전환
            $hasUnconfirmed = $order->options()
                ->whereNotIn('option_status', [
                    OrderStatusEnum::CANCELLED->value,
                    OrderStatusEnum::PARTIAL_CANCELLED->value,
                    OrderStatusEnum::CONFIRMED->value,
                ])
                ->exists();

            if (! $hasUnconfirmed) {
                $order->update([
                    'order_status' => OrderStatusEnum::CONFIRMED,
                    'confirmed_at' => now(),
                ]);
            }

            HookManager::doAction('sirsoft-ecommerce.order-option.after_confirm', $order, $option);

            return $option->fresh();
        });
    }
}
