<?php

namespace App\Extension\Helpers;

use App\Support\ExecutionEnvironment;
use Illuminate\Support\Facades\Log;

/**
 * 루트 진입·보호 파일의 **코어 관리 블록**(BEGIN ~ END 마커)만 교체하는 갱신기의 공통 추상화.
 *
 * 루트 `.htaccess`(Apache)와 루트 `web.config`(IIS)는 배포 레이아웃 ②(문서 루트 = 프로젝트 루트)의
 * 보호·위임 규칙을 담는 파일이면서, 동시에 운영자가 서버 규칙을 덧붙이는 파일이다. 코어 업데이트의
 * 증분(3-way) 판정은 base 가 "현재 디스크 스냅샷"이라 운영자 수정본은 언제나 changed 로 분류된다 —
 * 통째로 복사하면 운영자 규칙이 매 업데이트마다 소실된다.
 *
 * 그래서 이 파일들은 마커 블록 단위로 갱신한다:
 *  - 대상 파일 부재 → 소스 전체를 신규 복사 (기존 설치본에 파일 최초 도달)
 *  - 대상에 마커 블록 존재 → 블록 내부만 소스의 블록으로 교체, 블록 밖은 그대로 보존
 *  - 대상에 마커 부재(운영자가 블록을 지운 경우) → 파일을 건드리지 않고 warning 로그
 *    (운영자의 의도적 제거를 존중한다 — 조용한 덮어쓰기 금지)
 *  - 대상에 마커가 **하나만** 있거나 역순(손상) → 파일을 건드리지 않고 실패로 보고
 *    (지운 것이 아니라 잘린 것이다 — 「운영자 제거」 안내로 접으면 원인이 가려진다)
 *
 * 소스(릴리즈 판본)에서 블록을 찾지 못하면 방어적으로 전체 복사 규칙을 적용하지 않고
 * warning 만 남긴다 — 릴리즈 산출물 결함을 조용히 전파하지 않는다.
 *
 * **판정 로직은 이 클래스 하나가 소유한다** (G3 회차 16 W2 C-1). 자식은 마커 문자열·로그 태그·
 * 문구 키 접두만 정한다. 종전에는 `.htaccess` 갱신기 하나뿐이었고 `web.config` 는 「코어 소유,
 * 통째 교체」였다 — 같은 사이트에서 두 파일이 정반대로 동작했고, 사본으로 두 번째 갱신기를 만들면
 * 한쪽에만 들어간 보강(읽기 실패·손상 블록·CRLF·링크 대상·부분 쓰기)이 다른 쪽에서 조용히 빠진다.
 */
abstract class AbstractRootMarkerFileUpdater
{
    /**
     * 코어 관리 블록 시작 마커 (줄 단위 정확 일치가 아니라 줄 시작 매칭) — 자식이 파일 문법에 맞춰 정한다.
     *
     * 마커는 **그 파일 문법의 주석 한 줄**이어야 한다(`.htaccess` 는 `# …`, `web.config` 는 XML 주석).
     */
    public const BEGIN_MARKER = '';

    /**
     * 코어 관리 블록 종료 마커 — 자식이 정한다.
     */
    public const END_MARKER = '';

    /**
     * 업그레이드 로그 채널에 남기는 줄의 접두 태그 (`[root-htaccess]` 등).
     */
    protected const LOG_TAG = 'root-marker-file';

    /**
     * 운영자 안내 문구 키의 접두 (`settings.core_update.<접두>preserved` 등).
     *
     * 문구가 마커 문자열과 릴리즈 파일 이름을 인용하므로 파일마다 키가 다르다 — 한 벌의 문구로
     * 두 파일을 말하면 IIS 운영자가 `# BEGIN G7` 을 찾게 된다.
     */
    protected const MESSAGE_KEY_PREFIX = 'root_marker_file_';

    /** 임시 파일을 「앞선 실행의 잔해」로 볼 나이(초). */
    protected const STALE_TEMP_SECONDS = 300;

    /**
     * 원자 교체 임시 파일 이름의 중간 조각 (`<대상>.tmp.<hex>`).
     */
    public const TEMP_INFIX = '.tmp.';

    /**
     * 임시 파일 접미사의 **생성 규칙**(16진) — 만드는 쪽과 지우는 쪽이 같은 값을 본다.
     *
     * 접미사는 `bin2hex(random_bytes(8))`(16자) 이고, CSPRNG 가 없는 환경의 폴백은 그
     * 뒤에 `dechex(microtime)` 을 더 붙여 더 길어진다 — 그래서 하한만 둔다.
     *
     * 이 값이 리터럴 사본으로 갈리면 그 상태는 오류가 아니라 **모집단이 조용히 어긋나는
     * 것**으로만 나타난다: 한쪽이 좁으면 잔해가 영원히 남고, 넓으면 운영자가 같은 이름
     * 공간에 둔 파일이 조용히 사라진다. 형제 정리 주체
     * (`StorageLeftoverPruneService::pruneRootEntryTemp`)가 이 상수를 그대로 읽는다.
     */
    public const TEMP_SUFFIX_PATTERN = '/^[0-9a-f]{16,}$/';

    /** 블록을 새 판본으로 교체했다. */
    public const RESULT_UPDATED = 'updated';

    /**
     * 대상에 파일이 없어 **새로 만들었다.**
     *
     * `RESULT_UPDATED` 와 나누는 이유: 그 파일이 없던 사이트는 지금까지 프로젝트 루트가
     * 문서 루트 안에 있는 배치(레이아웃 ③ — URL 에 `/public` 이 노출되는 형태)로 운영됐을
     * 수 있고, 이 파일이 생기는 순간 그 주소 체계가 정본 주소로 301 되며 위임이 시작된다.
     * 보호 측면에서는 개선이지만 **주소가 바뀌는 것은 운영자가 알아야 할 변화**다.
     */
    public const RESULT_CREATED = 'created';

    /** 블록이 이미 같아 쓸 필요가 없었다. */
    public const RESULT_UNCHANGED = 'unchanged';

    /** 운영자가 블록을 지웠다 — 의도를 존중해 보존했다. */
    public const RESULT_PRESERVED = 'preserved';

    /** 대상 파일에 코어 블록이 **두 벌 이상** 있다 — 사람이 정리해야 한다. */
    public const RESULT_DUPLICATE_BLOCK = 'duplicate_block';

