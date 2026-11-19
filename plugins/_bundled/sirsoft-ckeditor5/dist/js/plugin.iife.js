var SirsoftCkeditor5=(function(x){"use strict";var lo=Object.defineProperty;var io=(x,w,R)=>w in x?lo(x,w,{enumerable:!0,configurable:!0,writable:!0,value:R}):x[w]=R;var Ct=(x,w,R)=>io(x,typeof w!="symbol"?w+"":w,R);const w=new Map;let R=!1;function se(){return R}function Et(t){R=t}const J=new Map;function X(t,o){const e=J.get(t)??[];e.push(o),J.set(t,e)}function vt(t){(J.get(t)??[]).forEach(o=>{try{o.stop()}catch{}}),J.delete(t)}function de(t){const o=globalThis?.G7Core;if(typeof o?.url?.api=="function")return o.url.api(t);const e=globalThis?.G7Config?.basePath,n=typeof e=="string"&&e!==""&&e!=="/"?(e.startsWith("/")?e:"/"+e).replace(/\/+$/,""):"";return t=typeof t=="string"?t.trim():t,!n||!t.startsWith("/")||t.startsWith("//")||St(t,n)?t:n+t}function $(t){const o=globalThis?.G7Core;if(typeof o?.url?.route=="function")return o.url.route(t);const e=globalThis?.G7Config?.basePath,n=typeof e=="string"&&e!==""&&e!=="/"?(e.startsWith("/")?e:"/"+e).replace(/\/+$/,""):"";if(t=typeof t=="string"?t.trim():t,!n||typeof t!="string"||!St(t,n))return t;const r=t.slice(n.length);return r===""||r.startsWith("?")||r.startsWith("#")?"/"+r:r}function St(t,o){if(!t.startsWith(o))return!1;const e=t.charAt(o.length);return e===""||e==="/"||e==="?"||e==="#"}const ue=400,Lt=1e4;function lt(t,o){const{name:e,locale:n,multilingual:r}=o;let a=o.seedPath??$(location.pathname),l=null,c=!1,s=null,d,i=0;const m=()=>{if(o.readExternal)return o.readExternal();const f=window.G7Core?.state?.getLocal?.()?.form;if(!f||!e)return;const b=r?f[e]?.[n]:f[e];return typeof b=="string"?b:void 0};d=a!==$(location.pathname)&&o.seedValue!==void 0?o.seedValue:m();const g=u=>{$(location.pathname)!==a&&(a=$(location.pathname),s=new Set([u]),d!==void 0&&s.add(d),l!==null&&s.add(l),l=null,c=!0,i=Date.now())},p=window.setInterval(()=>{let u;try{u=t.getData()}catch{window.clearInterval(p);return}g(u);const f=m();if(f===void 0){c&&Date.now()-i>Lt&&(c=!1,s=null);return}if(c){if(s?.has(f)){Date.now()-i>Lt&&(c=!1,s=null,d=f);return}if(f!==u){Et(!0);try{t.setData(f)}finally{Et(!1)}}c=!1,s=null,l=f,d=f;return}if(d=f,f===l||f===u){l=f;return}},ue);return{shouldEmit:()=>{if(!c)try{g(t.getData())}catch{}return!c},noteEmitted:u=>{l=u},stop:()=>window.clearInterval(p)}}const fe="43.3.1",it="sirsoft-ckeditor5",ct=`dist/vendor/ckeditor5/${fe}`,st="ckeditor5-vendor-css";function dt(){const t=window?.G7Core?.asset;if(!t||typeof t.plugin!="function")throw new Error("G7Core.asset is unavailable — core 7.0.10 or later is required to resolve bundled CKEditor5 assets");return t}function me(){return dt().plugin(it,`${ct}/ckeditor5.umd.js`)}function At(){return dt().plugin(it,`${ct}/ckeditor5.css`)}function ge(t){return dt().plugin(it,`${ct}/translations/${t}.umd.js`)}const It="ckeditor5-editor:",Z="layout-script:ckeditor5_script",Pt=500,pe=3e4;let K=null;function xt(){K!==null&&(clearInterval(K),K=null)}function he(){const t=window?.G7Core?.assets;if(typeof t?.clearFailure!="function"||typeof t?.getFailures!="function")return;const o=()=>t.getFailures()??[],e=()=>{o().some(r=>r?.id===Z)&&t.clearFailure(Z)};e(),xt();const n=Date.now();K=setInterval(()=>{if(!o().some(a=>String(a?.id??"").startsWith(It))||Date.now()-n>=pe){xt();return}e()},Pt)}const ke=5e3;let B=null;function be(){const t=window?.G7Core?.assets;if(typeof t?.clearFailure!="function"||typeof t?.getFailures!="function")return;const o=()=>{(t.getFailures()??[]).some(r=>r?.id===Z)&&t.clearFailure(Z)};o(),B!==null&&clearInterval(B);const e=Date.now();B=setInterval(()=>{if(Date.now()-e>=ke){B!==null&&(clearInterval(B),B=null);return}o()},Pt)}function tt(t,o,e,n){const r=window?.G7Core?.assets?.notifyFailure;if(typeof r=="function"){r({id:t,label:o,retry:e,message:n}),t.startsWith(It)&&he();return}console.warn(`[ckeditor5] 자산 로드 실패: ${o}`)}const ye="ckeditor5-vendor-umd",we=15e3,_e=2500,Ce=50;let W=null;function et(){return typeof window.CKEDITOR?.ClassicEditor=="function"}function Gt(){return typeof window.CKEDITOR_VERSION=="string"}function ut(){return Array.from(document.querySelectorAll("script[src]")).filter(t=>(t.getAttribute("src")??"").toLowerCase().includes("ckeditor5.umd.js"))}function Ee(t){return new Promise(o=>{let e=!1;const n=new Set,r=new Set;let a=null;const l=Date.now(),c=i=>{e||(e=!0,clearInterval(d),o(i))},s=i=>{n.has(i)||(n.add(i),i.addEventListener("load",()=>{et()&&c("ready")}),i.addEventListener("error",()=>{r.add(i),a=Date.now()}))},d=setInterval(()=>{if(et()){c("ready");return}ut().forEach(s);const i=ut().some(g=>!r.has(g)),m=Date.now();if(!i&&a!==null&&m-a>=_e){c("failed");return}m-l>=we&&c(i?"timeout":"failed")},Ce);t.forEach(s)})}async function ve(){const t=window?.G7Core?.asset?.loadScript;if(typeof t!="function")return!1;try{await t(me(),{id:ye},{label:"ckeditor5 UMD"})}catch(e){return console.warn("[ckeditor5] 편집기 본체를 불러오지 못했습니다:",e),!1}const o=et();return o&&be(),o}function Tt(){return et()?Promise.resolve(!0):W||(W=(async()=>{if(Gt())return console.warn("[ckeditor5] 편집기 본체가 두 번 실행되어 전역이 비었습니다 — 다시 싣지 않습니다."),!1;const t=ut();if(t.length>0){const o=await Ee(t);if(o==="ready")return!0;if(o==="timeout"||Gt())return!1}return ve()})().finally(()=>{W=null}),W)}const Se="data-ck-unsafe-attribute-";function Le(t){const o=t?.editing?.view?.domConverter;if(!o||typeof o.setDomElementAttribute!="function")return;const e=o.setDomElementAttribute.bind(o);o.setDomElementAttribute=(n,r,a,l)=>{if(r!=="style"||!Ae(o,n,a,l)){e(n,r,a,l);return}if(n.removeAttribute(`${Se}style`),a===""||a===null||a===void 0){n.removeAttribute("style");return}n.style.cssText=String(a)}}function Ae(t,o,e,n){return!o?.style||typeof t.shouldRenderAttribute!="function"?!1:t.shouldRenderAttribute("style",e,String(o.tagName??"").toLowerCase())?!0:n?.shouldRenderUnsafeAttribute?.("style")===!0}const Ie={ko:"한국어",en:"English",ja:"日本語",zh:"中文",fr:"Français",de:"Deutsch",es:"Español"};function $t(t){const o=window,e=o.G7Core?.state?.get?.()?.appConfig?.localeNames?.[t],n=o.G7Config?.appConfig?.localeNames?.[t],r=typeof e=="string"&&e?e:n;return typeof r=="string"&&r!==""?r:Ie[t]??t}function Pe(t,o,e){t.replaceChildren();const n=document.createElement("i");n.className="ckeditor-icon ckeditor-icon--globe ckeditor-lang-icon",t.appendChild(n);const r=document.createElement("span");if(r.textContent=$t(o),t.appendChild(r),e){const a=document.createElement("span");a.className="ckeditor-required",a.textContent="*",t.appendChild(a)}}const Ut="data-readonly",Mt="read-only";function Rt(t,o){const e=t.getAttribute(Ut);return e==="true"?!0:e==="false"?!1:o}function xe(t,o){const e=t;!e||typeof e.enableReadOnlyMode!="function"||typeof e.disableReadOnlyMode!="function"||(o?e.enableReadOnlyMode(Mt):e.disableReadOnlyMode(Mt))}function Ge(t,o){t.querySelectorAll("textarea").forEach(e=>{e.readOnly=o})}function Te(t,o,e){let n=null;const r=()=>{const l=Rt(t,e);if(l!==n){n=l;for(const c of o())xe(c,l);Ge(t,l)}};if(r(),typeof MutationObserver>"u")return{stop:()=>{}};const a=new MutationObserver(r);return a.observe(t,{attributes:!0,attributeFilter:[Ut]}),{stop:()=>a.disconnect()}}const $e="upload",Ue=["jpeg","png","gif","webp"];function ft(t,o){const e=t?.response?.data,n=e?.error?.message??e?.message;return typeof n=="string"&&n!==""?n:o}function Dt(t){return Ft("sirsoft-ckeditor5.editor.upload_failed","Couldn't upload file: {{name}}.").replace("{{name}}",t)}function Ot(){return Ft("sirsoft-ckeditor5.editor.upload_login_required","Sign in to upload images. Your draft is kept.")}function Ft(t,o){const e=window?.G7Core?.t,n=typeof e=="function"?e(t):null;return typeof n=="string"&&n!==t?n:o}function mt(t,o,e){const n=window?.G7Core?.t,r=typeof n=="function"?n(t,o):null;return typeof r=="string"&&r!==t?r:e}class Me{constructor(o,e){Ct(this,"controller",new AbortController);Ct(this,"options");this.loader=o,this.options=typeof e=="string"?{legacyUrl:e}:e}async upload(){const o=this.options.target??null,e=window.G7Core?.contentMedia;return o&&e&&typeof e.upload=="function"?this.uploadByContract(o,e):this.uploadLegacy()}async uploadByContract(o,e){const n=await this.loader.file,r=Dt(n?.name??"");if(!n)throw r;const a=e.validateFile(o,n);if(!a?.ok)throw mt(a?.messageKey??"",a?.params,r);const l=this.options.getContent?.()??"";if(e.isAtLimit?.(l,o)===!0)throw mt("core.content_media.client.limit_reached",{max:o.policy?.maxPerDocument??0},r);try{const c=await e.upload(o,n,{signal:this.controller.signal,onProgress:(s,d)=>{this.loader.uploadTotal=d,this.loader.uploaded=s}});if(typeof c?.url!="string"||c.url==="")throw r;return{default:c.url}}catch(c){throw typeof c=="string"?c:window.G7Core?.api?.isAnonymousUnauthorized?.(c)===!0?Ot():ft(c,mt("core.content_media.client.upload_failed",void 0,r))}}async uploadLegacy(){const o=await this.loader.file,e=Dt(o?.name??""),n=window.G7Core?.api;if(!o||typeof n?.post!="function")throw e;const r=new FormData;r.append($e,o);let a;try{a=await n.post(this.options.legacyUrl,r,{headers:{"Content-Type":"multipart/form-data"},signal:this.controller.signal,onUploadProgress:s=>{typeof s.total=="number"&&(this.loader.uploadTotal=s.total),this.loader.uploaded=s.loaded}})}catch(s){throw n.isAnonymousUnauthorized?.(s)===!0?Ot():ft(s,e)}const l=Re(a?.urls);if(l!==null)return l;const c=a?.url??a?.urls?.default;if(typeof c!="string"||c==="")throw ft({response:{data:a}},e);return{default:c}}abort(){this.controller.abort()}}function Re(t){if(t===null||typeof t!="object"||Array.isArray(t))return null;const o=t,e=o.default;if(typeof e!="string"||e==="")return null;const n={default:e};let r=0;for(const[a,l]of Object.entries(o))a==="default"||!/^[1-9][0-9]*$/.test(a)||typeof l!="string"||l===""||(n[a]=l,r++);return r>0?n:null}function Nt(t){return function(e){const n=typeof t=="string"?{legacyUrl:t}:{...t,getContent:()=>typeof e.getData=="function"?e.getData():""};e.plugins.get("FileRepository").createUploadAdapter=r=>new Me(r,n)}}const zt=Object.freeze({video:"g7VideoUpload",audio:"g7AudioUpload"});function De(t){return t?.policy?["video","audio"].filter(o=>t.policy[o]?.enabled===!0):[]}function Oe(t,o){const e=o.replace(/&/g,"&amp;").replace(/"/g,"&quot;").replace(/</g,"&lt;");return t==="video"?`<video controls="controls" preload="metadata" src="${e}"></video>`:`<audio controls="controls" preload="metadata" src="${e}"></audio>`}function V(t,o,e){const n=window?.G7Core?.t,r=typeof n=="function"?n(t,o):null;return typeof r=="string"&&r!==t?r:e}async function Fe(t,o,e,n){const r=window?.G7Core?.contentMedia,a=window?.G7Core?.toast?.error??(()=>{});if(!r)return;const l=r.validateFile(o,n);if(!l?.ok||l.kind!==e){a(V(l?.messageKey??"core.content_media.client.kind_not_allowed",l?.params,"Not allowed."));return}const c=typeof t.getData=="function"?t.getData():"";if(r.isAtLimit?.(c,o)===!0){a(V("core.content_media.client.limit_reached",{max:o.policy?.maxPerDocument??0},"Too many media."));return}try{const s=await r.upload(o,n,{}),d=t.data.processor.toView(Oe(e,s.url)),i=t.data.toModel(d);t.model.insertContent(i)}catch(s){if(window?.G7Core?.api?.isAnonymousUnauthorized?.(s)===!0){a(V("sirsoft-ckeditor5.editor.upload_login_required",void 0,"Sign in to upload. Your draft is kept."));return}const d=s?.response?.data?.message;a(typeof d=="string"&&d!==""?d:V("core.content_media.client.upload_failed",void 0,"Upload failed."))}}function Ne(t,o){return function(n){const r=window.CKEDITOR;if(!(!r?.FileDialogButtonView||o.length===0))for(const a of o){const l=t.policy?.[a]?.extensions??[],c=t.policy?.[a]?.mimeTypes??[],s=a==="video"?V("core.content_media.client.video_button",void 0,"Insert video"):V("core.content_media.client.audio_button",void 0,"Insert audio");n.ui.componentFactory.add(zt[a],d=>{const i=new r.FileDialogButtonView(d);return i.set({acceptedType:[...l.map(m=>`.${m}`),...c].join(","),allowMultipleFiles:!1}),i.buttonView.set({label:s,withText:!0,tooltip:!0}),i.on("done",(m,g)=>{const p=Array.from(g??[])[0];p&&Fe(n,t,a,p)}),i})}}}const ot="data-ckeditor5-fallback",rt=new WeakMap;function nt(t,o,e,n){const r=window.G7Core;if(!r?.state?.setLocal||!t)return;e&&n&&r.state.getLocal?.()?.hasChanges!==!0&&r.state.setLocal({hasChanges:!0});const a={[`form.${t}_mode`]:"text"};if(typeof o=="string")a[`form.${t}`]=o;else for(const[l,c]of Object.entries(o))a[`form.${t}.${l}`]=c;r.state.setLocal(a,{render:!1,selfManaged:!0})}function gt(t){const{container:o,name:e,height:n,readOnly:r,placeholder:a,multilingual:l}=t;if(o.querySelector(`[${ot}]`))return;o.innerHTML="";const c=t.trackChanges!==!1,s=l?t.locales??[]:[],d=t.activeLocale??s[0]??"",i=l?{...t.contentMap??{}}:{"":t.initialContent??""},m=document.createElement("textarea");m.setAttribute(ot,"1"),m.name=e,m.readOnly=r,m.placeholder=a??"",m.style.cssText=["width:100%",`height:${n}px`,"padding:12px","border:1px solid #d1d5db","border-radius:6px","font-family:inherit","font-size:14px","line-height:1.6","resize:vertical","box-sizing:border-box"].join(";");let g=d;const p=new Map,u=()=>l?g:"";(l?s:[""]).forEach(b=>{const I=lt({getData:()=>b===u()?m.value:i[b]??"",setData:v=>{i[b]=v,b===u()&&(m.value=v)}},{name:e,locale:b,multilingual:l,seedPath:t.seedPath,seedValue:i[b]??""});p.set(b,I),o.id&&X(o.id,I)});const f=()=>[...p.values()].every(b=>b.shouldEmit());if(l){const b=document.createElement("div");b.className="ckeditor-locale-tabs";const I=()=>{b.querySelectorAll("button").forEach(v=>{const U=v.dataset.locale??"",S=U===g;v.classList.toggle("ckeditor-locale-tab--active",S);const P=(i[U]??"").trim()!=="",F=v.querySelector("[data-check]");F&&(F.style.display=P?"":"none")})};s.forEach((v,U)=>{const S=document.createElement("button");S.type="button",S.dataset.locale=v,S.classList.add("ckeditor-locale-tab"),U===0&&S.classList.add("ckeditor-locale-tab--default"),S.textContent=$t(v);const P=document.createElement("span");P.dataset.check="1",P.textContent="✓",P.style.display="none",S.appendChild(P),S.addEventListener("click",()=>{i[g]=m.value,g=v,m.value=i[v]??"",m.dataset.locale=v,f()&&nt(e,i,!1,c),I()}),b.appendChild(S)}),o.appendChild(b),m.dataset.locale=g,m.value=i[g]??"",I(),m.addEventListener("input",()=>{i[g]=m.value,f()&&(p.get(g)?.noteEmitted(m.value),nt(e,i,!0,c)),I()})}else m.value=i[""]??"",m.addEventListener("input",()=>{i[""]=m.value,f()&&(p.get("")?.noteEmitted(m.value),nt(e,m.value,!0,c))});o.appendChild(m),rt.set(o,{multilingual:l,values:i,activeLocale:g,syncs:p}),nt(e,l?i:i[""]??"",!1,c)}function ze(t){const o=rt.get(t);if(!o)return null;const e=t.querySelector(`textarea[${ot}]`);return e&&(o.values[o.multilingual?e.dataset.locale??o.activeLocale:""]=e.value),o.multilingual?{...o.values}:o.values[""]??""}function je(t){return t.querySelector(`[${ot}]`)?(rt.get(t)?.syncs.forEach(o=>o.stop()),t.innerHTML="",rt.delete(t),!0):!1}const jt="data-ckeditor5-min-height",Bt="--ckeditor5-min-height",Be="ckeditor-embed-preview",Ve="ckeditor-embed-preview__frame",Vt={minimal:["bold","italic","underline","|","link","uploadImage","|","undo","redo"],standard:["heading","|","bold","italic","underline","strikethrough","|","alignment","|","link","blockQuote","|","bulletedList","numberedList","indent","outdent","|","insertTable","uploadImage","|","undo","redo"],full:["heading","|","fontSize","fontColor","fontBackgroundColor","|","bold","italic","underline","strikethrough","|","alignment","|","link","blockQuote","insertTable","uploadImage","mediaEmbed","horizontalLine","|","bulletedList","numberedList","indent","outdent","|","codeBlock","sourceEditing","|","undo","redo"]};function We(t){if(t==="en")return Promise.resolve();const o=`ckeditor5-translations-${t}`;if(document.getElementById(o))return Promise.resolve();const e=window?.G7Core?.asset?.loadScript;return new Promise(n=>{let r;try{r=ge(t)}catch(l){console.warn("[ckeditor5] 번역 URL 을 만들지 못했습니다 (영어로 동작):",l),n();return}if(typeof e=="function"){e(r,{id:o},{label:`ckeditor5 translations: ${t}`,retries:1}).catch(l=>{console.warn(`[ckeditor5] 번역 로드 실패 (${t}) — 영어로 동작합니다:`,l)}).then(()=>n());return}const a=document.createElement("script");a.id=o,a.src=r,a.onload=()=>n(),a.onerror=()=>{console.warn(`[ckeditor5] 번역 로드 실패 (${t}) — 영어로 동작합니다.`),n()},document.head.appendChild(a)})}async function Wt(){if(document.getElementById(st))return;const t=window?.G7Core?.asset?.loadStylesheet;let o;try{o=At()}catch(e){console.warn("[ckeditor5] CSS URL 을 만들지 못했습니다:",e);return}if(typeof t!="function"){const e=document.createElement("link");e.id=st,e.rel="stylesheet",e.href=o,document.head.appendChild(e);return}try{await t(o,{id:st},{label:"ckeditor5 CSS"})}catch(e){console.warn("[ckeditor5] CSS 로드 실패:",e),tt("ckeditor5-css",D("editor.asset.style_label","편집기 스타일"),()=>Wt())}}async function pt(t,o,e,n,r){const a=ze(e);if(!await Tt())throw new Error("[sirsoft-ckeditor5] 편집기 본체를 여전히 불러올 수 없습니다.");je(e);const l={...t,params:{...t.params??{},content:n?a??{}:a??""}};if(await te(l,o),!r)return;window.G7Core?.state?.setLocal?.({[`form.${r}_mode`]:"html"})}function D(t,o){const e=window?.G7Core?.t;if(typeof e!="function")return o;const n=`sirsoft-ckeditor5.${t}`,r=e(n);return typeof r=="string"&&r!==n?r:o}const qt="sirsoft-ckeditor5-library",qe=`
/* ==========================================================================
 * 1. 본문·편집 영역 공통 보정 (라이트)
 * ========================================================================== */

/* 표 너비 인라인 스타일 무력화 - 컨테이너에 맞게 100% (prose 와의 충돌 방지) */
:root .ck-content figure.table { width: 100%; }
:root .ck-content figure.table table { width: 100%; }
/* 표 tr 배경색 초기화 */
:root .ck-content table tr { background-color: unset; }
/* 표 col 절대 px 너비 무력화 - 컨테이너에 맞게 비율로 */
:root .ck-content table col { width: auto !important; }

/* 표 정렬 보정: CKEditor5 기본 CSS 의 margin-left:auto 를 float 으로 덮어씀 */
:root .ck-content figure.table[style*="float:left"],
:root .ck-content figure.table[style*="float: left"] {
    margin-left: 0 !important;
    margin-right: 1em !important;
}
:root .ck-content figure.table[style*="float:right"],
:root .ck-content figure.table[style*="float: right"] {
    margin-left: 1em !important;
    margin-right: 0 !important;
}

/* 이미지 캡션 - 라이트 모드 */
:root .ck-content .image > figcaption {
    background-color: #f3f4f6;
    color: #374151;
    font-size: 0.875em;
    padding: 4px 8px;
    text-align: center;
}

/* 목록 마커 및 들여쓰기 복원 (Tailwind preflight reset 대응) */
:root .ck.ck-editor__editable ul,
:root .ck-content ul {
    list-style-type: disc;
    padding-left: 2em;
}
:root .ck.ck-editor__editable ol,
:root .ck-content ol {
    list-style-type: decimal;
    padding-left: 2em;
}
:root .ck.ck-editor__editable ul ul,
:root .ck-content ul ul {
    list-style-type: circle;
    padding-left: 2em;
}
:root .ck.ck-editor__editable ul ul ul,
:root .ck-content ul ul ul {
    list-style-type: square;
    padding-left: 2em;
}
:root .ck.ck-editor__editable ol ol,
:root .ck-content ol ol {
    list-style-type: lower-alpha;
    padding-left: 2em;
}
:root .ck.ck-editor__editable ol ol ol,
:root .ck-content ol ol ol {
    list-style-type: lower-roman;
    padding-left: 2em;
}

/* ==========================================================================
 * 2. 읽기 화면(방문자가 보는 본문) 전용 보정 — 편집 영역에는 걸지 않는다
 * ========================================================================== */

/* figure.table 전체 너비 - prose 와의 충돌 방지 */
:root .ck-content:where(:not(.ck-editor__editable)) figure.table { display: table; margin: 1em 0; }

/* 이미지 인라인/정렬 - Tailwind preflight img:block 대응 */
:root .ck-content:where(:not(.ck-editor__editable)) img { display: inline; margin: 0; max-width: 100%; }
:root .ck-content:where(:not(.ck-editor__editable)) p:has(img) { line-height: 0; }
:root .ck-content:where(:not(.ck-editor__editable)) p:has(img[style*="float"]) { overflow: hidden; }

/* 이미지 캡션 - 다크 모드 (동봉 CSS 미지원) */
html[data-g7-color-scheme="dark"] .ck-content:where(:not(.ck-editor__editable)) .image > figcaption { color: hsl(0, 0%, 80%); background-color: hsl(0, 0%, 15%); }

/* 표 - 다크 모드 (동봉 CSS 미지원) */
html[data-g7-color-scheme="dark"] .ck-content:where(:not(.ck-editor__editable)) figure.table table { border-color: hsl(0, 0%, 35%); }
html[data-g7-color-scheme="dark"] .ck-content:where(:not(.ck-editor__editable)) figure.table table tr { background-color: unset; }
html[data-g7-color-scheme="dark"] .ck-content:where(:not(.ck-editor__editable)) figure.table table td,
html[data-g7-color-scheme="dark"] .ck-content:where(:not(.ck-editor__editable)) figure.table table th { border-color: hsl(0, 0%, 30%); color: hsl(0, 0%, 90%); background-color: unset; }
html[data-g7-color-scheme="dark"] .ck-content:where(:not(.ck-editor__editable)) figure.table table th { background-color: hsl(215, 20%, 25%); }

/* ==========================================================================
 * 3. 편집기 다크 모드 — CKEditor 변수와 UI 색 덮어쓰기
 * ========================================================================== */

/* CSS 변수 — 동봉 CSS 의 :root 선언을 이기도록 html[data-g7-color-scheme="dark"] */
html[data-g7-color-scheme="dark"] {
    --ck-color-base-foreground: #1f2937;
    --ck-color-base-background: #1f2937;
    --ck-color-base-border: #374151;
    --ck-color-text: #f3f4f6;
    --ck-color-toolbar-background: #111827;
    --ck-color-toolbar-border: #374151;
    --ck-color-button-default-background: transparent;
    --ck-color-button-default-hover-background: #374151;
    --ck-color-button-default-active-background: #4b5563;
    --ck-color-button-on-background: #374151;
    --ck-color-button-on-hover-background: #4b5563;
    --ck-color-focus-border: #3b82f6;
    --ck-color-input-background: #1f2937;
    --ck-color-input-text: #f3f4f6;
    --ck-color-input-border: #374151;
    --ck-color-panel-background: #1f2937;
    --ck-color-panel-border: #374151;
    --ck-color-list-button-hover-background: #374151;
    --ck-color-list-button-on-background: #3b82f6;
    --ck-color-list-button-on-color: #f3f4f6;
    --ck-color-list-button-on-hover-background: #4b5563;
    --ck-color-labeled-field-label-background: #1f2937;
    --ck-color-link-default: #60a5fa;
    --ck-color-link-selected-background: rgba(96, 165, 250, 0.1);
    --ck-color-table-focused-cell-background: rgba(59, 130, 246, 0.1);
}
/* 에디터 본문 */
html[data-g7-color-scheme="dark"] .ck.ck-editor__editable_inline {
    background-color: #1f2937 !important;
    color: #f3f4f6 !important;
    border-color: #374151 !important;
}
/* 메인 툴바 */
html[data-g7-color-scheme="dark"] .ck.ck-toolbar {
    background-color: #111827 !important;
    border-color: #374151 !important;
}
/* 모든 버튼 텍스트 */
html[data-g7-color-scheme="dark"] .ck.ck-button,
html[data-g7-color-scheme="dark"] .ck.ck-button .ck-button__label {
    color: #f3f4f6 !important;
}
/* 모든 SVG 아이콘 (툴바, 드롭다운 화살표 포함) */
html[data-g7-color-scheme="dark"] .ck.ck-icon,
html[data-g7-color-scheme="dark"] .ck .ck-icon,
html[data-g7-color-scheme="dark"] .ck.ck-button .ck-icon,
html[data-g7-color-scheme="dark"] .ck.ck-dropdown__arrow {
    color: #f3f4f6 !important;
}
html[data-g7-color-scheme="dark"] .ck.ck-button:hover,
html[data-g7-color-scheme="dark"] .ck.ck-button.ck-on {
    background-color: #374151 !important;
}
/* 툴팁 (호버 시 버튼 설명) */
html[data-g7-color-scheme="dark"] .ck.ck-tooltip .ck-tooltip__text {
    background-color: #1f2937 !important;
    color: #f3f4f6 !important;
}
/* 드롭다운 패널 / 리스트 / balloon 팝업 공통 */
html[data-g7-color-scheme="dark"] .ck.ck-dropdown__panel,
html[data-g7-color-scheme="dark"] .ck.ck-list,
html[data-g7-color-scheme="dark"] .ck.ck-balloon-panel {
    background-color: #1f2937 !important;
    border-color: #374151 !important;
}
/* 리스트 아이템 */
html[data-g7-color-scheme="dark"] .ck.ck-list__item .ck-button,
html[data-g7-color-scheme="dark"] .ck.ck-list__item .ck-button .ck-button__label {
    color: #f3f4f6 !important;
}
html[data-g7-color-scheme="dark"] .ck.ck-list__item .ck-button:hover {
    background-color: #374151 !important;
}
html[data-g7-color-scheme="dark"] .ck.ck-list__item .ck-button.ck-disabled .ck-button__label {
    color: #6b7280 !important;
}
/* 링크 팝업 / form */
html[data-g7-color-scheme="dark"] .ck.ck-link-form,
html[data-g7-color-scheme="dark"] .ck.ck-link-actions {
    background-color: #1f2937 !important;
    border-color: #374151 !important;
}
html[data-g7-color-scheme="dark"] .ck.ck-input-text {
    background-color: #111827 !important;
    color: #f3f4f6 !important;
    border-color: #374151 !important;
}
html[data-g7-color-scheme="dark"] .ck.ck-labeled-field-view__label {
    background-color: #1f2937 !important;
    color: #9ca3af !important;
}
/* 테이블 속성/셀 속성 폼 */
html[data-g7-color-scheme="dark"] .ck.ck-table-form,
html[data-g7-color-scheme="dark"] .ck.ck-table-cell-properties-form,
html[data-g7-color-scheme="dark"] .ck.ck-table-properties-form {
    background-color: #1f2937 !important;
    border-color: #374151 !important;
}
html[data-g7-color-scheme="dark"] .ck.ck-table-form .ck-label,
html[data-g7-color-scheme="dark"] .ck.ck-table-cell-properties-form .ck-label,
html[data-g7-color-scheme="dark"] .ck.ck-table-properties-form .ck-label {
    color: #d1d5db !important;
}
/* 색상 선택 패널 */
html[data-g7-color-scheme="dark"] .ck.ck-color-table,
html[data-g7-color-scheme="dark"] .ck.ck-color-grid {
    background-color: #1f2937 !important;
}
html[data-g7-color-scheme="dark"] .ck.ck-color-table .ck-color-table__remove-color,
html[data-g7-color-scheme="dark"] .ck.ck-color-table .ck-color-table__remove-color .ck-button__label {
    color: #f3f4f6 !important;
}
/* 헤딩/폰트 드롭다운 라벨 */
html[data-g7-color-scheme="dark"] .ck.ck-heading_paragraph,
html[data-g7-color-scheme="dark"] .ck.ck-heading_heading1,
html[data-g7-color-scheme="dark"] .ck.ck-heading_heading2,
html[data-g7-color-scheme="dark"] .ck.ck-heading_heading3 {
    color: #f3f4f6 !important;
}
/* 이미지 선택 시 balloon toolbar (floating) */
html[data-g7-color-scheme="dark"] .ck.ck-toolbar_floating {
    background-color: #111827 !important;
    border-color: #374151 !important;
}
/* 이미지 정렬 툴바 아이콘 */
html[data-g7-color-scheme="dark"] .ck.ck-toolbar .ck-button .ck-icon {
    color: #f3f4f6 !important;
}
/* 이미지 리사이즈 드롭다운 버튼 라벨 (25%, 50% 등) */
html[data-g7-color-scheme="dark"] .ck.ck-dropdown .ck-button__label {
    color: #f3f4f6 !important;
}
/* 이미지 리사이즈 드롭다운 내 선택 리스트 */
html[data-g7-color-scheme="dark"] .ck.ck-resize-image-form,
html[data-g7-color-scheme="dark"] .ck.ck-image-resize-form {
    background-color: #1f2937 !important;
    border-color: #374151 !important;
    color: #f3f4f6 !important;
}
html[data-g7-color-scheme="dark"] .ck.ck-resize-image-form .ck-label,
html[data-g7-color-scheme="dark"] .ck.ck-image-resize-form .ck-label {
    color: #d1d5db !important;
}
html[data-g7-color-scheme="dark"] .ck.ck-resize-image-form .ck-input-text,
html[data-g7-color-scheme="dark"] .ck.ck-image-resize-form .ck-input-text {
    background-color: #111827 !important;
    color: #f3f4f6 !important;
    border-color: #374151 !important;
}

/*
 * 편집 영역 안 본문 — 읽기 화면의 다크 규칙(2절)을 덮지 않도록 편집 영역(.ck-editor__editable)으로 좁힌다.
 * 종전에는 편집기를 한 번 띄우면 이 규칙들이 읽기 화면에도 남았다.
 */
/* 이미지 캡션 */
html[data-g7-color-scheme="dark"] .ck-content.ck-editor__editable .image > figcaption {
    background-color: #374151 !important;
    color: #d1d5db !important;
}
/* 이미지 리사이즈 핸들 */
html[data-g7-color-scheme="dark"] .ck-content.ck-editor__editable .image.ck-widget_selected .ck-widget__resizer__handle {
    background-color: #3b82f6 !important;
    border-color: #1d4ed8 !important;
}
/* 표 안 에디터 내용 (다크 배경) */
html[data-g7-color-scheme="dark"] .ck-content.ck-editor__editable table tr {
    background-color: unset;
}
html[data-g7-color-scheme="dark"] .ck-content.ck-editor__editable table td,
html[data-g7-color-scheme="dark"] .ck-content.ck-editor__editable table th {
    border-color: #4b5563;
    color: #f3f4f6 !important;
}
/* 목록 마커 색상 - 다크모드 */
html[data-g7-color-scheme="dark"] .ck.ck-editor__editable ul,
html[data-g7-color-scheme="dark"] .ck.ck-editor__editable ol {
    color: #f3f4f6;
}
html[data-g7-color-scheme="dark"] .ck.ck-editor__editable li::marker {
    color: #d1d5db;
}

/* ==========================================================================
 * 4. 편집기 최소 높이 — 값은 핸들러가 컨테이너에 CSS 변수로 싣는다
 * ========================================================================== */

/*
 * 편집 영역의 인라인 스타일은 포커스 때 CKEditor 가 다시 쓰므로 규칙으로 강제한다.
 * 핸들러는 컨테이너에 data-ckeditor5-min-height 속성과 --ckeditor5-min-height 변수만 단다.
 */
[data-ckeditor5-min-height] .ck-editor__editable {
    min-height: var(--ckeditor5-min-height) !important;
}
`;function Ht(){const t=window?.G7Core?.style;typeof t?.setRules=="function"&&(typeof t.has=="function"&&t.has(qt)||t.setRules(qt,qe))}function Yt(){const t=window.G7Core;return t?.locale?.supported?t.locale.supported():[localStorage.getItem("g7_locale")||"ko"]}function G(){return window.G7Core?.locale?.current?.()??localStorage.getItem("g7_locale")??"ko"}function He(t,o,e,n=!1){const r=[t.Essentials,t.Bold,t.Italic,t.Underline,t.Strikethrough,t.Link,t.Paragraph,t.Heading,t.BlockQuote,t.List,t.Alignment,t.Table,t.TableToolbar,t.TableProperties,t.TableCellProperties,t.Indent,t.IndentBlock,t.PasteFromOffice,t.GeneralHtmlSupport,t.Image,t.ImageResize,t.ImageStyle,t.ImageCaption,t.ImageToolbar];return o==="full"&&r.push(t.MediaEmbed,t.HorizontalLine,t.CodeBlock,t.SourceEditing,t.FontSize,t.FontColor,t.FontBackgroundColor),n&&o!=="full"&&r.push(t.MediaEmbed),e&&r.push(t.ImageUpload),r}function Qt(){return de("/api/plugins/sirsoft-ckeditor5/upload")}function Ye(){try{return window?.G7Core?.contentPolicy?.editorConstraints?.()??null}catch{return null}}function Qe(t){return(t??[]).map(o=>({name:String(o.id),url:(o.patterns??[]).map(e=>{try{return new RegExp(e,"i")}catch{return null}}).filter(e=>e!==null),html:e=>{const n=e?.groups?.id??"",r=String(o.player_url??"").replace("{id}",encodeURIComponent(n)).replace(/"/g,"&quot;");return`<div class="${Be}"><iframe src="${r}" class="${Ve}" allowfullscreen></iframe></div>`}})).filter(o=>o.url.length>0)}function Je(t){let o=t;if(typeof o=="string"){const n=o.trim();if(n===""||n==="null"||n==="undefined"||n.startsWith("{{"))return null;try{o=JSON.parse(n)}catch{return null}}if(o==null||typeof o!="object")return null;const e=window?.G7Core?.contentMedia?.normalizeTarget;return typeof e=="function"?e(o)??null:null}function Xe(t){const o={jpg:"jpeg",jpeg:"jpeg",png:"png",gif:"gif",webp:"webp",avif:"avif"},e=t.map(n=>o[String(n).toLowerCase()]).filter(n=>!!n);return Array.from(new Set(e))}async function Jt(t){try{await t?.destroy?.()}catch{}}function Xt(t,o){w.get(t)===o&&w.delete(t)}function Ze(t){let o=null;try{o=window?.G7Core?.AuthManager?.getInstance?.()?.getUser?.()??null}catch{o=null}return(o??t?._global?.currentUser??t?.currentUser)?.is_admin===!0}function Ke(t){const o=window?.G7Core?.toast?.error;if(typeof o!="function")return;let e;try{e=t?.plugins?.get?.("Notification")}catch{return}e?.on?.("show:warning",(n,r)=>{const a=typeof r?.message=="string"&&r.message!==""?r.message:typeof r?.title=="string"?r.title:"";a!==""&&o(a),n.stop()},{priority:"high"})}async function Zt(t,o){const e=window.CKEDITOR;if(!e)throw new Error("[sirsoft-ckeditor5] CKEDITOR 전역 객체를 찾을 수 없습니다. CDN 스크립트가 로드되었는지 확인하세요.");const n=Vt[o.toolbar]||Vt.standard,r=Ye(),a=r?.allowExternalEmbed===!0;let l=o.imageUpload?n:n.filter(f=>f!=="uploadImage");if(a&&!l.includes("mediaEmbed")){const f=l.indexOf("insertTable");l=[...l.slice(0,f+1),"mediaEmbed",...l.slice(f+1)]}r&&!a&&(l=l.filter(f=>f!=="mediaEmbed"));const c=De(o.mediaTarget??null),s=c.map(f=>zt[f]),d=l.indexOf("uploadImage")>=0?l.indexOf("uploadImage"):l.indexOf("insertTable"),i=s.length>0?[...l.slice(0,d+1),...s,...l.slice(d+1)]:l,m={initialData:o.initialContent,plugins:He(e,o.toolbar,o.imageUpload,a),toolbar:{items:i},table:{contentToolbar:["tableColumn","tableRow","mergeTableCells","|","tableProperties","tableCellProperties"],tableProperties:{defaultProperties:{borderStyle:"solid",borderColor:"#d1d5db",borderWidth:"1px",alignment:"left"}},tableCellProperties:{defaultProperties:{borderStyle:"solid",borderColor:"#d1d5db",borderWidth:"1px",padding:"8px"}}},image:{toolbar:["imageStyle:inline","imageStyle:alignLeft","imageStyle:alignCenter","imageStyle:alignRight","|","toggleImageCaption","|","resizeImage"],resizeOptions:[{name:"resizeImage:original",label:"Original",value:null},{name:"resizeImage:25",label:"25%",value:"25"},{name:"resizeImage:50",label:"50%",value:"50"},{name:"resizeImage:75",label:"75%",value:"75"}],resizeUnit:"%",upload:{types:o.mediaTarget?Xe(o.mediaTarget.policy?.image?.extensions??[]):[...Ue]}},indentBlock:{offset:40,unit:"px"},link:{defaultProtocol:"https://",decorators:{openInNewTab:{mode:"automatic",callback:f=>f.startsWith("http://")||f.startsWith("https://"),attributes:{target:"_blank",rel:"noopener noreferrer"}}}},htmlSupport:{allow:[{name:/.*/,attributes:!0,classes:!0,styles:!0}],...r&&(r.forbidTags??[]).length>0?{disallow:[{name:new RegExp(`^(${r.forbidTags.map(f=>f.replace(/[^a-z0-9-]/gi,"")).join("|")})$`)}]}:{}},placeholder:o.placeholder,language:G()};r&&(m.mediaEmbed={previewsInData:!1,providers:[],extraProviders:Qe(r.providers??[])});const g=[Le];o.imageUpload&&g.push(o.mediaTarget?Nt({target:o.mediaTarget,legacyUrl:Qt()}):Nt(Qt())),c.length>0&&g.push(Ne(o.mediaTarget,c)),m.extraPlugins=g;const p=await e.ClassicEditor.create(t,m);Ke(p);const u=document.getElementById(o.containerId);return u&&(u.setAttribute(jt,""),u.style.setProperty(Bt,`${o.height}px`)),o.readOnly&&p.enableReadOnlyMode("read-only"),p}function to(t,o,e,n,r){const a=document.createElement("div");a.className="ckeditor-locale-tabs";const l=new Map;o.forEach(d=>{const i=document.createElement("div");i.dataset.locale=d,d!==e&&(i.style.cssText="display:none;");const m=document.createElement("div");i.appendChild(m),l.set(d,m)});const c=()=>{r&&a.querySelectorAll("button").forEach(d=>{const i=d.dataset.locale;if(!i||d.classList.contains("ckeditor-locale-tab--active")){const u=d.querySelector(".ckeditor-check");u&&u.remove();return}const g=!!r(i)?.trim(),p=d.querySelector(".ckeditor-check");if(g&&!p){const u=document.createElement("i");u.className="ckeditor-icon ckeditor-icon--check ckeditor-check",d.appendChild(u)}else!g&&p&&p.remove()})},s=o[0];return o.forEach(d=>{const i=d===s,m=d===e,g=document.createElement("button");g.type="button",g.dataset.locale=d,g.className=["ckeditor-locale-tab",m?"ckeditor-locale-tab--active":"",i?"ckeditor-locale-tab--default":""].filter(Boolean).join(" "),Pe(g,d,i),g.addEventListener("click",async()=>{a.querySelectorAll("button").forEach(p=>{const f=p.dataset.locale===d;p.classList.toggle("ckeditor-locale-tab--active",f)}),l.forEach((p,u)=>{const f=p.parentElement;f&&(f.style.display=u===d?"block":"none")}),n&&await n(d),c()}),a.appendChild(g)}),t.appendChild(a),l.forEach(d=>{d.parentElement&&t.appendChild(d.parentElement)}),{tabsEl:a,contentEls:l,updateCheckIcons:c}}function Kt(t,o,e,n,r=!0,a=null){if(se())return;const l=window.G7Core;if(!l?.state?.setLocal)return;if(r&&l.state.getLocal?.()?.hasChanges!==!0&&l.state.setLocal({hasChanges:!0}),a&&a.scope==="global"){const d=n?`${a.path}.${o}`:a.path;window.G7Core?.state?.set?.({[d]:e});return}const c=a?.path??`form.${t}`,s={[`form.${t}_mode`]:"html"};n?s[`${c}.${o}`]=e:s[c]=e,l.state.setLocal(s,{debounce:300,debounceKey:`ckeditor-sync-${t}`,render:!1,selfManaged:!0})}function ht(t,o){const e=window.G7Core,n=typeof t.content=="string"?t.content:"";let a=n.startsWith("{{")&&n.endsWith("}}")?"":n;if(!a&&o){const c=(e?.state?.getLocal?.()??{})?.form?.[o];typeof c=="string"&&c&&(a=c)}return a}function kt(t,o){const e=window.G7Core;let n={};try{typeof t.content=="string"&&t.content.startsWith("{")&&!t.content.startsWith("{{")?n=JSON.parse(t.content):typeof t.content=="object"&&t.content!==null&&(n=t.content)}catch{n={}}if(Object.keys(n).length===0&&o){const a=(e?.state?.getLocal?.()??{})?.form?.[o];if(a&&typeof a=="object"&&!Array.isArray(a)?n=a:typeof a=="string"&&a&&(n={[G()]:a}),Object.keys(n).length===0){const l=e?.state?.get?.()??{},s=(l?._global?.selectedItem??l?.selectedItem)?.[o];s&&typeof s=="object"&&!Array.isArray(s)&&(n=s)}}return n}async function te(t,o){const e=t.params??{},n=e.name??"content",r=`ckeditor5-${n}`,a=$(location.pathname),l=e.multilingual===!0||e.multilingual==="true",c=l?"":ht(e,n),s=l?kt(e,n):{};vt(r);const d=w.get(r);if(d&&d.size>0){const y=[];d.forEach(k=>{k&&typeof k.destroy=="function"&&y.push(k.destroy().catch(()=>{}))}),await Promise.allSettled(y),w.delete(r)}const i=document.getElementById(r);if(!i){console.warn(`[sirsoft-ckeditor5] 컨테이너 엘리먼트를 찾을 수 없습니다: #${r}`);return}Wt(),Ht(),await We(G());const g=window.G7Core?.state?.get()||{},p=g._global?.plugins?.["sirsoft-ckeditor5"]??g.plugins?.["sirsoft-ckeditor5"]??{},u=e.name??"content",f=e.multilingual===!0||e.multilingual==="true",b=e.readOnly===!0||e.readOnly==="true",I=()=>Rt(i,b),v=e.imageUpload!==void 0?e.imageUpload===!0||e.imageUpload==="true":p.imageUpload===!0||p.imageUpload==="true",U=Je(e.mediaUpload),S=U?U.policy?.image?.enabled===!0:v&&Ze(g),P=e.placeholder??"",F=e.height!==void 0?Number(e.height)||400:Number(p.editorHeight)||400,oo=(e.toolbar!==void 0?e.toolbar:p.toolbar)??"standard",H=!(e.trackChanges===!1||e.trackChanges==="false"),ro=e.writeScope==="global"?"global":"local",re=typeof e.writePath=="string"&&e.writePath.trim()!==""&&!e.writePath.includes("{{")?e.writePath.trim():null,T=re===null?null:{scope:ro,path:re},wt=(y,k)=>{if(!T)return;const C=window.G7Core,h=T.scope==="global"?C?.state?.get?.():C?.state?.getLocal?.(),N=(k?`${T.path}.${y}`:T.path).split(".").reduce((z,L)=>z?.[L],h);return typeof N=="string"?N:void 0},no=(y,k)=>{if(T)return wt(y,k);const C=window.G7Core?.state?.getLocal?.()?.form,h=k?C?.[u]?.[y]:C?.[u];return typeof h=="string"?h:void 0},at=(y,k,C,h)=>{if($(location.pathname)===a)return{value:C(),path:a};const E=no(y,k)??"";return E!==h?{value:E,path:$(location.pathname)}:{value:E,path:a}};let ne=f?kt(e,u):{};const ao=await Tt();if(!i.isConnected)return;if(X(r,Te(i,()=>w.get(r)?.values()??[],b)),!ao){const y=f?Yt():[],k=f?y.map(h=>{const E=kt(e,u);return{locale:h,...at(h,!0,()=>E[h]??"",s[h]??"")}}):[{locale:"",...at(G(),!1,()=>ht(e,u),c)}],C={container:i,name:u,height:F,readOnly:I(),placeholder:P,trackChanges:H,multilingual:f,locales:f?y:void 0,activeLocale:G(),contentMap:f?Object.fromEntries(k.map(h=>[h.locale,h.value])):void 0,initialContent:f?void 0:k[0].value,seedPath:k.find(h=>h.path!==$(location.pathname))?.path};gt(C),tt(`ckeditor5-editor:${r}`,D("editor.asset.label","편집기"),()=>pt(t,o,i,C.multilingual,u),D("editor.asset.fallback_notice","편집기를 불러오지 못했습니다. 임시 입력창으로 전환했습니다. 작성한 내용은 그대로 저장됩니다."));return}const M=new Map;w.set(r,M);const ae={placeholder:P,get readOnly(){return I()},imageUpload:S,height:F,toolbar:oo,containerId:r,mediaTarget:U};if(f){const y=Yt(),k=G(),C={current:new Map},h={current:()=>{}},E=async _=>{if(M.has(_))return;const Y=C.current.get(_);if(!Y)return;const le=at(_,!0,()=>ne[_]??"",s[_]??""),ie=le.value;try{const j=await Zt(Y,{...ae,initialContent:ie});if(!i.isConnected){await Jt(j);return}M.set(_,j);let ce=!0;const _t=lt(j,{name:u,locale:_,multilingual:!0,readExternal:T?()=>wt(_,!0):void 0,seedPath:le.path,seedValue:ie});if(X(r,_t),j.model.document.on("change:data",()=>{if(ce||!_t.shouldEmit())return;const Q=j.getData();_t.noteEmitted(Q),Kt(u,_,Q,!0,H,T),h.current()}),ce=!1,u){const Q=window.G7Core;(Q?.state?.getLocal?.()??{}).form?.[`${u}_mode`]!=="html"&&Q.state.setLocal({[`form.${u}_mode`]:"html"})}}catch(j){console.error(`[sirsoft-ckeditor5] 에디터 초기화 오류 (locale: ${_}):`,j)}},N=_=>{const Y=M.get(_);return Y?Y.getData?.()??null:null},{contentEls:z,updateCheckIcons:L}=to(i,y,k,E,N);if(C.current=z,h.current=L,await E(k),!i.isConnected){Xt(r,M);return}if(!M.has(k)){w.delete(r),gt({container:i,name:u,height:F,readOnly:I(),placeholder:P,multilingual:!0,locales:y,activeLocale:k,contentMap:ne,trackChanges:H}),tt(`ckeditor5-editor:${r}`,D("editor.asset.label","편집기"),()=>pt(t,o,i,!0,u),D("editor.asset.fallback_notice","편집기를 불러오지 못했습니다. 임시 입력창으로 전환했습니다. 작성한 내용은 그대로 저장됩니다."));return}setTimeout(()=>{y.filter(_=>_!==k).forEach(_=>{E(_)})},2e3)}else{const y=at(G(),!1,()=>ht(e,u),c),k=y.value,C=document.createElement("div");i.appendChild(C);try{const h=await Zt(C,{...ae,initialContent:k});if(!i.isConnected){await Jt(h),Xt(r,M);return}const E=G();M.set(E,h);let N=!0;const z=lt(h,{name:u,locale:E,multilingual:!1,readExternal:T?()=>wt(E,!1):void 0,seedPath:y.path,seedValue:k});if(X(r,z),h.model.document.on("change:data",()=>{if(N||!z.shouldEmit())return;const L=h.getData();z.noteEmitted(L),Kt(u,E,L,!1,H,T)}),N=!1,k){const L=h.getData();(!L||L.trim()==="")&&h.setData(k)}if(u){const L=window.G7Core;(L?.state?.getLocal?.()??{}).form?.[`${u}_mode`]!=="html"&&L.state.setLocal({[`form.${u}_mode`]:"html"})}}catch(h){console.error("[sirsoft-ckeditor5] 에디터 초기화 오류:",h),w.delete(r),gt({container:i,name:u,height:F,readOnly:I(),placeholder:P,multilingual:!1,activeLocale:G(),initialContent:k,trackChanges:H,seedPath:y.path}),tt(`ckeditor5-editor:${r}`,D("editor.asset.label","편집기"),()=>pt(t,o,i,!1,u),D("editor.asset.fallback_notice","편집기를 불러오지 못했습니다. 임시 입력창으로 전환했습니다. 작성한 내용은 그대로 저장됩니다."))}}}async function eo(t,o){const n=`ckeditor5-${t.params?.name??"content"}`;vt(n);const r=w.get(n);if(!r||r.size===0)return;const a=[];r.forEach(c=>{c&&typeof c.destroy=="function"&&a.push(c.destroy().catch(s=>{console.warn("[sirsoft-ckeditor5] destroyEditor error:",s)}))}),await Promise.allSettled(a),w.delete(n);const l=document.getElementById(n);l&&(l.removeAttribute(jt),l.style.removeProperty(Bt))}const A="sirsoft-ckeditor5",bt="ckeditor5-content-styles";function ee(){if(Ht(),!document.getElementById(bt)){const t=window?.G7Core?.asset;try{const o=At();if(typeof t?.loadStylesheet=="function")t.loadStylesheet(o,{id:bt},{label:"ckeditor5 content CSS"}).catch(e=>{console.warn("[ckeditor5] 본문 스타일 로드 실패:",e),window?.G7Core?.assets?.notifyFailure?.({id:"ckeditor5-content-css",label:"본문 스타일",retry:()=>ee()})});else{const e=document.createElement("link");e.id=bt,e.rel="stylesheet",e.href=o,document.head.appendChild(e)}}catch(o){console.warn("[ckeditor5] 본문 스타일 URL 을 만들지 못했습니다:",o)}}}const O={initEditor:te,destroyEditor:eo,injectContentCss:ee},q=window.G7Core?.createLogger?.(`Plugin:${A}`)??{log:(...t)=>console.log(`[Plugin:${A}]`,...t),warn:(...t)=>console.warn(`[Plugin:${A}]`,...t),error:(...t)=>console.error(`[Plugin:${A}]`,...t)};function oe(t=!1){const o=window.G7Core?.getActionDispatcher?.();if(o)Object.entries(O).forEach(([e,n])=>{const r=`${A}.${e}`;o.registerHandler(r,n,{category:"plugin",source:A})}),q.log(`${Object.keys(O).length} handler(s) registered:`,Object.keys(O).map(e=>`${A}.${e}`));else if(t){let e=0;const n=50,r=()=>{const a=window.G7Core?.getActionDispatcher?.();a?(Object.entries(O).forEach(([l,c])=>{const s=`${A}.${l}`;a.registerHandler(s,c,{category:"plugin",source:A})}),q.log(`${Object.keys(O).length} handler(s) registered:`,Object.keys(O).map(l=>`${A}.${l}`))):(e++,e<=n?(q.warn(`ActionDispatcher not found, retrying... (${e}/${n})`),setTimeout(r,100)):q.error("Failed to register handlers: ActionDispatcher not available after maximum retries"))};r()}else q.warn("ActionDispatcher not found, handlers not registered")}function yt(){if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",()=>oe(!0));else{const t=!!window.G7Core?.getActionDispatcher?.();oe(!t)}}return yt(),typeof window<"u"&&(window.__SirsoftCkeditor5={identifier:A,handlers:Object.keys(O),initPlugin:yt}),x.initPlugin=yt,Object.defineProperty(x,Symbol.toStringTag,{value:"Module"}),x})({});
