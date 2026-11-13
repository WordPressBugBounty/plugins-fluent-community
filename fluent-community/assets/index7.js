var E=t=>{throw TypeError(t)};var B=(t,s,a)=>s.has(t)||E("Cannot "+a);var l=(t,s,a)=>(B(t,s,"read from private field"),a?a.call(t):s.get(t)),I=(t,s,a)=>s.has(t)?E("Cannot add the same private member more than once"):s instanceof WeakSet?s.add(t):s.set(t,a),T=(t,s,a,e)=>(B(t,s,"write to private field"),e?e.call(t,a):s.set(t,a),a);import{d as N}from"./index4.js?ver=2.14.0";import{a9 as P,al as S,ad as z,ae as p,aa as D,T as R}from"./vendor.js?ver=2.14.0";import{b as G,d as M,s as U,f as V,l as _}from"./todoList.js?ver=2.14.0";import{$ as q,R as J,T as K,x as Q,B as j,L as A,j as v,n as W,t as X,z as Y,g as Z,k as C,G as x,A as f}from"./milkdown.js?ver=2.14.0";import"./vue.js?ver=2.14.0";import"./dayjs.js?ver=2.14.0";import"./fcom-utils.js?ver=2.14.0";const L=({ctx:t,hide:s,show:a,config:e})=>{var h,b,y,k,w;const c=S();z(()=>{c()},[a]);const n=o=>m=>{m.preventDefault(),t&&o(t),c()},d=o=>{if(!t)return!1;const m=t.get(A),{state:{doc:u,selection:$}}=m;return u.rangeHasMark($.from,$.to,o)};return D`<host>
    <button
      class=${p("toolbar-item",t&&d(q.type(t))&&"active")}
      onmousedown=${n(o=>{o.get(v).call(W.key)})}
    >
      ${((h=e==null?void 0:e.boldIcon)==null?void 0:h.call(e))??G}
    </button>
    <button
      class=${p("toolbar-item",t&&d(J.type(t))&&"active")}
      onmousedown=${n(o=>{o.get(v).call(X.key)})}
    >
      ${((b=e==null?void 0:e.italicIcon)==null?void 0:b.call(e))??M}
    </button>
    <button
      class=${p("toolbar-item",t&&d(K.type(t))&&"active")}
      onmousedown=${n(o=>{o.get(v).call(Y.key)})}
    >
      ${((y=e==null?void 0:e.strikethroughIcon)==null?void 0:y.call(e))??U}
    </button>
    <div class="divider"></div>
    <button
      class=${p("toolbar-item",t&&d(Q.type(t))&&"active")}
      onmousedown=${n(o=>{o.get(v).call(Z.key)})}
    >
      ${((k=e==null?void 0:e.codeIcon)==null?void 0:k.call(e))??V}
    </button>
    <button
      class=${p("toolbar-item",t&&d(j.type(t))&&"active")}
      onmousedown=${n(o=>{const m=o.get(A),{selection:u}=m.state;if(d(j.type(o))){o.get(C.key).removeLink(u.from,u.to);return}o.get(C.key).addLink(u.from,u.to),s==null||s()})}
    >
      ${((w=e==null?void 0:e.linkIcon)==null?void 0:w.call(e))??_}
    </button>
  </host>`};L.props={ctx:Object,hide:Function,show:Boolean,config:Object};const O=P(L),F=x("CREPE_TOOLBAR");var i,r;class g{constructor(s,a,e){I(this,i);I(this,r);this.update=(n,d)=>{l(this,i).update(n,d)},this.destroy=()=>{l(this,i).destroy(),l(this,r).remove()},this.hide=()=>{l(this,i).hide()};const c=new O;T(this,r,c),l(this,r).ctx=s,l(this,r).hide=this.hide,l(this,r).config=e,T(this,i,new f({content:l(this,r),debounce:20,offset:10,shouldShow(n){const{doc:d,selection:h}=n.state,{empty:b,from:y,to:k}=h,w=!d.textBetween(y,k).length&&h instanceof R,o=!(h instanceof R),m=n.dom.getRootNode().activeElement,u=c.contains(m),$=!n.hasFocus()&&!u,H=!n.editable;return!($||o||b||w||H)}})),l(this,i).onShow=()=>{l(this,r).show=!0,setTimeout(()=>{let n=l(this,r).style.left;n=parseInt(n),n<0?l(this,r).style.left=0:n>350&&(l(this,r).style.left="350px")},0)},l(this,i).onHide=()=>{l(this,r).show=!1},this.update(a)}}i=new WeakMap,r=new WeakMap;N("milkdown-toolbar",O);const mt=(t,s)=>{t.config(a=>{a.set(F.key,{view:e=>new g(a,e,s)})}).use(F)};export{mt as defineFeature};