    /**
     * 대상 파일의 코어 블록이 **손상**됐다 — BEGIN 만 있거나, END 만 있거나, 역순이다.
     *
     * `preserved` 와 나누는 이유: 마커가 **하나도 없는** 파일은 「운영자가 지웠다」로 읽을
     * 수 있지만, 마커가 **하나만 남은** 파일은 지운 것이 아니라 **잘린** 것이다(전송 중단·
     * 부분 쓰기·손편집). 그 파일은 `<IfModule>` 이 닫히지 않아 Apache 가 500 을 내거나
     * 위임 규칙이 없어 프로젝트 루트가 열린 상태인데, 종전에는 이것도 `preserved` 로
     * 접혀 「일부러 지운 것이 아니라면 릴리즈 판본을 두라」는 안내가 나갔다 — 안내가
     * 원인을 가려 운영자가 엉뚱한 사실(지웠는가)을 확인하게 된다 (13차 G3 라운드 W4-2).
     */
    public const RESULT_BROKEN_BLOCK = 'broken_block';

    /** 릴리즈 소스에 블록이 없다 — 산출물 결함. */
    public const RESULT_SOURCE_INVALID = 'source_invalid';

    /**
     * 블록을 끼워 넣은 결과가 **적형(well-formed)이 아니다** (17차 D-3).
     *
     * XML(web.config) 자식만 이 판정을 낸다 — 운영자가 블록 **밖**에 닫히지 않은 태그를 두면
     * 교체 결과가 파싱 불가가 되고, IIS 는 web.config 파싱에 실패하면 사이트 전체를 500 으로
     * 만든다. `.htaccess` 는 줄 단위라 이 판정이 없다(자식이 `validateSpliced()` 를 재정의하지
     * 않으면 언제나 통과). 쓰기 **전**에 걸러 손상된 파일을 디스크에 확정하지 않는다.
     */
    public const RESULT_SPLICE_INVALID = 'splice_invalid';

    /** 대상 경로가 파일이 아니다. */
    public const RESULT_DESTINATION_INVALID = 'destination_invalid';

    /** 쓰기가 실패했다 — 보호 규칙이 구버전으로 남았다. */
    public const RESULT_WRITE_FAILED = 'write_failed';

    /**
     * 소스 또는 대상을 **읽지 못했다.**
     *
     * 이 결과가 필요한 이유: 읽기를 가드하지 않으면 두 방향으로 깨진다. ① 에러 핸들러가
     * 있는 문맥에서는 E_WARNING 이 예외로 승격돼 `apply()` 의 `RESULT_*` 계약을 뚫고
     * 나가 정상 업데이트가 통째로 롤백된다 ② 핸들러가 없는 문맥에서는 `false` 가 빈
     * 문자열로 캐스팅돼 「마커가 없다」= `preserved`(운영자가 지운 것으로 간주)라는
     * **사실이 아닌 판정**이 나간다. 대상이 파일인지 보는 가드는 이미 있었는데
     * (같은 사유가 그 자리에 적혀 있다) 읽을 수 있는지 보는 가드가 없었다.
     */
    public const RESULT_READ_FAILED = 'read_failed';

    /**
     * **사람의 조치가 필요 없는** 결과들 — 갱신에 성공했거나, 손대지 않는 것이 정상인 경우.
     *
     * `unchanged`·`preserved` 는 정상이다 — 실패와 섞으면 호출부가 둘을 구분할 수 없고,
     * 그것이 종전 `bool` 반환의 결함이었다(「변경 없음」과 「쓰기 실패」가 같은 `false`).
     *
     * 실패 목록이 아니라 **성공 목록**을 손으로 적는 이유: 실패 목록을 손으로 열거하면
     * 새 `RESULT_*` 를 등재하는 것을 잊었을 때 그 결과가 「실패가 아님」으로 떨어져
     * `noticeMessage()` 의 `default => null` 을 타고 통보에서 통째로 사라진다 — 콘솔에도
     * 업데이트 기록에도 남지 않고, 목록 안만 순회하는 게이트도 red 가 되지 않는다.
     * 방향을 뒤집으면 등재를 잊은 코드는 **실패로 떨어져 시끄럽게** 드러난다.
     */
    public const SUCCESS_RESULTS = [
        self::RESULT_UPDATED,
        self::RESULT_CREATED,
        self::RESULT_UNCHANGED,
        self::RESULT_PRESERVED,
    ];

    /**
     * 갱신되지 않았고 **사람의 조치가 필요한** 결과 전수를 돌려줍니다.
     *
     * 손으로 열거하지 않고 `RESULT_*` 상수 전수에서 `SUCCESS_RESULTS` 를 뺀 나머지로
     * 도출한다 — 새 실패 코드가 이 목록 밖에 남는 형태를 원천 차단한다.
     *
     * @return array<int, string> 실패로 분류되는 `RESULT_*` 값 목록
     */
    public static function failureResults(): array
    {
        $all = [];

        foreach ((new \ReflectionClass(static::class))->getConstants() as $name => $value) {
            if (str_starts_with($name, 'RESULT_') && is_string($value)) {
                $all[] = $value;
            }
        }

        return array_values(array_diff($all, self::SUCCESS_RESULTS));
    }

    /**
     * 결과가 운영자에게 알려야 할 실패인지 판정합니다.
     *
     * 성공 목록의 **여집합**으로 판정한다(위 `SUCCESS_RESULTS` 주석 참조) — 알지 못하는
     * 결과는 실패로 본다. 그래야 등재를 잊은 새 코드가 조용히 통과하지 않는다.
     *
     * @param  string  $result  `apply()` 반환값
     * @return bool 실패면 true
     */
    public static function isFailure(string $result): bool
    {
        return ! in_array($result, self::SUCCESS_RESULTS, true);
    }

    /**
     * 실패는 아니지만 운영자에게 **알려야 하는** 결과의 안내 문구를 돌려줍니다.
     *
     * `preserved` 는 「손대지 않았다」인데, 그 상태가 만들어지는 경로는 둘이다 — 운영자가
     * 일부러 코어 블록을 지웠거나(존중이 맞다), 예전 사이트의 `.htaccess` 가 그대로 있거나
     * 전송 중단으로 빈 파일이 됐거나. 후자에서는 **코어 보호 규칙이 영영 적용되지 않는데**
     * 콘솔에는 「적용 완료」만 뜬다. 문서 루트가 프로젝트 루트인 배치에서 그것은 `.env`
     * 노출이다. 둘을 코드로 구분할 방법이 없으므로 동작은 그대로 두고 사실만 통보한다.
     *
     * @param  string  $result  `apply()` 반환값
     * @param  string  $destinationPath  대상 파일 경로
     * @return string|null 알릴 것이 없으면 null
     */
    public static function noticeMessage(string $result, string $destinationPath): ?string
    {
        return match ($result) {
            self::RESULT_PRESERVED => (string) __('settings.core_update.'.static::MESSAGE_KEY_PREFIX.'preserved', ['path' => $destinationPath]),
            self::RESULT_CREATED => (string) __('settings.core_update.'.static::MESSAGE_KEY_PREFIX.'created', ['path' => $destinationPath]),
            default => null,
        };
    }

