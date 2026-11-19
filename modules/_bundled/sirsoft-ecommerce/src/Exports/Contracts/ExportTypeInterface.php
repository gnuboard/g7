<?php

namespace Modules\Sirsoft\Ecommerce\Exports\Contracts;

use App\Models\User;
use Carbon\CarbonInterface;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Modules\Sirsoft\Ecommerce\Exports\ExportField;

/**
 * 내보내기 유형 (주문내역·상품내역·마일리지 내역 …)
 *
 * 유형은 「어떤 행을, 어떤 필드로」를 책임진다. 행을 고르는 조건은 그 유형의 목록 화면과 **같은** 필터
 * 메서드·권한 범위를 써야 한다 — 다르면 화면에서 본 건수와 파일 행 수가 어긋난다.
 * 확장은 `sirsoft-ecommerce.export.registered_types` 필터로 유형을 더한다.
 */
interface ExportTypeInterface
{
    /**
     * 유형 키
     *
     * @return string 유형 키 (예: orders)
     */
    public function key(): string;

    /**
     * 유형 이름의 다국어 키 (관리 화면 「작업유형」·파일명 접두)
     *
     * @return string 다국어 키
     */
    public function labelKey(): string;

    /**
     * 이 유형의 데이터를 볼 수 있는 권한 — 요청·다운로드 모두 이 권한과 그 범위를 요구한다
     *
     * @return string 권한 식별자
     */
    public function readPermission(): string;

    /**
     * 행 단위 목록 (첫 항목이 기본값 — 목록 화면과 같은 단위)
     *
     * @return array<string, string> 단위 키 → 라벨 다국어 키
     */
    public function rowUnits(): array;

    /**
     * 기간 기준 후보 (첫 항목이 기본값). 빈 배열이면 기간 구좌를 쓰지 않는다
     *
     * @return array<string, string> 기간 기준 키 → 라벨 다국어 키
     */
    public function dateTypes(): array;

    /**
     * 기간이 반드시 있어야 하는지 (주문·마일리지 true, 상품 false)
     *
     * @return bool 기간 필수 여부
     */
    public function periodRequired(): bool;

    /**
     * 목록 화면 컬럼 → 내보낼 필드 매핑 (R5)
     *
     * 화면 컬럼 선택기의 모든 `field` 는 여기 있거나 `excludedScreenColumns()` 에 사유와 함께 있어야
     * 한다(동기화 잠금 테스트).
     *
     * @return array<string, array{label_key: string, fields: array<int, string>}> 화면 컬럼 키 → 라벨·필드 키 목록
     */
    public function screenColumns(): array;

    /**
     * 내보내기와 무관한 화면 컬럼(행 번호·체크박스 등)과 그 사유
     *
     * @return array<string, string> 화면 컬럼 키 → 사유
     */
    public function excludedScreenColumns(): array;

    /**
     * 목록 화면 쿼리 검증 규칙의 출처 (「현재 검색조건」 필터를 같은 규칙으로 검증한다)
     *
     * @return class-string 목록 FormRequest 클래스
     */
    public function listRequestClass(): string;

    /**
     * 행 단위별 필드 목록 (모델 선언 + 계산 필드 + 확장이 더한 필드)
     *
     * @param  string  $unit  행 단위
     * @return array<string, ExportField> 필드 키 → 필드
     */
    public function fields(string $unit): array;

    /**
     * 내보낼 원천 행을 고르는 쿼리를 만듭니다(목록과 같은 필터 + 권한 범위 + 기준 시각).
     *
     * @param  array<string, mixed>  $filters  검증된 목록 필터 (기간 키 포함)
     * @param  array<int, int>|null  $ids  「선택한 행」 ID 목록 (null 이면 필터 사용)
     * @param  User  $requester  요청자 (권한 범위 판정)
     * @param  CarbonInterface|null  $cutoff  기준 시각 — 기준일이 이 시각 이후인 행 제외
     * @return Builder 원천 쿼리
     */
    public function query(array $filters, ?array $ids, User $requester, ?CarbonInterface $cutoff): Builder;

    /**
     * 파일의 데이터 행 수를 셉니다(행 단위 반영).
     *
     * 하위 단위 행(예: 상품 옵션)이 상위와 따로 생길 수 있는 유형은 기준 시각 뒤에 생긴 하위 행도 빼야 한다 — 상위에만
     * 걸린 {@see query()} 의 기준 시각으로는 그 행이 걸러지지 않는다.
     *
     * @param  Builder  $query  원천 쿼리
     * @param  string  $unit  행 단위
     * @param  CarbonInterface|null  $cutoff  기준 시각 (null 이면 제한 없음 — 예상 건수)
     * @return int 행 수
     */
    public function countRows(Builder $query, string $unit, ?CarbonInterface $cutoff = null): int;

    /**
     * 데이터 행을 차례로 냅니다(청크 조회 — 메모리 일정).
     *
     * 각 항목은 [행 객체, 상위 단위의 첫 행인지, 통화 코드].
     *
     * @param  Builder  $query  원천 쿼리
     * @param  string  $unit  행 단위
     * @param  CarbonInterface|null  $cutoff  기준 시각 — {@see countRows()} 와 같은 하위 행 기준
     * @return Generator<int, array{0: object, 1: bool, 2: string|null}> 행 목록
     */
    public function rows(Builder $query, string $unit, ?CarbonInterface $cutoff = null): Generator;
}
