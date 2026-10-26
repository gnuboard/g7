<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Admin;

use App\Extension\HookManager;
use App\Helpers\PermissionHelper;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Models\User;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Ecommerce\Exceptions\ExportException;
use Modules\Sirsoft\Ecommerce\Exports\Contracts\ExportTypeInterface;
use Modules\Sirsoft\Ecommerce\Exports\ExportTypeRegistry;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\DestroyExportRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\DownloadExportRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\EstimateExportRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ExportBulkDeleteRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ExportCatalogRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ExportDownloadHistoryRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ExportListRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\RetryExportRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ShowExportRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreExportRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\DownloadByTokenRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\ExportCollection;
use Modules\Sirsoft\Ecommerce\Http\Resources\ExportDownloadCollection;
use Modules\Sirsoft\Ecommerce\Http\Resources\ExportResource;
use Modules\Sirsoft\Ecommerce\Models\Export;
use Modules\Sirsoft\Ecommerce\Services\ExportService;
use Modules\Sirsoft\Ecommerce\Support\ExportAccess;
use Symfony\Component\HttpFoundation\Response;

/**
 * 내보내기 컨트롤러 — 목록 화면 모달(카탈로그·예상 건수·요청)과 내보내기 관리 화면
 *
 * 내보내기 권한은 라우트 미들웨어가 보고, 범위(본인/소유 역할/전체 — 역할 관리의 코어 표준 범위)는 이 컨트롤러가
 * 작업마다 {@see ExportAccess} 로 다시 본다. 그 위에 「그 유형의 조회 권한」(주문=orders.read …)과
 * 개인정보 권한을 더 확인한다 — 주문 조회 권한을 잃은 운영자가 예전에 만든
 * 주문 파일을 받아 가지 못하게 하려는 것이다.
 */
class ExportController extends AdminBaseController
{
    /**
     * @param  ExportService  $service  내보내기 서비스
     * @param  ExportTypeRegistry  $types  유형 레지스트리
     */
    public function __construct(
        private readonly ExportService $service,
        private readonly ExportTypeRegistry $types,
    ) {}