    /**
     * 결과에 대응하는 운영자 안내 문구를 돌려줍니다.
     *
     * 알아보지 못하는 결과라도 그것이 실패로 분류되면 **빈손으로 돌려보내지 않는다** —
     * 여기서 `null` 을 돌려주면 그 실패는 콘솔에도 업데이트 기록에도 남지 않는다.
     * 사유를 모르면 결과 코드 자체를 그대로 실어 사람이 추적할 수 있게 한다.
     *
     * @param  string  $result  `apply()` 반환값
     * @param  string  $destinationPath  대상 파일 경로
     * @return string|null 실패가 아니면 null
     */
    public static function failureMessage(string $result, string $destinationPath): ?string
    {
        return match ($result) {
            // 권한은 **파일과 그 디렉토리 둘 다** 지목한다. 교체가 원자적이라 같은
            // 디렉토리에 임시 파일을 만드는데, 문서 루트를 0555 로 잠그고 `.htaccess` 만
            // 644 로 두는 하드닝에서는 파일이 쓰기 가능한데도 그 임시 파일 생성이 실패한다.
            // 파일 권한만 지목하면 운영자가 이미 정상인 값을 확인하고 원인에 닿지 못한다.
            self::RESULT_WRITE_FAILED => (string) __('settings.core_update.'.static::MESSAGE_KEY_PREFIX.'write_failed', ['path' => $destinationPath]),
            self::RESULT_READ_FAILED => (string) __('settings.core_update.'.static::MESSAGE_KEY_PREFIX.'read_failed', ['path' => $destinationPath]),
            self::RESULT_DUPLICATE_BLOCK => (string) __('settings.core_update.'.static::MESSAGE_KEY_PREFIX.'duplicate_block', ['path' => $destinationPath]),
            self::RESULT_BROKEN_BLOCK => (string) __('settings.core_update.'.static::MESSAGE_KEY_PREFIX.'broken_block', ['path' => $destinationPath]),
            self::RESULT_SOURCE_INVALID => (string) __('settings.core_update.'.static::MESSAGE_KEY_PREFIX.'source_invalid'),
            self::RESULT_SPLICE_INVALID => (string) __('settings.core_update.'.static::MESSAGE_KEY_PREFIX.'splice_invalid', ['path' => $destinationPath]),
            self::RESULT_DESTINATION_INVALID => (string) __('settings.core_update.'.static::MESSAGE_KEY_PREFIX.'destination_invalid', ['path' => $destinationPath]),
            default => self::isFailure($result)
                ? (string) __('settings.core_update.'.static::MESSAGE_KEY_PREFIX.'unrecognized_failure', [
                    'result' => $result,
                    'path' => $destinationPath,
                ])
                : null,
        };
    }

