<?php

namespace App\Services\Screen;

use Symfony\Component\HttpFoundation\Response;

/**
 * 내부 API 도구({@see InternalApiClient}) 응답.
 *
 * @since 7.2.0
 */
final class InternalApiResponse
{
    /** @var array<string, mixed>|null JSON 디코드 메모 */
    private ?array $decoded = null;

    /**
     * @param  Response  $response  하위 요청 응답
     */
    public function __construct(
        private readonly Response $response,
    ) {}

    /**
     * HTTP 상태 코드.
     *
     * @return int 상태 코드
     */
    public function status(): int
    {
        return $this->response->getStatusCode();
    }

    /**
     * 2xx 여부.
     *
     * @return bool 성공이면 true
     */
    public function ok(): bool
    {
        return $this->status() >= 200 && $this->status() < 300;
    }

    /**
     * JSON 본문 (점 경로를 주면 그 값).
     *
     * @param  string|null  $key  점 경로 (예: `data.items`), null 이면 전체
     * @param  mixed  $default  없을 때 값
     * @return mixed 값
     */
    public function json(?string $key = null, mixed $default = null): mixed
    {
        if ($this->decoded === null) {
            $decoded = json_decode((string) $this->response->getContent(), true);
            $this->decoded = is_array($decoded) ? $decoded : [];
        }

        return $key === null ? $this->decoded : data_get($this->decoded, $key, $default);
    }

    /**
     * 응답 헤더 값.
     *
     * @param  string  $name  헤더 이름
     * @return string|null 값
     */
    public function header(string $name): ?string
    {
        return $this->response->headers->get($name);
    }

    /**
     * 원본 응답.
     *
     * @return Response 응답
     */
    public function toResponse(): Response
    {
        return $this->response;
    }
}