    /**
     * 모달 카탈로그 (형식·행 단위·필드·화면 컬럼 매핑·기간·언어·경고)
     *
     * @param  ExportCatalogRequest  $request  요청
     * @return JsonResponse 카탈로그
     */
    public function catalog(ExportCatalogRequest $request): JsonResponse
    {
        if (! $this->canReadType($request->exportType(), $request->user())) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'exceptions.export.domain_permission_required', 403);
        }

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.export.catalog_retrieved',
            $this->service->catalog($request->exportType(), $request->user(), $request->validated('start_date'), $request->validated('end_date'))
        );
    }

    /**
     * 예상 행 수
     *
     * @param  EstimateExportRequest  $request  요청
     * @return JsonResponse 예상 행 수
     */
    public function estimate(EstimateExportRequest $request): JsonResponse
    {
        if (! $this->canReadType($request->exportType(), $request->user())) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'exceptions.export.domain_permission_required', 403);
        }

        try {
            $count = $this->service->estimate($request->exportType(), $request->exportData(), $request->user());

            return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.export.estimated', ['count' => $count]);
        } catch (Exception $e) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'exceptions.operation_failed', 500);
        }
    }

    /**
     * 내보내기 요청 — 작업을 만들고 큐에 넣는다(202)
     *
     * @param  StoreExportRequest  $request  요청
     * @return JsonResponse 작업
     */
    public function store(StoreExportRequest $request): JsonResponse
    {
        $type = $request->exportType();
        $user = $request->user();
        $personalDenied = $request->requestsPersonalFields() && ! PermissionHelper::check('sirsoft-ecommerce.exports.personal_data', $user);

        if (! $this->canReadType($type, $user) || $personalDenied) {
            HookManager::doAction('sirsoft-ecommerce.export.request_denied', $type->key(), 'forbidden', $user);

            return ResponseHelper::moduleError('sirsoft-ecommerce', 'exceptions.export.domain_permission_required', 403);
        }

        try {
            $export = $this->service->request(
                $type,
                $request->exportData(),
                $user,
                (string) $request->header('Idempotency-Key'),
            );

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.export.requested',
                (new ExportResource($export))->toArray($request) + ['warnings_now' => $this->service->warnings()],
                Response::HTTP_ACCEPTED
            );
        } catch (ExportException $e) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'exceptions.export.'.$e->errorCode(), $e->status());
        } catch (Exception $e) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'exceptions.operation_failed', 500);
        }
    }

    /**
     * 내보내기 관리 목록
     *
     * @param  ExportListRequest  $request  요청
     * @return JsonResponse 목록
     */
    public function index(ExportListRequest $request): JsonResponse
    {
        $list = $this->service->list($request->validated(), $request->user());

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.export.list_retrieved',
            (new ExportCollection($list))->withWarnings($this->service->adminWarnings())->toArray($request)
        );
    }

    /**
     * 작업 상세 (진행률 갱신용)
     *
     * @param  ShowExportRequest  $request  요청
     * @param  Export  $export  작업
     * @return JsonResponse 작업
     */
    public function show(ShowExportRequest $request, Export $export): JsonResponse
    {
        if (! ExportAccess::inScope($export, $request->user())) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'exceptions.export.scope_denied', 403);
        }

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.export.retrieved',
            (new ExportResource($export->load('requester')))->toArray($request)
        );
    }

    /**
     * 파일 다운로드 (이력 기록)
     *
     * @param  DownloadExportRequest  $request  요청
     * @param  Export  $export  작업
     * @return Response 파일 스트림 또는 오류
     */
    public function download(DownloadExportRequest $request, Export $export): Response
    {
        if (! ExportAccess::inScope($export, $request->user(), ExportAccess::DOWNLOAD_PERMISSION)) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'exceptions.export.scope_denied', 403);
        }

        if (! ExportAccess::canReadSource($export, $request->user(), $this->types->find($export->type))) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'exceptions.export.domain_permission_required', 403);
        }

        try {
            return $this->service->download($export, $request->user(), $request->ip(), $request->userAgent());
        } catch (ExportException $e) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'exceptions.export.'.$e->errorCode(), $e->status());
        } catch (Exception $e) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'exceptions.operation_failed', 500);
        }
    }

    /**
     * 일회용 다운로드 주소 발급 — 브라우저가 이 주소를 직접 열어 스트리밍으로 받는다
     *
     * 권한·범위·원본 조회·개인정보 검사는 파일 다운로드와 같다. 주소는 60초 안에 한 번만 쓸 수 있다.
     *
     * @param  DownloadExportRequest  $request  요청
     * @param  Export  $export  작업
     * @return JsonResponse 주소와 유효 시간(초)
     */
    public function downloadLink(DownloadExportRequest $request, Export $export): JsonResponse
    {
        if (! ExportAccess::inScope($export, $request->user(), ExportAccess::DOWNLOAD_PERMISSION)) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'exceptions.export.scope_denied', 403);
        }
        if (! ExportAccess::canReadSource($export, $request->user(), $this->types->find($export->type))) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'exceptions.export.domain_permission_required', 403);
        }

        try {
            $token = $this->service->issueDownloadToken($export, $request->user());

            return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.export.download_link_issued', [
                'url' => route('api.modules.sirsoft-ecommerce.exports.file', ['token' => $token], false),
                'expires_in' => ExportService::DOWNLOAD_TOKEN_TTL_SECONDS,
                // 발급 시점의 횟수 — 화면이 이 값보다 커질 때까지 상세를 다시 읽어 기록 반영을 확인한다
                'download_count' => (int) $export->download_count,
            ]);
        } catch (ExportException $e) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'exceptions.export.'.$e->errorCode(), $e->status());
        }
    }

    /**
     * 일회용 토큰으로 파일 받기 (다운로드 이력·횟수·활동 로그 기록)
     *
     * 토큰 주인의 다운로드 권한·범위·원본 조회·개인정보 권한을 쓰는 시점에 다시 본다 — 발급 뒤 권한을 잃었으면 막는다.
     * 활동 로그 행위자가 토큰 주인이 되도록 이 요청 동안 그 사람을 로그인 사용자로 둔다.
     *
     * @param  DownloadByTokenRequest  $request  요청
     * @param  string  $token  토큰
     * @return Response 파일 스트림 또는 오류
     */
    // route-param:unused $token 은 DownloadByTokenRequest 가 검증 대상으로 옮겨 담고, 본문은 검증(확장 규칙 포함)을 거친 값만 쓴다.
    public function downloadByToken(DownloadByTokenRequest $request, string $token): Response
    {
        $claim = $this->service->consumeDownloadToken($request->validated()['token']);
        if ($claim === null) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'exceptions.export.download_link_expired', 410);
        }

        ['export' => $export, 'user' => $user] = $claim;
        Auth::setUser($user);

        if (! ExportAccess::inScope($export, $user, ExportAccess::DOWNLOAD_PERMISSION)) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'exceptions.export.scope_denied', 403);
        }
        if (! ExportAccess::canReadSource($export, $user, $this->types->find($export->type))) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'exceptions.export.domain_permission_required', 403);
        }

        try {
            return $this->service->download($export, $user, $request->ip(), $request->userAgent());
        } catch (ExportException $e) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'exceptions.export.'.$e->errorCode(), $e->status());
        } catch (Exception $e) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'exceptions.operation_failed', 500);
        }
    }

    /**
     * 다운로드 이력
     *
     * @param  ExportDownloadHistoryRequest  $request  요청
     * @param  Export  $export  작업
     * @return JsonResponse 이력 목록
     */
    public function downloads(ExportDownloadHistoryRequest $request, Export $export): JsonResponse
    {
        if (! ExportAccess::inScope($export, $request->user())) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'exceptions.export.scope_denied', 403);
        }

        $perPage = (int) ($request->validated()['per_page'] ?? 20);
        $history = $this->service->downloadHistory($export, $perPage, $request->user());

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.export.downloads_retrieved',
            (new ExportDownloadCollection($history))->forExport($export)->toArray($request)
        );
    }

    /**
     * 실패한 작업 다시 요청(같은 조건으로 새 작업)
     *
     * @param  RetryExportRequest  $request  요청
     * @param  Export  $export  실패한 작업
     * @return JsonResponse 새 작업
     */
    public function retry(RetryExportRequest $request, Export $export): JsonResponse
    {
        if (! ExportAccess::inScope($export, $request->user())) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'exceptions.export.scope_denied', 403);
        }

        if (! $this->canReadDomain($export, $request->user())) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'exceptions.export.domain_permission_required', 403);
        }

        try {
            $new = $this->service->retry($export, $request->user(), $request->validated('reason'));

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.export.requested',
                (new ExportResource($new))->toArray($request),
                Response::HTTP_ACCEPTED
            );
        } catch (ExportException $e) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'exceptions.export.'.$e->errorCode(), $e->status());
        } catch (Exception $e) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'exceptions.operation_failed', 500);
        }
    }

    /**
     * 작업 삭제 (파일 삭제 + 소프트 삭제, 진행중이면 409)
     *
     * @param  DestroyExportRequest  $request  요청
     * @param  Export  $export  작업
     * @return JsonResponse 결과
     */
    public function destroy(DestroyExportRequest $request, Export $export): JsonResponse
    {
        if (! ExportAccess::inScope($export, $request->user(), ExportAccess::DELETE_PERMISSION)) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'exceptions.export.scope_denied', 403);
        }

        try {
            $this->service->delete($export, $request->user());

            return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.export.deleted');
        } catch (ExportException $e) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'exceptions.export.'.$e->errorCode(), $e->status());
        } catch (Exception $e) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'exceptions.operation_failed', 500);
        }
    }

    /**
     * 선택 삭제 — 진행중·권한 범위 밖·이미 없는 작업은 건너뛰고 그 수를 알린다
     *
     * @param  ExportBulkDeleteRequest  $request  요청
     * @return JsonResponse 처리 결과
     */
    public function bulkDestroy(ExportBulkDeleteRequest $request): JsonResponse
    {
        try {
            $result = $this->service->bulkDelete(array_map('intval', $request->validated()['ids']), $request->user());

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.export.bulk_deleted',
                ['deleted_count' => $result['deleted'], 'skipped_count' => $result['skipped']],
                200,
                ['count' => $result['deleted'], 'skipped' => $result['skipped']]
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'exceptions.operation_failed', 500);
        }
    }

    /**
     * 그 파일 유형의 조회 권한(과 개인정보 파일이면 개인정보 권한)이 있는지 — 다시 요청용
     *
     * 다시 요청은 요청한 사람의 범위로 새로 뽑으므로 범위를 보지 않는다. 이미 만든 파일을 받는 판정은
     * {@see ExportAccess::canReadSource()} 가 범위까지 본다.
     *
     * @param  Export  $export  작업
     * @param  User|null  $user  요청자
     * @return bool 허용 여부
     */
    private function canReadDomain(Export $export, ?User $user): bool
    {
        return $this->canReadType($this->types->find($export->type), $user)
            && ExportAccess::canHandlePersonal($export, $user);
    }

    /**
     * 그 유형의 원본 목록을 볼 권한(주문=orders.read …)이 있는지 — 유형 검증을 통과한 뒤 판정한다
     *
     * @param  ExportTypeInterface|null  $type  유형
     * @param  User|null  $user  요청자
     * @return bool 허용 여부
     */
    private function canReadType(?ExportTypeInterface $type, ?User $user): bool
    {
        return $type !== null && PermissionHelper::check($type->readPermission(), $user);
    }
}
