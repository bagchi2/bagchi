import json,re,html
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
SITE=ROOT/'tools'/'egg-rate-today'
DATA=SITE/'data'
CITY=SITE/'cities'
latest=json.loads((DATA/'latest.json').read_text())
history=json.loads((DATA/'history.json').read_text())


def slug(s):
    return re.sub(r'[^a-z0-9]+','-',re.sub(r'\([^)]*\)','',s.lower())).strip('-')

def money(v):
    return f'₹{v:,.2f}'.rstrip('0').rstrip('.')

def esc(s): return html.escape(str(s),quote=True)

CSS='''
:root{--bg:#f5f7fb;--surface:#fff;--text:#11161d;--muted:#6b7482;--line:#e7eaf0;--accent:#eb4d52;--accent2:#ff9a5a;--dark:#11161d;--good:#16845b;--bad:#c63f45;--shadow:0 14px 36px rgba(17,22,29,.08)}*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif}.container{width:min(1180px,calc(100% - 32px));margin:auto}.top{position:sticky;top:0;z-index:10;background:rgba(255,255,255,.94);backdrop-filter:blur(12px);border-bottom:1px solid var(--line)}.nav{height:70px;display:flex;justify-content:space-between;align-items:center}.brand{font-weight:900;font-size:24px;letter-spacing:-.05em;color:var(--text);text-decoration:none}.brand b{color:var(--accent)}nav{display:flex;gap:22px}nav a{color:var(--muted);text-decoration:none;font-weight:700;font-size:14px}.hero{padding:70px 0 86px;background:linear-gradient(135deg,#10151c,#2a303a);color:#fff}.grid2{display:grid;grid-template-columns:1.55fr .75fr;gap:40px;align-items:center}.eyebrow{font-size:11px;letter-spacing:.16em;font-weight:900;color:var(--accent)}h1{font-size:clamp(42px,6vw,76px);line-height:.98;letter-spacing:-.06em;margin:14px 0 20px}h2{font-size:34px;letter-spacing:-.045em;margin:6px 0 0}.lead{max-width:690px;color:#cfd4dd;font-size:18px;line-height:1.7}.muted{color:#9da6b4;font-size:13px}.hero-rate{padding:30px;border:1px solid rgba(255,255,255,.13);background:rgba(255,255,255,.06);border-radius:24px}.hero-number{font-size:50px;font-weight:900;letter-spacing:-.05em;margin:10px 0}.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-top:-36px;position:relative}.card{background:var(--surface);border:1px solid var(--line);border-radius:18px;box-shadow:var(--shadow)}.stat{padding:22px}.stat span,.stat small{display:block;color:var(--muted);font-size:12px}.stat strong{display:block;font-size:26px;margin:8px 0 4px;letter-spacing:-.03em}.section{padding:58px 0}.head{display:flex;justify-content:space-between;align-items:end;gap:20px;margin-bottom:20px}.search,.select{height:44px;border:1px solid var(--line);border-radius:12px;padding:0 14px;background:#fff;min-width:250px;outline:0}.table-wrap{overflow:auto}.table{width:100%;border-collapse:collapse;min-width:820px}.table th,.table td{text-align:left;padding:14px 16px;border-bottom:1px solid var(--line);font-size:14px}.table th{background:#fafbfc;color:var(--muted);font-size:11px;letter-spacing:.08em;text-transform:uppercase}.table a{color:var(--text);font-weight:800;text-decoration:none}.pill{display:inline-block;padding:5px 9px;border-radius:999px;background:#f1f3f6;font-size:11px;color:#5c6572}.notice{padding:22px;border-radius:18px;background:#fff7ec;border:1px solid #f0dcba;color:#625c52;line-height:1.7}.chartbox{padding:20px}.chart{width:100%;height:auto;display:block}.legend{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px}.legend span{font-size:12px;color:var(--muted)}.month-nav{display:flex;gap:8px;flex-wrap:wrap;margin:15px 0}.month-nav button{border:1px solid var(--line);background:#fff;padding:9px 12px;border-radius:10px;cursor:pointer;font-weight:700}.month-nav button.active{background:var(--dark);color:#fff;border-color:var(--dark)}.two{display:grid;grid-template-columns:1fr 1fr;gap:18px}.footer{background:#10151c;color:#d7dce4;padding:40px 0}.footer a{display:block;color:#9ea6b4;text-decoration:none;margin-top:8px;font-size:14px}.small{font-size:12px;color:var(--muted)}.right{text-align:right}.up{color:var(--good);font-weight:800}.down{color:var(--bad);font-weight:800}.zero{color:var(--muted)}@media(max-width:850px){nav{display:none}.grid2{grid-template-columns:1fr}.stats{grid-template-columns:repeat(2,1fr)}.head{align-items:stretch;flex-direction:column}.search,.select{width:100%;min-width:0}.two{grid-template-columns:1fr}}@media(max-width:520px){.container{width:calc(100% - 20px)}.stats{grid-template-columns:1fr}.hero{padding:50px 0 70px}h1{font-size:44px}.section{padding:45px 0}}
'''


