# Vbank / 통보 수신 API 레퍼런스

> **소유**: 플러그인 `sirsoft-pay_nhnkcp`. 이 문서는 결제대행사(NHN KCP) 서버가 직접 POST 로 보내는 통보 수신 경로를 서술한다. 브라우저가 접속하는 결제 콜백과는 접근 제어가 다르다.

---

## TL;DR (5초 요약)

```text
1. NHN KCP 서버가 직접 보내는 통보(가상계좌 입금·에스크로 공통)를 받는 경로다
2. 통보 수신 경로는 KCP 공식 발신 IP 만 허용한다 (위변조·재처리 방어)
3. IP 화이트리스트는 코어 확장 미들웨어 self-gate 로 통보 라우트명에만 적용된다
4. 브라우저 결제 콜백(payment.callback)에는 IP 확인이 적용되지 않는다
5. 검사 동작·허용 범위는 라우트 파일 직접 부착 시절과 동일하다
```

---

## 통보 수신 경로

| 라우트명 | 메서드/URI | 용도 |
| --- | --- | --- |
| `web.plugins.sirsoft-pay_nhnkcp.payment.vbank-notify` | `POST /plugins/sirsoft-pay_nhnkcp/payment/vbank-notify` | 가상계좌 입금통보(NOTI) 수신 |
| `web.plugins.sirsoft-pay_nhnkcp.payment.escrow-common-notify` | `POST /plugins/sirsoft-pay_nhnkcp/payment/escrow-common-notify` | 에스크로 공통통보 수신 |

**설명**

위 두 경로는 NHN KCP 서버가 구매자의 가상계좌 실입금·에스크로 상태 변경을 가맹점에 알리기 위해 직접 POST 로 호출한다. 브라우저를 거치지 않는 서버 대 서버 통신이므로, 위변조·재처리 요청을 막기 위해 **NHN KCP 공식 발신 IP 만 허용**한다.

이 IP 화이트리스트 검사는 코어의 확장 미들웨어 self-gate(`Plugin::getMiddleware()` 의 `targets` 로 위 두 통보 라우트명에만 정밀 타게팅)로 수행된다. 브라우저가 접속하는 결제 콜백(`payment.callback`)에는 적용되지 않는다 — 콜백은 정상 사용자의 브라우저에서 임의 IP 로 도달하므로 IP 로 제한하면 결제가 끊긴다. 검사 자체의 동작·허용 범위는 라우트 파일에서 직접 부착하던 이전 방식과 동일하다.

상세: [docs/backend/middleware.md "확장 미들웨어 선언 (self-gate)"](../../../../../docs/backend/middleware.md).