    /**
     * 소스(신 버전) `.htaccess` 를 대상 경로에 마커 블록 보존 규칙으로 적용합니다.
     *
     * 반환은 `RESULT_*` 중 하나다. **`bool` 이 아닌 이유**: 종전 계약은 「변경했다/안 했다」
     * 였는데 「블록이 같아 쓸 필요가 없었다」와 「쓰기가 실패했다」가 같은 `false` 였다.
     * 그래서 호출부가 그 값을 소비하려 해도 실패를 가려낼 수 없었고, 실제로 두 호출부
     * 모두 값을 버린 채 업데이트를 「완료」로 보고했다 (3차 G3 라운드 H4).
     *
     * @param  string  $sourcePath  신 버전 `.htaccess` 절대 경로 (_pending)
     * @param  string  $destinationPath  활성 루트 `.htaccess` 절대 경로
     * @return string `RESULT_*` 중 하나
     */
    public static function apply(string $sourcePath, string $destinationPath): string
    {
        if (! is_file($sourcePath)) {
            return self::RESULT_SOURCE_INVALID;
        }

        // 잔해 스윕은 **진입부**가 소유한다. 종전에는 실제 쓰기 경로(`writeAtomically()`)
        // 안에만 있어, 앞선 실행이 rename 직전 fatal 로 끊겨 임시 파일을 남긴 사이트에서
        // 이후 모든 업데이트가 「블록 동일」로 끝나면 그 잔해가 **영구히** 남았다
        // (`read_failed`·`duplicate_block`·`preserved`·`unchanged` 는 전부 쓰기 앞에서 반환한다).
        // 잔해가 남는 자리는 임시 파일이 만들어지는 자리 — 링크면 링크 **대상**의 디렉토리다.
        self::sweepStaleTempFiles(self::resolveWriteTarget($destinationPath) ?? $destinationPath);

        // 대상 부재 → 신규 복사 (퍼미션 컨벤션은 코어 업데이트 공통 헬퍼를 따른다)
        //
        // 복사 **결과를 확인한다.** `copyFile()` 은 `void` 이고 내부 `File::copy()` 의 `false`
        // 를 삼키므로, 확인하지 않으면 파일이 만들어지지 않았는데도 `RESULT_UPDATED` 가
        // 나간다 — 그것도 하필 가장 위험한 조합에서다. 레이아웃 ②로 전환하는 첫 업데이트
        // (루트 `.htaccess` 가 처음 도달하는 그 순간) + 프로젝트 루트가 웹 프로세스에
        // 쓰기 불가(FTP 업로드로 소유자 상이)이면, 보호 규칙 파일이 **없는 채로** 콘솔에는
        // 「코어 파일 적용 완료」가 뜬다. 그 상태의 사이트는 `.env` 가 그대로 열려 있다.
        if (! file_exists($destinationPath)) {
            try {
                FilePermissionHelper::copyFile($sourcePath, $destinationPath);
            } catch (\Throwable $e) {
                Log::channel('upgrade')->warning('['.static::LOG_TAG.'] 신규 복사에 실패했습니다 (루트 쓰기 권한 확인 필요)', [
                    'destination' => $destinationPath,
                    'error' => $e->getMessage(),
                ]);

                return self::RESULT_WRITE_FAILED;
            }

            // 복사 **결과를 확인한다.** `copyFile()` 은 `void` 라 성공 여부를 반환하지 않는다.
            if (! is_file($destinationPath)) {
                Log::channel('upgrade')->warning('['.static::LOG_TAG.'] 신규 복사 후에도 대상 파일이 없습니다', [
                    'destination' => $destinationPath,
                ]);

                return self::RESULT_WRITE_FAILED;
            }

            // 복사 **결과를 확인한다.** 「블록이 하나인가」만으로는 두 축이 빠진다.
            //
            //  ① **경합** — 부재 판정과 복사 사이에 운영자가 그 파일을 올렸을 수 있다.
            //     레이아웃 ②로 전환하는 시점은 정확히 운영자가 루트 파일을 만지는 시점이다.
            //     `File::copy()` 는 무조건 덮어쓰므로, 그 창에 걸리면 운영자 파일이 사라지고
            //     결과는 소스 그대로다 — 즉 블록 수는 정확히 1 이라 **탐지되지 않는다.**
            //     종전 주석은 그 판정으로 경합을 되짚는다고 적었으나 성립하지 않았다.
            //  ② **부분 복사** — 디스크가 차서 `# END G7` 이 잘려도 `countBlocks()` 는 BEGIN·END
            //     중 큰 쪽을 쓰므로 1 이 나온다. 그 파일은 `<IfModule>` 이 닫히지 않아 Apache 가
            //     500 을 내거나 위임 규칙이 없어 프로젝트 루트가 열린다.
            //
            // 그래서 **소스와 내용이 같은지**를 본다. 형제 경로(`copyRootFileOrWarn`)가 이미
            // 같은 규율로 크기를 대조한다 — 여기만 그 밖이었다. 내용 비교는 경합(다른 내용이
            // 있었다면 덮어쓰기 전 크기가 달랐다는 사실까지는 알 수 없으나)과 부분 복사를
            // 함께 잡고, 무엇보다 「복사가 온전한가」라는 이 자리의 실제 질문에 답한다.
            $copied = @file_get_contents($destinationPath);
            $source = @file_get_contents($sourcePath);

            if ($copied === false || $source === false || $copied !== $source) {
                Log::channel('upgrade')->warning('['.static::LOG_TAG.'] 신규 복사 결과가 소스와 다릅니다 — 부분 복사이거나 복사 직전에 파일이 생겼을 수 있습니다', [
                    'destination' => $destinationPath,
                    'source_bytes' => $source === false ? null : strlen($source),
                    'copied_bytes' => $copied === false ? null : strlen($copied),
                ]);

                return self::RESULT_WRITE_FAILED;
            }

            if (self::countBlocks($copied) !== 1) {
                Log::channel('upgrade')->warning('['.static::LOG_TAG.'] 신규 복사 결과의 관리 블록 수가 1이 아닙니다', [
                    'destination' => $destinationPath,
                ]);

                return self::RESULT_WRITE_FAILED;
            }

            return self::RESULT_CREATED;
        }

        // 경로에 파일이 아닌 것이 있으면(디렉토리 등) 읽기·쓰기 어느 쪽도 성립하지 않는다.
        // 가드 없이 진행하면 PHP 경고가 나고, 그 경고는 프레임워크 에러 핸들러에서 예외로
        // 승격되어 업데이트 실행 전체를 세운다.
        if (! is_file($destinationPath)) {
            Log::channel('upgrade')->warning('['.static::LOG_TAG.'] 대상 경로가 파일이 아니어서 갱신을 건너뜁니다', [
                'destination' => $destinationPath,
            ]);

            return self::RESULT_DESTINATION_INVALID;
        }

        // 읽기도 가드한다 — 바로 위 `is_file()` 가드가 적어 둔 사유(경고가 예외로 승격돼
        // 업데이트 실행 전체를 세운다)는 읽기에도 그대로 적용된다. 형제 규약은
        // `CoreBackupHelper` 의 `@file_get_contents` + `=== false` 판정이다.
        $sourceContent = @file_get_contents($sourcePath);
        if ($sourceContent === false) {
            Log::channel('upgrade')->warning('['.static::LOG_TAG.'] 릴리즈 소스를 읽지 못해 갱신을 건너뜁니다', [
                'source' => $sourcePath,
            ]);

            return self::RESULT_READ_FAILED;
        }

        $sourceBlock = static::extractBlock($sourceContent);
        if ($sourceBlock === null) {
            Log::channel('upgrade')->warning('['.static::LOG_TAG.'] 릴리즈 소스에서 G7 마커 블록을 찾지 못해 갱신을 건너뜁니다', [
                'source' => $sourcePath,
            ]);

            return self::RESULT_SOURCE_INVALID;
        }

        $destination = @file_get_contents($destinationPath);
        if ($destination === false) {
            // 여기서 `(string) false` 로 넘어가면 빈 문자열이 되어 「마커가 없다」로 읽히고,
            // 그 판정은 `RESULT_PRESERVED`(= 운영자가 일부러 지웠다) 다 — 읽지 못한 것을
            // 운영자 의도로 보고하는 셈이라 보호 규칙이 영영 적용되지 않는다.
            Log::channel('upgrade')->warning('['.static::LOG_TAG.'] 대상 파일을 읽지 못해 갱신을 건너뜁니다 — 소유자·권한을 확인하세요', [
                'destination' => $destinationPath,
            ]);

            return self::RESULT_READ_FAILED;
        }

        // 코어 블록이 두 벌 이상이면 손대지 않는다. 종전에는 **첫 블록만** 교체하고 나머지는
        // 그대로 뒀는데, Apache 는 뒤에 오는 지시자가 이기는 경우가 많아 실질적으로 구버전
        // 규칙이 살아남았고 로그도 반환값도 「갱신 성공」이었다. 어느 쪽을 남길지는 코어가
        // 추측할 문제가 아니므로 운영자에게 넘긴다 (3차 G3 라운드 M2).
        if (static::countBlocks($destination) > 1) {
            Log::channel('upgrade')->warning('['.static::LOG_TAG.'] 대상 파일에 G7 마커 블록이 두 벌 이상이라 갱신을 건너뜁니다 — 중복 블록을 정리해야 합니다', [
                'destination' => $destinationPath,
            ]);

            return self::RESULT_DUPLICATE_BLOCK;
        }

        $spliced = static::spliceBlock($destination, $sourceBlock);

        if ($spliced === null && static::hasAnyMarker($destination)) {
            // 블록이 서지 않는데 마커가 **남아 있다** — 지운 것이 아니라 잘린 것이다.
            // 코어가 추측해 고치지 않는다(어느 쪽이 살아남아야 하는지 모른다). 운영자에게
            // 손상 사실을 그대로 싣는다 — `preserved` 로 접으면 안내가 원인을 가린다.
            Log::channel('upgrade')->warning('['.static::LOG_TAG.'] 대상 파일의 G7 마커 블록이 손상되어(BEGIN·END 중 하나만 있거나 역순) 갱신을 건너뜁니다 — 파일을 확인해야 합니다', [
                'destination' => $destinationPath,
            ]);

            return self::RESULT_BROKEN_BLOCK;
        }

        if ($spliced === null) {
            Log::channel('upgrade')->warning('['.static::LOG_TAG.'] 대상 파일에 G7 마커 블록이 없어 갱신을 건너뜁니다 — 운영자 제거로 간주(보존)', [
                'destination' => $destinationPath,
            ]);

            return self::RESULT_PRESERVED;
        }

        if ($spliced === $destination) {
            return self::RESULT_UNCHANGED; // 블록 동일 — 쓰기 생략 (mtime·퍼미션 보존)
        }

        // 끼워 넣은 결과가 적형인지 **쓰기 전에** 판정한다 (17차 D-3). 운영자가 블록 밖에 닫히지
        // 않은 태그를 두면 교체 결과가 파싱 불가가 되고, IIS 는 web.config 파싱 실패 시 사이트
        // 전체를 500 으로 만든다 — 손상본을 디스크에 확정하기 전에 막는다. `.htaccess` 는 이
        // 판정을 재정의하지 않아 언제나 통과한다(줄 단위라 적형 개념이 없다).
        if (! static::validateSpliced($spliced)) {
            Log::channel('upgrade')->warning('['.static::LOG_TAG.'] 블록 교체 결과가 적형(well-formed)이 아니어서 쓰기를 건너뜁니다 — 블록 밖 내용을 확인해야 합니다', [
                'destination' => $destinationPath,
            ]);

            return self::RESULT_SPLICE_INVALID;
        }

        // 내용만 교체 — 기존 파일의 퍼미션/소유자는 그대로 유지된다.
        // 결과를 판정한다: 공유 호스팅에서 루트 파일만 읽기 전용인 경우가 있고, 그때
        // 「갱신했다」고 보고하면 다른 코어 파일은 신 버전인데 진입·보호 규칙만 구버전인
        // 상태가 아무 흔적 없이 남는다. `@` 로 경고를 억누르는 것은 결과를 보기 위해서다
        // (억누르지 않으면 프레임워크 에러 핸들러가 예외로 승격해 업데이트 전체를 세운다).
        if (! static::writeAtomically($destinationPath, $spliced)) {
            // 파일 이름을 리터럴로 박지 않는다 — 이 경로는 공통 추상화라 web.config 갱신에도
            // 그대로 탄다. `.htaccess` 라고 적으면 IIS 운영자에게 엉뚱한 파일을 지목한다 (17차 D-4).
            Log::channel('upgrade')->warning('['.static::LOG_TAG.'] 루트 보호 파일 쓰기 실패 — 보호 규칙이 구버전으로 남습니다', [
                'destination' => $destinationPath,
            ]);

            return self::RESULT_WRITE_FAILED;
        }

        return self::RESULT_UPDATED;
    }