def svg_chart(points, width=1000, height=320, label_every=1):
    pts=[p for p in points if p.get('value') is not None]
    if not pts: return '<div class="small">No historical data available yet.</div>'
    vals=[float(p['value']) for p in pts]; mn=min(vals); mx=max(vals); pad=(mx-mn)*.12 if mx!=mn else .5; lo=mn-pad; hi=mx+pad
    def x(i): return 52 + i*(width-80)/max(1,len(pts)-1)
    def y(v): return height-34 - (v-lo)/(hi-lo)*(height-60)
    path=' '.join((('M' if i==0 else 'L')+f'{x(i):.1f},{y(v):.1f}') for i,v in enumerate(vals))
    circles=''.join(f'<circle cx="{x(i):.1f}" cy="{y(v):.1f}" r="3" fill="currentColor" opacity=".9"><title>{esc(pts[i]["label"])} • ₹{v:.2f}</title></circle>' for i,v in enumerate(vals))
    # only label endpoints and a few middle points to avoid crowding
    labels=''
    idxs=sorted(set([0,len(pts)-1]+([i for i in range(0,len(pts),max(1,len(pts)//5))])))
    for i in idxs:
        labels += f'<text x="{x(i):.1f}" y="{height-8}" text-anchor="middle" font-size="11" fill="#7b8492">{esc(pts[i]["label"][:10])}</text>'
    return f'''<svg class="chart" viewBox="0 0 {width} {height}" role="img" aria-label="Price history chart"><g stroke="#eceef2" stroke-width="1"><line x1="52" y1="20" x2="{width-28}" y2="20"/><line x1="52" y1="{height/2}" x2="{width-28}" y2="{height/2}"/><line x1="52" y1="{height-34}" x2="{width-28}" y2="{height-34}"/></g><polyline points="{path[1:]}" fill="none" stroke="currentColor" stroke-width="3" stroke-linejoin="round" stroke-linecap="round"/><g>{circles}</g><text x="8" y="26" font-size="12" fill="#7b8492">₹{hi:.2f}</text><text x="8" y="{height-40}" font-size="12" fill="#7b8492">₹{lo:.2f}</text><g>{labels}</g></svg>'''

# latest table
markets=latest['markets']

def header(title,desc,canonical):
    return f'''<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{esc(title)}</title><meta name="description" content="{esc(desc)}"><link rel="canonical" href="{canonical}"><style>{CSS}</style></head><body><header class="top"><div class="container nav"><a class="brand" href="/tools/egg-rate-today/">Egg<b>Rate</b> India</a><nav><a href="/tools/egg-rate-today/#rates">Today's Rates</a><a href="/tools/egg-rate-today/#history">History</a><a href="/tools/egg-rate-today/#about">About</a></nav></div></header>'''

def footer():
    return '''<footer class="footer"><div class="container two"><div><div class="brand" style="color:#fff">Egg<b>Rate</b> India</div><p class="small">Independent egg-rate information portal.</p></div><div><strong>Source</strong><a href="https://www.e2necc.com/home/eggprice" target="_blank" rel="noopener">E2NECC Egg Price</a><a href="/tools/egg-rate-today/sitemap.xml">Sitemap</a></div></div></footer><script>document.documentElement.dataset.eggRatePage='ready';</script></body></html>'''

rows_html=''
for m in sorted(markets,key=lambda x:x['name']):
    r=m['rate']; s=slug(m['name'])
    rows_html += f'<tr><td><a href="cities/{s}.html">{esc(m["name"])}</a></td><td><strong>{money(r/100)}</strong></td><td>{money(r*.12)}</td><td>{money(r*.30)}</td><td>₹{r:,.0f}</td><td>{money(r*2.10)}</td><td><a href="cities/{s}.html">View →</a></td></tr>'

# all current data inline for homepage
homepage = header('Today Egg Rate in India — NECC Egg Price','Today’s NECC suggested egg rates by Indian market with 2026 history, market comparison and price charts.','https://bagchi.in/tools/egg-rate-today/')
lo=min(markets,key=lambda x:x['rate']); hi=max(markets,key=lambda x:x['rate'])
homepage += f'''<main><section class="hero"><div class="container grid2"><div><span class="eyebrow">NECC SUGGESTED MARKET REFERENCE</span><h1>Today's Egg Rate in India</h1><p class="lead">Compare current egg rates across NECC reference markets, see 2026 historical movement and open a dedicated city page with its own table and graph.</p><div class="updated muted">Latest official sheet used: <strong>{latest['date']}</strong></div></div><div class="hero-rate"><div class="muted">Current market range</div><div class="hero-number">{money(lo['rate']/100)} – {money(hi['rate']/100)}</div><div class="muted">per egg</div></div></div></section><section class="container stats"><div class="card stat"><span>Lowest Market</span><strong>{money(lo['rate']/100)}</strong><small>{esc(lo['name'])}</small></div><div class="card stat"><span>Highest Market</span><strong>{money(hi['rate']/100)}</strong><small>{esc(hi['name'])}</small></div><div class="card stat"><span>Markets Tracked</span><strong>{len(markets)}</strong><small>NECC reference centres</small></div><div class="card stat"><span>Archive</span><strong>Jan–Aug 2026</strong><small>monthly + current data</small></div></section><section class="container section" id="rates"><div class="head"><div><span class="eyebrow">MARKET BOARD</span><h2>Egg Rate by Market</h2></div><input class="search" id="q" placeholder="Search city or market…"></div><div class="card table-wrap"><table class="table"><thead><tr><th>Market</th><th>1 Egg</th><th>Dozen</th><th>Tray</th><th>100 Eggs</th><th>210 Eggs</th><th></th></tr></thead><tbody id="tbody">{rows_html}</tbody></table></div></section><section class="container section" id="history"><div class="head"><div><span class="eyebrow">2026 ARCHIVE</span><h2>India Egg Price Movement</h2><p class="small">Historical backfill is stored by market and rendered on each city page.</p></div></div><div class="card chartbox">{svg_chart([{'label':m,'value':None} for m in []])}</div></section><section class="container section" id="about"><div class="notice"><strong>Important rate disclaimer</strong><p>NECC states that its suggested egg prices are merely suggestive and not mandatory; they are published for the reference and information of the trade and industry. Actual local or retail prices may differ.</p></div></section></main>'''
homepage += f'''<script>const r=document.getElementById('q'),t=document.getElementById('tbody');r.addEventListener('input',()=>{{const q=r.value.toLowerCase();[...t.rows].forEach(x=>x.style.display=x.cells[0].textContent.toLowerCase().includes(q)?'':'none')}});</script>'''+footer()
(SITE/'index.html').write_text(homepage,encoding='utf-8')

# city pages. historical schema: history[city] = {months:[...], daily:[...]}
history_by_city=history.get('markets',{})
for m in markets:
    name=m['name']; r=m['rate']; s=slug(name); h=history_by_city.get(name,{"months":[],"daily":[]})
    months=h.get('months',[]); daily=h.get('daily',[])
    points=[{'label':x['month'],'value':x['avg']} for x in sorted(months,key=lambda z:z['month'])]
    if not points: points=[{'label':latest['date'],'value':r/100}]
    month_rows=''.join(f'<tr><td>{esc(x["month"])}</td><td><strong>₹{x["avg"]:.2f}</strong></td><td>₹{x["high"]:.2f}</td><td>₹{x["low"]:.2f}</td></tr>' for x in sorted(months,key=lambda z:z['month'],reverse=True))
    if daily:
        recent=daily[-90:]
        daily_rows=''.join(f'<tr><td>{esc(x["date"])}</td><td>₹{x["rate"]:.2f}</td><td class="{("up" if x.get("change",0)>0 else "down" if x.get("change",0)<0 else "zero")}">{("+" if x.get("change",0)>0 else "")}{x.get("change",0):.2f}</td></tr>' for x in reversed(recent))
    else:
        daily_rows='<tr><td colspan="3">Daily archive will populate automatically from the backfill/update workflow.</td></tr>'
    page=header(f'{name} Egg Rate Today — 2026 History','Today’s '+name+' NECC suggested egg rate with January–August 2026 historical table and graph.',f'https://bagchi.in/tools/egg-rate-today/cities/{s}.html')
    page += f'''<main><section class="hero"><div class="container grid2"><div><span class="eyebrow">MARKET RATE</span><h1>{esc(name)} Egg Rate Today</h1><p class="lead">Today’s NECC suggested reference rate with a dedicated 2026 history view.</p><div class="muted">Latest sheet date: <strong>{latest['date']}</strong></div></div><div class="hero-rate"><div class="muted">100 eggs</div><div class="hero-number">₹{r:,.0f}</div><div class="muted">{money(r/100)} per egg</div></div></div></section><section class="container stats"><div class="card stat"><span>Single</span><strong>{money(r/100)}</strong><small>1 egg</small></div><div class="card stat"><span>Dozen</span><strong>{money(r*.12)}</strong><small>12 eggs</small></div><div class="card stat"><span>Tray</span><strong>{money(r*.30)}</strong><small>30 eggs</small></div><div class="card stat"><span>Peti</span><strong>{money(r*2.10)}</strong><small>210 eggs</small></div></section><section class="container section"><div class="head"><div><span class="eyebrow">2026 TREND</span><h2>{esc(name)} Egg Price Graph</h2></div><span class="pill">Jan–Aug 2026</span></div><div class="card chartbox">{svg_chart(points)}</div></section><section class="container section"><div class="head"><div><span class="eyebrow">MONTHLY TABLE</span><h2>January–August 2026</h2></div></div><div class="card table-wrap"><table class="table"><thead><tr><th>Month</th><th>Average / Egg</th><th>High</th><th>Low</th></tr></thead><tbody>{month_rows or '<tr><td colspan="4">Historical monthly archive is being populated by the backfill workflow.</td></tr>'}</tbody></table></div></section><section class="container section"><div class="head"><div><span class="eyebrow">DAILY TABLE</span><h2>Recent Daily Rates</h2></div></div><div class="card table-wrap"><table class="table"><thead><tr><th>Date</th><th>Rate / Egg</th><th>Change / Egg</th></tr></thead><tbody>{daily_rows}</tbody></table></div></section><section class="container section"><div class="notice"><strong>Important rate disclaimer</strong><p>NECC suggested prices are for reference and information only and are not mandatory selling prices. Retail rates can differ.</p></div></section></main>'''+footer()
    (CITY/(s+'.html')).write_text(page,encoding='utf-8')

# index city links no external assets.
# Rebuild sitemap/LLM metadata for the subfolder.
base='https://bagchi.in/tools/egg-rate-today/'
urls=['index.html']+[f'cities/{slug(m["name"])}.html' for m in markets]
xml='<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n'+''.join(f'  <url><loc>{base}{u}</loc><changefreq>daily</changefreq><priority>{"1.0" if u=="index.html" else "0.8"}</priority></url>\n' for u in urls)+'</urlset>\n'
(SITE/'sitemap.xml').write_text(xml,encoding='utf-8')
(SITE/'llms.txt').write_text(f'''# EggRate India\n\nURL: {base}\n\nSource: {latest["source_url"]}\nLast official sheet in package: {latest["date"]}\nMarkets: {len(markets)}\n\nHistorical archive: January 2026 through August 2026, with bootstrap history and an automated backfill job.\n\nDisclaimer: E2NECC says its suggested prices are merely suggestive, not mandatory, and are published for reference and information of the trade and industry.\n''',encoding='utf-8')
