const CACHE='rishe-event-__RISHE_VERSION__';
const APP='__RISHE_APP_URL__';
const ASSET='__RISHE_ASSET_URL__';
const SHELL=[APP,ASSET+'app.css?ver=__RISHE_VERSION__',ASSET+'pos-v23.css?ver=__RISHE_VERSION__',ASSET+'app.js?ver=__RISHE_VERSION__',ASSET+'icon.svg'];
self.addEventListener('install',event=>event.waitUntil(caches.open(CACHE).then(cache=>cache.addAll(SHELL)).then(()=>self.skipWaiting())));
self.addEventListener('activate',event=>event.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(k=>k.startsWith('rishe-event-')&&k!==CACHE).map(k=>caches.delete(k)))).then(()=>self.clients.claim())));
self.addEventListener('fetch',event=>{const req=event.request,url=new URL(req.url);if(req.method!=='GET')return;if(url.pathname.includes('/wp-json/'))return;if(url.pathname.startsWith(new URL(APP).pathname)||url.href.startsWith(ASSET)){event.respondWith(fetch(req).then(res=>{const copy=res.clone();caches.open(CACHE).then(c=>c.put(req,copy));return res}).catch(()=>caches.match(req).then(hit=>hit||caches.match(APP))))}});
