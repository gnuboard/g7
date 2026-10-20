<?php

namespace App\Support;

use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 서비스 공급자 부팅 중 남기는 번역 문구 로그를 언어팩 번역 등록이 끝난 뒤로 미루는 헬퍼.
 *
 * 활성 언어팩(ja 등)의 번역 경로는 `LanguagePackServiceProvider` 의 booted 콜백에서 등록된다. 그보다 앞선
 * 공급자의 `boot()`(예: `CoreServiceProvider` 의 확장 자동 비활성화 판정)가 그 자리에서 `__()` 로 문구를 만들면
 * 사이트 언어가 ja 여도 ja 번역이 아직 없어 폴백(ko) 문구로 기록된다.
 *
 * 앱이 아직 booted 가 아니면 번역 키·치환값·맥락만 대기열에 담고, 언어팩 공급자가 등록을 마친 직후(일찍 돌아가는
 * 갈래·예외 갈래 포함) `flush()` 로 그때 번역해 남긴다. booted 뒤에는 바로 남긴다. 부팅이 끝나기 전에 프로세스가
 * 끝나도 기록이 사라지지 않도록 종료 시점에도 남은 대기열을 비운다.
 */
class BootDeferredLog
{
    /**
     * 미뤄 둔 로그 (레벨·번역 키·치환값·맥락).
     *
     * @var array<int, array{level: string, key: string, replace: array<string, mixed>, context: array<string, mixed>}>
     */
    private static array $pending = [];

    /**
     * 종료 시점 비우기를 이미 등록했는지 여부 (프로세스당 한 번).
     */
    private static bool $shutdownRegistered = false;

    /**
     * 경고 로그를 남기거나 부팅 중이면 미룹니다.
     *
     * @param  string  $key  번역 키
     * @param  array<string, mixed>  $replace  문구 자리표시자 값
     * @param  array<string, mixed>  $context  로그 맥락
     * @return void 반환값 없음
     */
    public static function warning(string $key, array $replace = [], array $context = []): void
    {
        self::write('warning', $key, $replace, $context);
    }

    /**
     * 정보 로그를 남기거나 부팅 중이면 미룹니다.
     *
     * @param  string  $key  번역 키
     * @param  array<string, mixed>  $replace  문구 자리표시자 값
     * @param  array<string, mixed>  $context  로그 맥락
     * @return void 반환값 없음
     */
    public static function info(string $key, array $replace = [], array $context = []): void
    {
        self::write('info', $key, $replace, $context);
    }

    /**
     * 미뤄 둔 로그를 지금의 번역기로 문구를 만들어 남기고 대기열을 비웁니다.
     *
     * `LanguagePackServiceProvider` 가 번역 경로 등록을 마친 직후 부른다. 대기열을 먼저 비워 두 번 남기지 않는다.
     *
     * @return void 반환값 없음
     */
    public static function flush(): void
    {
        $pending = self::$pending;
        self::$pending = [];

        foreach ($pending as $entry) {
            try {
                self::emit($entry['level'], $entry['key'], $entry['replace'], $entry['context']);
            } catch (Throwable) {
                // 로그 기록 실패가 부팅을 막지 않는다
            }
        }
    }

    /**
     * 미뤄 둔 로그를 남기지 않고 버립니다 (시험 전용).
     *
     * 대기열은 프로세스 정적 상태라, 시험이 비우기 전에 실패하면 남은 항목이 다음 시험으로 샌다. 시험의 tearDown 이
     * 부른다. 운영 경로에서는 부르지 않는다 — 부팅 중 기록은 `flush()` 로만 비운다.
     *
     * @return void 반환값 없음
     */
    public static function resetForTesting(): void
    {
        self::$pending = [];
    }

    /**
     * 아직 남기지 않은 로그 수를 반환합니다.
     *
     * @return int 대기 중인 로그 수
     */
    public static function pendingCount(): int
    {
        return count(self::$pending);
    }

    /**
     * 부팅 중이면 대기열에 담고, 아니면 바로 남깁니다.
     *
     * @param  string  $level  로그 레벨 (warning, info)
     * @param  string  $key  번역 키
     * @param  array<string, mixed>  $replace  문구 자리표시자 값
     * @param  array<string, mixed>  $context  로그 맥락
     * @return void 반환값 없음
     */
    private static function write(string $level, string $key, array $replace, array $context): void
    {
        if (! self::isBooting()) {
            self::emit($level, $key, $replace, $context);

            return;
        }

        self::$pending[] = ['level' => $level, 'key' => $key, 'replace' => $replace, 'context' => $context];

        if (! self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function(static fn () => self::flush());
        }
    }

    /**
     * 번역 문구로 로그를 남깁니다.
     *
     * 맥락 값 중 `DeferredTranslation` 도 이때 번역한다 — 부팅 중 만든 사유 문장(예: 「못 끔」 기록의 `reason`)이
     * 본문과 같은 언어로 남게 한다.
     *
     * @param  string  $level  로그 레벨 (warning, info)
     * @param  string  $key  번역 키
     * @param  array<string, mixed>  $replace  문구 자리표시자 값
     * @param  array<string, mixed>  $context  로그 맥락
     * @return void 반환값 없음
     */
    private static function emit(string $level, string $key, array $replace, array $context): void
    {
        $context = array_map(
            static fn (mixed $value): mixed => $value instanceof DeferredTranslation ? $value->resolve() : $value,
            $context,
        );

        Log::{$level}(__($key, $replace), $context);
    }

    /**
     * 앱이 서비스 공급자 부팅 중(booted 이전)인지 판정합니다.
     *
     * @return bool 부팅 중이면 true
     */
    private static function isBooting(): bool
    {
        $app = Container::getInstance();

        return $app instanceof Application && ! $app->isBooted();
    }
}
