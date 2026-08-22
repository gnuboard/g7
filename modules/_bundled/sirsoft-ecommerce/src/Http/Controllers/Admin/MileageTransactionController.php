<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Ecommerce\DTO\MileageAdminDeductDto;
use Modules\Sirsoft\Ecommerce\DTO\MileageAdminEarnDto;
use Modules\Sirsoft\Ecommerce\Exceptions\MileageValidationException;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ExtendMileageExpiryRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\MileageTransactionListRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreMileageTransactionRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateMileageTransactionRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\MileageTransactionCollection;
use Modules\Sirsoft\Ecommerce\Http\Resources\MileageTransactionResource;
use Modules\Sirsoft\Ecommerce\Services\UserMileageService;

/**
 * 관리자 마일리지 내역 관리 컨트롤러
 */
class MileageTransactionController extends AdminBaseController
{
    /**
     * @param  UserMileageService  $mileageService  마일리지 서비스
     */
    public function __construct(
        private UserMileageService $mileageService,
    ) {}

    /**
     * 마일리지 내역 목록 조회 (필터/페이지네이션)
     *
     * @param  MileageTransactionListRequest  $request  요청
     * @return JsonResponse 응답
     */
    public function index(MileageTransactionListRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = (int) ($filters['per_page'] ?? 20);

        $transactions = $this->mileageService->paginateAdminHistory($filters, $perPage);

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.mileage.list_retrieved',
            (new MileageTransactionCollection($transactions))
                ->withCurrencies($this->mileageService->getFilterCurrencies())
        );
    }

    /**
     * 수동 지급/차감
     *
     * @param  StoreMileageTransactionRequest  $request  요청
     * @return JsonResponse 응답
     */
    public function store(StoreMileageTransactionRequest $request): JsonResponse
    {
        $data = $request->validated();
        $userId = (int) $this->mileageService->resolveUserIdByUuid($data['user_id']);
        $grantedBy = (int) Auth::id();

        try {
            if ($data['action'] === 'earn') {
                $transaction = $this->mileageService->adminEarn($userId, new MileageAdminEarnDto(
                    amount: (int) $data['amount'],
                    currency: $data['currency'],
                    grantedBy: $grantedBy,
                    memo: $data['memo'] ?? null,
                    description: $data['description'] ?? null,
                    expiresAt: $data['expires_at'] ?? null,
                    useDefaultExpiry: (bool) ($data['use_default_expiry'] ?? true),
                ));
            } else {
                $transaction = $this->mileageService->adminDeduct($userId, new MileageAdminDeductDto(
                    amount: (int) $data['amount'],
                    currency: $data['currency'],
                    grantedBy: $grantedBy,
                    memo: $data['memo'] ?? null,
                    description: $data['description'] ?? null,
                ));
            }
        } catch (MileageValidationException $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.mileage.validation_failed',
                422,
                ['general' => [$e->getMessage()]]
            );
        }

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.mileage.transaction_created',
            new MileageTransactionResource($transaction),
            201
        );
    }

    /**
     * 적립건 편집 — 사유(memo) 변경 + 만료일(expires_at) 직접 지정
     *
     * 마일리지 원장은 불변이므로 적립계 거래의 부가 필드만 보정합니다. 전달된 키만 갱신하며
     * (memo / expires_at), 적립계 외 거래·소멸/사용된 lot 등 도메인 규칙 위반은 Service 가
     * MileageValidationException(422)으로 거부합니다.
     *
     * @param  UpdateMileageTransactionRequest  $request  요청
     * @param  int  $id  거래 ID
     * @return JsonResponse 응답
     */
    public function update(UpdateMileageTransactionRequest $request, int $id): JsonResponse
    {
        $data = $request->validated();
        $touchMemo = $request->has('memo');
        $touchExpiry = $request->has('expires_at');

        try {
            $transaction = $this->mileageService->updateAdminTransaction(
                $id,
                $touchMemo ? ($data['memo'] ?? null) : null,
                $touchExpiry && ! empty($data['expires_at']) ? Carbon::parse($data['expires_at']) : null,
                $touchMemo,
                $touchExpiry,
            );
        } catch (MileageValidationException $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.mileage.validation_failed',
                422,
                ['general' => [$e->getMessage()]]
            );
        }

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.mileage.transaction_updated',
            new MileageTransactionResource($transaction)
        );
    }

    /**
     * 일괄 유효기간 연장
     *
     * @param  ExtendMileageExpiryRequest  $request  요청
     * @return JsonResponse 응답
     */
    public function extendExpiry(ExtendMileageExpiryRequest $request): JsonResponse
    {
        $data = $request->validated();
        $userId = (int) $this->mileageService->resolveUserIdByUuid($data['user_id']);

        $count = $this->mileageService->extendLotExpiry(
            $userId,
            array_map('intval', $data['lot_ids']),
            (int) $data['days'],
        );

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.mileage.expiry_extended',
            ['extended_count' => $count]
        );
    }

    /**
     * 거래 행 확장 (연결 거래 조회 — FIFO 소비 source / 복원 연결)
     *
     * @param  int  $id  거래 ID
     * @return JsonResponse 응답
     */
    public function linked(int $id): JsonResponse
    {
        $linked = $this->mileageService->getLinkedTransactions($id);
        if ($linked === null) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'messages.mileage.not_found', 404);
        }

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.mileage.linked_retrieved',
            new MileageTransactionCollection($linked)
        );
    }
}
