'use strict';

// Plain browser JavaScript. No packages, tokens, proxy or server.
const $ = id => document.getElementById(id);
const escapeHTML = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const safeURL = value => {try {const url = new URL(value); return url.protocol === 'https:' ? url.href : '';} catch {return '';}};
const STORAGE_KEY = 'trendradar.public.v1';
const SNAPSHOT_READ_AT = '2026-11-06T09:19:22Z';
const shortDate = value => new Intl.DateTimeFormat('nl-NL', {day:'numeric',month:'short',timeZone:'Europe/Amsterdam'}).format(new Date(value));
const fullDate = value => new Intl.DateTimeFormat('nl-NL', {day:'numeric',month:'long',year:'numeric',hour:'2-digit',minute:'2-digit',timeZone:'Europe/Amsterdam'}).format(new Date(value));
const formatNumber = value => new Intl.NumberFormat('nl-NL',{notation:'compact',maximumFractionDigits:1}).format(value);

const platforms = [
  {id:'playstore', name:'Google Play', symbol:'▷', color:'#a4dcc2', bg:'#19352f', metric:'downloads', region:'global', method:'snapshot', source:'Google Play', url:'https://play.google.com/store/apps', docs:'https://play.google.com/store/apps/details?id=com.instagram.android&hl=nl', description:'Openbare downloadondergrenzen op de Google Play-pagina’s van vier geselecteerde Android-apps. Momentopname geraadpleegd op 6 november 2026. Een waarde zoals 5 miljard+ is de door Google Play getoonde ondergrens, geen exact aantal, geen dag- of maandtotaal en geen aantal actieve gebruikers. De posities zijn alleen de volgorde binnen deze selectie, geen algemene toplijst. Apple publiceert in de gebruikte RSS-feed geen downloadtotaal.', unit:'downloads Android (min.)'},
  {id:'steam', name:'Steam', symbol:'◉', color:'#9bcce3', bg:'#1b3041', metric:'activity', region:'global', method:'live', source:'Steam Charts / Web API', url:'https://store.steampowered.com/charts/mostplayed', docs:'https://partner.steamgames.com/doc/webapi/ISteamUserStats#GetNumberOfCurrentPlayers', description:'Totaal gelijktijdig actieve, met Steam verbonden spelers voor vier geselecteerde games. Geen downloads, geen unieke dag- of maandgebruikers en geen spelersprofielen. De positie is binnen de geselecteerde games, geen volledige Steam-top. Als het rechtstreekse browserverzoek niet werkt, blijft een duidelijk gedateerde momentopname van de officiële Steam Charts of een eerder opgehaalde meting zichtbaar.', unit:'spelers gelijktijdig online'},

  {id:'x', name:'X / Twitter', symbol:'𝕏', color:'#d4d7e2', bg:'#2b2d38', metric:'trending', region:'NL', method:'snapshot', source:'Trends24', url:'https://trends24.in/netherlands/', docs:'https://docs.x.com/x-api/trends/trends-by-woeid/introduction', description:'Selectie van openbare trendonderwerpen uit de Nederlandse tijdlijn van Trends24. De oorspronkelijke bronpositie blijft staan; persoonsnamen zijn uit deze meegeleverde selectie weggelaten. Er zijn geen bevestigde aantallen views of posts overgenomen. De officiële X-trend-API vereist een developeraccount en bearer token.', unit:'trendpositie'},
  {id:'instagram', name:'Instagram', symbol:'◎', color:'#ee9fca', bg:'#352036', metric:'trending', region:null, method:'external', source:'Instagram Explore', url:'https://www.instagram.com/explore/', docs:'https://www.postman.com/meta/instagram/documentation/6yqw8pt/instagram-api', description:'Deze versie heeft geen verifieerbare algemene ranglijst van de meest bekeken Instagram-posts. Explore is een ontdekpagina die kan verschillen per gebruiker en een login kan vragen. De officiële API is gericht op professionele accounts; toegang daartoe is niet gekoppeld.', emptyTitle:'Instagram, met de juiste context.', emptyText:'Er is hier geen betrouwbare algemene top van Instagram beschikbaar. Je kunt Explore bij Instagram openen. Die pagina kan persoonlijk samengesteld zijn en is dus geen objectieve wereldwijde toplijst. We vullen ontbrekende views of rangposities niet zelf in.', unit:'niet beschikbaar'},
  {id:'youtube', name:'YouTube', symbol:'▶', color:'#ff9eaa', bg:'#38202b', metric:'views', region:'NL', method:'snapshot', source:'YouTube Trends24', url:'https://youtube.trends24.in/netherlands/', docs:'https://developers.google.com/youtube/data/guides/implementation/videos', description:'Selectie van zes niet-persoonlijke video-onderwerpen uit de Nederlandse lijst van YouTube Trends24. De omschrijvingen zijn verkort in het Nederlands. Views zijn afgeronde wereldwijde kijktotalen van die video’s, geen Nederlandse dagtotalen. De bronvolgorde is geen sortering op views. Dit is geen volledige of officiële YouTube-toplijst. Automatisch ophalen met de officiële YouTube Data API vereist een API-key.', unit:'views totaal (≈)'},
  {id:'tiktok', name:'TikTok', symbol:'♪', color:'#8fdcdd', bg:'#173338', metric:'trending', region:null, method:'external', source:'TikTok Creative Center', url:'https://ads.tiktok.com/creative/creativeCenter/trends', docs:'https://business-api.tiktok.com/gateway/docs/index?doc_id=1825119005427714', description:'TikTok Creative Center biedt trends zoals hashtags. De lijst wordt in deze losse HTML-versie niet automatisch ingelezen. Beschikbare filters en toegang worden door TikTok bepaald.', emptyTitle:'Ontdek TikTok bij de bron.', emptyText:'Het Creative Center is de officiële plek voor beschikbare TikTok-trends, zoals populaire hashtags. Deze website leest die lijsten niet automatisch uit. Bij de bron kunnen extra filters of een login nodig zijn. Er worden hier geen fictieve views getoond.', unit:'bij bron'},
  {id:'google', name:'Google', symbol:'G', color:'#9db9ff', bg:'#202c43', metric:'trending', region:'NL', method:'snapshot', source:'Google Trends', url:'https://trends.google.com/trending?geo=NL', docs:'https://support.google.com/trends/answer/3076011?hl=nl', description:'Selectie van openbare zoekonderwerpen uit Google Trending Now, Nederland, afgelopen 24 uur, gesorteerd op relevantie bij raadplegen. Persoonsnamen zijn niet overgenomen. Aantallen zoals 20K+ zijn ondergrenzen uit de bron, geen exacte zoekvolumes. Het zijn zoekopdrachten, geen views. De selectie wordt niet automatisch ververst.', unit:'zoekopdrachten (24u)'},
  {id:'apps', name:'App Store', symbol:'↗', color:'#8bbcf5', bg:'#1b2d43', metric:'downloads', region:'NL', method:'live', source:'Apple RSS', url:'https://apps.apple.com/nl/charts/iphone/top-free-apps/36', docs:'https://rss.marketingtools.apple.com/', description:'De top 100 gratis iPhone-apps voor Nederland uit de Apple RSS-feed. Apple publiceert hier een rangpositie, geen exacte downloadaantallen. We nemen alleen de appnaam, rangpositie en openbare App Store-link over. Ontwikkelaars- en accountgegevens worden niet opgeslagen.', unit:'App Store-positie'},
  {id:'hn', name:'Hacker News', symbol:'Y', color:'#ffbd92', bg:'#39291f', metric:'trending', region:'global', method:'live', source:'Hacker News API', url:'https://news.ycombinator.com/', docs:'https://github.com/HackerNews/API', description:'De eerste 30 beschikbare berichten uit de officiële topstories-lijst, met de openbare titel en het puntentotaal. De volgorde komt van Hacker News. Punten zijn geen views. Gebruikersnamen, profielgegevens en reacties worden niet overgenomen. De content is wereldwijd en overwegend Engelstalig.', unit:'punten'}
];
const platformById = Object.fromEntries(platforms.map(p => [p.id,p]));
const theme = p => `--platform-color:${p.color};--platform-bg:${p.bg}`;
const snapshots = {};

