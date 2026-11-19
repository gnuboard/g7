<?php

namespace App\Execution;

/**
 * 실행 모드.
 *
 * 웹 요청 안에서 Artisan 커맨드를 실행해야 할 때 어떤 실행기를 쓸지의 선택이다.
 * VendorMode 와 같은 골격 — 열거·안전 변환·라벨.
 *
 * - Auto: 매 판정마다 환경 감지 (저장 기본값 — 호스팅 환경 변화를 따라간다)
 * - Subprocess: 강제 서브프로세스 (`php artisan …` 스폰, 현행 경로)
 * - Inprocess: 강제 in-process (`Artisan::call()`, CLI 없는 환경)
 *
 * 이 모드는 **웹 요청에서 커맨드를 실행할 때만** 관여한다. 운영자가 쉘에서
 * `php artisan …` 을 직접 부르는 경우는 이미 CLI 안이므로 모드와 무관하다.
 */
enum ExecutionMode: string
{
    case Auto = 'auto';
    case Subprocess = 'subprocess';
    case Inprocess = 'inprocess';

    /**
     * 다국어 라벨.
     *
     * @return string 번역된 모드 라벨
     */
    public function label(): string
    {
        return match ($this) {
            self::Auto => __('settings.execution_mode.auto'),
            self::Subprocess => __('settings.execution_mode.subprocess'),
            self::Inprocess => __('settings.execution_mode.inprocess'),
        };
    }

    /**
     * 허용값 목록 (설정 검증·허용값 표가 소비).
     *
     * @return list<string> 모드 문자열 목록
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 문자열에서 안전하게 변환합니다 (null/미지원 값은 Auto 폴백).
     *
     * @param  string|null  $value  변환할 문자열
     * @return self 변환된 모드
     */
    public static function fromStringOrAuto(?string $value): self
    {
        if ($value === null || $value === '') {
            return self::Auto;
        }

        return self::tryFrom($value) ?? self::Auto;
    }
}
