<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Extension\StorageInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Extension\HookContextCapture;
use App\Extension\HookManager;
use App\Helpers\InlineMediaPolicy;
use App\Helpers\PermissionHelper;
use App\Helpers\TimezoneHelper;
use App\Models\User;
use App\Support\ExecutionEnvironment;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Enums\ExportStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\ExportException;
use Modules\Sirsoft\Ecommerce\Exports\Contracts\ExportFormatInterface;
use Modules\Sirsoft\Ecommerce\Exports\Contracts\ExportTypeInterface;
use Modules\Sirsoft\Ecommerce\Exports\ExportColumn;
use Modules\Sirsoft\Ecommerce\Exports\ExportDecimal;
use Modules\Sirsoft\Ecommerce\Exports\ExportField;
use Modules\Sirsoft\Ecommerce\Exports\ExportFieldType;
use Modules\Sirsoft\Ecommerce\Exports\ExportFormatRegistry;
use Modules\Sirsoft\Ecommerce\Exports\ExportRunContext;
use Modules\Sirsoft\Ecommerce\Exports\ExportTypeRegistry;
use Modules\Sirsoft\Ecommerce\Exports\ExportValueFormatter;
use Modules\Sirsoft\Ecommerce\Jobs\GenerateExportJob;
use Modules\Sirsoft\Ecommerce\Models\Export;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ExportDownloadRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ExportRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Support\ExportAccess;
use Modules\Sirsoft\Ecommerce\Support\MemberDisplayName;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * 내보내기 서비스 — 카탈로그·요청 접수·파일 생성·다운로드·삭제·만료 정리
 *
 * 흐름: 목록 화면 모달 → 요청 접수(작업 행 대기) → 큐 작업이 파일 생성(진행중 → 완료/실패) → 관리 화면에서
 * 다운로드(이력 기록) → 보관 기간이 지나면 정리 스케줄이 파일을 지우고 만료로 바꾼다.
 *
 * 큐가 sync 여도(D16) 요청을 받는다 — 응답을 먼저 보내고 같은 PHP 프로세스가 응답 뒤에 만든다.
 * 저장소가 공개 디스크여도(D17) 만든다 — 경고만 남기고 운영자 책임으로 둔다.
 */
class ExportService
{
    /**
     * 저장소 카테고리
     */
    public const STORAGE_CATEGORY = 'exports';

    /**
     * 일회용 다운로드 토큰 수명(초)
     */
    public const DOWNLOAD_TOKEN_TTL_SECONDS = 60;

    /**
     * 일회용 다운로드 토큰 캐시 키 접두 (모듈 캐시 드라이버가 앞에 모듈 접두를 붙인다)
     */
    private const DOWNLOAD_TOKEN_PREFIX = 'download-token:';

    /**
     * 기간 상한(일) — 시작일 포함 1년
     */
    public const MAX_PERIOD_DAYS = 366;

    /**
     * 「선택한 행」 최대 수
     */
    public const MAX_SELECTED_IDS = 1000;

    /**
     * 요청자당 대기+진행중 최대 수 (화면 설정 없는 고정 정책, D12)
     */
    public const MAX_ACTIVE_PER_USER = 3;

    /**
     * 큐 작업 시간 제한(초) — GenerateExportJob::$timeout 과 같은 값
     */
    public const JOB_TIMEOUT_SECONDS = 1800;

    /**
     * 대기가 이 시간(분)을 넘으면 큐 워커 미동작으로 본다
     */
    public const STALE_PENDING_MINUTES = 10;

    /**
     * 보관 기간이 끝난 뒤 파일을 지우기까지 기다리는 시간(분) — 만료 직전에 시작한 다운로드의 전송을 끊지 않는다
     */
    public const PRUNE_GRACE_MINUTES = 10;

    /**
     * 보관 기간 기본값(일)
     */
    public const DEFAULT_RETENTION_DAYS = 7;

    /**
     * 진행률을 몇 행마다 기록하는가
     */
    private const PROGRESS_EVERY = 500;

    /**
     * @param  ExportRepositoryInterface  $exports  작업 Repository
     * @param  ExportDownloadRepositoryInterface  $downloads  다운로드 이력 Repository
     * @param  ExportTypeRegistry  $types  유형 레지스트리
     * @param  ExportFormatRegistry  $formats  형식 레지스트리
     * @param  ExportValueFormatter  $formatter  셀 값 변환기
     * @param  UserRepositoryInterface  $users  회원 Repository
     * @param  StorageInterface  $storage  모듈 저장소 (비공개 기본 디스크)
     * @param  CacheInterface  $cache  모듈 캐시 (일회용 다운로드 토큰)
     */
    public function __construct(
        private readonly ExportRepositoryInterface $exports,
        private readonly ExportDownloadRepositoryInterface $downloads,
        private readonly ExportTypeRegistry $types,
        private readonly ExportFormatRegistry $formats,
        private readonly ExportValueFormatter $formatter,
        private readonly UserRepositoryInterface $users,
        private readonly StorageInterface $storage,
        private readonly CacheInterface $cache,
    ) {}

    // ─────────────────────────────────────────────
    // 카탈로그
    // ─────────────────────────────────────────────

