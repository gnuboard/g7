<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Modules\Sirsoft\Ecommerce\Enums\SequenceAlgorithm;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;
use Modules\Sirsoft\Ecommerce\Exceptions\SequenceCodeDuplicateException;
use Modules\Sirsoft\Ecommerce\Exceptions\SequenceNotFoundException;
use Modules\Sirsoft\Ecommerce\Exceptions\SequenceOverflowException;
use Modules\Sirsoft\Ecommerce\Models\Sequence;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\SequenceRepositoryInterface;

/**
 * 시퀀스 서비스
 *
 * 상품코드, 주문번호 등의 채번 로직을 담당합니다.
 * 다양한 알고리즘 지원: Hybrid, Sequential, Daily, Timestamp
 */
class SequenceService
{
    public function __construct(
        protected SequenceRepositoryInterface $repository
    ) {}

    /**
     * 코드 생성
     *
     * 코드 생성과 이력 삽입이 원자적으로 수행됩니다.
     * UNIQUE 제약조건으로 DB 레벨에서 중복을 방지합니다.
     *
     * @param SequenceType $type 시퀀스 타입
     * @return string 생성된 코드 (항상 문자열 반환)
     * @throws SequenceNotFoundException 시퀀스가 없을 때
     * @throws SequenceOverflowException 최대값 도달 시
     * @throws SequenceCodeDuplicateException 코드 중복 시
     */
    public function generateCode(SequenceType $type): string
    {
        // Before 훅
        HookManager::doAction('sirsoft-ecommerce.sequence.before_generate', $type);

        // NanoID 알고리즘은 채번테이블 미사용 (DB 트랜잭션/락 불필요)
        $sequence = $this->repository->findByType($type);

        if (! $sequence) {
            throw new SequenceNotFoundException($type);
        }

        if ($sequence->algorithm === SequenceAlgorithm::NANOID) {
            $code = $this->generateNanoIdCode($sequence);

            // After 훅
            HookManager::doAction('sirsoft-ecommerce.sequence.after_generate', $type, $code);

            return $code;
        }

        $code = DB::transaction(function () use ($type) {
            // FOR UPDATE 락으로 동시성 제어
            $sequence = $this->repository->findByTypeForUpdate($type);

            // 타임스탬프 알고리즘은 별도 경로로 처리
            if ($sequence->algorithm === SequenceAlgorithm::TIMESTAMP) {
                return $this->generateTimestampCode($sequence, $type);
            }

            // 알고리즘별 다음 값 계산
            $nextValue = match ($sequence->algorithm) {
                SequenceAlgorithm::HYBRID => $this->calculateHybrid($sequence),
                SequenceAlgorithm::SEQUENTIAL => $this->calculateSequential($sequence),
                SequenceAlgorithm::DAILY => $this->calculateDaily($sequence),
                default => throw new \InvalidArgumentException("지원하지 않는 알고리즘: {$sequence->algorithm->value}"),
            };

            // 최대값 체크
            if ($nextValue > $sequence->max_value) {
                if ($sequence->cycle) {
                    $nextValue = $sequence->min_value;
                } else {
                    throw new SequenceOverflowException($type, $sequence->max_value);
                }
            }

            // 코드 포맷팅
            $code = $this->formatCode($sequence, $nextValue);

            // 코드 이력 삽입 (UNIQUE 제약조건으로 중복 방지)
            try {
                $this->repository->insertCode($type, $code);
            } catch (QueryException $e) {
                // UNIQUE 제약조건 위반 (SQLSTATE 23000)
                // getCode()가 int 또는 string을 반환할 수 있으므로 == 비교 사용
                if ($e->getCode() == 23000) {
                    throw new SequenceCodeDuplicateException($type, $code);
                }
                throw $e;
            }

            // current_value 업데이트
            $this->repository->updateCurrentValue($sequence, $nextValue);

            // 코드 이력 정리 (max_history_count가 설정된 경우)
            $this->cleanupOldCodes($type, $sequence->max_history_count ?? 0);

            return $code;
        });

        // After 훅
        HookManager::doAction('sirsoft-ecommerce.sequence.after_generate', $type, $code);

        return $code;
    }

