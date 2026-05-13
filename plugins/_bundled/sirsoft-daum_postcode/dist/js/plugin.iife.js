var SirsoftDaumPostcode=function(m){"use strict";const y=window.G7Core?.createLogger?.("DaumPostcode:SetFieldReadOnly")??{log:(...e)=>console.log("[DaumPostcode:SetFieldReadOnly]",...e),warn:(...e)=>console.warn("[DaumPostcode:SetFieldReadOnly]",...e),error:(...e)=>console.error("[DaumPostcode:SetFieldReadOnly]",...e)};function C(e,c){const o=e.params||{},t=o.fields||[],n=o.readOnly!==!1;if(!t.length){y.warn("[setFieldReadOnly] No fields specified");return}y.log(`[setFieldReadOnly] Setting readOnly=${n} for fields:`,t),t.forEach(r=>{const s=document.querySelectorAll(`input[name="${r}"], textarea[name="${r}"]`);if(s.length===0){y.warn(`[setFieldReadOnly] No input found with name="${r}"`);return}s.forEach(i=>{i.readOnly=n,n?i.classList.add("readonly"):i.classList.remove("readonly"),y.log(`[setFieldReadOnly] Set readOnly=${n} on input[name="${r}"]`)})})}const a=window.G7Core?.createLogger?.("DaumPostcode:OpenPostcode")??{log:(...e)=>console.log("[DaumPostcode:OpenPostcode]",...e),warn:(...e)=>console.warn("[DaumPostcode:OpenPostcode]",...e),error:(...e)=>console.error("[DaumPostcode:OpenPostcode]",...e)};function O(e){return{zipcode:e.zonecode||"",address:e.roadAddress||e.jibunAddress||"",addressDetail:"",region:e.sido||"",city:e.sigungu||"",countryCode:"KR",_raw:e}}function x(e,c){const o=e.params||{},{callbackAction:t,displayMode:n="layer",width:r=500,height:s=600,theme:i,targetFields:l}=o,b=(window.G7Core?.plugin?.getSettings?.("sirsoft-daum_postcode")||{}).target_fields||{zipcode:"shipping.zipcode",address:"shipping.address",region:"shipping.region",city:"shipping.city"};if(a.log("[openPostcode] Starting with params:",{displayMode:n,width:r,height:s,hasCallbackAction:!!t,targetFields:l||b}),!window.daum?.Postcode){a.error("[openPostcode] Daum Postcode API not loaded");return}const g={oncomplete:h=>{a.log("[openPostcode] Address selected (raw):",h);const f=O(h);a.log("[openPostcode] Converted to G7 format:",f),t?(a.log("[openPostcode] Executing callbackAction"),L(t,f,c)):(a.log("[openPostcode] Executing default setState"),S(l||b,f))},width:r,height:s};i&&(g.theme=i);const v=new window.daum.Postcode(g);if(n==="popup")v.open();else{const{layer:h,closeLayer:f}=$(r,s),D=g.oncomplete;g.oncomplete=k=>{f(),D(k)},new window.daum.Postcode(g).embed(h)}}function $(e,c){const o=document.createElement("div");o.id="daum-postcode-overlay",o.style.cssText=`
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.5);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 9999;
    `;const t=document.createElement("div");t.id="daum-postcode-layer",t.style.cssText=`
        width: ${e}px;
        height: ${c}px;
        background-color: white;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    `;const n=document.createElement("button");n.type="button",n.innerHTML="×",n.style.cssText=`
        position: absolute;
        top: -12px;
        right: -12px;
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background-color: #374151;
        color: white;
        border: none;
        cursor: pointer;
        font-size: 20px;
        line-height: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 10;
    `;const r=document.createElement("div");r.style.cssText="position: relative;",r.appendChild(n),r.appendChild(t),o.appendChild(r),document.body.appendChild(o);const s=()=>{o.parentNode&&o.parentNode.removeChild(o)};n.addEventListener("click",s),o.addEventListener("click",l=>{l.target===o&&s()});const i=l=>{l.key==="Escape"&&(s(),document.removeEventListener("keydown",i))};return document.addEventListener("keydown",i),a.log("[openPostcode] Layer created with size:",{width:e,height:c}),{overlay:o,layer:t,closeLayer:s}}function L(e,c,o){const t=window.G7Core;if(!t?.dispatch){a.error("[openPostcode] G7Core.dispatch not available");return}const n=o?.state||t?.state?.getLocal?.()||{},r={state:n,setState:o?.setState,data:{...o?.data||{},_local:n,$event:c},isolatedContext:o?.isolatedContext};a.log("[openPostcode] Dispatching with componentContext:",{hasLocalState:!!n,sourceType:o?.state?"handlerContext":"getLocal",localCheckout:n?.checkout,event:c});try{const s=Array.isArray(e)?e:[e];for(const i of s)i&&typeof i=="object"&&(a.log("[openPostcode] Dispatching action:",i),t.dispatch(i,{componentContext:r}))}catch(s){a.error("[openPostcode] callbackAction failed:",s)}}function S(e,c){const o=window.G7Core;if(!o?.state?.setLocal){a.error("[openPostcode] G7Core.state.setLocal not available");return}const t={};e.zipcode&&(t[e.zipcode]=c.zipcode),e.address&&(t[e.address]=c.address),e.region&&(t[e.region]=c.region),e.city&&(t[e.city]=c.city),a.log("[openPostcode] Setting local state:",t),o.state.setLocal(t)}const p={setFieldReadOnly:C,openPostcode:x},d="sirsoft-daum_postcode",u=window.G7Core?.createLogger?.(`Plugin:${d}`)??{log:(...e)=>console.log(`[Plugin:${d}]`,...e),warn:(...e)=>console.warn(`[Plugin:${d}]`,...e),error:(...e)=>console.error(`[Plugin:${d}]`,...e)};function P(e=!1){const c=window.G7Core?.getActionDispatcher?.();if(c)Object.entries(p).forEach(([o,t])=>{const n=`${d}.${o}`;c.registerHandler(n,t,{category:"plugin",source:d})}),u.log(`${Object.keys(p).length} handler(s) registered:`,Object.keys(p).map(o=>`${d}.${o}`));else if(e){let o=0;const t=50,n=()=>{const r=window.G7Core?.getActionDispatcher?.();r?(Object.entries(p).forEach(([s,i])=>{const l=`${d}.${s}`;r.registerHandler(l,i,{category:"plugin",source:d})}),u.log(`${Object.keys(p).length} handler(s) registered:`,Object.keys(p).map(s=>`${d}.${s}`))):(o++,o<=t?(u.warn(`ActionDispatcher not found, retrying... (${o}/${t})`),setTimeout(n,100)):u.error("Failed to register handlers: ActionDispatcher not available after maximum retries"))};n()}else u.warn("ActionDispatcher not found, handlers not registered")}function w(){if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",()=>P(!0));else{const e=!!window.G7Core?.getActionDispatcher?.();P(!e)}}return w(),typeof window<"u"&&(window.__SirsoftDaumPostcode={identifier:d,handlers:Object.keys(p),initPlugin:w}),m.initPlugin=w,Object.defineProperty(m,Symbol.toStringTag,{value:"Module"}),m}({});
//# sourceMappingURL=plugin.iife.js.map
