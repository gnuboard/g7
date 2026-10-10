<?php

namespace App\Enums;

/**
 * 파일 → DB 레이아웃 행 동기화 모드 (#727).
 *
 * 확장 업데이트의 레이아웃 처리 방식(`layout_strategy`)과 같은 뜻이다. 설치처럼 행이 없을 때는 둘 다 "생성" 으로 같다.
 */
enum LayoutSyncMode: string
{
    /**
     * 새 판으로 덮어쓴다 — 「모두 교체」·「레이아웃 갱신」.
     */
    case Overwrite = 'overwrite';

    /**
     * 운영자가 고친 부분은 두고 손대지 않은 부분만 새 판으로 바꾼다 — 「수정 유지」·활성화·템플릿 전환.
     */
    case Keep = 'keep';

    /**
     * 확장 업데이트 전략 값(`keep`/`overwrite`)에서 모드를 만듭니다.
     *
     * @param  bool  $preserveModified  수정 유지 여부
     * @return self 모드
     */
    public static function fromPreserve(bool $preserveModified): self
    {
        return $preserveModified ? self::Keep : self::Overwrite;
    }

    /**
     * 모든 모드 값을 문자열 배열로 반환합니다.
     *
     * @return array<string> 모드 값 목록
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 유효한 모드 값인지 확인합니다.
     *
     * @param  string  $value  검증할 값
     * @return bool 유효하면 true
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string 번역된 모드 라벨
     */
    public function label(): string
    {
        return __('extensions.layout_sync.mode.'.$this->value);
    }
}