    /**
     * 다음 시퀀스 값 조회 및 증가 (원자적 연산)
     *
     * 알고리즘에 따라 다른 방식으로 다음 값을 계산합니다.
     *
     * @param SequenceType $type 시퀀스 타입
     * @return int 다음 시퀀스 값
     * @throws SequenceNotFoundException 시퀀스가 없을 때
     * @throws SequenceOverflowException 최대값 도달 시
     */
    public function getNextSequence(SequenceType $type): int
    {
        return DB::transaction(function () use ($type) {
            // FOR UPDATE 락으로 동시성 제어
            $sequence = $this->repository->findByTypeForUpdate($type);

            if (! $sequence) {
                throw new SequenceNotFoundException($type);
            }

            // 알고리즘별 다음 값 계산
            $nextValue = match ($sequence->algorithm) {
                SequenceAlgorithm::HYBRID => $this->calculateHybrid($sequence),
                SequenceAlgorithm::SEQUENTIAL => $this->calculateSequential($sequence),
                SequenceAlgorithm::DAILY => $this->calculateDaily($sequence),
            };

            // 최대값 체크
            if ($nextValue > $sequence->max_value) {
                if ($sequence->cycle) {
                    $nextValue = $sequence->min_value;
                } else {
                    throw new SequenceOverflowException($type, $sequence->max_value);
                }
            }

            $this->repository->updateCurrentValue($sequence, $nextValue);

            return $nextValue;
        });
    }

    /**
     * 하이브리드 알고리즘: max(timestamp, lastValue) + increment
     *
     * - 개별 등록: 시간이 지났으면 새 타임스탬프 기준
     * - 일괄 등록: 빠르게 연속 생성 시 시퀀스 증가
     *
     * @param Sequence $sequence 시퀀스 모델
     * @return int 다음 시퀀스 값
     */
    protected function calculateHybrid(Sequence $sequence): int
    {
        $currentTimestamp = time();
        $baseValue = max($currentTimestamp, $sequence->current_value);

        return $baseValue + $sequence->increment;
    }

    /**
     * 순수 시퀀스 알고리즘: current_value + increment
     *
     * - 단순히 현재 값에서 증가
     *
     * @param Sequence $sequence 시퀀스 모델
     * @return int 다음 시퀀스 값
     */
    protected function calculateSequential(Sequence $sequence): int
    {
        return $sequence->current_value + $sequence->increment;
    }

    /**
     * 일별 리셋 알고리즘: 날짜 변경 시 min_value부터 시작
     *
     * - 날짜가 바뀌면 min_value로 리셋
     * - 같은 날이면 current_value + increment
     *
     * @param Sequence $sequence 시퀀스 모델
     * @return int 다음 시퀀스 값
     */
    protected function calculateDaily(Sequence $sequence): int
    {
        $today = Carbon::today();

        // 날짜가 바뀌었으면 리셋
        if (! $sequence->last_reset_date || ! $today->isSameDay($sequence->last_reset_date)) {
            $this->repository->updateLastResetDate($sequence, $today);

            return $sequence->min_value;
        }

        return $sequence->current_value + $sequence->increment;
    }

    /**
     * 시퀀스 초기화
     *
     * @param SequenceType $type 시퀀스 타입
     * @param array $options 옵션 (algorithm, prefix, pad_length, max_value 등)
     * @return Sequence
     */
    public function initializeSequence(SequenceType $type, array $options = []): Sequence
    {
        // 타입별 기본 설정 가져오기
        $defaultConfig = $type->getDefaultConfig();

        $data = array_merge([
            'type' => $type->value,
            'algorithm' => $defaultConfig['algorithm']->value,
            'prefix' => $defaultConfig['prefix'],
            'current_value' => 0,
            'increment' => 1,
            'min_value' => 1,
            'max_value' => $defaultConfig['max_value'],
            'cycle' => false,
            'pad_length' => $defaultConfig['pad_length'],
            'max_history_count' => $defaultConfig['max_history_count'],
            'date_format' => null,
            'last_reset_date' => null,
        ], $options);

        return $this->repository->create($data);
    }

    /**
     * 타임스탬프 알고리즘: Ymd-His + 밀리초 + 랜덤 보정
     *
     * - 형식: 20260208-1435226549 (날짜-시분초 + 밀리초3자리 + 랜덤1자리)
     * - 중복 시 재시도 (최대 10회)
     * - 주문번호 등 시간 기반 고유 코드에 적합
     *
     * @param Sequence $sequence 시퀀스 모델
     * @param SequenceType $type 시퀀스 타입
     * @return string 생성된 코드
     * @throws SequenceCodeDuplicateException 최대 재시도 초과 시
     */
    protected function generateTimestampCode(Sequence $sequence, SequenceType $type): string
    {
        $maxRetries = 10;

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            $now = Carbon::now();
            $dateTime = $now->format('Ymd-His');
            $milliseconds = (int) ($now->micro / 1000);
            $suffix = str_pad((string) $milliseconds, 3, '0', STR_PAD_LEFT) . random_int(0, 9);
            $code = "{$dateTime}{$suffix}";

            // 접두사가 있으면 적용
            if ($sequence->prefix) {
                $code = $sequence->prefix . $code;
            }

            // 중복 확인
            if ($this->repository->codeExists($type, $code)) {
                usleep(1000); // 1ms 대기 후 재시도

                continue;
            }

            // 코드 이력 삽입 (UNIQUE 제약조건으로 중복 방지)
            try {
                $this->repository->insertCode($type, $code);
            } catch (QueryException $e) {
                if ($e->getCode() == 23000) {
                    usleep(1000);

                    continue;
                }
                throw $e;
            }

            // current_value를 현재 타임스탬프로 업데이트
            $this->repository->updateCurrentValue($sequence, time());

            // 코드 이력 정리 (max_history_count가 설정된 경우)
            $this->cleanupOldCodes($type, $sequence->max_history_count ?? 0);

            return $code;
        }

