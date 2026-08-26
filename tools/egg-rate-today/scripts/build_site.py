#!/usr/bin/env python3
"""Generate city pages from the master design in index.html."""
from pathlib import Path
import json,re,shutil
ROOT=Path(__file__).resolve().parents[1]; INDEX=ROOT/'index.html'; CITIES=ROOT/'cities'; DATA=ROOT/'data/latest.json'
latest=json.loads(DATA.read_text()); CITIES.mkdir(exist_ok=True)
html=INDEX.read_text(encoding='utf-8')
for m in latest.get('markets',[]):
    cid=m['id']; city=html.replace('>all-india<','>'+cid+'<').replace('PAGE_MARKET_ID = "all-india"','PAGE_MARKET_ID = "'+cid+'"')
    if 'let MARKETS_DATA = [];' not in city:
        city=city.replace('  const IS_CITY_PAGE = PAGE_MARKET_ID !== "all-india";\n','  const IS_CITY_PAGE = PAGE_MARKET_ID !== "all-india";\n  let MARKETS_DATA = [];\n  let ALL_INDIA_AVG_SERIES = [];\n  let ALL_INDIA_INFO = {id:"all-india",name:"All-India Weighted Average",state:"National Benchmark",zone:"Pan-India",currentRate:0,change24h:0,change24hPct:0,status:"flat",history:[]};\n')
    city=city.replace("DATA_BASE = \"data\"","DATA_BASE = \"../data\"")
    city=city.replace('<link rel="canonical" href="https://bagchi.in/tools/egg-rate-today/">','<link rel="canonical" href="https://bagchi.in/tools/egg-rate-today/cities/'+cid+'.html">')
    # Ensure relative assets/JSON paths remain correct; all CSS/JS remain inline.
    city=city.replace('href="data/','href="../data/')
    (CITIES/(cid+'.html')).write_text(city,encoding='utf-8')
print('Generated',len(list(CITIES.glob('*.html'))),'city pages')