    /**
     * 내보내기 모달이 그릴 카탈로그를 만듭니다(형식·행 단위·필드·화면 컬럼 매핑·기간·언어·경고).
     *
     * 개인정보 필드는 `exports.personal_data` 권한이 있는 사람에게만 싣는다 — 서버 검증도 같은 기준이다.
     *
     * @param  ExportTypeInterface  $type  유형
     * @param  User  $user  보는 사람
     * @param  string|null  $listStart  목록의 조회 시작일 (Y-m-d — 그대로 쓸 수 있는지 판정용)
     * @param  string|null  $listEnd  목록의 조회 종료일 (Y-m-d)
     * @return array<string, mixed> 카탈로그
     */
    public function catalog(ExportTypeInterface $type, User $user, ?string $listStart = null, ?string $listEnd = null): array
    {
        $parentLevelKey = $this->parentLevelKey('parent_level_units', $type->key());

        $canPersonal = PermissionHelper::check('sirsoft-ecommerce.exports.personal_data', $user);

        $units = [];
        $fieldsByUnit = [];
        $screenByUnit = [];
        foreach ($type->rowUnits() as $unit => $labelKey) {
            $units[] = ['key' => $unit, 'label' => __($labelKey)];
            $available = $this->availableFields($type, $unit, $user);
            $fieldsByUnit[$unit] = array_values(array_map(fn (ExportField $f) => [
                'key' => $f->key,
                'label' => __($f->labelKey),
                'group' => $f->group,
                'type' => $f->type->value,
                'personal' => $f->personal,
                'summable' => $f->summable,
                'parent_level' => $f->parentLevel,
            ], $available));

            foreach ($type->screenColumns() as $screenKey => $def) {
                $screenByUnit[$unit][$screenKey] = array_values(array_filter($def['fields'], fn ($k) => isset($available[$k])));
            }
        }

        $screenColumns = [];
        foreach ($type->screenColumns() as $screenKey => $def) {
            $screenColumns[] = ['key' => $screenKey, 'label' => __($def['label_key'])];
        }

        $dateTypes = [];
        foreach ($type->dateTypes() as $key => $labelKey) {
            $dateTypes[] = ['key' => $key, 'label' => __($labelKey)];
        }

        $locales = [];
        foreach ($this->dataLocales() as $code) {
            $locales[] = ['code' => $code, 'label' => (string) (config("app.locale_names.{$code}") ?? $code)];
        }

        $formats = [];
        foreach ($this->formats->all() as $format) {
            $formats[] = ['key' => $format->key(), 'label' => __($format->labelKey()), 'extension' => $format->extension()];
        }

        return [
            'type' => $type->key(),
            'label' => __($type->labelKey()),
            'formats' => $formats,
            'row_units' => $units,
            'default_row_unit' => array_key_first($type->rowUnits()),
            'screen_columns' => $screenColumns,
            'screen_column_fields' => $screenByUnit,
            'fields' => $fieldsByUnit,
            // 「첫 행에만 표시」 항목 배지 — 그 유형의 상위 단위(주문 단위·상품 단위). 문구가 없는 유형은 창이 공용 문구를 쓴다
            'parent_level_label' => $parentLevelKey !== null ? __($parentLevelKey) : null,
            'date_types' => $dateTypes,
            'period_required' => $type->periodRequired(),
            // 목록의 조회 기간을 그대로 쓸 수 있는가 — 기간이 필수인데 없거나 1년을 넘으면 창이 기본 기간을 채운다
            'list_period_usable' => ! $type->periodRequired() || $this->listPeriodUsable($listStart, $listEnd),
            'default_period' => $this->defaultPeriod($type),
            'max_period_days' => self::MAX_PERIOD_DAYS,
            'max_selected_ids' => self::MAX_SELECTED_IDS,
            'locales' => $locales,
            'default_locale' => in_array(app()->getLocale(), $this->dataLocales(), true) ? app()->getLocale() : $this->fallbackLocale(),
            'can_personal_data' => $canPersonal,
            'warnings' => $this->warnings(),
        ];
    }

    /**
     * 보는 사람이 고를 수 있는 필드(행 단위 반영, 개인정보 권한 반영)
     *
     * @param  ExportTypeInterface  $type  유형
     * @param  string  $unit  행 단위
     * @param  User  $user  보는 사람
     * @return array<string, ExportField> 필드 키 → 필드
     */
    public function availableFields(ExportTypeInterface $type, string $unit, User $user): array
    {
        $canPersonal = PermissionHelper::check('sirsoft-ecommerce.exports.personal_data', $user);

        return array_filter($type->fields($unit), fn (ExportField $f) => $canPersonal || ! $f->personal);
    }

    /**
     * 기간이 상한(시작일 포함 1년) 안인지 판정합니다 — 요청 검증과 창 기본 기간이 같은 규칙을 쓴다.
     *
     * 2/29 시작이면 다음 해 2/28 까지다(넘침 더하기 후 하루 빼기).
     *
     * @param  CarbonInterface  $start  시작일
     * @param  CarbonInterface  $end  종료일
     * @return bool 시작일 이후이고 1년 안이면 true
     */
    public static function periodWithinLimit(CarbonInterface $start, CarbonInterface $end): bool
    {
        $startDay = CarbonImmutable::instance($start)->startOfDay();
        $endDay = CarbonImmutable::instance($end)->startOfDay();

        return ! $endDay->lessThan($startDay) && ! $endDay->greaterThan($startDay->addYear()->subDay());
    }

    /**
     * 목록의 조회 기간을 내보내기 기간으로 그대로 쓸 수 있는지 판정합니다.
     *
     * @param  string|null  $start  목록 시작일 (Y-m-d)
     * @param  string|null  $end  목록 종료일 (Y-m-d)
     * @return bool 두 날짜가 있고 상한 안이면 true
     */
    private function listPeriodUsable(?string $start, ?string $end): bool
    {
        if (! $start || ! $end) {
            return false;
        }

        return self::periodWithinLimit(CarbonImmutable::parse($start), CarbonImmutable::parse($end));
    }

    /**
     * 기간이 필수인 유형의 기본 기간(오늘 포함 최근 1개월)을 만듭니다 — 목록의 「1개월」 단추와 같은 범위.
     *
     * 「오늘」은 그 유형의 직접 지정 기간을 해석하는 시간대로 잡는다: 주문은 사이트 시간대(date_basis=site,
     * {@see effectiveFilters()}), 그 밖은 운영자 시간대. 브라우저 날짜로 잡으면 자정 무렵 하루가 어긋난다.
     *
     * @param  ExportTypeInterface  $type  유형
     * @return array{mode: string, date_type: string|null, start_date: string, end_date: string}|null 기본 기간 (필수가 아니면 null)
     */
    private function defaultPeriod(ExportTypeInterface $type): ?array
    {
        if (! $type->periodRequired()) {
            return null;
        }

        $timezone = $type->key() === 'orders' ? TimezoneHelper::getSiteTimezone() : TimezoneHelper::getUserTimezone();
        $today = CarbonImmutable::now($timezone)->startOfDay();

        return [
            'mode' => 'custom',
            'date_type' => array_key_first($type->dateTypes()),
            // audit:allow datetime-display-user-timezone reason: 시간대를 지정해 만든 Carbon($today — 주문은 사이트, 그 밖은 보는 사람 시간대) — 날짜 키
            'start_date' => $today->subMonthNoOverflow()->toDateString(),
            // audit:allow datetime-display-user-timezone reason: 시간대를 지정해 만든 Carbon($today) — 날짜 키
            'end_date' => $today->toDateString(),
        ];
    }

    /**
     * 지금 설치 상태의 경고 코드 목록 (모달·관리 화면 상단 띠)
     *
     * @return array<int, string> 경고 코드 (queue_sync · storage_public)
     */
    public function warnings(): array
    {
        $warnings = [];

        if ($this->isQueueSync()) {
            $warnings[] = 'queue_sync';
        }
        if ($this->isStoragePublic()) {
            $warnings[] = 'storage_public';
        }

        return $warnings;
    }

