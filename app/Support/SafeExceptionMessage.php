<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * 관리자 화면에 실어 보낼 예외 사유를 고릅니다.
 *
 * 확장 설치·활성화·업데이트 실패 사유는 「…에 실패했습니다: :error」 로 감싸 검증 오류(422)로 내려가므로
 * ResponseHelper 의 운영 모드 500 차단을 거치지 않는다. 500 응답이라도 `errors` 를 배열(`['error' => …]`)로
 * 실으면 차단 대상이 아니다(레이아웃·커스텀 다국어 관리, 개인정보 직권 실행 기록도 같은 경로). DB 예외 원문에는 SQL·DB 호스트·포트·DB 이름이,
 * PHP 런타임 오류 원문에는 서버 파일 경로가 담긴다. 운영 모드(app.debug=false)에서는 이런 내부 예외의
 * 원문을 일반 문구로 바꾸고 원문은 서버 로그에 남긴다. 확장·코어가 일부러 던진 사용자용 사유(번역 문구)와
 * 디버그 모드의 원문은 그대로 돌려준다.
 */
final class SafeExceptionMessage
{
    /**
     * 화면에 보일 사유를 돌려줍니다.
     *
     * @param  \Throwable  $e  처리 중 잡은 예외
     * @param  array<string, mixed>  $context  서버 로그에 함께 남길 맥락(확장 식별자·작업 이름 등)
     * @return string 화면에 보일 사유
     */
    public static function for(\Throwable $e, array $context = []): string
    {
        if (! self::isInternal($e) || config('app.debug')) {
            return $e->getMessage();
        }

        Log::error('관리 작업 내부 오류 — 화면에는 일반 문구로 바꿔 보냈습니다', $context + [
            'exception_class' => $e::class,
            'detail' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        return __('extensions.errors.internal_error');
    }

    /**
     * 원문을 화면에 보이면 안 되는 내부 예외인지 판정합니다.
     *
     * DB 예외(QueryException 은 PDOException 을 잇는다)·PHP 경고가 승격된 예외·PHP 엔진 오류(TypeError 등)이거나,
     * 다른 예외가 DB 예외 원문을 문구에 이어 붙여 다시 던진 경우(SQLSTATE 표지)다.
     *
     * @param  \Throwable  $e  판정할 예외
     * @return bool 내부 예외이면 true
     */
    public static function isInternal(\Throwable $e): bool
    {
        if ($e instanceof \PDOException || $e instanceof \ErrorException || $e instanceof \Error) {
            return true;
        }

        return str_contains($e->getMessage(), 'SQLSTATE[');
    }
}