    /**
     * 블록을 끼워 넣은 결과가 적형(well-formed)인지 판정합니다 — 기본은 언제나 참 (17차 D-3).
     *
     * `.htaccess` 는 줄 단위라 적형 개념이 없어 이 기본값을 그대로 쓴다. XML 파일(web.config)
     * 자식이 재정의해, 운영자가 블록 밖에 둔 내용이 닫히지 않아 교체 결과가 파싱 불가가 되는
     * 경우를 쓰기 **전에** 걸러낸다.
     *
     * @param  string  $content  교체가 끝난 파일 전체 내용
     * @return bool 적형이면 true
     */
    protected static function validateSpliced(string $content): bool
    {
        return true;
    }

    /**
     * 마커 줄에 **선행 공백**을 허용할지 — 기본은 불허(컬럼 0 만) (17차 D-2).
     *
     * `.htaccess` 는 릴리즈 판본이 컬럼 0 에 마커를 두고 그 컨벤션을 그대로 강제한다. 반면
     * IIS 관리 도구는 web.config 를 저장할 때 XML 트리를 **재직렬화하며 자식 요소를 들여쓴다**
     * — 그러면 `  <!-- BEGIN G7 -->` 가 되어 컬럼 0 판정으로는 마커를 영영 찾지 못하고, 갱신이
     * `preserved`(운영자가 지웠다)로 오분류된다. XML 주석은 중첩할 수 없어 선행 공백을 허용해도
     * 블록 경계가 모호해지지 않으므로, XML 자식만 이 판정을 넓힌다.
     *
     * @return bool 선행 공백을 허용하면 true
     */
    protected static function markerAllowsLeadingWhitespace(): bool
    {
        return false;
    }

    /**
     * 내용에서 G7 마커 블록(마커 줄 포함)을 추출합니다.
     *
     * @param  string  $content  파일 전체 내용
     * @return string|null 블록 문자열 (마커 포함, END 줄의 종결자 포함). 마커 부재/역순이면 null
     */
    public static function extractBlock(string $content): ?string
    {
        $range = static::blockRange($content);
        if ($range === null) {
            return null;
        }

        return substr($content, $range[0], $range[1] - $range[0]);
    }

    /**
     * 대상 내용의 G7 블록을 새 블록으로 교체한 전체 내용을 반환합니다.
     *
     * @param  string  $content  대상 파일 전체 내용
     * @param  string  $newBlock  교체할 새 블록 (마커 포함)
     * @return string|null 교체된 전체 내용. 대상에 블록이 없으면 null
     */
    public static function spliceBlock(string $content, string $newBlock): ?string
    {
        $range = static::blockRange($content);
        if ($range === null) {
            return null;
        }

        // 새 블록의 줄바꿈을 **대상 파일의 것**에 맞춘다. 릴리즈 판본은 항상 LF 인데
        // (`.gitattributes` 가 강제한다) 운영자 파일이 CRLF 면 그대로 끼워 넣는 순간
        // 한 파일 안에서 줄바꿈이 갈린다 — 이 저장소는 툴체인의 CRLF 사각으로 이미 한 번
        // 사고를 겪었다(§16.18). 규칙 자체는 살아 있어도 파일이 섞이면 다음 편집·비교가
        // 그 지점부터 어긋난다 (3차 G3 라운드 L9).
        return substr($content, 0, $range[0])
            .static::matchLineEndings($newBlock, $content)
            .substr($content, $range[1]);
    }

