<?php

namespace App\Extension\Concerns;

/**
 * GitHub 최신 판 조회 실패 사유를 업데이트 확인 응답에 싣는 공통 조각.
 *
 * 매니저 셋(Module·Plugin·Template)의 단건 업데이트 확인(check*Update)은 GitHub 조회에 실패해도 예외를 던지지 않고 번들로
 * 대체하거나 「업데이트 없음」으로 답한다. 그 답만으로는 「확인했더니 최신」과 「확인하지 못함」을 가를 수 없어 비대화형
 * 실행(cron·스크립트)이 실패를 성공으로 읽었다. 조회에 실패하면 응답에 `check_error`(사유 문자열)를 더한다 — 성공했거나
 * 릴리스가 아직 없으면(HTTP 404) 키를 싣지 않는다. 응답 배열에 키만 더하므로 관리자 화면 호출부는 그대로 동작한다.
 *
 * 사유는 최신 판 조회 함수(`fetchLatestVersion()`)가 기록하고 확인 함수가 꺼내 쓴다. 테스트가 조회 함수를 대체하면
 * 기록이 남지 않아 종전 동작(사유 없음)으로 돈다.
 */
trait TracksGithubVersionCheckError
{
    /** 마지막 GitHub 최신 판 조회의 실패 사유 (성공·릴리스 없음이면 null) */
    private ?string $githubVersionCheckError = null;

    /**
     * 새 조회를 시작하기 전에 지난 실패 사유를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function resetGithubVersionCheckError(): void
    {
        $this->githubVersionCheckError = null;
    }

    /**
     * 조회 실패 사유를 기록합니다.
     *
     * @param  string|null  $reason  실패 사유 (null 이면 기록하지 않음)
     * @return void 반환값 없음
     */
    protected function recordGithubVersionCheckError(?string $reason): void
    {
        if ($reason !== null && $reason !== '') {
            $this->githubVersionCheckError = $reason;
        }
    }

    /**
     * 마지막 조회의 실패 사유를 꺼냅니다.
     *
     * @return string|null 실패 사유 (없으면 null)
     */
    protected function githubVersionCheckError(): ?string
    {
        return $this->githubVersionCheckError;
    }

    /**
     * `GithubHelper::fetchLatestRelease()` 결과에서 조회 실패 사유를 뽑습니다.
     *
     * 연결 실패는 헬퍼가 준 사유를, 404 가 아닌 실패 응답(403 요청 한도 초과·5xx 등)은 상태 코드를 담은 사유를 돌려준다.
     * 판을 받았거나 404(릴리스 없음)면 null 이다.
     *
     * @param  array{version?: string|null, error?: string|null, status?: int|null}  $release  조회 결과
     * @return string|null 실패 사유
     */
    protected function githubReleaseCheckError(array $release): ?string
    {
        if (($release['version'] ?? null) !== null) {
            return null;
        }

        if (is_string($release['error'] ?? null) && $release['error'] !== '') {
            return $release['error'];
        }

        $status = $release['status'] ?? null;

        if (is_int($status) && $status !== 404 && ($status < 200 || $status >= 300)) {
            return (string) __('extensions.errors.github_check_http_status', ['status' => $status]);
        }

        return null;
    }

    /**
     * 업데이트 확인 응답에 조회 실패 사유를 더합니다 (사유가 없으면 그대로).
     *
     * @param  array<string, mixed>  $response  업데이트 확인 응답
     * @param  string|null  $checkError  조회 실패 사유
     * @return array<string, mixed> 사유를 더한 응답
     */
    protected function withUpdateCheckError(array $response, ?string $checkError): array
    {
        return $checkError === null ? $response : $response + ['check_error' => $checkError];
    }
}