    /**
     * 큐 연결이 sync 인지 (워커 없이 요청 안에서 실행)
     *
     * @return bool sync 여부
     */
    public function isQueueSync(): bool
    {
        $connection = (string) config('queue.default');

        return config("queue.connections.{$connection}.driver", $connection) === 'sync';
    }

    /**
     * 내보내기 임시 파일을 만들 디렉터리 — 호스팅이 `sys_get_temp_dir()` 를 막거나 그 폴더에 쓸 수 없으면
     * 앱 저장소 아래 임시 디렉터리를 쓴다.
     *
     * @return string 디렉터리 경로
     */
    private static function temporaryDirectory(): string
    {
        if (ExecutionEnvironment::hasFunction('sys_get_temp_dir')) {
            $directory = sys_get_temp_dir();

            if (is_dir($directory) && is_writable($directory)) {
                return $directory;
            }
        }

        $fallback = storage_path('app/tmp/exports');

        if (! is_dir($fallback)) {
            @mkdir($fallback, 0775, true);
        }

        return $fallback;
    }

    /**
     * 내보내기 파일을 담는 디스크가 공개인지 (D17 — 차단하지 않고 경고만)
     *
     * 디스크 설정의 visibility 가 public 이거나 공개 URL 이 설정돼 있으면 공개로 본다.
     *
     * @return bool 공개 여부
     */
    public function isStoragePublic(): bool
    {
        $config = (array) config('filesystems.disks.'.$this->storage->getDisk(), []);

        return ($config['visibility'] ?? null) === 'public' || ! empty($config['url']);
    }

    // ─────────────────────────────────────────────
    // 요청 접수
    // ─────────────────────────────────────────────

    /**
     * 내보내기를 요청합니다(작업 행 생성 + 큐 발행).
     *
     * 같은 요청자·같은 중복 방지 키면 새로 만들지 않고 기존 작업을 돌려준다.
     *
     * @param  ExportTypeInterface  $type  유형
     * @param  array<string, mixed>  $data  검증된 요청 (StoreExportRequest)
     * @param  User  $user  요청자
     * @param  string  $idempotencyKey  중복 방지 키
     * @return Export 작업
     */
    public function request(ExportTypeInterface $type, array $data, User $user, string $idempotencyKey): Export
    {
        $existing = $this->exports->findByIdempotencyKey($user->id, $idempotencyKey);
        if ($existing !== null) {
            return $existing;
        }

        HookManager::doAction('sirsoft-ecommerce.export.before_request', $type->key(), $data, $user);
        $data = HookManager::applyFilters('sirsoft-ecommerce.export.filter_request_data', $data, $type->key(), $user);

        $unit = $data['row_unit'] ?? array_key_first($type->rowUnits());
        $fieldKeys = $this->selectedFieldKeys($type, $unit, $data, $user);
        $available = $this->availableFields($type, $unit, $user);
        $personal = collect($fieldKeys)->contains(fn ($k) => ($available[$k] ?? null)?->personal === true);

        $now = CarbonImmutable::now();
        $warnings = $this->warnings();

        // 같은 키의 요청이 동시에 들어와 둘 다 위 조회를 지나면 하나는 고유 제약에 걸린다 — 먼저 저장된 작업을 돌려준다
        try {
            $export = DB::transaction(fn () => $this->exports->create([
                'uuid' => (string) Str::uuid(),
                'type' => $type->key(),
                'format' => $data['format'],
                'status' => ExportStatusEnum::PENDING->value,
                'requested_by' => $user->id,
                // 요청 시점의 이커머스 관리자 영역 표시 이름을 남긴다(기록물 — 그 뒤 설정이나 회원 이름이 바뀌어도 이 값은 그대로다)
                'requester_name_snapshot' => Str::limit((string) MemberDisplayName::name($user, MemberDisplayName::AREA_ADMIN), 97),
                'options' => [
                    'column_mode' => $data['column_mode'],
                    'screen_columns' => array_values($data['screen_columns'] ?? []),
                    'fields' => $fieldKeys,
                    'row_unit' => $unit,
                    'scope' => $data['scope'],
                    'period' => $data['period'] ?? null,
                    'locale_mode' => $data['locale_mode'],
                    'locale' => $data['locale'] ?? null,
                    'include_totals' => (bool) ($data['include_totals'] ?? false),
                    'selected_count' => count($data['selected_ids'] ?? []),
                ],
                'filters' => $this->effectiveFilters($type, $data),
                'selected_ids' => $data['scope'] === 'selected' ? array_values(array_map('intval', $data['selected_ids'] ?? [])) : null,
                'context' => HookContextCapture::capture(),
                'contains_personal_data' => $personal,
                'reason' => $data['reason'] ?? null,
                'idempotency_key' => $idempotencyKey,
                'warnings' => $warnings,
                'locale' => app()->getLocale(),
                'timezone' => TimezoneHelper::getUserTimezone(),
                'format_version' => $this->formats->find($data['format'])?->version(),
                'requested_at' => $now,
                'cutoff_at' => $now,
            ]));
        } catch (UniqueConstraintViolationException $e) {
            return $this->exports->findByIdempotencyKey($user->id, $idempotencyKey) ?? throw $e;
        }

        // 커밋 뒤에 발행한다 — 커밋 전에 워커가 집으면 행을 못 찾는다. sync 면 응답 뒤로 미룬다(D16).
        if ($this->isQueueSync()) {
            GenerateExportJob::dispatchAfterResponse($export->id);
        } else {
            try {
                GenerateExportJob::dispatch($export->id)->afterCommit();
            } catch (Throwable $e) {
                Log::error('[내보내기] 큐 발행 실패', ['export_id' => $export->id, 'error' => $e->getMessage()]);
                $this->exports->update($export, ['status' => ExportStatusEnum::FAILED->value, 'error_code' => 'enqueue_failed']);

                throw new ExportException('enqueue_failed', 500);
            }
        }

        HookManager::doAction('sirsoft-ecommerce.export.after_request', $export, $user);

        return $export;
    }

    /**
     * 예상 행 수를 셉니다(모달 하단 안내).
     *
     * @param  ExportTypeInterface  $type  유형
     * @param  array<string, mixed>  $data  검증된 요청
     * @param  User  $user  요청자
     * @return int 예상 행 수
     */
    public function estimate(ExportTypeInterface $type, array $data, User $user): int
    {
        $unit = $data['row_unit'] ?? array_key_first($type->rowUnits());
        $ids = $data['scope'] === 'selected' ? array_map('intval', $data['selected_ids'] ?? []) : null;
        $query = $type->query($this->effectiveFilters($type, $data), $ids, $user, null);

        return $type->countRows($query, $unit);
    }

