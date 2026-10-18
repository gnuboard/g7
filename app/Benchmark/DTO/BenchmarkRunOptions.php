<?php

namespace App\Benchmark\DTO;

/**
 * 계측 실행 옵션 — 커맨드 옵션을 축 실행기로 한 번 전달하는 값 묶음 (Value Object)
 *
 * 축마다 쓰는 옵션이 다르지만(offsets 는 list 축만, allowWrite 는 write/batch 축만)
 * 실행기 시그니처를 하나로 두기 위해 한 객체로 모읍니다.
 */
final readonly class BenchmarkRunOptions
{
    /**
     * @param  array<int, int>  $offsets  측정할 OFFSET 목록 (list 축)
     * @param  int  $runs  측정 횟수 (첫 회는 버림)
     * @param  int  $seed  계측 전 합성 행 시딩 건수 (0 = 시딩 안 함, list 축)
     * @param  bool  $fresh  시딩 전 대상 테이블 비움 (list 축)
     * @param  bool  $explain  실행 계획 수집 (list 축)
     * @param  bool  $allowWrite  데이터 변경 축 실행 허용
     * @param  string|null  $asUser  계측에 사용할 기존 계정 (ID 또는 이메일, screen 축)
     * @param  int  $perPage  목록/화면 1페이지 건수
     */
    public function __construct(
        public array $offsets = [0],
        public int $runs = 3,
        public int $seed = 0,
        public bool $fresh = false,
        public bool $explain = false,
        public bool $allowWrite = false,
        public ?string $asUser = null,
        public int $perPage = 20,
    ) {}

    /**
     * 배열로 직렬화합니다. (리포트의 실행 조건 기재용)
     *
     * @return array<string, mixed> 직렬화 결과
     */
    public function toArray(): array
    {
        return [
            'offsets' => $this->offsets,
            'runs' => $this->runs,
            'seed' => $this->seed,
            'fresh' => $this->fresh,
            'explain' => $this->explain,
            'allow_write' => $this->allowWrite,
            'as_user' => $this->asUser,
            'per_page' => $this->perPage,
        ];
    }
}
