<?php
/**
 * Step 2: 요구사항 검증
 *
 * 서버 요구사항을 검증하고 결과를 표시합니다.
 */
// $error 는 index.php 의 POST 처리(handleStep2Post — 서버측 노출 게이트)에서 설정될 수 있다.
if (! isset($error)) {
    $error = null;
}
?>

<div class="installer-container installer-container-wide">
    <h1 class="installer-title"><?= htmlspecialchars(lang('step_2_requirements')) ?></h1>

    <?php /* 서버측 게이트가 거부한 사유 — 화면 게이트를 우회한 POST 에만 나타난다. */ ?>
    <?php if ($error) { ?>
        <div class="alert alert-error" role="alert">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php } ?>

    <?php /* 스피너 → 결과 카드로 **통째로** 갈아 끼워지는 영역이다. 라이브 리전 선언이 없으면
             검사가 끝났다는 사실도, 통과 여부도 화면 밖에서는 전달되지 않는다. inprocess 에서만
             렌더되는 안내 카드가 유일한 라이브 리전이라 subprocess 환경의 이 화면에는 라이브
             리전이 하나도 없었다. `aria-busy` 는 검사가 도는 동안 중간 상태를
             읽지 않게 한다 — JS 가 결과를 그린 뒤 해제한다. */ ?>
    <div id="requirements-result" class="requirements-list" role="status" aria-live="polite" aria-busy="true">
        <!-- AJAX 로딩 중 -->
        <div class="loading-box">
            <div class="spinner"></div>
            <p class="loading-text"><?= htmlspecialchars(lang('checking_requirements')) ?></p>
        </div>
    </div>

    <div id="navigation-buttons" class="btn-group btn-group-spread hidden">
        <button type="button" data-action="goToStep" data-arg="1" class="btn btn-secondary">
            <?= htmlspecialchars(lang('previous')) ?>
        </button>

        <div class="btn-group-right">
            <?php /* [다시 검사] 는 중립 재시도다 — 빨강 솔리드(btn-danger)는 삭제·중단과 같은
                     시각 언어라 요구사항 화면에서 「무언가를 지운다」로 오독된다 */ ?>
            <button data-action="recheckRequirements" id="recheck-btn" class="btn btn-secondary hidden">
                <?= htmlspecialchars(lang('recheck')) ?>
            </button>

            <form method="POST" class="form-inline">
                <?php /* 웹 노출 점검(B-4)의 판정은 브라우저가 내리고 게이트도 화면이 잠근다.
                         그 결과가 화면 밖으로 나가지 않으면 「보호 확인됨으로 통과했는지 /
                         판정 불능에서 운영자가 직접 확인하고 넘어갔는지」를 나중에 되짚을 근거가
                         없다. 이 값을 [다음 단계] POST 에 실어 서버가 설치 로그에 한 줄 남기고,
                         닫힌 집합으로 정규화한 뒤 자기 신고 `exposed` 만 막는다(request-handler.php).
                         게이트의 목적은 경고 무시 방지이지 공격자 차단이 아니다(4차 제품 결정 —
                         차단은 화면이 한다). 값 위조·API 직접 호출은 설계상 열려 있다. */ ?>
                <input type="hidden" name="web_exposure_verdict" id="web-exposure-verdict" value="pending">
                <button type="submit" name="proceed" id="next-btn" disabled class="btn btn-primary hidden">
                    <?= htmlspecialchars(lang('next')) ?>
                </button>
            </form>
        </div>
    </div>
</div>