<?php

namespace App\CoreUpdate;

/**
 * 웹 코어 업데이트의 단계 (한 요청 = 한 단계).
 *
 * 단계를 요청 경계로 나누는 것은 편의가 아니라 **안전 요건**이다 (§7 R25) — 파일 교체
 * 뒤에도 같은 프로세스가 살아 있으면 사라진 클래스를 참조해 fatal 이 난다. 특히
 * `Apply` 와 `UpgradeSteps` 사이에는 **반드시 요청 경계가 있어야 한다**: 업그레이드
 * 스텝은 새로 부팅한 신버전 코드에서 돌아야 하고, 그것이 무CLI 환경에서 spawn 격리를
 * 대신하는 유일한 수단이다. 이 불변식은 `CoreUpdateOrchestrator` 가 한 요청에 한
 * 단계만 수행하는 것으로 성립하며 계약 테스트가 고정한다.
 *
 * 순서는 이 enum 의 선언 순서가 아니라 `sequence()` 가 소유한다 — 열거 순서에 의미를
 * 싣는 형태는 나중에 케이스를 추가하는 사람이 그 사실을 알 방법이 없다.
 *
 * @since 7.1.0
 */
enum CoreUpdateStep: string
{
    /** 사전 진단 — 요구사항·경로·쓰기 가능성·여유 공간 (D26). */
    case Preflight = 'preflight';

    /** 유지보수 모드 활성화 (웹은 필수 — D27). */
    case Maintenance = 'maintenance';

    /** 새 버전 내려받기 또는 운영자가 올린 ZIP 추출 (㉯ — D18). */
    case Download = 'download';

    /** 백업 생성 + 소유권 스냅샷 (웹은 백업 필수 — D27). */
    case Backup = 'backup';

    /** `_pending` 의 vendor 구성 (무CLI 는 번들 모드). */
    case Vendor = 'vendor';

    /** 신규 파일 manifest + 3-way 증분 적용 목록 산출. */
    case Plan = 'plan';

    /** 코어 파일 적용 — 이 단계 뒤로는 같은 프로세스가 구버전 클래스를 참조하지 않는다. */
    case Apply = 'apply';

    /** vendor 디렉토리를 운영 위치로 복사. */
    case VendorCopy = 'vendor_copy';

    /** 마이그레이션 + 코어 config 재로드·권한/메뉴 재동기. */
    case Migrate = 'migrate';

    /** 업그레이드 스텝 — 파일 적용과 **다른 요청**에서만 실행한다. */
    case UpgradeSteps = 'upgrade_steps';

    /** 번들 확장·언어팩 일괄 업데이트 (운영자 선택 — D28). */
    case Bundled = 'bundled';

    /** 버전 기록·캐시·소유권 복원·링크 복구·정리·유지보수 해제. */
    case Finalize = 'finalize';

    /**
     * 정상 진행 순서를 반환합니다.
     *
     * @return list<self> 단계 순서
     */
    public static function sequence(): array
    {
        return [
            self::Preflight,
            self::Maintenance,
            self::Download,
            self::Backup,
            self::Vendor,
            self::Plan,
            self::Apply,
            self::VendorCopy,
            self::Migrate,
            self::UpgradeSteps,
            self::Bundled,
            self::Finalize,
        ];
    }

    /**
     * 이 단계의 순서 번호(1-base)를 반환합니다.
     *
     * @return int 순서 번호
     */
    public function position(): int
    {
        return (int) array_search($this, self::sequence(), true) + 1;
    }

    /**
     * 다음 단계를 반환합니다.
     *
     * @return self|null 마지막 단계면 null
     */
    public function next(): ?self
    {
        $sequence = self::sequence();
        $index = (int) array_search($this, $sequence, true);

        return $sequence[$index + 1] ?? null;
    }

    /**
     * 화면 표시용 다국어 키.
     *
     * @return string 번역 키
     */
    public function labelKey(): string
    {
        return 'settings.core_update.web.steps.'.$this->value;
    }

    /**
     * 파일 교체가 이미 일어난 뒤의 단계인지 여부.
     *
     * 참이면 이 요청은 신버전 코드로 부팅된 프로세스에서 돌아야 한다.
     *
     * @return bool 파일 적용 이후 단계 여부
     */
    public function runsAfterFileReplacement(): bool
    {
        return $this->position() > self::Apply->position();
    }

    /**
     * 값으로 단계를 찾습니다 (알 수 없으면 null).
     *
     * @param  mixed  $value  저장된 값
     * @return self|null 해석된 단계
     */
    public static function tryFromValue(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }
}
