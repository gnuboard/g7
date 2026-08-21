<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Repositories;

use App\Models\NotificationDefinition;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Plugins\Sirsoft\MessageBizppurio\Enums\BizppurioTemplateStatus;
use Plugins\Sirsoft\MessageBizppurio\Models\BizppurioTemplate;
use Plugins\Sirsoft\MessageBizppurio\Repositories\Contracts\BizppurioTemplateRepositoryInterface;

/**
 * 비즈뿌리오 알림 템플릿 Repository 구현체 (#597).
 */
class BizppurioTemplateRepository implements BizppurioTemplateRepositoryInterface
{
    /**
     * 관리 화면 목록이 실제로 그리는 컬럼 (목록 컬럼 프루닝 — content/approved_content 등
     * 대형 JSON 은 상세 조회 전용이라 목록에서 제외한다).
     *
     * @var array<int, string>
     */
    private const LIST_COLUMNS = [
        'id',
        'notification_type',
        'alimtalk_enabled',
        'template_code',
        'status',
        'requested_at',
        'approved_at',
        'last_synced_at',
        'fallback_sms_enabled',
        'sms_only',
        'is_active',
    ];

    /**
     * PK 로 템플릿을 조회합니다.
     *
     * @param  int  $id  템플릿 PK
     * @return BizppurioTemplate|null 매칭 행 또는 null
     */
    public function find(int $id): ?BizppurioTemplate
    {
        return BizppurioTemplate::query()->find($id);
    }

    /**
     * 알림 유형으로 템플릿을 조회합니다.
     *
     * @param  string  $notificationType  코어 notification_definitions.type
     * @return BizppurioTemplate|null 매칭 행 또는 null
     */
    public function findByType(string $notificationType): ?BizppurioTemplate
    {
        return BizppurioTemplate::query()
            ->byNotificationType($notificationType)
            ->first();
    }

    /**
     * 관리 화면 목록을 페이지네이션 조회합니다 (알림 정의 라벨·소속 조인).
     *
     * 알림 정의(1:1, type unique)와 LEFT JOIN 해 목록이 그리는 라벨(definition_name)과
     * 소속(definition_source)을 함께 싣는다. 1:1 조인이므로 행 부풀림이 없다. 정렬은
     * 비고유 컬럼(updated_at) 뒤에 기본키를 덧붙여 전순서를 보장한다.
     *
     * @param  array<string, mixed>  $filters  status / search
     * @param  int  $page  페이지 번호
     * @param  int  $perPage  페이지 크기
     * @return LengthAwarePaginator 페이지네이션 결과
     */
    public function paginateWithDefinitions(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $templates = (new BizppurioTemplate)->getTable();
        $definitions = (new NotificationDefinition)->getTable();

        $query = BizppurioTemplate::query()
            ->leftJoin($definitions.' as nd', 'nd.type', '=', $templates.'.notification_type')
            ->select(array_map(
                static fn (string $column): string => $templates.'.'.$column,
                self::LIST_COLUMNS,
            ))
            ->addSelect([
                'nd.name as definition_name',
                'nd.variables as definition_variables',
                'nd.extension_type as definition_extension_type',
                'nd.extension_identifier as definition_extension_identifier',
            ])
            // 목록 배지가 "미작성 vs 작성중"을 구분하도록 content 존재 플래그만 계산해 싣는다.
            // raw 안의 테이블명에는 빌더가 프리픽스를 붙여주지 않으므로 직접 부착한다.
            ->selectRaw('('.DB::getTablePrefix().$templates.'.content is not null) as has_content');

        if (! empty($filters['status'])) {
            $query->where($templates.'.status', (string) $filters['status']);
        }

        if (! empty($filters['search'])) {
            $term = '%'.addcslashes((string) $filters['search'], '%_\\').'%';
            // nd.name 은 translatable JSON 컬럼 — raw LIKE 는 유니코드 이스케이프 저장값과
            // 비교되어 비ASCII(한글 등) 검색이 항상 0건이 된다. 운영자가 화면에서 보는
            // 표시명으로 검색되도록 로케일별 값을 추출해 비교한다 (선례:
            // sirsoft-ecommerce ProductInquiryRepository 의 product_name_snapshot 검색).
            // 로케일 키는 config 유래(사용자 입력 아님)이고 검색어는 바인딩으로 전달된다.
            $locales = config('app.translatable_locales', ['ko', 'en']);
            // raw 안의 조인 별칭에는 빌더가 프리픽스를 붙여주지 않으므로 직접 부착한다
            // (조인 선언의 'as nd' 는 실제로 '{prefix}nd' 별칭이 된다).
            $ndName = DB::getTablePrefix().'nd.name';
            $query->where(function ($q) use ($templates, $term, $locales, $ndName) {
                $q->where($templates.'.notification_type', 'like', $term);
                foreach ($locales as $locale) {
                    $q->orWhereRaw(
                        "JSON_UNQUOTE(JSON_EXTRACT({$ndName}, '$.{$locale}')) LIKE ?",
                        [$term],
                    );
                }
            });
        }

        return $query
            ->orderByDesc($templates.'.updated_at')
            // audit:allow repository-pagination-key-tiebreak reason: 아래 기본키 tiebreak 이 전순서를 보장한다 —
            // 조인 모호성 회피를 위해 테이블 한정 식별자(변수 결합)를 쓰므로 룰 정규식이 인식하지 못할 뿐이다
            ->orderByDesc($templates.'.id')
            // audit:allow repository-paginate-column-pruning reason: 위 select()/addSelect() 가 목록 소비 컬럼만
            // 명시 조회한다 — paginate 인자의 컬럼 기본값은 기존 select 를 덮지 않는다
            ->paginate($perPage, page: $page);
    }