// Public source facts, not demo data. These are fixed snapshots, never called live.
snapshots.x = [
  [1,'Goedemorgen'],[2,'#midAJA'],[3,'#twepaf'],[4,'#psvbru'],[5,'Democrats'],
  [7,'#fcmaja'],[8,'#necomo'],[13,'Paraguay'],[16,'Iran'],[17,'Republicans'],
  [18,'Brugge'],[21,'Europa League']
].map(([rank,title]) => ({id:`x:${title.toLowerCase()}`,platform:'x',title,rank,value:null,displayValue:`#${rank}`,unit:'trendpositie',region:'NL',metric:'trending',observedAt:'2026-11-06T08:42:18Z',period:'Bronmeting; selectie uit NL-trends',url:`https://x.com/search?q=${encodeURIComponent(title)}&src=trend_click`,sourceURL:platformById.x.url}));

snapshots.google = [
  [1,'zorgverzekering',20000,'20K+'],[2,'virus',20000,'20K+'],
  [3,'hazerswoude-dorp',20000,'20K+'],[6,'az - juventus',50000,'50K+'],
  [7,'hanos',5000,'5K+'],[10,'gasprijs zakt',10000,'10K+'],
  [12,'nasdaq',10000,'10K+'],[14,'ticketmaster',2000,'2K+'],
  [15,'statiegeld',2000,'2K+'],[17,'australië',2000,'2K+'],
  [18,'satelliet',2000,'2K+'],[21,'friet',2000,'2K+']
].map(([rank,title,value,displayValue]) => ({id:`google:${title}`,platform:'google',title,rank,value,displayValue,unit:'zoekopdrachten (24u)',region:'NL',metric:'trending',observedAt:SNAPSHOT_READ_AT,period:'Afgelopen 24 uur bij raadplegen; ondergrens',url:`https://trends.google.com/trends/explore?geo=NL&q=${encodeURIComponent(title)}`,sourceURL:platformById.google.url}));

