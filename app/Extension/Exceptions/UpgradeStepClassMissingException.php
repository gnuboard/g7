<?php

namespace App\Extension\Exceptions;

/**
 * 업그레이드 단계·데이터 마이그레이션 파일은 있는데 클래스를 쓸 수 없을 때 던지는 예외.
 *
 * 예전에는 네임스페이스 오타·오토로드 누락으로 `class_exists` 가 거짓이면 그 단계를 조용히 건너뛰고
 * 업데이트가 성공했다 — 데이터는 옛 형태인데 버전만 올라갔다. 이제 업데이트를 실패로 끝내고 기존
 * 업데이트 실패 경로(실패 사유 반환·상태 복원)를 탄다.
 */
class UpgradeStepClassMissingException extends \RuntimeException
{
    /**
     * @param  string  $stepFile  업그레이드 단계(또는 데이터 마이그레이션) 파일명
     * @param  string  $class  기대한 클래스(FQCN)
     * @param  string|null  $interface  클래스는 있지만 구현하지 않은 인터페이스·부모 클래스 (없으면 클래스 자체가 없음)
     */
    public function __construct(
        public readonly string $stepFile,
        public readonly string $class,
        public readonly ?string $interface = null,
    ) {
        parent::__construct($interface === null
            ? __('extensions.errors.upgrade_step_class_missing', ['file' => $stepFile, 'class' => $class])
            : __('extensions.errors.upgrade_step_interface_missing', ['file' => $stepFile, 'class' => $class, 'interface' => $interface]));
    }

    /**
     * 클래스가 없거나 기대한 상위 타입이 아니면 예외를 던집니다.
     *
     * @param  string  $file  파일 경로
     * @param  string  $class  기대한 클래스(FQCN)
     * @param  class-string  $parent  구현·상속해야 하는 인터페이스 또는 부모 클래스
     * @return void 반환값 없음
     *
     * @throws self 클래스 없음 또는 상위 타입 불일치
     */
    public static function assertUsable(string $file, string $class, string $parent): void
    {
        if (! class_exists($class)) {
            throw new self(basename($file), $class);
        }

        if (! is_subclass_of($class, $parent)) {
            throw new self(basename($file), $class, $parent);
        }
    }
}
