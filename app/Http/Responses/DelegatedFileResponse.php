<?php

namespace App\Http\Responses;

use App\Support\FileServing\FileDelegation;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * 웹서버 파일 위임을 운영자 설정으로만 켜는 파일 응답
 *
 * Symfony `BinaryFileResponse::prepare()` 에는 위임 분기(실경로 계산 · `X-Accel-Mapping` 치환 · `rawurlencode` ·
 * 본문 생략 · Range 처리 건너뜀)가 이미 있다. 다만 그 분기를 여는 조건이 **요청 헤더** `X-Sendfile-Type` 이고,
 * 그 헤더를 믿을지 정하는 플래그(`$trustXSendfileTypeHeader`)는 프로세스 전역 static 이다. 전역으로 켜면 방문자가
 * 보낸 `X-Sendfile-Type`·`X-Accel-Mapping` 이 그대로 쓰여, 웹서버에 임의 경로를 넘기는 표면이 된다.
 *
 * 이 클래스는 그 분기를 재사용하되 판단은 앱이 한다:
 *
 *  - 위임이 꺼져 있으면 부모 `prepare()` 를 그대로 부른다(신뢰 플래그 꺼짐 — 요청 헤더는 무시된다).
 *  - 켜져 있으면 요청을 **복제**해 그 복제본에 설정값으로 만든 `X-Sendfile-Type`(과 `X-Accel-Mapping`)을
 *    덮어쓰고, 이 응답의 `prepare()` 동안만 신뢰 플래그를 켰다가 `finally` 로 되돌린다. 원래 요청은 건드리지 않는다.
 *  - 위임 헤더가 실제로 섰으면 진단 헤더 `X-G7-File-Delegation: applied`, 세우지 못했으면(nginx 매핑 밖 —
 *    심볼릭 링크로 실경로가 설치 위치 밖인 파일 등) `skipped` 를 붙이고 위임 없이 다시 준비한다. 그 경우 PHP 가
 *    본문을 보내고 Range 도 종전처럼 처리한다.
 *
 * 304 와 같은 비성공·빈 응답은 부모가 위임 분기에 들어가지 않으므로 진단 헤더도 붙지 않는다.
 *
 * @since 7.2.0
 */
class DelegatedFileResponse extends BinaryFileResponse
{
    /**
     * 요청에 맞춰 응답을 준비합니다 — 위임이 켜져 있으면 웹서버 위임 헤더를 세웁니다.
     *
     * @param  Request  $request  현재 요청
     * @return static 준비된 응답
     */
    public function prepare(Request $request): static
    {
        $type = FileDelegation::sendfileType();

        if ($type === null || ! $this->isSuccessful() || $this->isEmpty()) {
            return parent::prepare($request);
        }

        // 방문자가 보낸 값은 쓰지 않는다 — 복제본에 설정값을 **덮어쓴다**(set). 원래 요청은 그대로 둔다.
        $delegated = clone $request;
        $delegated->headers->set('X-Sendfile-Type', $type);

        if ($type === 'X-Accel-Redirect') {
            // Symfony 는 매핑을 `HeaderUtils::split()` 으로 나누는데, 그 파서는 역슬래시를 이스케이프로 읽어 지운다 —
            // Windows 실경로(`C:\…`)가 `C:Users…` 로 망가져 어떤 파일과도 맞지 않았다(위임이 조용히 skipped). 역슬래시를 겹쳐 넘긴다.
            $delegated->headers->set('X-Accel-Mapping', addcslashes(FileDelegation::accelMapping(), '\\'));
        } else {
            $delegated->headers->remove('X-Accel-Mapping');
        }

        $previous = static::$trustXSendfileTypeHeader;
        static::$trustXSendfileTypeHeader = true;

        try {
            parent::prepare($delegated);
        } finally {
            static::$trustXSendfileTypeHeader = $previous;
        }

        if ($this->headers->has($type)) {
            $this->normalizeAccelRedirectSeparators($type);
            $this->headers->set(FileDelegation::DIAGNOSTIC_HEADER, 'applied');

            return $this;
        }

        // 매핑 밖이라 Symfony 가 위임 헤더를 세우지 않았다 — 위임 없이 다시 준비해 Range 를 종전처럼 처리한다.
        parent::prepare($request);
        $this->headers->set(FileDelegation::DIAGNOSTIC_HEADER, 'skipped');

        if (config('app.debug')) {
            Log::debug('웹서버 파일 위임을 건너뜀 — 파일 실경로가 위임 매핑 밖입니다', [
                'file' => $this->getFile()->getPathname(),
                'mapping' => FileDelegation::accelMapping(),
            ]);
        }

        return $this;
    }

    /**
     * Windows 의 경로 구분자(`\`)가 `X-Accel-Redirect` URI 에 남지 않게 `/` 로 바꿉니다.
     *
     * Symfony 는 실경로에서 매핑 접두사를 떼어 낸 나머지를 그대로 잇는다 — Windows 에서는 그 나머지가
     * `storage\app\…` 라 URI 에 `%5C` 가 섞인다. POSIX 에서는 `\` 가 파일 이름의 일부일 수 있으므로 손대지 않는다.
     *
     * @param  string  $type  세운 위임 헤더 이름
     * @return void 반환값 없음
     */
    private function normalizeAccelRedirectSeparators(string $type): void
    {
        if ($type !== 'X-Accel-Redirect' || DIRECTORY_SEPARATOR !== '\\') {
            return;
        }

        $uri = rawurldecode((string) $this->headers->get($type));
        $this->headers->set($type, rawurlencode(str_replace('\\', '/', $uri)));
    }
}