    /**
     * 블록의 줄바꿈을 대상 내용의 지배적 줄바꿈에 맞춥니다.
     *
     * @param  string  $block  삽입할 블록 (LF 기준 릴리즈 판본)
     * @param  string  $reference  대상 파일 전체 내용
     * @return string 줄바꿈을 맞춘 블록
     */
    protected static function matchLineEndings(string $block, string $reference): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $block);

        // CR 단독(`\r`) 파일도 표본에 넣는다 — 줄머리 판정이 CR 을 인정하는 이상(아래
        // `markerLineStart()`), 교체 블록의 줄바꿈도 그 파일의 것을 따라야 한 파일 안에서
        // 갈리지 않는다. 판정은 CRLF·LF·CR 셋 중 **가장 많은 것**이다.

        // 판정 대상은 **블록 밖**이다. 파일 전체를 세면 ~100행짜리 LF 코어 블록이 표본을
        // 지배해, 운영자가 CRLF 로 몇 줄 덧붙인 파일에서도 LF 가 이겨 한 파일 안에 줄바꿈이
        // 갈린 채로 남는다 — 3차 L9 가 닫으려던 상태가 그대로 존속한다. 블록 밖이 비어 있으면
        // (코어 블록만 있는 파일) 전체를 표본으로 쓴다.
        $range = static::blockRange($reference);
        $outside = $range === null
            ? $reference
            : substr($reference, 0, $range[0]).substr($reference, $range[1]);

        $sample = trim($outside) === '' ? $reference : $outside;

        // CRLF 가 LF 단독보다 많으면 CRLF 파일로 본다. 판정 대상에서 CRLF 를 먼저 빼야
        // LF 를 두 번 세지 않는다.
        $crlf = substr_count($sample, "\r\n");
        $lf = substr_count($sample, "\n") - $crlf;
        $cr = substr_count($sample, "\r") - $crlf;

        if ($crlf > $lf && $crlf >= $cr) {
            return str_replace("\n", "\r\n", $normalized);
        }

        if ($cr > $lf && $cr > $crlf) {
            return str_replace("\n", "\r", $normalized);
        }

        return $normalized;
    }

    /**
     * 내용에 BEGIN·END 마커 줄이 **하나라도** 있는지 봅니다.
     *
     * `blockRange()` 가 null 인데 이것이 참이면 블록은 「없는」 것이 아니라 「손상된」
     * 것이다 — BEGIN 만, END 만, 또는 역순.
     *
     * @param  string  $content  파일 전체 내용
     * @return bool 마커 줄이 하나라도 있으면 true
     */
    protected static function hasAnyMarker(string $content): bool
    {
        return static::markerLineStart($content, static::BEGIN_MARKER) !== null
            || static::markerLineStart($content, static::END_MARKER) !== null;
    }

    /**
     * 내용에서 블록의 [시작 오프셋, 끝 오프셋) 을 계산합니다.
     *
     * 시작 = BEGIN 마커 줄의 첫 문자, 끝 = END 마커 줄의 마지막 문자 다음.
     * BEGIN 이 END 보다 뒤에 있으면(손상) null.
     *
     * @param  string  $content  파일 전체 내용
     * @return array{0:int,1:int}|null 블록 범위
     */
    protected static function blockRange(string $content): ?array
    {
        $begin = static::markerLineStart($content, static::BEGIN_MARKER);
        if ($begin === null) {
            return null;
        }

        $endLineStart = static::markerLineStart($content, static::END_MARKER, $begin);
        if ($endLineStart === null) {
            return null;
        }

        // 줄 끝은 `\n` 뿐 아니라 `\r` 도 본다 — CR 단독 파일에서는 `\n` 이 없어 END 줄의
        // 끝이 파일 끝으로 읽히고, 그러면 END 뒤의 운영자 규칙이 통째로 블록 안으로 들어가
        // 교체 시 사라진다. `strcspn` 은 첫 `\r` 또는 `\n` 에서 멈춘다 (3차 G3 라운드 L9).
        $end = $endLineStart + strcspn($content, "\r\n", $endLineStart);

        // END 줄의 **종결자도 블록에 넣는다** (G3 회차 15). 종전에는 종결자를 블록 밖에
        // 남겼는데, 그러면 `spliceBlock()` 이 새 블록의 줄바꿈을 대상에 맞춰도 그 한 글자는
        // 옛것 그대로다 — LF 코어 블록 + CRLF 운영자 꼬리인 파일에서 `# END G7` 뒤에만 LF 가
        // 홀로 남아 「줄바꿈은 대상에 맞춘다」는 선언과 어긋난다. 종결자는 CRLF 한 쌍이면
        // 둘 다, 아니면 한 글자다. 파일 끝이면 없다.
        if (substr($content, $end, 2) === "\r\n") {
            $end += 2;
        } elseif ($end < strlen($content)) {
            $end += 1;
        }

        return [$begin, $end];
    }

    /**
     * 내용에 코어 관리 블록(BEGIN 마커 줄)이 몇 벌 있는지 셉니다.
     *
     * 두 벌 이상이면 어느 쪽이 유효한지 코어가 판단할 수 없다 — 갱신을 건너뛰고 운영자에게
     * 알린다. `blockRange()` 는 첫 BEGIN 만 보므로 이 계수 없이는 중복이 드러나지 않는다.
     *
     * @param  string  $content  파일 전체 내용
     * @return int BEGIN 마커 줄의 수
     */
    protected static function countBlocks(string $content): int
    {
        // BEGIN 과 END 를 **둘 다** 센 뒤 큰 쪽을 쓴다. BEGIN 만 세면 `BEGIN 1 + END 2` 인
        // 파일이 「블록 하나」로 통과하는데, `blockRange()` 는 첫 END 까지만 잡으므로 교체 후
        // 구버전 규칙 꼬리와 고아 `# END G7` 이 블록 **밖**에 남고 결과는 `updated` 다.
        // Apache 는 뒤에 오는 지시자가 이기는 경우가 많아, 3차가 막으려던 「구버전 규칙 생존 +
        // 성공 보고」가 이 형태로 재현된다.
        $count = max(
            static::countMarkerLines($content, static::BEGIN_MARKER),
            static::countMarkerLines($content, static::END_MARKER)
        );

        return $count;
    }

    /**
     * 마커 줄이 몇 번 나오는지 셉니다.
     *
     * @param  string  $content  파일 전체 내용
     * @param  string  $marker  마커 문자열
     * @return int 마커 줄의 수
     */
    protected static function countMarkerLines(string $content, string $marker): int
    {
        $count = 0;
        $offset = 0;

        while (($pos = static::markerLineStart($content, $marker, $offset)) !== null) {
            $count++;
            $offset = $pos + strlen($marker);
        }

        return $count;
    }

    /**
     * 임시 파일에 쓴 뒤 rename 으로 교체합니다 (원자적 게시).
     *
     * 직접 truncate-then-write 하면 그 창에 들어온 요청이 **보호 규칙이 없는(또는 반쪽인)**
     * `.htaccess` 를 만난다 — 위임 규칙까지 사라지므로 사이트가 통째로 어긋나고, 그 잔해는
     * 「BEGIN 만 남은 파일」로 굳어 이후 모든 업데이트가 「운영자가 지웠다」로 오판해 영구히
     * 건너뛴다. 저장소는 이미 같은 규율을 상태 파일 쓰기에서 쓰고 있다 (3차 G3 라운드 M1).
     *
     * 부분 쓰기도 실패로 본다 — `file_put_contents` 는 디스크가 차면 `false` 가 아니라
     * **쓴 바이트 수**를 돌려주므로, `=== false` 만 보면 잘린 파일이 성공으로 통과한다.
     *
     * @param  string  $destinationPath  대상 파일 절대 경로
     * @param  string  $content  기록할 전체 내용
     * @return bool 교체에 성공했으면 true
     */
    protected static function writeAtomically(string $destinationPath, string $content): bool
    {
        // rename 은 대상 **파일**의 쓰기 비트를 보지 않는다(디렉토리 권한만 본다). 원자적
        // 교체로 바꾸면서 이 검사를 빼면, 운영자가 일부러 읽기 전용으로 잠근 파일을 조용히
        // 덮어쓰게 된다 — 종전 계약(그 경우 실패로 보고)이 바뀐다. 원자성만 얻고 계약은
        // 그대로 둔다.
        if (! is_writable($destinationPath)) {
            return false;
        }

        // 대상이 링크면 `rename` 은 링크를 **끊고** 그 자리에 일반 파일을 놓는다. 운영자가
        // `/var/www/html/.htaccess → /etc/g7/htaccess` 로 묶어 서버 설정을 한곳에서 관리하는
        // 구성이 그렇게 조용히 해체되고, 이후 원본을 고쳐도 사이트에 반영되지 않는다.
        // 형제 파일(루트 `index.php`)은 `copy()` 경로라 링크를 **통과해 대상에 쓴다** — 같은
        // 운영자 구성에서 두 파일이 정반대로 동작하고 있었다. 링크 대상에 직접 쓴다.
        //
        // 임시 파일도 **링크 대상의 디렉토리**에 만든다 (G3 회차 15). 종전에는 링크가 놓인
        // 디렉토리에 만들고 `rename` 만 대상으로 했는데, 링크 대상이 다른 파일시스템(별도
        // 마운트의 `/etc/g7/`)이면 `rename` 이 EXDEV 로 실패해 갱신이 `write_failed` 로 끝난다.
        // 잔해 스윕도 같은 디렉토리를 본다 — 임시 파일이 생기는 자리가 곧 잔해가 남는 자리다.
        $writeTarget = static::resolveWriteTarget($destinationPath);
        if ($writeTarget === null) {
            return false;
        }

        // 앞선 실행이 fatal·타임아웃으로 끊기면 `.htaccess.tmp.<hex>` 가 **문서 루트에**
        // 영구히 남는다(정상 실패 경로는 스스로 지우지만 프로세스가 죽으면 그 코드가 돌지
        // 않는다). 이름이 점으로 시작해 서빙은 막히더라도, 걷어 주는 곳이 없으면 실행할
        // 때마다 하나씩 쌓인다. 진행 중인 다른 실행의 것을 지우지 않도록 충분히 오래된
        // 것만 — 형제 선례(installer-state.php 의 tmp 스윕)와 같은 규칙이다.
        static::sweepStaleTempFiles($writeTarget);

        // 폴백은 **차단 가능한 함수를 쓰지 않는다.** 종전 폴백 `uniqid()` 는 하드닝 프로필이
        // 막을 수 있는 이름이고, 그것이 막히면 catch 블록 안에서 다시 `Error` 가 나 받을 곳이
        // 없다. 이 값은 비밀이 아니라 같은 디렉토리에서 겹치지 않을 이름이면 충분하다.
        try {
            $suffix = bin2hex(random_bytes(8));
        } catch (\Throwable) {
            $seed = '';
            for ($i = 0; $i < 8; $i++) {
                $seed .= chr(mt_rand(0, 255));
            }
            $suffix = bin2hex($seed).dechex((int) (microtime(true) * 1000));
        }

        $tmpPath = $writeTarget.self::TEMP_INFIX.$suffix;

        $written = @file_put_contents($tmpPath, $content, LOCK_EX);
        if ($written === false || $written !== strlen($content)) {
            @unlink($tmpPath);

            // 실패 사유를 갈라 남긴다 — 임시 파일을 **만들지 못한** 것은 대상 파일이 아니라
            // 그 디렉토리의 권한 문제이고, 둘을 뭉뚱그리면 운영자가 이미 정상인 파일 권한만
            // 들여다보게 된다. 링크면 그 디렉토리는 링크 **대상**의 것이다.
            Log::channel('upgrade')->warning('['.static::LOG_TAG.'] 원자적 교체용 임시 파일 쓰기 실패', [
                'destination' => $destinationPath,
                'write_target' => $writeTarget,
                'directory' => dirname($writeTarget),
                'reason' => $written === false ? 'temp_create_failed' : 'partial_write',
            ]);

            return false;
        }

        // 원본의 퍼미션을 승계한다 — rename 은 임시 파일의 퍼미션을 가져가므로, 승계하지
        // 않으면 umask 기본값이 그 자리에 남는다.
        $perms = @fileperms($destinationPath);
        if ($perms !== false && ExecutionEnvironment::hasFunction('chmod')) {
            @chmod($tmpPath, $perms & 0777);
        }

        if (! @rename($tmpPath, $writeTarget)) {
            @unlink($tmpPath);

            return false;
        }

        return true;
    }

    /**
     * 실제로 쓸 파일 경로를 돌려줍니다 — 대상이 링크면 링크 대상, 아니면 대상 자신.
     *
     * 임시 파일 생성·잔해 스윕·`rename` 셋이 **같은 디렉토리**를 봐야 한다. 한 곳이라도
     * 링크 쪽을 보면 교차 파일시스템 링크에서 `rename` 이 실패하거나 잔해가 걷히지 않는다.
     *
     * @param  string  $destinationPath  대상 파일 절대 경로 (링크일 수 있음)
     * @return string|null 쓸 경로. 링크를 읽지 못하면 null
     */
    public static function resolveWriteTarget(string $destinationPath): ?string
    {
        if (! is_link($destinationPath)) {
            return $destinationPath;
        }

        $linkTarget = @readlink($destinationPath);
        if ($linkTarget === false) {
            return null;
        }

        return str_starts_with($linkTarget, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\/]/', $linkTarget) === 1
            ? $linkTarget
            : dirname($destinationPath).DIRECTORY_SEPARATOR.$linkTarget;
    }

    /**
     * 앞선 실행이 남긴 오래된 임시 파일을 걷는다.
     *
     * 원자적 교체는 임시 파일을 만든 뒤 `rename` 한다. 정상 실패 경로는 스스로 지우지만
     * fatal·타임아웃으로 프로세스가 끊기면 그 코드가 돌지 않아 잔해가 남고, 걷는 곳이
     * 없으면 실행할 때마다 하나씩 쌓인다. 진행 중인 다른 실행의 것을 지우지 않도록
     * 충분히 오래된 것만 지운다.
     *
     * @param  string  $destinationPath  대상 파일 절대 경로
     */
    protected static function sweepStaleTempFiles(string $destinationPath): void
    {
        if (! ExecutionEnvironment::hasFunction('glob')) {
            return;
        }

        $prefix = $destinationPath.self::TEMP_INFIX;

        foreach (glob($prefix.'*') ?: [] as $stale) {
            if (! is_file($stale)) {
                continue;
            }

            // **생성 규칙과 같은 형태만** 지운다.
            //
            // 종전에는 `<대상>.tmp.*` 에 걸린 것을 무조건 지웠다. 접미사는 이 클래스가
            // 16진으로 만드는데(위 `bin2hex` · 폴백도 hex), 삭제 규칙은 그 사실을 쓰지
            // 않아 운영자가 같은 이름 공간에 둔 파일(`.htaccess.tmp.new` 등)이 300초 뒤
            // 조용히 사라졌다 — 생산자 규칙과 소비자 규칙이 갈려 있던 자리다
            // (10차 G3 라운드 F10-9).
            if (preg_match(self::TEMP_SUFFIX_PATTERN, substr($stale, strlen($prefix))) !== 1) {
                continue;
            }

            $mtime = @filemtime($stale);
            if ($mtime !== false && $mtime < time() - self::STALE_TEMP_SECONDS) {
                @unlink($stale);
            }
        }
    }

    /**
     * 마커로 시작하는 줄의 시작 오프셋을 찾습니다.
     *
     * 마커는 줄 시작(선행 공백 허용 안 함 — 릴리즈 판본이 컬럼 0 에 두는 컨벤션)에
     * 있어야 하며, 마커 뒤는 **줄 끝**(공백·탭만 허용한 뒤 개행 또는 파일 끝)이어야 한다.
     *
     * 종전에는 「마커 뒤가 줄 끝 **또는 공백**」이었다 — `# BEGIN G7-extra` 를 가르려는
     * 판정이었는데, 공백 한 글자만 보고 인정하므로 `# BEGIN G7 마커를 지우지 마세요` 같은
     * 운영자 주석(마커 문자열로 **시작하는** 문장)이 두 번째 BEGIN 으로 계수됐다. 그 결과는
     * `duplicate_block` — 운영자는 지운 것도 겹쳐 쓴 것도 없는데 「중복 블록을 지우라」는
     * 안내를 받고 갱신은 영구히 건너뛰어진다(fail-closed 오분류). 뒤에 텍스트가 오는 줄은
     * 마커가 아니라 마커를 **언급하는** 문장이다 (G3 회차 14 W4-2).
     *
     * 들여쓴 마커(`    # BEGIN G7`)는 그대로 「마커 없음」이다 — 릴리즈 판본은 컬럼 0 이고,
     * 그 판정을 넓히면 블록 안 주석의 들여쓴 언급이 경계로 읽힌다. 그 파일이 받는 안내는
     * 「마커를 찾지 못했다(지워졌거나 형태가 바뀌었다)」이며, 「운영자가 지웠다」로 단정하지
     * 않는다 (`settings.core_update.root_htaccess_preserved`).
     *
     * @param  string  $content  파일 전체 내용
     * @param  string  $marker  마커 문자열
     * @param  int  $offset  검색 시작 오프셋
     * @return int|null 마커 줄 시작 오프셋
     */
    protected static function markerLineStart(string $content, string $marker, int $offset = 0): ?int
    {
        $pos = $offset;
        $markerLen = strlen($marker);

        // UTF-8 BOM 뒤의 첫 줄도 줄 시작으로 인정한다. 인정하지 않으면 BOM 이 붙은
        // `.htaccess` 는 블록을 영영 찾지 못해 갱신이 **영구히** 건너뛰어지고, 로그에는
        // 「운영자가 지웠다」는 사실이 아닌 사유가 남는다 (3차 G3 라운드 L8).
        $bomLength = str_starts_with($content, "\xEF\xBB\xBF") ? 3 : 0;

        $allowIndent = static::markerAllowsLeadingWhitespace();

        while (($pos = strpos($content, $marker, $pos)) !== false) {
            // 줄머리는 `\n` 뒤뿐 아니라 `\r` 뒤도 인정한다 — CR 단독(`\r`) 줄바꿈 파일에서
            // 마커를 찾지 못하면 블록이 「없다」로 읽혀 `preserved`(운영자가 지웠다)라는
            // 사실 아닌 안내가 나가고 갱신이 영구히 건너뛰어진다. CRLF 파일에서는 `\n`
            // 뒤 판정이 먼저 성립하므로 결과가 바뀌지 않는다.
            $atLineStart = $pos === 0 || $pos === $bomLength || $content[$pos - 1] === "\n" || $content[$pos - 1] === "\r";

            // 선행 공백을 허용하는 자식(web.config)은 이전 개행부터 마커까지가 공백·탭만이면
            // 줄머리로 인정한다 — IIS 재직렬화가 자식 요소를 들여쓰기 때문 (17차 D-2).
            if (! $atLineStart && $allowIndent) {
                $lineStart = $pos;
                while ($lineStart > 0 && $content[$lineStart - 1] !== "\n" && $content[$lineStart - 1] !== "\r") {
                    $lineStart--;
                }

                $gap = substr($content, $lineStart, $pos - $lineStart);
                if ($gap === '' || strspn($gap, " \t") === strlen($gap)) {
                    $atLineStart = $lineStart === 0
                        || $lineStart === $bomLength
                        || $content[$lineStart - 1] === "\n"
                        || $content[$lineStart - 1] === "\r";
                }
            }

            // 마커 뒤는 공백·탭만 지나 개행 또는 파일 끝이어야 한다 — 공백 뒤에 텍스트가
            // 이어지면 마커가 아니라 마커를 언급하는 문장이다(위 독블록).
            $after = $pos + $markerLen;
            $after += strspn($content, " \t", $after);
            $terminated = $after >= strlen($content) || $content[$after] === "\n" || $content[$after] === "\r";

            if ($atLineStart && $terminated) {
                return $pos;
            }

            $pos += $markerLen;
        }

        return null;
    }
}
