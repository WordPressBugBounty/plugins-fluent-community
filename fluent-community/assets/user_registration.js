const g="modulepreload",y=function(i){return"/"+i},b={},w=function(f,c,l){if(!c||c.length===0)return f();const s=document.getElementsByTagName("link");return Promise.all(c.map(o=>{if(o=y(o),o in b)return;b[o]=!0;const e=o.endsWith(".css"),n=e?'[rel="stylesheet"]':"";if(!!l)for(let _=s.length-1;_>=0;_--){const r=s[_];if(r.href===o&&(!e||r.rel==="stylesheet"))return}else if(document.querySelector(`link[href="${o}"]${n}`))return;const a=document.createElement("link");if(a.rel=e?"stylesheet":g,e||(a.as="script",a.crossOrigin=""),a.href=o,document.head.appendChild(a),e)return new Promise((_,r)=>{a.addEventListener("load",_),a.addEventListener("error",()=>r(new Error(`Unable to preload CSS for ${o}`)))})})).then(()=>f()).catch(o=>{const e=new Event("vite:preloadError",{cancelable:!0});if(e.payload=o,window.dispatchEvent(e),!e.defaultPrevented)throw o})};w(()=>Promise.resolve().then(()=>v),void 0);document.addEventListener("DOMContentLoaded",function(){function i(o){const e=[];if(typeof o=="object"&&o.join===void 0)for(const n in o)e.push(i(o[n]));else if(typeof o=="object"&&o.join!==void 0)for(const n in o)e.push(i(o[n]));else typeof o=="function"||typeof o=="string"&&e.push(o);return e.join("<br />")}function f(o,e=null,n=null){if(o&&n)if(o=i(o),e){const t=n.querySelector(`[name="${e}"]`);if(!t){f(o,null,n);return}const a=document.createElement("div");a.className="fcom_field_error fcom_field_error",a.innerHTML=o;let _=t.closest(".fcom_form-group");_?(_.classList.add("fcom_has_error"),_.appendChild(a)):f(o,null,n)}else{const t=document.createElement("div");t.className="fcom_field_error fcom_global_error",t.innerHTML=o,n.appendChild(t)}}function c(o){o.querySelectorAll(".fcom_form_input input").forEach(function(e){e.addEventListener("blur",function(){e.closest(".fcom_form-group").classList.remove("fcom_has_error")})}),o.addEventListener("submit",function(e){e.preventDefault(),o.querySelectorAll(".fcom_field_error").forEach(function(r){r.remove()}),o.querySelectorAll(".fcom_has_error").forEach(function(r){r.classList.remove("fcom_has_error")});const t=o.querySelectorAll('button[type="submit"]');t.length&&t.forEach(function(r){r.classList.add("fcom_loading"),r.setAttribute("disabled","disabled")});const a=new FormData(o),_=new XMLHttpRequest;_.open("POST",window.fluentComRegistration.ajax_url,!0),_.send(a),_.onload=function(){if(t.length&&t.forEach(function(r){r.classList.remove("fcom_loading"),r.removeAttribute("disabled")}),_.status>=200&&_.status<300){let r=_.responseText;if(!r||r.indexOf("<!DOCTYPE html>")!==-1){f("Something went wrong. Please try again later",null,o),window.location.reload();return}const m=JSON.parse(_.responseText);if(m.verifcation_html){let p=o.getElementsByClassName("fcom_form_main_fields");p.length>0&&(p[0].style.display="none");let u=o.querySelector(".fcom_verification_wrap");u&&u.remove();let d=document.createElement("div");d.className="fcom_verification_wrap",d.innerHTML=m.verifcation_html,o.appendChild(d)}else if(m.redirect_url){window.location.href=m.redirect_url,submitButton.innerText="Redirecting...";return}else m.success_html?document.getElementById("fcom_user_onboard_wrap").innerHTML=m.success_html:f("Something went wrong. Please try again later",null,o)}else{const r=JSON.parse(_.responseText);if((!r||!r.message)&&f("Something went wrong. Please try again later",null,o),f(r.message,null,o),r.errors)for(const m in r.errors)f(r.errors[m],m,o)}}})}let l=document.getElementById("fcom_user_registration_form");l&&c(l);let s=document.getElementById("fcom_user_login_form");if(s&&c(s),window.fluentComRegistration&&window.fluentComRegistration.is_logged_in){const o=document.getElementById("fcom_user_accept_form");o&&o.addEventListener("submit",function(e){e.preventDefault();const n=o.querySelector('button[type="submit"]');n.classList.add("fcom_loading"),n.setAttribute("disabled","disabled"),o.querySelectorAll(".fcom_field_error").forEach(function(r){r.remove()});const a=new FormData(o),_=new XMLHttpRequest;_.open("POST",window.fluentComRegistration.ajax_url,!0),_.send(a),_.onload=function(){if(n.classList.remove("fcom_loading"),n.removeAttribute("disabled"),_.status>=200&&_.status<300){const r=JSON.parse(_.responseText);if(r.redirect_url){window.location.href=r.redirect_url,n.innerText="Redirecting...";return}else f("Something went wrong. Please try again later",null,o)}else{const r=JSON.parse(_.responseText);!r||!r.message?f("Something went wrong. Please try again later",null,o):f(r.message,null,o)}}})}});const h=`.fcom_user_onboard{max-width:600px;margin:0 auto;padding:40px 20px}.fcom_user_onboard .fcom_onboard_header{text-align:center;margin-bottom:20px}.fcom_user_onboard .fls_login_wrapper{width:100%;max-width:100%}.fcom_user_onboard .fcom_onboard_form{padding:20px 0;color:var(--fcom-primary-text, #333)}.fcom_user_onboard .fcom_onboard_form .fcom_form-group,.fcom_user_onboard .fcom_onboard_form .fs_input_wrap,.fcom_user_onboard .fcom_onboard_form .fls_login_wrapper{margin-bottom:20px}.fcom_user_onboard .fcom_onboard_form .fcom_form-group label,.fcom_user_onboard .fcom_onboard_form .fs_input_wrap label,.fcom_user_onboard .fcom_onboard_form .fls_login_wrapper label{display:block;font-size:16px;margin-bottom:5px;font-weight:500}.fcom_user_onboard .fcom_onboard_form .fcom_form-group input[type=text],.fcom_user_onboard .fcom_onboard_form .fcom_form-group input[type=email],.fcom_user_onboard .fcom_onboard_form .fcom_form-group input[type=password],.fcom_user_onboard .fcom_onboard_form .fcom_form-group .fls_magic_input,.fcom_user_onboard .fcom_onboard_form .fs_input_wrap input[type=text],.fcom_user_onboard .fcom_onboard_form .fs_input_wrap input[type=email],.fcom_user_onboard .fcom_onboard_form .fs_input_wrap input[type=password],.fcom_user_onboard .fcom_onboard_form .fs_input_wrap .fls_magic_input,.fcom_user_onboard .fcom_onboard_form .fls_login_wrapper input[type=text],.fcom_user_onboard .fcom_onboard_form .fls_login_wrapper input[type=email],.fcom_user_onboard .fcom_onboard_form .fls_login_wrapper input[type=password],.fcom_user_onboard .fcom_onboard_form .fls_login_wrapper .fls_magic_input{width:100%;padding:10px;font-size:16px;border:1px solid var(--fcom-primary-border, #ccc);border-radius:5px;background:transparent;color:var(--fcom-primary-text, #333);margin-top:0!important}.fcom_user_onboard .fcom_onboard_form .fcom_form-group input[type=text]:focus,.fcom_user_onboard .fcom_onboard_form .fcom_form-group input[type=email]:focus,.fcom_user_onboard .fcom_onboard_form .fcom_form-group input[type=password]:focus,.fcom_user_onboard .fcom_onboard_form .fcom_form-group .fls_magic_input:focus,.fcom_user_onboard .fcom_onboard_form .fs_input_wrap input[type=text]:focus,.fcom_user_onboard .fcom_onboard_form .fs_input_wrap input[type=email]:focus,.fcom_user_onboard .fcom_onboard_form .fs_input_wrap input[type=password]:focus,.fcom_user_onboard .fcom_onboard_form .fs_input_wrap .fls_magic_input:focus,.fcom_user_onboard .fcom_onboard_form .fls_login_wrapper input[type=text]:focus,.fcom_user_onboard .fcom_onboard_form .fls_login_wrapper input[type=email]:focus,.fcom_user_onboard .fcom_onboard_form .fls_login_wrapper input[type=password]:focus,.fcom_user_onboard .fcom_onboard_form .fls_login_wrapper .fls_magic_input:focus{border-color:var(--fcom-primary-text, #333)}.fcom_user_onboard .fcom_onboard_form .fcom_form-group input[type=text]:focus-visible,.fcom_user_onboard .fcom_onboard_form .fcom_form-group input[type=email]:focus-visible,.fcom_user_onboard .fcom_onboard_form .fcom_form-group input[type=password]:focus-visible,.fcom_user_onboard .fcom_onboard_form .fcom_form-group .fls_magic_input:focus-visible,.fcom_user_onboard .fcom_onboard_form .fs_input_wrap input[type=text]:focus-visible,.fcom_user_onboard .fcom_onboard_form .fs_input_wrap input[type=email]:focus-visible,.fcom_user_onboard .fcom_onboard_form .fs_input_wrap input[type=password]:focus-visible,.fcom_user_onboard .fcom_onboard_form .fs_input_wrap .fls_magic_input:focus-visible,.fcom_user_onboard .fcom_onboard_form .fls_login_wrapper input[type=text]:focus-visible,.fcom_user_onboard .fcom_onboard_form .fls_login_wrapper input[type=email]:focus-visible,.fcom_user_onboard .fcom_onboard_form .fls_login_wrapper input[type=password]:focus-visible,.fcom_user_onboard .fcom_onboard_form .fls_login_wrapper .fls_magic_input:focus-visible{outline:none}.fcom_user_onboard .fcom_onboard_form .fcom_form-group input[type=text]:disabled,.fcom_user_onboard .fcom_onboard_form .fcom_form-group input[type=text]:read-only,.fcom_user_onboard .fcom_onboard_form .fcom_form-group input[type=email]:disabled,.fcom_user_onboard .fcom_onboard_form .fcom_form-group input[type=email]:read-only,.fcom_user_onboard .fcom_onboard_form .fcom_form-group input[type=password]:disabled,.fcom_user_onboard .fcom_onboard_form .fcom_form-group input[type=password]:read-only,.fcom_user_onboard .fcom_onboard_form .fcom_form-group .fls_magic_input:disabled,.fcom_user_onboard .fcom_onboard_form .fcom_form-group .fls_magic_input:read-only,.fcom_user_onboard .fcom_onboard_form .fs_input_wrap input[type=text]:disabled,.fcom_user_onboard .fcom_onboard_form .fs_input_wrap input[type=text]:read-only,.fcom_user_onboard .fcom_onboard_form .fs_input_wrap input[type=email]:disabled,.fcom_user_onboard .fcom_onboard_form .fs_input_wrap input[type=email]:read-only,.fcom_user_onboard .fcom_onboard_form .fs_input_wrap input[type=password]:disabled,.fcom_user_onboard .fcom_onboard_form .fs_input_wrap input[type=password]:read-only,.fcom_user_onboard .fcom_onboard_form .fs_input_wrap .fls_magic_input:disabled,.fcom_user_onboard .fcom_onboard_form .fs_input_wrap .fls_magic_input:read-only,.fcom_user_onboard .fcom_onboard_form .fls_login_wrapper input[type=text]:disabled,.fcom_user_onboard .fcom_onboard_form .fls_login_wrapper input[type=text]:read-only,.fcom_user_onboard .fcom_onboard_form .fls_login_wrapper input[type=email]:disabled,.fcom_user_onboard .fcom_onboard_form .fls_login_wrapper input[type=email]:read-only,.fcom_user_onboard .fcom_onboard_form .fls_login_wrapper input[type=password]:disabled,.fcom_user_onboard .fcom_onboard_form .fls_login_wrapper input[type=password]:read-only,.fcom_user_onboard .fcom_onboard_form .fls_login_wrapper .fls_magic_input:disabled,.fcom_user_onboard .fcom_onboard_form .fls_login_wrapper .fls_magic_input:read-only{background:#f6f7f7}.fcom_user_onboard .fcom_onboard_form .fcom_form-group .fcom_field_error,.fcom_user_onboard .fcom_onboard_form .fs_input_wrap .fcom_field_error,.fcom_user_onboard .fcom_onboard_form .fls_login_wrapper .fcom_field_error{display:none}.fcom_user_onboard .fcom_onboard_form .fcom_form-group.fcom_has_error input[type=text],.fcom_user_onboard .fcom_onboard_form .fcom_form-group.fcom_has_error input[type=email],.fcom_user_onboard .fcom_onboard_form .fcom_form-group.fcom_has_error input[type=password],.fcom_user_onboard .fcom_onboard_form .fs_input_wrap.fcom_has_error input[type=text],.fcom_user_onboard .fcom_onboard_form .fs_input_wrap.fcom_has_error input[type=email],.fcom_user_onboard .fcom_onboard_form .fs_input_wrap.fcom_has_error input[type=password],.fcom_user_onboard .fcom_onboard_form .fls_login_wrapper.fcom_has_error input[type=text],.fcom_user_onboard .fcom_onboard_form .fls_login_wrapper.fcom_has_error input[type=email],.fcom_user_onboard .fcom_onboard_form .fls_login_wrapper.fcom_has_error input[type=password]{border-color:#f56565}.fcom_user_onboard .fcom_onboard_form .fcom_form-group.fcom_has_error .fcom_field_error,.fcom_user_onboard .fcom_onboard_form .fs_input_wrap.fcom_has_error .fcom_field_error,.fcom_user_onboard .fcom_onboard_form .fls_login_wrapper.fcom_has_error .fcom_field_error{display:block}.fcom_user_onboard .fcom_onboard_form .fcom_form-group .fcom_inline_checkbox,.fcom_user_onboard .fcom_onboard_form .fs_input_wrap .fcom_inline_checkbox,.fcom_user_onboard .fcom_onboard_form .fls_login_wrapper .fcom_inline_checkbox{display:flex;align-items:center;margin-top:10px}.fcom_user_onboard .fcom_onboard_form .fcom_form-group .fcom_inline_checkbox input[type=checkbox],.fcom_user_onboard .fcom_onboard_form .fs_input_wrap .fcom_inline_checkbox input[type=checkbox],.fcom_user_onboard .fcom_onboard_form .fls_login_wrapper .fcom_inline_checkbox input[type=checkbox]{margin-right:10px}.fcom_user_onboard .fcom_onboard_form .fcom_form-group .fcom_inline_checkbox label,.fcom_user_onboard .fcom_onboard_form .fs_input_wrap .fcom_inline_checkbox label,.fcom_user_onboard .fcom_onboard_form .fls_login_wrapper .fcom_inline_checkbox label{margin:0;font-weight:400}.fcom_user_onboard .fcom_onboard_form .fcom_field_error{color:#f56565;font-weight:400;font-size:90%;margin-top:5px;line-height:1.2}.fcom_completed{text-align:center;padding:20px 0}.fcom_completed h2{font-size:24px;margin-bottom:10px;margin-top:0;line-height:1.2}.fcom_completed a{text-decoration:none}.fcom_completed .fcom_complted_header{margin-bottom:20px}.fcom_native_login form>p{margin-bottom:20px}.fcom_native_login form>p label{display:block;font-size:16px;margin-bottom:5px;font-weight:500}.fcom_native_login form>p input[type=text],.fcom_native_login form>p input[type=email],.fcom_native_login form>p input[type=password]{width:100%;padding:7px 10px;font-size:16px;border:1px solid #ccc;border-radius:5px}.fcom_spaced_divider{margin:30px 0;position:relative;padding:30px 0;border-top:1px solid #ebeef5}.fcom_full_layout{min-height:100vh;display:flex}@media (max-width: 768px){.fcom_full_layout{flex-direction:column}}.fcom_full_layout *{box-sizing:border-box}.fcom_full_layout>div{flex:1}.fcom_full_layout .fcom_layout_side{display:flex;align-items:center;background:var(--fcom_background_color, #f6f7f7);background-size:cover;background-position:center;background-blend-mode:multiply}.fcom_full_layout .fcom_layout_side .fcom_welcome{padding:20px;text-align:center;margin:0 auto;max-width:800px}.fcom_full_layout .fcom_layout_side .fcom_welcome img{max-width:200px;max-height:auto}.fcom_full_layout .fcom_layout_side .fcom_welcome h2{font-size:24px;margin-bottom:10px;margin-top:8px;line-height:1.2;color:var(--fcom_title_color, #333)}.fcom_full_layout .fcom_layout_side .fcom_welcome .fcom_sub_title{color:var(--fcom_text_color, #606266)}.fcom_full_layout .fcom_layout_side .fcom_welcome p{font-size:16px;margin-bottom:0;color:var(--fcom_text_color, #606266)}.fcom_layout_main{background:var(--fcom-primary-bg, white);color:var(--fcom-primary-text, #19283a);display:flex;align-items:center;justify-content:center;padding:20px}.fcom_layout_main .fcom_onboard_header h2{color:var(--fcom-primary-text, #19283a);font-size:24px;line-height:1.4;margin:0 0 10px}.fcom_layout_main .fcom_onboard_header .fcom_onboard_sub{color:var(--fcom-primary-text, #19283a)}.fcom_layout_main .fcom_onboard_header .fcom_onboard_sub p{margin:0 0 10px}.fcom_layout_main .fcom_onboard_body .fcom_onboard_form #wp-submit,.fcom_layout_main .fcom_onboard_body .fcom_onboard_form .fcom_btn_submit,.fcom_layout_main .fcom_onboard_body .fcom_onboard_form .fls_magic_submit_wrapper #fls_magic_submit,.fcom_layout_main .fcom_onboard_body .fcom_onboard_form .fls_reset_pass_wrapper .fls_reset_pass_form #fls_reset_pass,.fcom_layout_main .fcom_onboard_body .fcom_onboard_form #fls_verification_submit,.fcom_layout_main .fcom_onboard_body .fcom_onboard_form .fcom_btn_primary{padding-inline:0;color:var(--fcom-primary-button-text, var(--fcom-primary-bg, #FFFFFF));background:var(--fcom-primary-button, #2B2E33);border:1px solid var(--fcom-primary-button, #2B2E33);transition:all .3s ease;text-decoration:none;gap:8px;padding:12px 25px;border-radius:8px;margin:0;cursor:pointer;line-height:1}.fcom_layout_main .fcom_onboard_body .fcom_onboard_form #wp-submit:hover,.fcom_layout_main .fcom_onboard_body .fcom_onboard_form .fcom_btn_submit:hover,.fcom_layout_main .fcom_onboard_body .fcom_onboard_form .fls_magic_submit_wrapper #fls_magic_submit:hover,.fcom_layout_main .fcom_onboard_body .fcom_onboard_form .fls_reset_pass_wrapper .fls_reset_pass_form #fls_reset_pass:hover,.fcom_layout_main .fcom_onboard_body .fcom_onboard_form #fls_verification_submit:hover,.fcom_layout_main .fcom_onboard_body .fcom_onboard_form .fcom_btn_primary:hover{opacity:.8}.fcom_layout_main .fcom_onboard_body .fcom_onboard_form button.has_svg_loader,.fcom_layout_main .fcom_onboard_body .fcom_onboard_form #fls_verification_submit{display:flex}.fcom_layout_main .fcom_onboard_body .fcom_onboard_form button.has_svg_loader .fls_loading_svg,.fcom_layout_main .fcom_onboard_body .fcom_onboard_form #fls_verification_submit .fls_loading_svg{display:none;width:1em;height:1em}.fcom_layout_main .fcom_onboard_body .fcom_onboard_form button.has_svg_loader.fcom_loading,.fcom_layout_main .fcom_onboard_body .fcom_onboard_form #fls_verification_submit.fcom_loading{opacity:.8}.fcom_layout_main .fcom_onboard_body .fcom_onboard_form button.has_svg_loader.fcom_loading .fls_loading_svg,.fcom_layout_main .fcom_onboard_body .fcom_onboard_form #fls_verification_submit.fcom_loading .fls_loading_svg{display:inline-block}.fcom_layout_main a{color:var(--fcom-text-link, "#2271b1")}.fcom_social_auth_wrap .fm_login_wrapper{padding:0 0 20px;margin:0 0 20px;border-bottom:1px solid var(--fcom-primary-border, #e4e7eb);max-width:100%!important}.fls_magic_login_btn .magic_btn_secondary,.fm_buttons_wrap .fs_auth_btn,.fm_buttons_wrap .fs_auth_btn.fs_auth_google,.magic_back_regular .magic_btn_secondary{cursor:pointer;border:none;border-radius:8px;width:100%;padding:8px;color:var(--fcom-menu-text-active, var(--fcom-menu-text, #545861));background:var(--fcom-active-bg, #f0f3f5);border:1px solid var(--fcom-active-border, #e4e7eb);transition:all .3s ease}.fls_magic_login_btn .magic_btn_secondary svg,.fm_buttons_wrap .fs_auth_btn svg,.fm_buttons_wrap .fs_auth_btn.fs_auth_google svg,.magic_back_regular .magic_btn_secondary svg{color:var(--fcom-menu-text-active, var(--fcom-menu-text, #545861))!important;fill:var(--fcom-menu-text-active, var(--fcom-menu-text, #545861))!important}.fls_magic_login_btn .magic_btn_secondary:hover,.fm_buttons_wrap .fs_auth_btn:hover,.fm_buttons_wrap .fs_auth_btn.fs_auth_google:hover,.magic_back_regular .magic_btn_secondary:hover{color:var(--fcom-menu-text-active, var(--fcom-menu-text, #545861))!important;background:var(--fcom-active-bg, #f0f3f5)!important;border:1px solid var(--fcom-primary-button, #2B2E33)!important}.fls_magic_login_btn .magic_btn_secondary:hover svg,.fm_buttons_wrap .fs_auth_btn:hover svg,.fm_buttons_wrap .fs_auth_btn.fs_auth_google:hover svg,.magic_back_regular .magic_btn_secondary:hover svg{color:var(--fcom-menu-text-active, var(--fcom-menu-text, #545861));fill:var(--fcom-menu-text-active, var(--fcom-menu-text, #545861))}.fcom_highlight_message{padding:10px;border:1px solid var(--fcom-primary-border);border-radius:5px;margin:0 0 10px;background:var(--fcom-highlight-bg, #f0f3f5)}
`,v=Object.freeze(Object.defineProperty({__proto__:null,default:h},Symbol.toStringTag,{value:"Module"}));
