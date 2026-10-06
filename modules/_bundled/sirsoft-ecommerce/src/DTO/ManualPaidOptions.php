<?php

namespace Modules\Sirsoft\Ecommerce\DTO;

/**
 * 수동 결제 처리 확인 창에서 운영자가 고른 세 항목 (Q13·Q14)
 *
 * 관리자가 결제 전 주문을 결제완료 이후로 올리거나 취소 주문·상품을 되살릴 때 함께 처리할 일을 담는다.
 * 요청에 키가 없으면 **켬(true)** 이다 — 이 키를 모르는 기존 API 클라이언트가 업그레이드만으로
 * 「수동 결제완료가 마일리지·재고를 빼먹는」 종전 결함으로 돌아가지 않게 한다. 기본값을 「모두 체크」로
 * 두는 이유는 기본값이 곧 정상 회계이기 때문이다.
 *
 * `applyRestoration` 은 요청 키가 아니다(Q26 — 운영자가 고를 항목이 아님). 일괄 처리의 1단계
 * (마일리지만 먼저 모두 시도)에서 복원을 미루려고 내부에서만 끈다.
 */
final class ManualPaidOptions
{
    /** 요청 키 — 확인 창 체크박스 세 개 */
    public const KEYS = ['apply_stock_deduction', 'apply_mileage_deduction', 'record_paid_amount'];

    /**
     * @param  bool  $applyStockDeduction  재고 차감
     * @param  bool  $applyMileageDeduction  사용 마일리지 차감
     * @param  bool  $recordPaidAmount  결제금액 기록
     * @param  bool  $applyRestoration  재활성 상품 행·합계 복원 (내부 전용)
     */
    public function __construct(
        public readonly bool $applyStockDeduction = true,
        public readonly bool $applyMileageDeduction = true,
        public readonly bool $recordPaidAmount = true,
        public readonly bool $applyRestoration = true,
    ) {}

    /**
     * 세 항목을 모두 켠 기본값을 만듭니다.
     *
     * @return self 기본 옵션
     */
    public static function defaults(): self
    {
        return new self;
    }

    /**
     * 요청 배열에서 만듭니다 — 키가 없으면 true.
     *
     * @param  array<string, mixed>  $input  요청 입력
     * @return self 옵션
     */
    public static function fromInput(array $input): self
    {
        $pick = static fn (string $key): bool => array_key_exists($key, $input)
            ? filter_var($input[$key], FILTER_VALIDATE_BOOLEAN)
            : true;

        return new self($pick('apply_stock_deduction'), $pick('apply_mileage_deduction'), $pick('record_paid_amount'));
    }

    /**
     * 입력에서 세 키를 떼어 내고 옵션을 돌려줍니다.
     *
     * 주문 단건 수정은 요청 배열을 그대로 저장소 갱신에 넘기므로, 세 키가 섞이면 존재하지 않는 컬럼을
     * 쓰려다 실패한다.
     *
     * @param  array<string, mixed>  $data  요청 입력 (참조 — 세 키가 제거된다)
     * @return self 옵션
     */
    public static function extract(array &$data): self
    {
        $options = self::fromInput($data);

        foreach (self::KEYS as $key) {
            unset($data[$key]);
        }

        return $options;
    }

    /**
     * 같은 선택에서 일부 항목만 켠 사본을 만듭니다. (일괄 2단계 처리용)
     *
     * @param  bool|null  $stock  재고 차감 (null 이면 유지)
     * @param  bool|null  $mileage  마일리지 차감 (null 이면 유지)
     * @param  bool|null  $record  결제금액 기록 (null 이면 유지)
     * @param  bool|null  $restoration  복원 (null 이면 유지)
     * @return self 사본
     */
    public function with(?bool $stock = null, ?bool $mileage = null, ?bool $record = null, ?bool $restoration = null): self
    {
        return new self(
            $stock ?? $this->applyStockDeduction,
            $mileage ?? $this->applyMileageDeduction,
            $record ?? $this->recordPaidAmount,
            $restoration ?? $this->applyRestoration,
        );
    }

    /**
     * 요청 키 => 값 배열을 돌려줍니다. (활동 로그용)
     *
     * @return array<string, bool> 세 항목 값
     */
    public function toArray(): array
    {
        return [
            'apply_stock_deduction' => $this->applyStockDeduction,
            'apply_mileage_deduction' => $this->applyMileageDeduction,
            'record_paid_amount' => $this->recordPaidAmount,
        ];
    }

    /**
     * 운영자가 끈 항목 키 목록을 돌려줍니다.
     *
     * @return array<int, string> 끈 항목 키
     */
    public function skippedKeys(): array
    {
        return array_keys(array_filter($this->toArray(), static fn (bool $on) => ! $on));
    }
}
