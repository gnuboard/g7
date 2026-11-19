<?php

// audit:allow job-generator-needs-scale-test reason: 원본 파일 1건의 「등록 시 생성」 규격만 만드는 단건 잡 — 페이로드는 디스크·경로뿐이고 행을 적재하지 않는다. 기존 이미지 일괄 생성은 커서·limit 으로 끊어 도는 ThumbnailPregenerator::batch 몫이다

namespace App\Jobs;

use App\Thumbnails\DTO\ThumbnailSource;
use App\Thumbnails\ThumbnailPregenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 원본 하나의 「등록 시 미리 생성」 규격 썸네일을 만드는 큐 작업
 *
 * 같은 원본이 짧은 사이에 여러 번 저장돼도 한 번만 돈다(ShouldBeUnique — 키는 디스크·경로).
 * 실패는 경고 로그만 남긴다 — 업로드는 이미 성공했고, 썸네일은 첫 요청 때 다시 만들어진다.
 */
class GenerateThumbnailsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int 최대 시도 횟수 */
    public int $tries = 1;

    /** @var int 타임아웃 (초) */
    public int $timeout = 120;

    /** @var int 중복 방지 잠금 유지 시간 (초) */
    public int $uniqueFor = 300;

    /**
     * @param  string  $disk  원본 디스크
     * @param  string  $path  디스크 위 원본 전체 경로
     * @param  string  $mime  원본 MIME
     * @param  bool  $gated  권한 원본인지
     */
    public function __construct(
        public readonly string $disk,
        public readonly string $path,
        public readonly string $mime,
        public readonly bool $gated,
    ) {}

    /**
     * 중복 방지 키
     *
     * @return string 디스크·경로
     */
    public function uniqueId(): string
    {
        return $this->disk.'|'.$this->path;
    }

    /**
     * 작업을 실행합니다.
     *
     * @param  ThumbnailPregenerator  $pregenerator  미리 만들기
     * @return void 반환값 없음
     */
    public function handle(ThumbnailPregenerator $pregenerator): void
    {
        try {
            $pregenerator->generateFor(new ThumbnailSource($this->disk, $this->path, $this->mime, $this->gated));
        } catch (\Throwable $e) {
            Log::warning('등록 시 썸네일 생성 실패', ['disk' => $this->disk, 'path' => $this->path, 'error' => $e->getMessage()]);
        }
    }
}