snapshots.youtube = [
  [2,'Nieuwe trailer van Dune, deel drie',28000000,'≈ 28 mln','uJqaGLmZpGo'],
  [3,'Minecraft-gevecht: 10.000 spelers',14000000,'≈ 14 mln','bHxLy6Qza3M'],
  [5,'Percy Jackson: trailer voor seizoen 3',8900000,'≈ 8,9 mln','nD45l6hCyN8'],
  [8,'Lanceringstrailer voor GTA VI',74000000,'≈ 74 mln','rz1B4B2yBZY'],
  [9,'Brawl Stars: geanimeerd oorsprongsverhaal',9800000,'≈ 9,8 mln','t6ySEQzL1yE'],
  [11,'Nieuwe Avengers-film: bioscoopteaser',19000000,'≈ 19 mln','UfNu7pcR0e8']
].map(([rank,title,value,displayValue,video]) => ({id:`youtube:${video}`,platform:'youtube',title,rank,value,displayValue,unit:'views totaal (≈)',region:'NL',metric:'views',observedAt:SNAPSHOT_READ_AT,period:'Wereldwijde cumulatieve views; selectiebron NL',url:`https://www.youtube.com/watch?v=${video}`,sourceURL:platformById.youtube.url}));


// Public aggregate facts only. Android totals and active players are different metrics.
const DOWNLOADS_READ_AT = '2026-11-06T13:37:17Z';
const STEAM_GAMES = [
  {id:730,title:'Counter-Strike 2',snapshot:1060050},
  {id:578080,title:'PUBG: BATTLEGROUNDS',snapshot:708750},
  {id:570,title:'Dota 2',snapshot:618571},
  {id:413150,title:'Stardew Valley',snapshot:68366}
];
snapshots.playstore = [
  ['com.whatsapp','WhatsApp Messenger',10000000000,'10 miljard+'],
  ['com.instagram.android','Instagram',5000000000,'5 miljard+'],
  ['com.twitter.android','X / Twitter',1000000000,'1 miljard+'],
  ['com.zhiliaoapp.musically','TikTok',1000000000,'1 miljard+']
].map(([key,title,value,displayValue],index)=>({id:'playstore:'+key,platform:'playstore',title,rank:index+1,value,displayValue,
  unit:platformById.playstore.unit,region:'global',metric:'downloads',observedAt:DOWNLOADS_READ_AT,
  period:'Wereldwijd cumulatief op Google Play; ondergrens, geen actieve gebruikers',
  url:'https://play.google.com/store/apps/details?id='+encodeURIComponent(key)+'&hl=nl',sourceURL:'https://play.google.com/store/apps/details?id='+encodeURIComponent(key)+'&hl=nl'}));
snapshots.steam = STEAM_GAMES.map((game,index)=>({id:'steam:'+game.id,platform:'steam',title:game.title,rank:index+1,value:game.snapshot,
  displayValue:new Intl.NumberFormat('nl-NL').format(game.snapshot),unit:platformById.steam.unit,region:'global',metric:'activity',
  observedAt:DOWNLOADS_READ_AT,period:'Momentopname officiële Steam Charts; gelijktijdig online, niet unieke gebruikers',
  url:'https://store.steampowered.com/app/'+game.id,sourceURL:platformById.steam.url}));