    /**
     * 요청에서 실제로 낼 필드 키 목록을 정합니다.
     *
     * 「현재 선택한 컬럼」은 화면 컬럼 키를 매핑으로 펼치고, 「데이터 선택」은 고른 필드 키를 그대로 쓴다.
     * 어느 쪽이든 행 단위에 없는 필드와 권한 밖 개인정보 필드는 뺀다.
     *
     * @param  ExportTypeInterface  $type  유형
     * @param  string  $unit  행 단위
     * @param  array<string, mixed>  $data  검증된 요청
     * @param  User  $user  요청자
     * @return array<int, string> 필드 키 (출력 순서)
     */
    public function selectedFieldKeys(ExportTypeInterface $type, string $unit, array $data, User $user): array
    {
        $available = $this->availableFields($type, $unit, $user);

        if (($data['column_mode'] ?? 'current') === 'current') {
            $screen = $type->screenColumns();
            $keys = [];
            foreach ($data['screen_columns'] ?? [] as $screenKey) {
                foreach ($screen[$screenKey]['fields'] ?? [] as $fieldKey) {
                    if (isset($available[$fieldKey])) {
                        $keys[] = $fieldKey;
                    }
                }
            }
        } else {
            $keys = array_values(array_filter($data['fields'] ?? [], fn ($k) => isset($available[$k])));
        }

        return array_values(array_unique($keys));
    }

    /**
     * 범위·기간 조합으로 실제 쓸 필터를 만듭니다.
     *
     * | 범위 \ 기간 | 현재 조회 기간 | 직접 선택 |
     * | 현재 검색조건 | 필터 그대로 | 필터에서 기간 키만 교체 |
     * | 선택한 행 | (필터 없음 — ID 우선) | 동일 |
     * | 조건 없음 | 기간 키만 | 선택 기간만 |
     *
     * @param  ExportTypeInterface  $type  유형
     * @param  array<string, mixed>  $data  검증된 요청
     * @return array<string, mixed> 필터
     */
    public function effectiveFilters(ExportTypeInterface $type, array $data): array
    {
        if (($data['scope'] ?? 'filter') === 'selected') {
            return [];
        }

        $current = (array) ($data['filters'] ?? []);
        $periodKeys = ['date_type', 'start_date', 'end_date', 'date_basis'];
        $period = (array) ($data['period'] ?? []);

        if (($period['mode'] ?? 'current') === 'custom') {
            $periodFilters = array_filter([
                'date_type' => count($type->dateTypes()) > 1 ? ($period['date_type'] ?? array_key_first($type->dateTypes())) : null,
                'start_date' => $period['start_date'] ?? null,
                'end_date' => $period['end_date'] ?? null,
                // 직접 고른 기간은 사이트 시간대로 해석한다(주문 목록의 date_basis=site 와 같은 규칙)
                'date_basis' => $type->key() === 'orders' ? 'site' : null,
            ], fn ($v) => $v !== null && $v !== '');
        } else {
            $periodFilters = array_intersect_key($current, array_flip($periodKeys));
        }

        if (($data['scope'] ?? 'filter') === 'all') {
            return $periodFilters;
        }

        return array_merge(array_diff_key($current, array_flip($periodKeys)), $periodFilters);
    }

    // ─────────────────────────────────────────────
    // 파일 생성 (큐 작업)
    // ─────────────────────────────────────────────

