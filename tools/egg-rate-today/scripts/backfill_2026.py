#!/usr/bin/env python3
"""Backfill January 2026 through today from public monthly NECC-history pages.

Primary current source remains E2NECC. Historical backfill uses public daily-history pages
because E2NECC's public current sheet does not expose a simple API for all prior months.
The script never invents missing observations: a month/city is skipped when no page is found.
"""
import json,re,time
from datetime import date
from pathlib import Path
import requests
from bs4 import BeautifulSoup

ROOT=Path(__file__).resolve().parents[1]; DATA=ROOT/'data'; HISTORY=DATA/'history.json'
BASE='https://eggratetoday.com/egg-rate-history/{slug}/{ym}'
CITY_SLUGS={'e-godavari':'e-godavari','w-godavari':'w-godavari','luknow':'lucknow','muzaffurpur':'muzaffarpur','bengaluru':'bengaluru','brahmapur':'brahmapur','mysuru':'mysuru','vizag':'vizag','ahmedabad':'ahmedabad','ajmer':'ajmer','allahabad':'allahabad','barwala':'barwala','bhopal':'bhopal','chennai':'chennai','chittoor':'chittoor','delhi':'delhi','hospet':'hospet','hyderabad':'hyderabad','indore':'indore','jabalpur':'jabalpur','kanpur':'kanpur','kolkata':'kolkata','ludhiana':'ludhiana','mumbai':'mumbai','nagpur':'nagpur','namakkal':'namakkal','patna':'patna','pune':'pune','raipur':'raipur','ranchi':'ranchi','surat':'surat','varanasi':'varanasi','vijayawada':'vijayawada','warangal':'warangal'}
HEAD={'User-Agent':'Mozilla/5.0 (compatible; EggRateIndiaHistory/1.0)'}
PRICE_RE=re.compile(r'₹\s*([0-9]+(?:\.[0-9]+)?)')
DATE_RE=re.compile(r'\b([A-Z][a-z]+\s+\d{1,2},\s+2026)\b')

def parse_month(text):
    soup=BeautifulSoup(text,'html.parser'); rows=[]
    for tr in soup.find_all('tr'):
        cells=[re.sub(r'\s+',' ',c.get_text(' ',strip=True)) for c in tr.find_all(['th','td'])]
        if len(cells)>=2: rows.append(cells)
    out={}
    for row in rows:
        d=None
        for c in row[:2]:
            m=DATE_RE.search(c)
            if m:
                d=m.group(1); break
        if not d: continue
        price=None
        for c in row[1:]:
            m=PRICE_RE.search(c)
            if m: price=float(m.group(1)); break
        if price is not None: out[d]=round(price,2)
    return out

def main():
    import calendar
    try: hist=json.loads(HISTORY.read_text())
    except: hist={'records':[]}
    records={r['date']:r for r in hist.get('records',[])}
    today=date.today()
    for month in range(1,today.month+1):
        last=calendar.monthrange(2026,month)[1] if month<today.month else today.day
        ym=f'2026-{month:02d}'
        for cid,slug in CITY_SLUGS.items():
            url=BASE.format(slug=slug,ym=ym)
            try:
                r=requests.get(url,headers=HEAD,timeout=25)
                if r.status_code!=200: continue
                daily=parse_month(r.text)
                if not daily: continue
                for dstr,val in daily.items():
                    # Ignore dates beyond current day in current month.
                    dt=date.fromisoformat(__import__('datetime').datetime.strptime(dstr,'%B %d, %Y').date().isoformat())
                    if dt>today: continue
                    key=dt.isoformat(); rec=records.setdefault(key,{'date':key,'markets':{},'source':'public-historical-archive'})
                    rec['markets'][cid]=val
            except Exception as e:
                print('skip',cid,ym,e)
            time.sleep(.15)
    # Calculate national daily averages from available city records.
    for rec in records.values():
        vals=[v for v in rec.get('markets',{}).values() if isinstance(v,(int,float))]
        if vals: rec['national_avg']=round(sum(vals)/len(vals),2)
    ordered=[records[k] for k in sorted(records)]
    hist={'source':'E2NECC current data + public historical archive backfill','coverage_start':ordered[0]['date'] if ordered else None,'coverage_end':ordered[-1]['date'] if ordered else None,'records':ordered,'notes':'Historical observations are only written when a public daily-history page provides them; missing city/month pages remain missing rather than being fabricated.'}
    HISTORY.write_text(json.dumps(hist,indent=2,ensure_ascii=False)+'\n')
    print('Backfilled',len(ordered),'daily records from January 2026 through',today.isoformat())
if __name__=='__main__':main()
