var E=(e,s,n)=>{if(!s.has(e))throw TypeError("Cannot "+n)};var l=(e,s,n)=>(E(e,s,"read from private field"),n?n.call(e):s.get(e)),I=(e,s,n)=>{if(s.has(e))throw TypeError("Cannot add the same private member more than once");s instanceof WeakSet?s.add(e):s.set(e,n)},T=(e,s,n,t)=>(E(e,s,"write to private field"),t?t.call(e,n):s.set(e,n),n);import{d as z}from"./index6.js?ver=2.4.0";import{b4 as H,ca as N,b8 as P,b5 as S,b9 as b,az as B}from"./vendor.js?ver=2.4.0";import{g as D,j as G,s as M,d as K,l as U}from"./todoList.js?ver=2.4.0";import{y as V,R as _,z as q,A as J,B as A,L as C,C as v,D as Q,E as W,F as X,G as Y,I as F,K as Z,M as x}from"./milkdown.js?ver=2.4.0";import"./vue.js?ver=2.4.0";import"./dayjs.js?ver=2.4.0";import"./lodash.js?ver=2.4.0";const j=({ctx:e,hide:s,show:n,config:t})=>{var h,p,y,k,w;const c=N();P(()=>{c()},[n]);const o=a=>m=>{m.preventDefault(),e&&a(e),c()},i=a=>{if(!e)return!1;const m=e.get(C),{state:{doc:u,selection:$}}=m;return u.rangeHasMark($.from,$.to,a)};return S`<host>
    <button
      class=${b("toolbar-item",e&&i(V.type(e))&&"active")}
      onmousedown=${o(a=>{a.get(v).call(Q.key)})}
    >
      ${((h=t==null?void 0:t.boldIcon)==null?void 0:h.call(t))??D}
    </button>
    <button
      class=${b("toolbar-item",e&&i(_.type(e))&&"active")}
      onmousedown=${o(a=>{a.get(v).call(W.key)})}
    >
      ${((p=t==null?void 0:t.italicIcon)==null?void 0:p.call(t))??G}
    </button>
    <button
      class=${b("toolbar-item",e&&i(q.type(e))&&"active")}
      onmousedown=${o(a=>{a.get(v).call(X.key)})}
    >
      ${((y=t==null?void 0:t.strikethroughIcon)==null?void 0:y.call(t))??M}
    </button>
    <div class="divider"></div>
    <button
      class=${b("toolbar-item",e&&i(J.type(e))&&"active")}
      onmousedown=${o(a=>{a.get(v).call(Y.key)})}
    >
      ${((k=t==null?void 0:t.codeIcon)==null?void 0:k.call(t))??K}
    </button>
    <button
      class=${b("toolbar-item",e&&i(A.type(e))&&"active")}
      onmousedown=${o(a=>{const m=a.get(C),{selection:u}=m.state;if(i(A.type(a))){a.get(F.key).removeLink(u.from,u.to);return}a.get(F.key).addLink(u.from,u.to),s==null||s()})}
    >
      ${((w=t==null?void 0:t.linkIcon)==null?void 0:w.call(t))??U}
    </button>
  </host>`};j.props={ctx:Object,hide:Function,show:Boolean,config:Object};const L=H(j),R=Z("CREPE_TOOLBAR");var d,r;class f{constructor(s,n,t){I(this,d,void 0);I(this,r,void 0);this.update=(o,i)=>{l(this,d).update(o,i)},this.destroy=()=>{l(this,d).destroy(),l(this,r).remove()},this.hide=()=>{l(this,d).hide()};const c=new L;T(this,r,c),l(this,r).ctx=s,l(this,r).hide=this.hide,l(this,r).config=t,T(this,d,new x({content:l(this,r),debounce:20,offset:10,shouldShow(o){const{doc:i,selection:h}=o.state,{empty:p,from:y,to:k}=h,w=!i.textBetween(y,k).length&&h instanceof B,a=!(h instanceof B),m=o.dom.getRootNode().activeElement,u=c.contains(m),$=!o.hasFocus()&&!u,O=!o.editable;return!($||a||p||w||O)}})),l(this,d).onShow=()=>{l(this,r).show=!0,setTimeout(()=>{let o=l(this,r).style.left;o=parseInt(o),console.log(o),o<0?(l(this,r).style.left=0,console.log(l(this,r))):o>350&&(l(this,r).style.left="350px")},0)},l(this,d).onHide=()=>{l(this,r).show=!1},this.update(n)}}d=new WeakMap,r=new WeakMap;z("milkdown-toolbar",L);const rt=(e,s)=>{e.config(n=>{n.set(R.key,{view:t=>new f(n,t,s)})}).use(R)};export{rt as defineFeature};