        throw new SequenceCodeDuplicateException($type, 'timestamp-retry-exhausted');
    }

    /**
     * 오래된 코드 이력 정리
     *
     * max_history_count가 0보다 크면, 해당 타입의 이력을 최신 N개만 유지하고
     * 나머지를 삭제합니다. 0이면 무제한(삭제 안 함).
     *
     * @param SequenceType $type 시퀀스 타입
     * @param int $maxHistoryCount 최대 보관 건수 (0: 무제한)
     * @return void
     */
    protected function cleanupOldCodes(SequenceType $type, int $maxHistoryCount): void
    {
        if ($maxHistoryCount <= 0) {
            return;
        }

        $currentCount = $this->repository->countCodes($type);

        if ($currentCount > $maxHistoryCount) {
            $this->repository->deleteOldCodes($type, $maxHistoryCount);
        }
    }

    /**
     * 코드 포맷팅
     *
     * 시퀀스 값을 설정에 따라 문자열 코드로 변환합니다.
     * - 상품 (Hybrid): 1737561235 (타임스탬프 기반, 패딩 없음)
     * - 주문 (Sequential): ORD-00000001 (접두사 + 패딩)
     *
     * @param Sequence $sequence 시퀀스 모델
     * @param int $value 시퀀스 값
     * @return string 포맷된 코드
     */
    protected function formatCode(Sequence $sequence, int $value): string
    {
        // 접두사가 있으면 패딩 적용
        if ($sequence->prefix) {
            $code = str_pad((string) $value, $sequence->pad_length, '0', STR_PAD_LEFT);
            $code = $sequence->prefix . $code;
        } else {
            // 접두사 없음: 값 그대로 사용 (상품코드 등)
            $code = (string) $value;
        }

        return $code;
    }

    /**
     * NanoID 코드 생성 (채번테이블 미사용)
     *
     * DB 트랜잭션/락 없이 랜덤 문자열을 생성합니다.
     * 중복 방지는 대상 테이블의 UNIQUE 제약조건에 위임합니다.
     *
     * @param Sequence $sequence 시퀀스 모델
     * @return string 생성된 NanoID 코드
     */
    protected function generateNanoIdCode(Sequence $sequence): string
    {
        $config = $sequence->type->getDefaultConfig();
        $length = $config['nanoid_length'] ?? 16;
        $alphabet = $config['nanoid_alphabet'] ?? '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

        $code = $this->generateNanoId($length, $alphabet);

        // 접두사가 있으면 적용
        if ($sequence->prefix) {
            $code = $sequence->prefix . $code;
        }

        return $code;
    }

    /**
     * NanoID 생성 (순수 PHP 구현)
     *
     * random_bytes() 기반으로 암호학적으로 안전한 랜덤 문자열을 생성합니다.
     * Rejection sampling으로 모듈로 편향을 방지합니다.
     *
     * @param int $length 생성할 문자열 길이
     * @param string $alphabet 사용할 문자 집합
     * @return string 생성된 NanoID
     */
    protected function generateNanoId(int $length, string $alphabet): string
    {
        $alphabetSize = strlen($alphabet);
        // 편향 없는 최대값 (rejection sampling 임계값)
        $maxValid = 256 - (256 % $alphabetSize);

        $id = '';
        while (strlen($id) < $length) {
            $bytes = random_bytes($length - strlen($id));
            for ($i = 0, $len = strlen($bytes); $i < $len && strlen($id) < $length; $i++) {
                $byte = ord($bytes[$i]);
                // 편향 방지: maxValid 이상의 값은 버림
                if ($byte < $maxValid) {
                    $id .= $alphabet[$byte % $alphabetSize];
                }
            }
        }

        return $id;
    }
}