    /**
     * 파일을 만듭니다(큐 작업 본체).
     *
     * @param  int  $exportId  작업 ID
     * @return void 반환값 없음
     */
    public function generate(int $exportId): void
    {
        // 대기 → 진행중을 원자적으로 — 다른 워커가 이미 집었거나 대기 중 삭제됐으면 아무것도 하지 않는다
        if (! $this->exports->claimForProcessing($exportId, CarbonImmutable::now())) {
            return;
        }

        $export = $this->exports->findById($exportId);
        if ($export === null) {
            return;
        }

        $tempPath = null;
        $writer = null;
        // 상주 워커는 컨테이너를 작업 사이에 재사용한다 — 복원한 요청자·요청·로케일·시간대를 끝나면 되돌려
        // 다음 작업(다른 관리자의 즉시 실행 등)이 이 요청자를 행위자로 물려받지 않게 한다
        $previousContext = HookContextCapture::snapshot();
        $previousTimezone = app()->bound('user_timezone') ? app('user_timezone') : null;

        try {
            // 요청 시점 컨텍스트(요청자·로케일·경로) 복원 — 라벨 언어·활동 로그 행위자·보는 사람 시간대
            HookContextCapture::restore((array) $export->context);
            if ($export->locale) {
                app()->setLocale($export->locale);
            }
            if ($export->timezone) {
                app()->instance('user_timezone', $export->timezone);
            }
            // 큐 sync 일 때 응답 뒤 실행 — 사용자가 창을 닫아도 끝까지, 실행 시간은 잡 제한만큼
            // 호스팅이 두 함수를 막으면 건너뛴다 — PHP 8 은 막힌 함수를 부르면 치명 오류다
            if ($this->isQueueSync()) {
                if (ExecutionEnvironment::hasFunction('ignore_user_abort')) {
                    ignore_user_abort(true);
                }
                if (ExecutionEnvironment::hasFunction('set_time_limit')) {
                    @set_time_limit(self::JOB_TIMEOUT_SECONDS);
                }
            }

            $requester = $export->requested_by ? $this->users->findById((int) $export->requested_by) : null;
            if ($requester === null) {
                throw new ExportException('requester_missing');
            }

            $type = $this->types->find($export->type);
            if ($type === null) {
                throw new ExportException('type_unavailable');
            }
            $format = $this->formats->find($export->format);
            if ($format === null) {
                throw new ExportException('format_unavailable');
            }

            $this->assertStillAllowed($type, $export, $requester);

            HookManager::doAction('sirsoft-ecommerce.export.after_start', $export);

            $options = (array) $export->options;
            $unit = (string) ($options['row_unit'] ?? array_key_first($type->rowUnits()));
            $ctx = $this->runContext($options, $unit, (string) ($export->timezone ?: config('app.timezone')));
            $fields = $this->availableFields($type, $unit, $requester);
            $columns = $this->columns(array_values(array_filter(array_map(fn ($k) => $fields[$k] ?? null, (array) ($options['fields'] ?? [])))), $ctx, $type->key());

            if ($columns === []) {
                throw new ExportException('no_fields');
            }

            $query = $type->query((array) $export->filters, $export->selected_ids, $requester, $export->cutoff_at);
            // 이 파일을 뽑은 원본 조회 범위 — 받을 때 지금 범위가 이보다 좁으면 막는다(ExportAccess::canReadSource)
            $sourceScope = ExportAccess::currentSourceScope($type, $requester);
            $total = $type->countRows($query, $unit, $export->cutoff_at);
            $this->exports->update($export, ['total_rows' => $total]);

            $tempPath = tempnam(self::temporaryDirectory(), 'g7export_');
            $writer = $format->createWriter($tempPath);
            $writer->writeHeader($columns);

            $totals = [];
            $processed = 0;
            foreach ($type->rows($query, $unit, $export->cutoff_at) as [$row, $isFirst, $currency]) {
                $cells = [];
                foreach ($columns as $i => $column) {
                    $field = $column->field;
                    // 상위 단위 금액은 상위의 첫 행에만(D13) — 나머지 행은 빈칸이라 열을 그대로 더해도 한 번씩만 셈
                    if ($field->parentLevel && ! $isFirst) {
                        $cells[$i] = null;

                        continue;
                    }
                    $cells[$i] = $this->formatter->format($column, ($field->resolver)($row), $ctx);

                    if ($ctx->includeTotals && $field->summable && $cells[$i] !== null) {
                        $units = ExportDecimal::toUnits($cells[$i]);
                        if ($units !== null) {
                            $bucket = (string) ($currency ?? '');
                            $totals[$bucket][$i] = ($totals[$bucket][$i] ?? 0) + $units;
                        }
                    }
                }

                $filtered = HookManager::applyFilters('sirsoft-ecommerce.export.filter_row', $cells, $export, $columns);
                $writer->writeRow(is_array($filtered) ? $filtered : $cells, $columns);

                $processed++;
                if ($processed % self::PROGRESS_EVERY === 0) {
                    $this->exports->updateProgress($export->id, $processed);
                }
            }

            if ($ctx->includeTotals && $processed > 0) {
                $writer->writeTotals($this->totalRows($totals, $columns), $columns);
            }

            $writer->close();
            $writer = null;

            $completedAt = CarbonImmutable::now();
            // audit:allow datetime-display-user-timezone reason: 저장 경로(디렉터리 날짜) — 기계값
            $path = $completedAt->format('Y/m').'/'.$export->uuid.'.'.$format->extension();
            $stream = fopen($tempPath, 'rb');
            try {
                $stored = $this->storage->put(self::STORAGE_CATEGORY, $path, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            if (! $stored) {
                throw new ExportException('storage_write_failed');
            }

            // 기록 갱신이 실패하면 방금 저장한 파일을 지운다 — 경로가 기록되지 않은 파일은 정리 스케줄이 찾지 못한다
            try {
                $this->exports->update($export, [
                    'status' => ExportStatusEnum::COMPLETED->value,
                    'file_name' => $this->fileName($type, $format, $export->requested_at ?? $completedAt, $ctx->timezone),
                    'file_path' => $path,
                    'file_size' => filesize($tempPath) ?: 0,
                    'checksum_sha256' => hash_file('sha256', $tempPath),
                    'processed_rows' => $processed,
                    'completed_at' => $completedAt,
                    'expires_at' => $completedAt->addDays($this->retentionDays()),
                    'error_code' => null,
                    'options' => array_merge((array) $export->options, ['source_scope' => $sourceScope]),
                ]);
            } catch (Throwable $e) {
                try {
                    $this->storage->delete(self::STORAGE_CATEGORY, $path);
                } catch (Throwable $cleanup) {
                    Log::warning('[내보내기] 기록 갱신 실패 뒤 파일 정리 실패', ['export_id' => $export->id, 'error' => $cleanup->getMessage()]);
                }

                throw $e;
            }

            HookManager::doAction('sirsoft-ecommerce.export.after_complete', $export->refresh());
        } catch (Throwable $e) {
            $code = $e instanceof ExportException ? $e->errorCode() : 'generation_failed';
            if (! $e instanceof ExportException) {
                Log::error('[내보내기] 파일 생성 실패', ['export_id' => $exportId, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            }
            $this->markFailed($exportId, $code);
        } finally {
            $writer?->close();
            if ($tempPath !== null && is_file($tempPath)) {
                @unlink($tempPath);
            }

            HookContextCapture::revert($previousContext);
            if ($previousTimezone !== null) {
                app()->instance('user_timezone', $previousTimezone);
            } else {
                app()->forgetInstance('user_timezone');
            }
        }
    }

    /**
     * 작업을 실패로 닫습니다(이미 끝난 작업은 건드리지 않는다).
     *
     * @param  int  $exportId  작업 ID
     * @param  string  $code  실패 사유 키
     * @return void 반환값 없음
     */
    public function markFailed(int $exportId, string $code): void
    {
        $export = $this->exports->findById($exportId);
        if ($export === null || in_array($export->status, [ExportStatusEnum::COMPLETED, ExportStatusEnum::EXPIRED, ExportStatusEnum::FAILED], true)) {
            return;
        }

        $this->exports->update($export, [
            'status' => ExportStatusEnum::FAILED->value,
            'error_code' => $code,
            'completed_at' => CarbonImmutable::now(),
        ]);

        HookManager::doAction('sirsoft-ecommerce.export.after_fail', $export->refresh(), $code);
    }

    /**
     * 요청 뒤 권한이 바뀌었는지 다시 확인합니다(실행 시점 재검사).
     *
     * @param  ExportTypeInterface  $type  유형
     * @param  Export  $export  작업
     * @param  User  $requester  요청자
     * @return void 반환값 없음
     *
     * @throws ExportException 권한을 잃었을 때 (permission_revoked)
     */
    private function assertStillAllowed(ExportTypeInterface $type, Export $export, User $requester): void
    {
        $allowed = PermissionHelper::check('sirsoft-ecommerce.exports.create', $requester)
            && PermissionHelper::check($type->readPermission(), $requester)
            && ExportAccess::canHandlePersonal($export, $requester);

        if (! $allowed) {
            throw new ExportException('permission_revoked');
        }
    }

    /**
     * 출력 조건을 만듭니다.
     *
     * @param  array<string, mixed>  $options  작업 옵션
     * @param  string  $unit  행 단위
     * @param  string  $timezone  시간대
     * @return ExportRunContext 출력 조건
     */
    private function runContext(array $options, string $unit, string $timezone): ExportRunContext
    {
        $locales = $this->dataLocales();
        $fallback = $this->fallbackLocale();
        $locale = (string) ($options['locale'] ?? app()->getLocale());

        return new ExportRunContext(
            rowUnit: $unit,
            localeMode: (string) ($options['locale_mode'] ?? ExportRunContext::LOCALE_MODE_ONE),
            locale: in_array($locale, $locales, true) ? $locale : $fallback,
            locales: $locales,
            fallbackLocale: $fallback,
            timezone: $timezone,
            includeTotals: (bool) ($options['include_totals'] ?? false),
        );
    }

    /**
     * 유형별 「첫 행에만 표시」 문구의 다국어 키를 돌려줍니다 — 그 유형의 문구가 없으면(외부 확장 유형 등) null.
     *
     * @param  string  $group  문구 묶음 (`parent_level_suffixes` 머리글 꼬리표 · `parent_level_units` 창 배지)
     * @param  string|null  $typeKey  작업 유형
     * @return string|null 다국어 키
     */
    private function parentLevelKey(string $group, ?string $typeKey): ?string
    {
        if ($typeKey === null) {
            return null;
        }

        $key = "sirsoft-ecommerce::export.headers.{$group}.{$typeKey}";

        return Lang::has($key) ? $key : null;
    }

    /**
     * 필드 목록을 파일 열로 펼칩니다(다국어 「언어별 컬럼」이면 언어 수만큼).
     *
     * @param  array<int, ExportField>  $fields  필드 (출력 순서)
     * @param  ExportRunContext  $ctx  출력 조건
     * @param  string|null  $typeKey  작업 유형 — 「첫 행에만 표시」 설명의 상위 단위(주문·상품)를 고른다
     * @return array<int, ExportColumn> 열 목록
     */
    public function columns(array $fields, ExportRunContext $ctx, ?string $typeKey = null): array
    {
        $suffix = __($this->parentLevelKey('parent_level_suffixes', $typeKey) ?? 'sirsoft-ecommerce::export.headers.parent_level_suffix');
        $columns = [];
        foreach ($fields as $field) {
            $label = __($field->labelKey);
            if ($field->parentLevel) {
                $label .= ' '.$suffix;
            }

            if ($field->type === ExportFieldType::TRANSLATABLE && $ctx->localeMode === ExportRunContext::LOCALE_MODE_PER_COLUMN) {
                foreach ($ctx->locales as $locale) {
                    $columns[] = new ExportColumn($field, "{$label} ({$locale})", $locale);
                }

                continue;
            }

            $columns[] = new ExportColumn($field, $label);
        }

        return $columns;
    }

    /**
     * 통화별 합계 행을 만듭니다.
     *
     * @param  array<string, array<int, int>>  $totals  통화 → (열 순번 → 눈금 합)
     * @param  array<int, ExportColumn>  $columns  열 목록
     * @return array<int, array<int, string|int|null>> 합계 행 목록
     */
    private function totalRows(array $totals, array $columns): array
    {
        ksort($totals);

        // 표식은 합계 대상이 아닌 첫 열에 쓴다 — 첫 열이 금액이면 그 합이 표식에 가려지므로. 고른 열이 모두
        // 합계 대상이면 빈 열이 없다 — 그때는 표식을 합계 바로 위 행에 따로 쓴다(열을 늘리면 데이터 행 모양이 바뀐다).
        $labelIndex = null;
        foreach ($columns as $i => $column) {
            if (! $column->field->summable) {
                $labelIndex = $i;
                break;
            }
        }

        $rows = [];
        foreach ($totals as $currency => $sums) {
            $label = __('sirsoft-ecommerce::export.headers.total').($currency !== '' ? " ({$currency})" : '');
            $row = array_fill(0, count($columns), null);
            foreach ($sums as $i => $units) {
                $row[$i] = ExportDecimal::fromUnits($units);
            }
            if ($labelIndex === null) {
                $rows[] = [0 => $label] + array_fill(0, count($columns), null);
            } else {
                $row[$labelIndex] = $label;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * 내려받을 파일명을 만듭니다 — `{유형 라벨}_{YYYYMMDD_HHmmss}.{확장자}` (개인정보 없음)
     *
     * @param  ExportTypeInterface  $type  유형
     * @param  ExportFormatInterface  $format  형식
     * @param  CarbonInterface  $at  기준 시각
     * @param  string  $timezone  표기 시간대
     * @return string 파일명
     */
    private function fileName(ExportTypeInterface $type, ExportFormatInterface $format, CarbonInterface $at, string $timezone): string
    {
        $label = preg_replace('/[\s\\\\\/:*?"<>|]+/u', '', __($type->labelKey())) ?: $type->key();

        return $label.'_'.$at->copy()->setTimezone($timezone)->format('Ymd_His').'.'.$format->extension();
    }

    // ─────────────────────────────────────────────
    // 관리 화면
    // ─────────────────────────────────────────────

    /**
     * 관리 화면 목록
     *
     * @param  array<string, mixed>  $filters  검증된 목록 필터
     * @param  User  $viewer  보는 사람
     * @return LengthAwarePaginator 작업 목록
     */
    public function list(array $filters, User $viewer): LengthAwarePaginator
    {
        return $this->exports->paginateForAdmin($filters, (int) ($filters['per_page'] ?? 20), $viewer);
    }

    /**
     * 관리 화면 상단 경고 (sync·공개 디스크 + 대기 지연)
     *
     * @return array<int, string> 경고 코드
     */
    public function adminWarnings(): array
    {
        $warnings = $this->warnings();

        if (! $this->isQueueSync() && $this->exports->hasStalePending(CarbonImmutable::now()->subMinutes(self::STALE_PENDING_MINUTES))) {
            $warnings[] = 'pending_stalled';
        }

        return $warnings;
    }

    /**
     * 일회용 다운로드 토큰을 발급합니다.
     *
     * 브라우저가 인증 헤더 없이 파일 주소를 직접 열어 스트리밍으로 받게 하려는 것이다(파일을 브라우저 메모리에 올리지
     * 않는다). 토큰은 추측할 수 없는 64자이고, 발급한 사람·작업에 묶이며 {@see DOWNLOAD_TOKEN_TTL_SECONDS}초 안에 한 번만
     * 쓸 수 있다. 권한 검사는 발급할 때와 쓸 때 두 번 한다.
     *
     * @param  Export  $export  작업 (완료·보관 중)
     * @param  User  $user  내려받을 사람
     * @return string 토큰
     *
     * @throws ExportException 내려받을 수 없는 상태일 때
     */
    public function issueDownloadToken(Export $export, User $user): string
    {
        if (! $export->isDownloadable()) {
            throw new ExportException($export->isPastRetention() ? 'file_expired' : 'not_downloadable', 410);
        }

        $token = Str::random(64);
        $this->cache->put(self::DOWNLOAD_TOKEN_PREFIX.$token, ['export_id' => $export->id, 'user_id' => $user->id], self::DOWNLOAD_TOKEN_TTL_SECONDS);

        return $token;
    }

    /**
     * 일회용 다운로드 토큰을 씁니다(쓰는 즉시 사라진다).
     *
     * @param  string  $token  토큰
     * @return array{export: Export, user: User}|null 작업·발급받은 사람 (없거나 만료·이미 썼으면 null)
     */
    public function consumeDownloadToken(string $token): ?array
    {
        $key = self::DOWNLOAD_TOKEN_PREFIX.$token;
        $payload = $this->cache->get($key);
        if (! is_array($payload)) {
            return null;
        }

        // 캐시 계약에 꺼내며 지우기(pull)가 없어, 같은 토큰을 동시에 읽은 요청이 둘 다 지나갈 수 있다. 그 작업 행을
        // 잠근 채 토큰이 아직 남았는지 다시 보고 지운다 — 같은 작업의 소비가 한 줄로 서서 하나만 남은 토큰을 본다.
        $claimed = DB::transaction(function () use ($key, $payload): bool {
            $this->exports->lockById((int) ($payload['export_id'] ?? 0));
            if (! $this->cache->has($key)) {
                return false;
            }
            $this->cache->forget($key);

            return true;
        });
        if (! $claimed) {
            return null;
        }

        $export = $this->exports->findById((int) ($payload['export_id'] ?? 0));
        $user = $this->users->findById((int) ($payload['user_id'] ?? 0));

        return $export !== null && $user !== null ? ['export' => $export, 'user' => $user] : null;
    }

    /**
     * 다운로드 이력
     *
     * @param  Export  $export  작업
     * @param  int  $perPage  쪽당 개수
     * @param  User  $viewer  보는 사람
     * @return LengthAwarePaginator 이력 목록
     */
    public function downloadHistory(Export $export, int $perPage, User $viewer): LengthAwarePaginator
    {
        HookManager::doAction('sirsoft-ecommerce.export.after_view_downloads', $export, $viewer);

        return $this->downloads->paginateForExport($export->id, $perPage);
    }

    /**
     * 파일을 내려보냅니다(이력 기록 + 횟수 증가).
     *
     * @param  Export  $export  작업
     * @param  User  $user  내려받는 사람
     * @param  string|null  $ip  요청 IP
     * @param  string|null  $userAgent  요청 User-Agent
     * @return StreamedResponse 파일 응답
     *
     * @throws ExportException 내려받을 수 없는 상태·파일 없음
     */
    // thumbnail-exempt: 내보내기 파일(엑셀·CSV) 다운로드 통로다 — 이미지가 아니어서 썸네일 대상이 아니다.
    public function download(Export $export, User $user, ?string $ip, ?string $userAgent): StreamedResponse
    {
        if (! $export->isDownloadable()) {
            throw new ExportException($export->isPastRetention() ? 'file_expired' : 'not_downloadable', 410);
        }

        $format = $this->formats->find($export->format);
        $mime = $format?->mimeType() ?? 'application/octet-stream';

        $response = $this->storage->response(self::STORAGE_CATEGORY, (string) $export->file_path, (string) $export->file_name, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
        ]);
        if ($response === null) {
            throw new ExportException('file_missing', 404);
        }

        DB::transaction(function () use ($export, $user, $ip, $userAgent) {
            $this->downloads->create([
                'export_id' => $export->id,
                'user_id' => $user->id,
                // 받는 시점의 이커머스 관리자 영역 표시 이름을 남긴다(기록물)
                'user_name_snapshot' => Str::limit((string) MemberDisplayName::name($user, MemberDisplayName::AREA_ADMIN), 97),
                'ip_address' => $ip,
                'user_agent' => $userAgent !== null ? Str::limit($userAgent, 497) : null,
                'downloaded_at' => CarbonImmutable::now(),
            ]);
            $this->exports->incrementDownloadCount($export->id);
        });

        HookManager::doAction('sirsoft-ecommerce.export.after_download', $export->refresh(), $user);

        InlineMediaPolicy::harden($response, $mime, (string) $export->file_name);

        return $response;
    }

    /**
     * 작업을 삭제합니다(파일 삭제 + 소프트 삭제 — 다운로드 이력은 보존).
     *
     * @param  Export  $export  작업
     * @param  User  $user  삭제하는 사람
     * @return void 반환값 없음
     *
     * @throws ExportException 진행중인 작업 (409)
     */
    public function delete(Export $export, User $user): void
    {
        if (! $export->status->isDeletable()) {
            throw new ExportException('processing_not_deletable', 409);
        }

        // 파일을 못 지웠으면 경로를 남긴다 — 정리 스케줄이 지운 작업의 남은 파일을 다시 지운다
        if ($this->deleteFile($export)) {
            $this->exports->update($export, ['file_path' => null]);
        }
        $this->exports->softDelete($export);

        HookManager::doAction('sirsoft-ecommerce.export.after_delete', $export, $user);
    }

    /**
     * 여러 작업을 삭제합니다. 진행중·권한 범위 밖은 건너뛴다.
     *
     * @param  array<int, int>  $ids  작업 ID
     * @param  User  $user  삭제하는 사람
     * @return array{deleted: int, skipped: int} 처리 결과
     */
    public function bulkDelete(array $ids, User $user): array
    {
        $deleted = 0;
        $skipped = 0;

        foreach ($this->exports->findManyByIds($ids) as $export) {
            if (! $export->status->isDeletable() || ! ExportAccess::inScope($export, $user, ExportAccess::DELETE_PERMISSION)) {
                $skipped++;

                continue;
            }
            $this->delete($export, $user);
            $deleted++;
        }

        // 이미 없는 ID 도 건너뜀으로 센다 — 화면은 「요청한 수 − 삭제한 수」를 건너뜀으로 안내한다
        return ['deleted' => $deleted, 'skipped' => max($skipped, count(array_unique($ids)) - $deleted)];
    }

    /**
     * 실패한 작업을 같은 조건으로 다시 요청합니다(새 작업).
     *
     * @param  Export  $export  실패한 작업
     * @param  User  $user  요청자
     * @param  string|null  $reason  다시 요청하는 사람이 적은 개인정보 사유 (남의 개인정보 작업이면 필수 — 요청 검증이 확인)
     * @return Export 새 작업
     *
     * @throws ExportException 실패 상태가 아닐 때·유형이 사라졌을 때
     */
    public function retry(Export $export, User $user, ?string $reason = null): Export
    {
        if ($export->status !== ExportStatusEnum::FAILED) {
            throw new ExportException('not_retryable', 409);
        }

        $type = $this->types->find($export->type);
        if ($type === null) {
            throw new ExportException('type_unavailable', 422);
        }

        $options = (array) $export->options;
        $data = [
            'format' => $export->format,
            'column_mode' => 'custom',
            'fields' => (array) ($options['fields'] ?? []),
            'screen_columns' => (array) ($options['screen_columns'] ?? []),
            'row_unit' => $options['row_unit'] ?? null,
            'scope' => ($export->selected_ids !== null) ? 'selected' : 'filter',
            'filters' => (array) $export->filters,
            'selected_ids' => $export->selected_ids ?? [],
            'period' => ['mode' => 'current'],
            'locale_mode' => $options['locale_mode'] ?? ExportRunContext::LOCALE_MODE_ONE,
            'locale' => $options['locale'] ?? null,
            'include_totals' => (bool) ($options['include_totals'] ?? false),
            // 사유는 요청한 사람의 것이다 — 남의 작업을 다시 요청하면 다시 요청한 사람이 적은 사유를 쓴다
            'reason' => (int) $export->requested_by === (int) $user->id ? ($reason ?: $export->reason) : $reason,
        ];

        // 지금 내보낼 행이 없으면 만들지 않는다 — 요청 창이 예상 0행이면 막는 것과 같은 기준(빈 파일·「0행 완료」 알림 방지).
        // 다시 요청한 사람의 권한 범위로 센다(새 작업도 그 범위로 뽑힌다)
        if ($this->estimate($type, $data, $user) === 0) {
            throw new ExportException('no_rows', 422);
        }

        $new = $this->request($type, $data, $user, 'retry-'.$export->uuid.'-'.Str::random(8));

        HookManager::doAction('sirsoft-ecommerce.export.after_retry', $new, $export, $user);

        return $new;
    }

    // ─────────────────────────────────────────────
    // 정리 스케줄
    // ─────────────────────────────────────────────

    /**
     * 보관 기간이 지난 파일을 지우고 만료로 바꾸며, 멈춘 진행중 작업을 실패로 닫습니다.
     *
     * @param  CarbonInterface|null  $now  기준 시각 (테스트용)
     * @return array{expired: int, failed: int} 처리 결과
     */
    public function prune(?CarbonInterface $now = null): array
    {
        $now = $now ?? CarbonImmutable::now();
        $expired = 0;
        $failed = 0;

        // 만료 뒤 유예가 지난 파일만 — 만료 전에 시작한 다운로드가 아직 전송 중일 수 있다
        foreach ($this->exports->getExpiredCompleted(CarbonImmutable::instance($now)->subMinutes(self::PRUNE_GRACE_MINUTES)) as $export) {
            // 파일을 못 지웠으면 기록을 그대로 둔다 — 경로를 지우면 다음 정리가 그 파일을 다시 찾지 못한다
            if (! $this->deleteFile($export)) {
                continue;
            }
            $this->exports->update($export, ['status' => ExportStatusEnum::EXPIRED->value, 'file_path' => null]);
            HookManager::doAction('sirsoft-ecommerce.export.after_expire', $export);
            $expired++;
        }

        // 삭제할 때 파일을 못 지운 작업 — 지금 다시 지운다
        foreach ($this->exports->getTrashedWithFile() as $export) {
            if ($this->deleteFile($export)) {
                $this->exports->update($export, ['file_path' => null]);
            }
        }

        $staleBefore = CarbonImmutable::instance($now)->subSeconds(self::JOB_TIMEOUT_SECONDS + 300);
        foreach ($this->exports->getStaleProcessing($staleBefore) as $export) {
            $this->markFailed($export->id, 'timeout');
            $failed++;
        }

        return ['expired' => $expired, 'failed' => $failed];
    }

    /**
     * 저장된 파일을 지웁니다(경로가 없으면 지운 것으로 본다).
     *
     * @param  Export  $export  작업
     * @return bool 지웠거나 지울 파일이 없으면 true, 삭제에 실패하면 false
     */
    private function deleteFile(Export $export): bool
    {
        if (empty($export->file_path)) {
            return true;
        }

        try {
            if ($this->storage->delete(self::STORAGE_CATEGORY, (string) $export->file_path)) {
                return true;
            }
            Log::warning('[내보내기] 파일 삭제 실패', ['export_id' => $export->id]);
        } catch (Throwable $e) {
            Log::warning('[내보내기] 파일 삭제 실패', ['export_id' => $export->id, 'error' => $e->getMessage()]);
        }

        return false;
    }

    /**
     * 보관 기간(일) — 이커머스 환경설정 › 기본정보 「내보내기 파일 보관 기간」
     *
     * 범위(1~30)는 설정 저장 요청(`StoreEcommerceSettingsRequest`)이 저장할 때 판정한다 — 여기서는 값이 없거나
     * 1 미만일 때만 기본값으로 대신한다.
     *
     * @return int 보관 기간(일)
     */
    public function retentionDays(): int
    {
        $days = (int) module_setting('sirsoft-ecommerce', 'basic_info.export_retention_days', self::DEFAULT_RETENTION_DAYS);

        return $days >= 1 ? $days : self::DEFAULT_RETENTION_DAYS;
    }

    /**
     * 다국어 필드의 언어 목록
     *
     * @return array<int, string> 언어 코드
     */
    public function dataLocales(): array
    {
        $locales = (array) config('app.translatable_locales', config('app.supported_locales', ['ko']));

        return array_values(array_filter($locales, 'is_string')) ?: ['ko'];
    }

    /**
     * 번역이 없을 때 대체할 기본 언어
     *
     * @return string 언어 코드
     */
    private function fallbackLocale(): string
    {
        // 다국어 값의 대체 언어는 사이트 기본 언어다(#148 D7 — app.fallback_locale 은 번역 문구 대체용 env 고정값)
        $fallback = (string) config('app.site_locale', config('app.locale'));
        $locales = $this->dataLocales();

        return is_string($fallback) && in_array($fallback, $locales, true) ? $fallback : $locales[0];
    }
}
