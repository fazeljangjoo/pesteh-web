(function(){
var $=function(s,r){return[].slice.call((r||document).querySelectorAll(s))},ua=navigator.userAgent||'',
os=/Android/i.test(ua)?'android':(/iPhone|iPad|iPod/i.test(ua)||(/Macintosh/i.test(ua)&&navigator.maxTouchPoints>1))?'iphone':/Windows/i.test(ua)?'windows':'web',
root=document.getElementById('root'),still=matchMedia('(prefers-reduced-motion:reduce)').matches,ids=['android','iphone','windows','web'];
function show(o,keep){
 if(ids.indexOf(o)<0)o=os;if(!root)return;root.setAttribute('data-os',o);
 $('.tab').forEach(function(t){t.setAttribute('aria-selected',t.dataset.os===o)});
 $('.panel').forEach(function(p){p.hidden=p.dataset.os!==o});
 $('.dv').forEach(function(d){d.classList.toggle('on',d.dataset.os===o)});
 var b=document.getElementById('barDl');if(b){var a=$('.panel:not([hidden]) .big')[0];if(a){b.href=a.href;b.textContent=a.dataset.bar||'دانلود رایگان';if(a.hasAttribute('download'))b.setAttribute('download','');else b.removeAttribute('download')}}
 $('.steps>li').forEach(function(l,i){l.classList.toggle('on',i===0)});
 if(!keep&&history.replaceState)history.replaceState(null,'','#'+o);
}
if(root){
 $('.tab').forEach(function(t,i,all){
  t.addEventListener('click',function(){show(t.dataset.os)});
  t.addEventListener('keydown',function(e){var n=e.key==='ArrowLeft'?i+1:e.key==='ArrowRight'?i-1:null;if(n===null)return;n=(n+all.length)%all.length;all[n].focus();show(all[n].dataset.os);e.preventDefault()});
 });
 var h=(location.hash||'').slice(1);show(ids.indexOf(h)>-1?h:os,true);
 addEventListener('hashchange',function(){var h=(location.hash||'').slice(1);if(ids.indexOf(h)>-1)show(h,true)});
 $('[data-os-hint]').forEach(function(e){e.hidden=e.dataset.osHint!==os});
}
/* tilt پوستهٔ سه‌بعدی با ماوس/لمس */
var sc=document.querySelector('.scene'),st=document.querySelector('.stack');
if(sc&&st&&!still){
 function mv(e){var r=sc.getBoundingClientRect(),x=(e.clientX-r.left)/r.width-.5,y=(e.clientY-r.top)/r.height-.5;st.classList.add('on');st.style.setProperty('--ry',-x*44+'deg');st.style.setProperty('--rx',y*24+'deg')}
 sc.addEventListener('pointermove',mv);sc.addEventListener('pointerdown',mv);
 sc.addEventListener('pointerleave',function(){st.classList.remove('on');st.style.removeProperty('--ry');st.style.removeProperty('--rx')});
}
if(!still&&matchMedia('(hover:hover)').matches)$('.cd').forEach(function(c){
 c.addEventListener('pointermove',function(e){var r=c.getBoundingClientRect();c.style.setProperty('--ty',((e.clientX-r.left)/r.width-.5)*10+'deg');c.style.setProperty('--tx',-((e.clientY-r.top)/r.height-.5)*10+'deg')});
 c.addEventListener('pointerleave',function(){c.style.removeProperty('--ty');c.style.removeProperty('--tx')});
});
/* ظاهر شدن با اسکرول */
if('IntersectionObserver' in window){var io=new IntersectionObserver(function(es){es.forEach(function(e){if(e.isIntersecting){e.target.classList.add('in');io.unobserve(e.target)}})},{threshold:.12});$('.rv').forEach(function(e){io.observe(e)})}else $('.rv').forEach(function(e){e.classList.add('in')});
/* همگام‌سازی مرحله‌ها با گوشی چسبان (صفحهٔ آموزش) */
var shots=$('.stk .ph img');
if(shots.length&&'IntersectionObserver' in window){
 var so=new IntersectionObserver(function(es){es.forEach(function(e){if(!e.isIntersecting)return;var l=e.target;$('.steps>li',l.parentNode).forEach(function(x){x.classList.toggle('on',x===l)});var i=+l.dataset.s,pn=l.closest('.panel'),sh2=pn?$('.stk .ph img',pn):[];if(!isNaN(i))sh2.forEach(function(m,k){m.classList.toggle('on',k===i%sh2.length)});var s=l.querySelector('.sm');if(s){s.classList.remove('s2');setTimeout(function(){s.classList.add('s2')},1800)}})},{rootMargin:'-40% 0px -40% 0px'});
 $('.steps>li').forEach(function(l){so.observe(l)});
}
/* بعد از زدن دانلود ویندوز: قدم بعدی */
$('[data-after]').forEach(function(a){a.addEventListener('click',function(){var n=document.getElementById(a.dataset.after);if(n)n.classList.add('on')})});
var sh=document.getElementById('share');
if(sh)sh.addEventListener('click',function(){var d={title:'پسته بازار',text:'پسته بازار: برنامهٔ حساب‌وکتاب خرید، فروش و انبار پسته. ۱۰ روز رایگان.',url:'https://pesteh-bazar.ir/download.html'};
 if(navigator.share)navigator.share(d).catch(function(){});else if(navigator.clipboard)navigator.clipboard.writeText(d.url).then(function(){sh.textContent='✓ لینک کپی شد'});else prompt('لینک را کپی کنید:',d.url)});
})();