const datasets = Object.fromEntries(platforms.map(p => [p.id,snapshots[p.id] || []]));
const sourceStates = Object.fromEntries(platforms.map(p => [p.id,{kind:snapshots[p.id]?'snapshot':p.method==='live'?'idle':p.method,at:snapshots[p.id]?.[0]?.observedAt || null,message:''}]));
let activePlatform='all', activeMetric='all', savedOnly=false, search='', region='all', limit=10;
let bookmarks = new Set(), savedItems = new Map(), refreshing=false, storageBlocked=false, toastTimer;

// Store a small allowlist of content fields, never raw API responses.
function cleanSignal(item, platform) {
  if (!item || typeof item.id!=='string' || typeof item.title!=='string' || !Number.isInteger(item.rank) || item.rank<1 || item.rank>100 || !Number.isFinite(Date.parse(item.observedAt))) return null;
  return {id:item.id.slice(0,240),platform,title:item.title.slice(0,500),rank:item.rank,
    value:typeof item.value==='number'&&Number.isFinite(item.value)&&item.value>=0?item.value:null,
    displayValue:String(item.displayValue || '').slice(0,40),unit:platformById[platform].unit,
    metric:platformById[platform].metric,region:platformById[platform].region,
    observedAt:item.observedAt,period:String(item.period || '').slice(0,160),
    url:safeURL(item.url),sourceURL:platformById[platform].url};
}
function restore() {
  try {
    const saved=JSON.parse(localStorage.getItem(STORAGE_KEY)||'null');
    if (!saved || saved.version!==1) return;
    bookmarks=new Set(Array.isArray(saved.bookmarks)?saved.bookmarks.filter(id=>typeof id==='string').slice(0,500):[]);
    if(Array.isArray(saved.savedItems))for(const raw of saved.savedItems.slice(0,500)){
      if(!platformById[raw?.platform]||!bookmarks.has(raw.id))continue;
      const item=cleanSignal(raw,raw.platform);if(item)savedItems.set(item.id,item);
    }
    for (const id of ['apps','hn','steam']) {
      if (!Array.isArray(saved.datasets?.[id])) continue;
      const cached=saved.datasets[id].slice(0,100).map(item=>cleanSignal(item,id)).filter(Boolean);
      if (cached.length) {datasets[id]=cached;sourceStates[id]={kind:'cached',at:cached[0].observedAt,message:'Eerder in deze browser opgehaald'};}
    }
  } catch {storageBlocked=true;}
}
function persist() {
  try {
    localStorage.setItem(STORAGE_KEY,JSON.stringify({version:1,bookmarks:[...bookmarks],savedItems:[...savedItems.values()],datasets:{apps:datasets.apps,hn:datasets.hn,steam:datasets.steam.filter(item=>!item.period.startsWith('Momentopname'))}}));
    storageBlocked=false;
  } catch {storageBlocked=true;}
}
function toast(message) {
  $('toast').textContent=message; $('toast').style.display='block';
  clearTimeout(toastTimer); toastTimer=setTimeout(()=>$('toast').style.display='none',4000);
}
function statusLabel(id) {
  const state=sourceStates[id];
  return {live:'Opgehaald',snapshot:'Momentopname',external:'Bij de bron',cached:'Opgeslagen',loading:'Wordt geladen',error:'Niet bereikbaar',idle:'Beschikbaar'}[state.kind];
}
function statusClass(id) {return ['live','cached'].includes(sourceStates[id].kind)?'ready':sourceStates[id].kind==='external'?'external':'snapshot';}
function icon(p) {return `<span class="platform-symbol" style="${theme(p)}" aria-hidden="true">${p.symbol}</span>`;}