    /**
     * 알림 설정 탭 행 표시용 전체 요약을 조회합니다.
     *
     * 행 수는 알림 정의 수에 묶인 설정성 테이블이라 전량 조회한다(페이지네이션 규정의
     * 설정성 테이블 예외). 요약에 불필요한 대형 JSON(content/approved_content)은 제외하되,
     * 반려 사유(inspection_detail)는 행 UI 의 [사유 보기]가 소비하므로 포함한다.
     *
     * @return Collection<int, BizppurioTemplate> 요약 컬럼만 실린 행 컬렉션
     */
    public function allSummaries(): Collection
    {
        return BizppurioTemplate::query()
            ->select(array_merge(self::LIST_COLUMNS, ['inspection_detail', 'sms_body']))
            // content 원본은 요약에 불필요하지만 "작성 여부"(미작성 vs 작성중 배지 분기)는
            // 행 UI 가 소비하므로 존재 플래그만 계산해 싣는다.
            ->selectRaw('(content is not null) as has_content')
            ->orderBy('notification_type')
            ->get();
    }

    /**
     * 특정 상태의 행 전체를 조회합니다 (동기화 커맨드 대상 선별).
     *
     * @param  string  $status  BizppurioTemplateStatus value
     * @return Collection<int, BizppurioTemplate>
     */
    public function allByStatus(string $status): Collection
    {
        return BizppurioTemplate::query()
            ->where('status', $status)
            ->orderBy('id')
            ->get();
    }

    /**
     * 템플릿 행을 생성합니다.
     *
     * @param  array<string, mixed>  $data  생성 데이터
     * @return BizppurioTemplate 생성된 행
     */
    public function create(array $data): BizppurioTemplate
    {
        return BizppurioTemplate::create($data);
    }

    /**
     * 템플릿 행을 갱신합니다.
     *
     * @param  BizppurioTemplate  $template  대상 행
     * @param  array<string, mixed>  $data  갱신 데이터
     * @return BizppurioTemplate 갱신된 행
     */
    public function update(BizppurioTemplate $template, array $data): BizppurioTemplate
    {
        $template->fill($data)->save();

        return $template;
    }

    /**
     * 템플릿 행을 삭제합니다.
     *
     * @param  BizppurioTemplate  $template  대상 행
     */
    public function delete(BizppurioTemplate $template): void
    {
        $template->delete();
    }

    /**
     * 특정 템플릿 코드가 이미 사용 중인지 확인합니다 (자체 채번 충돌 방지).
     *
     * @param  string  $templateCode  검사할 코드
     * @return bool 사용 중이면 true
     */
    public function templateCodeExists(string $templateCode): bool
    {
        return BizppurioTemplate::query()
            ->where('template_code', $templateCode)
            ->exists();
    }

    /**
     * 검수 신청을 위해 행을 원자적으로 선점합니다.
     *
     * 신청 가능 상태(draft/rejected)를 WHERE 조건에 포함한 조건부 UPDATE 라
     * 같은 행을 동시에 든 요청 중 정확히 하나만 1행 갱신을 받는다.
     *
     * @param  BizppurioTemplate  $template  대상 행
     * @return bool 선점 성공 여부
     */
    public function claimForInspection(BizppurioTemplate $template): bool
    {
        $claimed = BizppurioTemplate::query()
            ->whereKey($template->id)
            ->whereIn('status', [
                BizppurioTemplateStatus::Draft->value,
                BizppurioTemplateStatus::Rejected->value,
            ])
            ->update(['status' => BizppurioTemplateStatus::Requested->value]) === 1;

        if ($claimed) {
            $template->refresh();
        }

        return $claimed;
    }
}
