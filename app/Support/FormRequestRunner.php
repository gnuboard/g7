<?php

namespace App\Support;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * 관리자 화면 저장에 쓰는 FormRequest 를 HTTP 요청 없이(터미널에서) 같은 규칙으로 실행한다.
 *
 * 순서는 라라벨의 요청 해석(`ValidatesWhenResolvedTrait::validateResolved`)과 같다 —
 * `prepareForValidation()` → 검증기 생성(`withValidator`·`after` 포함) → 실패면 ValidationException →
 * `passedValidation()`. 다른 점은 하나, **`authorize()` 를 부르지 않는다**: 터미널은 셸 접근이 곧 권한이다
 * (`totp:reset`·`UserGradeGuard` 와 같은 관례). 화면의 권한 미들웨어도 이 경로에는 없다.
 *
 * 요청 문맥: 메서드·경로는 그 화면의 저장 요청과 같게 주고(경로·IP 를 쓰는 교차 검증이 같은 판정을 하도록),
 * 라우트 매개변수(`{key}`·`{identifier}`)는 `$routeParameters` 로 묶는다. 접속 IP 는 서버 자신(127.0.0.1)이다.
 */
class FormRequestRunner
{
    /**
     * FormRequest 를 실행하고 검증을 마친 요청 객체를 돌려준다 (`validated()`·확장 메서드 사용용).
     *
     * @param  class-string<FormRequest>  $class  실행할 FormRequest 클래스
     * @param  array<string, mixed>  $payload  요청 본문 (화면이 보내는 모양 그대로)
     * @param  array<string, mixed>  $routeParameters  라우트 매개변수 (예: ['key' => 'mail.host'])
     * @param  string  $method  HTTP 메서드
     * @param  string  $uri  화면 저장 요청의 경로 (예: '/api/admin/settings')
     * @return FormRequest 검증을 마친 요청
     *
     * @throws ValidationException 검증 실패
     */
    public static function run(
        string $class,
        array $payload,
        array $routeParameters = [],
        string $method = 'POST',
        string $uri = '/',
    ): FormRequest {
        if (! is_subclass_of($class, FormRequest::class)) {
            throw new InvalidArgumentException("{$class} is not a FormRequest");
        }

        $base = Request::create($uri, $method, $payload);

        /** @var FormRequest $request */
        $request = $class::createFrom($base);
        $request->setContainer(app())->setRedirector(app('redirect'));

        $route = (new Route([$method], ltrim($uri, '/'), []))->bind($base);
        foreach ($routeParameters as $name => $value) {
            $route->setParameter($name, $value);
        }
        $request->setRouteResolver(static fn () => $route);

        // protected 단계들 — 라라벨의 validateResolved() 에서 권한 검사만 뺀 순서
        Closure::bind(function (): void {
            $this->prepareForValidation();

            $validator = $this->getValidatorInstance();

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }

            $this->passedValidation();
        }, $request, $class)();

        return $request;
    }

    /**
     * FormRequest 를 실행하고 검증을 통과한 값(`validated()`)을 돌려준다.
     *
     * @param  class-string<FormRequest>  $class  실행할 FormRequest 클래스
     * @param  array<string, mixed>  $payload  요청 본문
     * @param  array<string, mixed>  $routeParameters  라우트 매개변수
     * @param  string  $method  HTTP 메서드
     * @param  string  $uri  화면 저장 요청의 경로
     * @return array<string, mixed> 검증을 통과한 값
     *
     * @throws ValidationException 검증 실패
     */
    public static function validate(
        string $class,
        array $payload,
        array $routeParameters = [],
        string $method = 'POST',
        string $uri = '/',
    ): array {
        return (array) self::run($class, $payload, $routeParameters, $method, $uri)->validated();
    }
}