function visibleGroups() {
  return platforms.filter(p=>activePlatform==='all'||p.id===activePlatform).map(p=>{
    const available=savedOnly?[...new Map([...savedItems.values(),...datasets[p.id]].filter(item=>item.platform===p.id).map(item=>[item.id,item])).values()]:datasets[p.id];
    let rows=available.filter(item=>(activeMetric==='all'||item.metric===activeMetric)&&(region==='all'||item.region===region)&&(!savedOnly||bookmarks.has(item.id))&&item.title.toLocaleLowerCase('nl').includes(search));
    rows.sort((a,b)=>a.rank-b.rank);
    return {platform:p,rows:rows.slice(0,limit)};
  });
}
function renderNavigation() {
  $('sidePlatforms').innerHTML=platforms.map(p=>`<button class="platform-nav ${activePlatform===p.id?'active':''}" data-platform="${p.id}" aria-pressed="${activePlatform===p.id}" style="${theme(p)}"><span class="small-symbol" aria-hidden="true">${p.symbol}</span>${p.name}</button>`).join('');
  $('platformCards').innerHTML=platforms.map(p=>`<button class="platform-card ${activePlatform===p.id?'active':''}" data-platform="${p.id}" aria-pressed="${activePlatform===p.id}" style="${theme(p)}">${icon(p)}<b>${p.name}</b><small class="${statusClass(p.id)}"><i></i>${statusLabel(p.id)}</small></button>`).join('');
  $('navOverview').classList.toggle('active',!savedOnly);
  $('navSaved').classList.toggle('active',savedOnly);
  $('savedCount').textContent=bookmarks.size;
  document.querySelectorAll('[data-metric]').forEach(button=>{
    const active=button.dataset.metric===activeMetric;
    button.classList.toggle('active',active);button.setAttribute('aria-pressed',String(active));
  });
}
function render() {
  renderNavigation();
  const total=Object.values(datasets).reduce((sum,list)=>sum+list.length,0);
  $('signalCount').innerHTML=`${total}<span>met herkomst</span>`;
  $('readyCount').innerHTML=`${platforms.filter(p=>datasets[p.id].length).length}<span>live of momentopname</span>`;
  $('sourceNotice').textContent=storageBlocked?'Browseropslag is niet beschikbaar. Bewaarde items kunnen na sluiten verdwijnen.':activePlatform!=='all'&&platformById[activePlatform].method==='external'?'Deze bron heeft hier geen automatisch ingelezen ranglijst. Open de beschikbare ontdekpagina bij het platform.':activePlatform!=='all'&&sourceStates[activePlatform].kind==='snapshot'?`Vaste momentopname · ${platformById[activePlatform].source} · ${new Intl.DateTimeFormat('nl-NL',{day:'numeric',month:'short',year:'numeric',timeZone:'Europe/Amsterdam'}).format(new Date(sourceStates[activePlatform].at))}. ${activePlatform==='steam'?'Vernieuwen probeert de spelersaantallen rechtstreeks op te halen.':'Vernieuwen haalt deze vaste selectie niet opnieuw op.'} Open de bron voor de huidige stand.`:'X, Google, YouTube en Google Play bevatten gedateerde momentopnamen. App Store, Hacker News en Steam proberen rechtstreeks te laden. Downloads, actieve spelers en views meten iets anders.';
  $('resultsTitle').textContent=savedOnly?'Bewaarde signalen':activePlatform==='all'?'Alle platforms':platformById[activePlatform].name;
  const groups=visibleGroups(); const count=groups.reduce((sum,g)=>sum+g.rows.length,0);
  $('resultsCount').textContent=`${count} ${count===1?'signaal':'signalen'}`;
  if (activePlatform!=='all'&&platformById[activePlatform].method==='external'&&!savedOnly) {
    $('results').innerHTML=externalCard(platformById[activePlatform]);
  } else if (!count) {
    const selected=activePlatform!=='all'?platformById[activePlatform]:null;
    const loading=selected?sourceStates[selected.id].kind==='loading':Object.values(sourceStates).some(s=>s.kind==='loading');
    $('results').innerHTML=`<div class="empty">${loading?'Openbare bronnen worden opgehaald…':savedOnly?'Je hebt hier nog geen signalen bewaard. Klik op ☆ bij een onderwerp.':'Geen signalen met deze filters. Een bron kan ook tijdelijk niet bereikbaar zijn.'}<br><button class="border-button" data-reset>Wis filters</button>${selected&&selected.method==='live'?`<a class="border-button" href="${selected.url}" target="_blank" rel="noopener noreferrer">Open ${selected.source} ↗</a>`:''}</div>`;
  } else {
    $('results').innerHTML=groups.filter(g=>g.rows.length).map(({platform:p,rows})=>`<section class="source-group"><div class="group-head"><span><b style="color:${p.color}">${p.symbol} &nbsp;${p.name}</b><span>· ${p.region==='NL'?'Nederland':'Wereldwijd'} · ${['playstore','steam'].includes(p.id)?'volgorde binnen selectie':p.method==='snapshot'?'selectie uit bron':'bronvolgorde'}</span></span><a href="${p.url}" target="_blank" rel="noopener noreferrer">${p.source} ↗</a></div>${rows.map(item=>renderSignal(item,p)).join('')}</section>`).join('');
  }
  $('export').disabled=count===0;
}
function externalCard(p) {
  return `<div class="external-state" style="${theme(p)}">${icon(p)}<div><h3>${p.emptyTitle}</h3><p>${p.emptyText}</p><a class="border-button" href="${p.url}" target="_blank" rel="noopener noreferrer">Open ${p.source} ↗</a><p class="external-note">Geen account wordt gekoppeld aan Trendradar. Je opent een externe website.</p></div></div>`;
}
function renderSignal(item,p) {
  const state=sourceStates[p.id];const saved=bookmarks.has(item.id);
  return `<article class="signal"><span class="source-rank" title="Oorspronkelijke positie in de bron">${String(item.rank).padStart(2,'0')}</span><div><button class="signal-title" data-detail="${escapeHTML(item.id)}">${escapeHTML(item.title)}</button><div class="signal-meta"><span class="tag">${p.id==='playstore'?'Android-app':p.id==='steam'?'Game-activiteit':p.id==='apps'?'Gratis iPhone-app':p.id==='google'?'Zoekonderwerp':p.id==='youtube'?'Video-onderwerp':p.id==='hn'?'Artikel':'Trendonderwerp'}</span><span>${item.region==='NL'?'NL-selectie':'Wereldwijd'}</span><span>${p.id==='youtube'?'Totaal wereldwijd':p.id==='google'?'24 uur bij bron':''}</span></div></div><div class="metric">${escapeHTML(item.displayValue)}<small>${escapeHTML(item.unit)}</small></div><div class="availability ${statusClass(p.id)}"><i></i> ${statusLabel(p.id)}<small>${p.id==='x'?'Bronmeting':'Geraadpleegd'} ${shortDate(item.observedAt)}</small></div><button class="save-button ${saved?'saved':''}" data-save="${escapeHTML(item.id)}" aria-label="${saved?'Verwijder':'Bewaar'} ${escapeHTML(item.title)}" aria-pressed="${saved}">${saved?'★':'☆'}</button></article>`;
}
function findSignal(id) {return Object.values(datasets).flat().find(item=>item.id===id)||savedItems.get(id);}
function showDetail(id) {
  const item=findSignal(id);if(!item)return;const p=platformById[item.platform];
  $('detailBody').innerHTML=`<span class="eyebrow small">${p.name.toUpperCase()} · ${statusLabel(p.id).toUpperCase()}</span><h2 class="detail-title">${escapeHTML(item.title)}</h2><div class="detail-metric">${escapeHTML(item.displayValue)}</div><p class="detail-text">${escapeHTML(item.unit)}<br>${escapeHTML(item.period)}<br>Bronpositie: #${item.rank}<br>${p.id==='x'?'Bronmeting':'Geraadpleegd'}: ${fullDate(item.observedAt)} (Nederlandse tijd)</p><p class="detail-text">${escapeHTML(p.description)}</p><div class="detail-actions"><a class="primary-button" href="${escapeHTML(safeURL(item.url)||p.url)}" target="_blank" rel="noopener noreferrer">Open onderwerp ↗</a><a class="border-button" href="${p.url}" target="_blank" rel="noopener noreferrer">Bron bekijken ↗</a></div>`;
  $('detailDialog').showModal();
}
function showSources() {
  $('sourceDetails').innerHTML=platforms.map(p=>`<div class="source-detail"><b style="color:${p.color}">${p.symbol} &nbsp;${p.name}</b><span class="status-detail">${statusLabel(p.id)}${sourceStates[p.id].at?' · '+fullDate(sourceStates[p.id].at):''}</span><p>${p.description}</p>${sourceStates[p.id].message?`<p>${escapeHTML(sourceStates[p.id].message)}</p>`:''}<a href="${p.url}" target="_blank" rel="noopener noreferrer">Bron ↗</a> &nbsp; <a href="${p.docs}" target="_blank" rel="noopener noreferrer">Documentatie ↗</a></div>`).join('');
  $('sourcesDialog').showModal();
}

