var E=(e,s,a)=>{if(!s.has(e))throw TypeError("Cannot "+a)};var l=(e,s,a)=>(E(e,s,"read from private field"),a?a.call(e):s.get(e)),I=(e,s,a)=>{if(s.has(e))throw TypeError("Cannot add the same private member more than once");s instanceof WeakSet?s.add(e):s.set(e,a)},T=(e,s,a,t)=>(E(e,s,"write to private field"),t?t.call(e,a):s.set(e,a),a);import{d as H}from"./index4.js?ver=2.13.0";import{aa as N,an as P,ae as S,ab as z,af as p,T as B}from"./vendor.js?ver=2.13.0";import{b as D,d as G,s as M,f as U,l as V}from"./todoList.js?ver=2.13.0";import{$ as _,R as q,T as J,x as K,B as R,L as j,j as v,n as Q,t as W,z as X,g as Y,k as A,G as Z,A as x}from"./milkdown.js?ver=2.13.0";import"./vue.js?ver=2.13.0";import"./dayjs.js?ver=2.13.0";import"./fcom-utils.js?ver=2.13.0";const F=({ctx:e,hide:s,show:a,config:t})=>{var h,b,y,k,w;const c=P();S(()=>{c()},[a]);const n=o=>m=>{m.preventDefault(),e&&o(e),c()},i=o=>{if(!e)return!1;const m=e.get(j),{state:{doc:u,selection:$}}=m;return u.rangeHasMark($.from,$.to,o)};return z`<host>
    <button
      class=${p("toolbar-item",e&&i(_.type(e))&&"active")}
      onmousedown=${n(o=>{o.get(v).call(Q.key)})}
    >
      ${((h=t==null?void 0:t.boldIcon)==null?void 0:h.call(t))??D}
    </button>
    <button
      class=${p("toolbar-item",e&&i(q.type(e))&&"active")}
      onmousedown=${n(o=>{o.get(v).call(W.key)})}
    >
      ${((b=t==null?void 0:t.italicIcon)==null?void 0:b.call(t))??G}
    </button>
    <button
      class=${p("toolbar-item",e&&i(J.type(e))&&"active")}
      onmousedown=${n(o=>{o.get(v).call(X.key)})}
    >
      ${((y=t==null?void 0:t.strikethroughIcon)==null?void 0:y.call(t))??M}
    </button>
    <div class="divider"></div>
    <button
      class=${p("toolbar-item",e&&i(K.type(e))&&"active")}
      onmousedown=${n(o=>{o.get(v).call(Y.key)})}
    >
      ${((k=t==null?void 0:t.codeIcon)==null?void 0:k.call(t))??U}
    </button>
    <button
      class=${p("toolbar-item",e&&i(R.type(e))&&"active")}
      onmousedown=${n(o=>{const m=o.get(j),{selection:u}=m.state;if(i(R.type(o))){o.get(A.key).removeLink(u.from,u.to);return}o.get(A.key).addLink(u.from,u.to),s==null||s()})}
    >
      ${((w=t==null?void 0:t.linkIcon)==null?void 0:w.call(t))??V}
    </button>
  </host>`};F.props={ctx:Object,hide:Function,show:Boolean,config:Object};const L=N(F),C=Z("CREPE_TOOLBAR");var d,r;class f{constructor(s,a,t){I(this,d,void 0);I(this,r,void 0);this.update=(n,i)=>{l(this,d).update(n,i)},this.destroy=()=>{l(this,d).destroy(),l(this,r).remove()},this.hide=()=>{l(this,d).hide()};const c=new L;T(this,r,c),l(this,r).ctx=s,l(this,r).hide=this.hide,l(this,r).config=t,T(this,d,new x({content:l(this,r),debounce:20,offset:10,shouldShow(n){const{doc:i,selection:h}=n.state,{empty:b,from:y,to:k}=h,w=!i.textBetween(y,k).length&&h instanceof B,o=!(h instanceof B),m=n.dom.getRootNode().activeElement,u=c.contains(m),$=!n.hasFocus()&&!u,O=!n.editable;return!($||o||b||w||O)}})),l(this,d).onShow=()=>{l(this,r).show=!0,setTimeout(()=>{let n=l(this,r).style.left;n=parseInt(n),n<0?l(this,r).style.left=0:n>350&&(l(this,r).style.left="350px")},0)},l(this,d).onHide=()=>{l(this,r).show=!1},this.update(a)}}d=new WeakMap,r=new WeakMap;H("milkdown-toolbar",L);const rt=(e,s)=>{e.config(a=>{a.set(C.key,{view:t=>new f(a,t,s)})}).use(C)};export{rt as defineFeature};
