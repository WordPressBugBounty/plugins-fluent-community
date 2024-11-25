const g="modulepreload",x=function(m){return"/"+m},b={},y=function(i,c,d){if(!c||c.length===0)return i();const s=document.getElementsByTagName("link");return Promise.all(c.map(o=>{if(o=x(o),o in b)return;b[o]=!0;const n=o.endsWith(".css"),t=n?'[rel="stylesheet"]':"";if(!!d)for(let e=s.length-1;e>=0;e--){const r=s[e];if(r.href===o&&(!n||r.rel==="stylesheet"))return}else if(document.querySelector(`link[href="${o}"]${t}`))return;const f=document.createElement("link");if(f.rel=n?"stylesheet":g,n||(f.as="script",f.crossOrigin=""),f.href=o,document.head.appendChild(f),n)return new Promise((e,r)=>{f.addEventListener("load",e),f.addEventListener("error",()=>r(new Error(`Unable to preload CSS for ${o}`)))})})).then(()=>i()).catch(o=>{const n=new Event("vite:preloadError",{cancelable:!0});if(n.payload=o,window.dispatchEvent(n),!n.defaultPrevented)throw o})};y(()=>Promise.resolve().then(()=>w),void 0);document.addEventListener("DOMContentLoaded",function(){function m(o){const n=[];if(typeof o=="object"&&o.join===void 0)for(const t in o)n.push(m(o[t]));else if(typeof o=="object"&&o.join!==void 0)for(const t in o)n.push(m(o[t]));else typeof o=="function"||typeof o=="string"&&n.push(o);return n.join("<br />")}function i(o,n=null,t=null){if(o&&t)if(o=m(o),n){const _=t.querySelector(`[name="${n}"]`);if(!_){i(o,null,t);return}const f=document.createElement("div");f.className="fcom_field_error fcom_field_error",f.innerHTML=o;let e=_.closest(".fcom_form-group");e?(e.classList.add("fcom_has_error"),e.appendChild(f)):i(o,null,t)}else{const _=document.createElement("div");_.className="fcom_field_error fcom_global_error",_.innerHTML=o,t.appendChild(_)}}function c(o){o.querySelectorAll(".fcom_form_input input").forEach(function(n){n.addEventListener("blur",function(){n.closest(".fcom_form-group").classList.remove("fcom_has_error")})}),o.addEventListener("submit",function(n){n.preventDefault(),o.querySelectorAll(".fcom_field_error").forEach(function(r){r.remove()}),o.querySelectorAll(".fcom_has_error").forEach(function(r){r.classList.remove("fcom_has_error")});const _=o.querySelector('button[type="submit"]');_.classList.add("fcom_loading"),_.setAttribute("disabled","disabled");const f=new FormData(o),e=new XMLHttpRequest;e.open("POST",window.fluentComRegistration.ajax_url,!0),e.send(f),e.onload=function(){if(_.classList.remove("fcom_loading"),_.removeAttribute("disabled"),e.status>=200&&e.status<300){let r=e.responseText;if(!r||r.indexOf("<!DOCTYPE html>")!==-1){i("Something went wrong. Please try again later",null,o),window.location.reload();return}const a=JSON.parse(e.responseText);if(a.verifcation_html){let u=o.getElementsByClassName("fcom_form_main_fields");u.length>0&&(u[0].style.display="none");let p=o.querySelector(".fcom_verification_wrap");p&&p.remove();let l=document.createElement("div");l.className="fcom_verification_wrap",l.innerHTML=a.verifcation_html,o.appendChild(l)}else if(a.redirect_url){window.location.href=a.redirect_url,_.innerText="Redirecting...";return}else a.success_html?document.getElementById("fcom_user_onboard_wrap").innerHTML=a.success_html:i("Something went wrong. Please try again later",null,o)}else{const r=JSON.parse(e.responseText);if((!r||!r.message)&&i("Something went wrong. Please try again later",null,o),i(r.message,null,o),r.errors)for(const a in r.errors)i(r.errors[a],a,o)}}})}let d=document.getElementById("fcom_user_registration_form");d&&c(d);let s=document.getElementById("fcom_user_login_form");if(s&&c(s),window.fluentComRegistration&&window.fluentComRegistration.is_logged_in){const o=document.getElementById("fcom_user_accept_form");o&&o.addEventListener("submit",function(n){n.preventDefault();const t=o.querySelector('button[type="submit"]');t.classList.add("fcom_loading"),t.setAttribute("disabled","disabled"),o.querySelectorAll(".fcom_field_error").forEach(function(r){r.remove()});const f=new FormData(o),e=new XMLHttpRequest;e.open("POST",window.fluentComRegistration.ajax_url,!0),e.send(f),e.onload=function(){if(t.classList.remove("fcom_loading"),t.removeAttribute("disabled"),e.status>=200&&e.status<300){const r=JSON.parse(e.responseText);if(r.redirect_url){window.location.href=r.redirect_url,t.innerText="Redirecting...";return}else i("Something went wrong. Please try again later",null,o)}else{const r=JSON.parse(e.responseText);!r||!r.message?i("Something went wrong. Please try again later",null,o):i(r.message,null,o)}}})}});const h=`.fcom_user_onboard{max-width:600px;margin:0 auto;padding:40px 20px}.fcom_user_onboard .fcom_onboard_header{text-align:center;margin-bottom:20px}.fcom_user_onboard .fcom_onboard_header img{max-width:200px;max-height:80px}.fcom_user_onboard .fcom_onboard_header h2{font-size:24px;margin-bottom:10px;margin-top:0;line-height:1.2}.fcom_user_onboard .fcom_onboard_header p{font-size:16px;margin-bottom:0}.fcom_user_onboard .fcom_onboard_form{padding:20px 0}.fcom_user_onboard .fcom_onboard_form .fcom_form-group,.fcom_user_onboard .fcom_onboard_form .fs_input_wrap{margin-bottom:20px}.fcom_user_onboard .fcom_onboard_form .fcom_form-group label,.fcom_user_onboard .fcom_onboard_form .fs_input_wrap label{display:block;font-size:16px;margin-bottom:5px;font-weight:500}.fcom_user_onboard .fcom_onboard_form .fcom_form-group input[type=text],.fcom_user_onboard .fcom_onboard_form .fcom_form-group input[type=email],.fcom_user_onboard .fcom_onboard_form .fcom_form-group input[type=password],.fcom_user_onboard .fcom_onboard_form .fs_input_wrap input[type=text],.fcom_user_onboard .fcom_onboard_form .fs_input_wrap input[type=email],.fcom_user_onboard .fcom_onboard_form .fs_input_wrap input[type=password]{width:100%;padding:10px;font-size:16px;border:1px solid #ccc;border-radius:5px;background:transparent}.fcom_user_onboard .fcom_onboard_form .fcom_form-group input[type=text]:disabled,.fcom_user_onboard .fcom_onboard_form .fcom_form-group input[type=text]:read-only,.fcom_user_onboard .fcom_onboard_form .fcom_form-group input[type=email]:disabled,.fcom_user_onboard .fcom_onboard_form .fcom_form-group input[type=email]:read-only,.fcom_user_onboard .fcom_onboard_form .fcom_form-group input[type=password]:disabled,.fcom_user_onboard .fcom_onboard_form .fcom_form-group input[type=password]:read-only,.fcom_user_onboard .fcom_onboard_form .fs_input_wrap input[type=text]:disabled,.fcom_user_onboard .fcom_onboard_form .fs_input_wrap input[type=text]:read-only,.fcom_user_onboard .fcom_onboard_form .fs_input_wrap input[type=email]:disabled,.fcom_user_onboard .fcom_onboard_form .fs_input_wrap input[type=email]:read-only,.fcom_user_onboard .fcom_onboard_form .fs_input_wrap input[type=password]:disabled,.fcom_user_onboard .fcom_onboard_form .fs_input_wrap input[type=password]:read-only{background:#f6f7f7}.fcom_user_onboard .fcom_onboard_form .fcom_form-group .fcom_field_error,.fcom_user_onboard .fcom_onboard_form .fs_input_wrap .fcom_field_error{display:none}.fcom_user_onboard .fcom_onboard_form .fcom_form-group.fcom_has_error input[type=text],.fcom_user_onboard .fcom_onboard_form .fcom_form-group.fcom_has_error input[type=email],.fcom_user_onboard .fcom_onboard_form .fcom_form-group.fcom_has_error input[type=password],.fcom_user_onboard .fcom_onboard_form .fs_input_wrap.fcom_has_error input[type=text],.fcom_user_onboard .fcom_onboard_form .fs_input_wrap.fcom_has_error input[type=email],.fcom_user_onboard .fcom_onboard_form .fs_input_wrap.fcom_has_error input[type=password]{border-color:#f56565}.fcom_user_onboard .fcom_onboard_form .fcom_form-group.fcom_has_error .fcom_field_error,.fcom_user_onboard .fcom_onboard_form .fs_input_wrap.fcom_has_error .fcom_field_error{display:block}.fcom_user_onboard .fcom_onboard_form .fcom_form-group .fcom_inline_checkbox,.fcom_user_onboard .fcom_onboard_form .fs_input_wrap .fcom_inline_checkbox{display:flex;align-items:center;margin-top:10px}.fcom_user_onboard .fcom_onboard_form .fcom_form-group .fcom_inline_checkbox input[type=checkbox],.fcom_user_onboard .fcom_onboard_form .fs_input_wrap .fcom_inline_checkbox input[type=checkbox]{margin-right:10px}.fcom_user_onboard .fcom_onboard_form .fcom_form-group .fcom_inline_checkbox label,.fcom_user_onboard .fcom_onboard_form .fs_input_wrap .fcom_inline_checkbox label{margin:0;font-weight:400}.fcom_user_onboard .fcom_onboard_form .fcom_form-group .fcom_btn_primary,.fcom_user_onboard .fcom_onboard_form .fs_input_wrap .fcom_btn_primary{cursor:pointer;padding:8px 16px;background:#409eff;border:0;border-radius:5px;color:#fff;font-size:16px}.fcom_user_onboard .fcom_onboard_form .fcom_form-group .fcom_btn_primary:hover,.fcom_user_onboard .fcom_onboard_form .fs_input_wrap .fcom_btn_primary:hover{background:#2d8cf0}.fcom_user_onboard .fcom_onboard_form .fcom_form-group .fcom_btn_primary.fcom_loading,.fcom_user_onboard .fcom_onboard_form .fs_input_wrap .fcom_btn_primary.fcom_loading{background:#409eff;opacity:.7;cursor:not-allowed}.fcom_user_onboard .fcom_onboard_form .fcom_form-group .fcom_btn_primary.fcom_loading:after,.fcom_user_onboard .fcom_onboard_form .fs_input_wrap .fcom_btn_primary.fcom_loading:after{content:"";display:inline-block;width:10px;height:10px;border:2px solid #fff;border-top-color:transparent;border-radius:50%;animation:spin 1s linear infinite;margin-left:10px}@keyframes spin{to{transform:rotate(360deg)}}.fcom_user_onboard .fcom_onboard_form .fcom_field_error{color:#f56565;font-weight:400;font-size:90%;margin-top:5px;line-height:1.2}.fcom_user_onboard .fcom_onboard_form .fls_login_wrapper .login-submit #wp-submit{width:auto;padding:8px 16px}.fcom_btn_success,#fls_verification_submit{cursor:pointer;padding:10px 20px;background:#67c23a;border:0;border-radius:5px;color:#fff;font-size:16px}.fcom_btn_success svg,#fls_verification_submit svg{display:none!important}.fcom_btn_success:hover,#fls_verification_submit:hover{background:#5cb85c}.fcom_completed{text-align:center;padding:20px 0}.fcom_completed h2{font-size:24px;margin-bottom:10px;margin-top:0;line-height:1.2}.fcom_completed a{text-decoration:none}.fcom_completed .fcom_complted_header{margin-bottom:20px}.fcom_native_login form>p{margin-bottom:20px}.fcom_native_login form>p label{display:block;font-size:16px;margin-bottom:5px;font-weight:500}.fcom_native_login form>p input[type=text],.fcom_native_login form>p input[type=email],.fcom_native_login form>p input[type=password]{width:100%;padding:7px 10px;font-size:16px;border:1px solid #ccc;border-radius:5px}.fcom_native_login #fcom_user_submit{cursor:pointer;padding:10px 20px;background:#67c23a;border:0;border-radius:5px;color:#fff;font-size:16px}.fcom_native_login #fcom_user_submit:hover{background:#5cb85c}.fcom_spaced_divider{margin:30px 0;position:relative;padding:30px 0;border-top:1px solid #ebeef5}.fcom_full_layout{min-height:100vh;display:flex}@media (max-width: 768px){.fcom_full_layout{flex-direction:column}}.fcom_full_layout *{box-sizing:border-box}.fcom_full_layout>div{flex:1}.fcom_full_layout .fcom_layout_side{display:flex;align-items:center;background:#f6f7f7;background-size:cover;background-position:center;background-blend-mode:multiply}.fcom_full_layout .fcom_layout_side .fcom_welcome{padding:20px;text-align:center;margin:0 auto;max-width:600px}.fcom_full_layout .fcom_layout_side .fcom_welcome img{max-width:200px;max-height:80px}.fcom_full_layout .fcom_layout_side .fcom_welcome h2{font-size:24px;margin-bottom:10px;margin-top:8px;line-height:1.2}.fcom_full_layout .fcom_layout_side .fcom_welcome p{font-size:16px;margin-bottom:0}.fcom_full_layout .fcom_layout_main{background:white;display:flex;align-items:center;justify-content:center;padding:20px}
`,w=Object.freeze(Object.defineProperty({__proto__:null,default:h},Symbol.toStringTag,{value:"Module"}));