async function getJSON(url,timeout=9000) {
  const controller=new AbortController();const timer=setTimeout(()=>controller.abort(),timeout);
  try {
    const response=await fetch(url,{signal:controller.signal,credentials:'omit',referrerPolicy:'no-referrer'});
    if(!response.ok)throw new Error('Bron niet bereikbaar');return await response.json();
  } finally {clearTimeout(timer);}
}
function parseApple(data,observedAt) {
  let list;
  if(Array.isArray(data.feed?.entry))list=data.feed.entry.map(a=>({key:String(a.id?.attributes?.['im:id']||''),title:a['im:name']?.label,url:a.id?.label}));
  else if(Array.isArray(data.feed?.results))list=data.feed.results.map(a=>({key:String(a.id||''),title:a.name,url:a.url}));
  else throw new Error('Geen Apple-ranglijst');
  if(!list.length||list.some(a=>!a.key||typeof a.title!=='string'))throw new Error('Ongeldige Apple-ranglijst');
  return list.slice(0,100).map((a,index)=>({id:`apps:${a.key}`,platform:'apps',title:a.title,rank:index+1,value:null,displayValue:`#${index+1}`,unit:platformById.apps.unit,region:'NL',metric:'downloads',observedAt,period:'Top gratis iPhone-apps; geen downloadaantallen',url:safeURL(a.url),sourceURL:platformById.apps.url}));
}
async function loadApps() {
  for(const url of ['https://itunes.apple.com/nl/rss/topfreeapplications/limit=100/json','https://rss.marketingtools.apple.com/api/v2/nl/apps/top-free/100/apps.json']) {
    try {return parseApple(await getJSON(url,6500),new Date().toISOString());}catch{}
  }
  throw new Error('Apple is niet bereikbaar of blokkeert het browserverzoek.');
}
function parseHN(item,rank,observedAt) {
  if(!item||!Number.isInteger(item.id)||item.deleted||item.dead||item.type!=='story'||typeof item.title!=='string')return null;
  const score=typeof item.score==='number'&&Number.isFinite(item.score)&&item.score>=0?item.score:null;
  return {id:`hn:${item.id}`,platform:'hn',title:item.title,rank,value:score,displayValue:score===null?'—':formatNumber(score),unit:platformById.hn.unit,region:'global',metric:'trending',observedAt,period:'Topstories-positie en huidig puntentotaal',url:`https://news.ycombinator.com/item?id=${encodeURIComponent(item.id)}`,sourceURL:platformById.hn.url};
}
async function loadHN() {
  const ids=await getJSON('https://hacker-news.firebaseio.com/v0/topstories.json');
  if(!Array.isArray(ids)||!ids.length)throw new Error('Geen Hacker News-lijst');
  const first=ids.slice(0,30);const rows=new Array(first.length);let cursor=0;
  const at=new Date().toISOString();
  await Promise.all(Array.from({length:5},async()=>{
    while(cursor<first.length) {
      const index=cursor++;
      try {rows[index]=parseHN(await getJSON(`https://hacker-news.firebaseio.com/v0/item/${first[index]}.json`),index+1,at);}catch{}
    }
  }));
  const list=rows.filter(Boolean);if(!list.length)throw new Error('Hacker News is niet bereikbaar.');return list;
}

