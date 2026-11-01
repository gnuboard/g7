<?php

namespace Tests\Fixtures\Architecture;

use Illuminate\Support\Facades\Log;

/**
 * 정적 호출 구조 검사(StaticCallTargetsStaticMethodTest)의 대조군 — 위반은 정확히 1건이다.
 *
 * 검사 모집단(app·routes·config·database·upgrades·번들 확장) 밖에 두며, 실행하지 않고 토큰과 리플렉션으로만 읽는다.
 */
class StaticCallTargetFixture
{
    /**
     * 인스턴스 메서드.
     *
     * @return string 고정 문자열
     */
    public function instanceMethod(): string
    {
        return 'instance';
    }

    /**
     * 정적 메서드.
     *
     * @return string 고정 문자열
     */
    public static function staticMethod(): string
    {
        return 'static';
    }
}

/**
 * 대조군의 부모 클래스.
 */
class StaticCallParentFixture
{
    /**
     * 부모의 인스턴스 메서드.
     *
     * @return string 고정 문자열
     */
    public function parentInstance(): string
    {
        return 'parent';
    }
}

/**
 * 허용 사례와 위반 1건을 담은 클래스.
 */
class StaticCallViolationFixture extends StaticCallParentFixture
{
    /**
     * 인스턴스 메서드 안의 전달 호출·Facade·static 메서드 호출 — 모두 허용.
     *
     * @return void 반환값 없음
     */
    public function forwards(): void
    {
        parent::parentInstance();
        self::helper();
        static::helper();
        StaticCallViolationFixture::helper();
        StaticCallParentFixture::parentInstance();
        Log::info('fixture');
        StaticCallTargetFixture::staticMethod();
        StaticCallTargetFixture::undefinedOnPurpose();
    }

    /**
     * 전달 호출 대상 인스턴스 메서드.
     *
     * @return void 반환값 없음
     */
    public function helper(): void {}

    /**
     * 정적 메서드에서 남의 인스턴스 메서드를 정적으로 부른다 — 위반 1건.
     *
     * @return string 호출 결과
     */
    public static function violates(): string
    {
        return StaticCallTargetFixture::instanceMethod();
    }
}
