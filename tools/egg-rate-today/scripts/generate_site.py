import json,re
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
d=json.loads((ROOT/'data/latest.json').read_text())
markets=d['markets']
def slug(s): return re.sub(r'[^a-z0-9]+','-',re.sub(r'\([^)]*\)','',s.lower())).strip('-')
def money(v): return f'₹{v:,.2f}'.rstrip('0').rstrip('.')
for m in markets:
    n=m['name']; r=m['rate']; p=r/100; t=r*.30; c=r*2.10; s=slug(n)
    html=f'''<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{n} Egg Rate Today — NECC Egg Price</title><meta name="description" content="Today's {n} egg rate: {money(p)} per egg, {money(t)} for 30 eggs, ₹{r:,.0f} for 100 eggs and {money(c)} for 210 eggs."><link rel="stylesheet" href="../assets/css/style.css"></head><body>
<header class="site-header"><div class="container nav"><a class="brand" href="../">Egg<span>Rate</span> India</a><nav><a href="../">Today's Rates</a><a href="../#history">History</a></nav></div></header>
<main><section class="hero"><div class="container hero-grid"><div><span class="eyebrow">MARKET RATE</span><h1>{n} Egg Rate Today</h1><p class="hero-copy">NECC suggested reference rate automatically updated from E2NECC.</p><div class="updated">Updated: <strong>{d['date']}</strong></div></div><div class="hero-rate"><div class="muted">Per egg</div><div class="hero-number">{money(p)}</div><div class="muted">NECC suggested reference</div></div></div></section>
<section class="container stats"><article class="stat"><span>1 Egg</span><strong>{money(p)}</strong><small>per piece</small></article><article class="stat"><span>30 Eggs</span><strong>{money(t)}</strong><small>calculated</small></article><article class="stat"><span>100 Eggs</span><strong>₹{r:,.0f}</strong><small>reference</small></article><article class="stat"><span>210 Eggs</span><strong>{money(c)}</strong><small>calculated</small></article></section>
<section class="container section"><div class="notice"><strong>Important</strong><p>NECC suggested prices are for reference and information only and are not mandatory selling prices. Actual local prices may vary by seller, quantity, transport, demand and supply.</p></div></section>
<section class="container section"><h2>About {n} Egg Rate</h2><p class="hero-copy" style="color:#4f5663">Today's suggested market rate is {money(p)} per egg. Based on the reference rate, 30 eggs are {money(t)}, 100 eggs are ₹{r:,.0f}, and 210 eggs are {money(c)}.</p></section></main>
<footer><div class="container footer-grid"><div><div class="brand">Egg<span>Rate</span> India</div><p>Independent egg-rate information portal.</p></div><div><strong>Source</strong><a href="https://www.e2necc.com/home/eggprice" target="_blank" rel="noopener">E2NECC Egg Price</a></div></div></footer></body></html>'''
    (ROOT/'cities'/f'{s}.html').write_text(html,encoding='utf-8')
base='https://YOUR-DOMAIN.com/'
urls=['index.html']+[f"cities/{slug(x['name'])}.html" for x in markets]
xml='<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n'+''.join(f'  <url><loc>{base}{u}</loc><changefreq>daily</changefreq><priority>0.8</priority></url>\n' for u in urls)+'</urlset>\n'
(ROOT/'sitemap.xml').write_text(xml,encoding='utf-8')
(ROOT/'robots.txt').write_text('User-agent: *\nAllow: /\nSitemap: https://YOUR-DOMAIN.com/sitemap.xml\n',encoding='utf-8')
(ROOT/'llms.txt').write_text(f"# EggRate India\n\nIndependent informational egg-rate portal.\n\n## Source\n{d['source_url']}\n\n## Last generated\n{d['date']}\n\n## Disclaimer\nNECC suggested egg prices are reference prices and are not mandatory selling prices. Actual local prices may differ.\n",encoding='utf-8')