function parseSteam(data,game,observedAt) {
  const count=data?.response?.player_count;
  if(data?.response?.result!==1||!Number.isSafeInteger(count)||count<0) throw new Error('Geen geldig openbaar Steam-spelerstotaal.');
  return {id:'steam:'+game.id,platform:'steam',title:game.title,rank:1,value:count,
    displayValue:new Intl.NumberFormat('nl-NL').format(count),unit:platformById.steam.unit,
    region:'global',metric:'activity',observedAt,period:'Gelijktijdig online bij ophalen; geen unieke dag- of maandgebruikers',
    url:'https://store.steampowered.com/app/'+game.id,sourceURL:platformById.steam.url};
}
async function loadSteam() {
  const results=await Promise.allSettled(STEAM_GAMES.map(async game=>{
    const data=await getJSON('https://api.steampowered.com/ISteamUserStats/GetNumberOfCurrentPlayers/v1/?appid='+game.id,7000);
    return parseSteam(data,game,new Date().toISOString());
  }));
  // Keep one coherent complete selection; do not mix old and new game counts.
  if(results.some(result=>result.status!=='fulfilled'))throw new Error('Steam is niet volledig bereikbaar vanuit deze browser. De getoonde momentopname is niet live.');
  return results.map(result=>result.value).sort((a,b)=>b.value-a.value).map((item,index)=>({...item,rank:index+1}));
}

async function refreshSources(force=false) {
  if(refreshing)return;refreshing=true;$('refresh').disabled=true;$('refresh').textContent='↻ Bronnen laden…';
  await Promise.allSettled(['apps','hn','steam'].map(async id=>{
    const recent=sourceStates[id].kind!=='snapshot'&&sourceStates[id].at&&Date.now()-Date.parse(sourceStates[id].at)<900000;
    if(!force&&recent&&datasets[id].length)return;
    const previousState={...sourceStates[id]};sourceStates[id]={...sourceStates[id],kind:'loading'};render();
    try {
      datasets[id]=await(id==='apps'?loadApps():id==='hn'?loadHN():loadSteam());
      sourceStates[id]={kind:'live',at:datasets[id][0].observedAt,message:''};persist();
    }catch(error) {
      sourceStates[id]={kind:datasets[id].length?(previousState.kind==='snapshot'?'snapshot':'cached'):'error',at:datasets[id][0]?.observedAt||null,message:String(error.message)};
    }
    render();
  }));
  refreshing=false;$('refresh').disabled=false;$('refresh').textContent='↻ Vernieuw bronnen';render();
  if(force)toast('Directe bronnen bijgewerkt waar bereikbaar. Momentopnamen blijven gedateerd.');
}
function resetFilters() {
  activePlatform='all';activeMetric='all';savedOnly=false;search='';region='all';$('search').value='';$('region').value='all';render();
}
document.addEventListener('click',event=>{
  const platform=event.target.closest('[data-platform]');
  if(platform){activePlatform=activePlatform===platform.dataset.platform?'all':platform.dataset.platform;activeMetric='all';savedOnly=false;render();return;}
  const save=event.target.closest('[data-save]');
  if(save){const id=save.dataset.save;if(bookmarks.has(id)){bookmarks.delete(id);savedItems.delete(id);}else{bookmarks.add(id);const item=findSignal(id);if(item)savedItems.set(id,item);}persist();render();if(storageBlocked)toast('Opslaan is geblokkeerd door je browser.');return;}
  const detail=event.target.closest('[data-detail]');if(detail){showDetail(detail.dataset.detail);return;}
  if(event.target.closest('[data-open-sources]'))showSources();
  if(event.target.closest('[data-reset]'))resetFilters();
});
document.querySelectorAll('[data-metric]').forEach(button=>button.onclick=()=>{activeMetric=button.dataset.metric;render();});
$('search').oninput=event=>{search=event.target.value.toLocaleLowerCase('nl').trim();render();};
$('region').onchange=event=>{region=event.target.value;render();};
$('limit').onchange=event=>{limit=Number(event.target.value);render();};
$('navOverview').onclick=()=>resetFilters();
$('navSaved').onclick=()=>{savedOnly=true;activePlatform='all';activeMetric='all';search='';region='all';$('search').value='';$('region').value='all';render();};
$('refresh').onclick=()=>refreshSources(true);
$('closeSources').onclick=()=>$('sourcesDialog').close();
$('closeDetail').onclick=()=>$('detailDialog').close();
for(const id of ['sourcesDialog','detailDialog'])$(id).onclick=event=>{
  if(event.target!==$(id))return;const box=$(id).getBoundingClientRect();
  if(event.clientX<box.left||event.clientX>box.right||event.clientY<box.top||event.clientY>box.bottom)$(id).close();
};
$('export').onclick=()=>{
  const rows=visibleGroups().flatMap(g=>g.rows);if(!rows.length)return;
  const cell=value=>{let s=String(value??'');if(/^[\s]*[=+\-@]/.test(s))s="'"+s;return '"'+s.replace(/"/g,'""')+'"';};
  const lines=[['Platform','Onderwerp','Bronpositie','Getoonde waarde','Eenheid','Regio selectie','Periode','Geraadpleegd of bronmeting','Status','Bron'].map(cell).join(';')];
  for(const item of rows){const p=platformById[item.platform];lines.push([p.name,item.title,item.rank,item.displayValue,item.unit,item.region,item.period,item.observedAt,statusLabel(p.id),p.url].map(cell).join(';'));}
  const blob=new Blob(['\ufeff'+lines.join('\r\n')],{type:'text/csv;charset=utf-8;'});const url=URL.createObjectURL(blob);const link=document.createElement('a');link.href=url;link.download='trendradar-openbare-signalen.csv';link.click();setTimeout(()=>URL.revokeObjectURL(url),5000);toast('De zichtbare signalen zijn geëxporteerd met bron en meetmoment.');
};
restore();render();refreshSources();
