import json,re,requests,time
from bs4 import BeautifulSoup
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]; SITE=ROOT/'tools'/'egg-rate-today'; DATA=SITE/'data'
HEAD={'User-Agent':'Mozilla/5.0 (compatible; BagchiEggRateBackfill/1.0)'}
ALIAS={
'Ahmedabad':'ahmedabad','Ajmer':'ajmer','Barwala':'barwala','Bengaluru (CC)':'bengaluru','Brahmapur (OD)':'brahmapur','Chennai (CC)':'chennai','Chittoor':'chittoor','Delhi (CC)':'delhi','E.Godavari':'east-godavari','Hospet':'hospet','Hyderabad':'hyderabad','Jabalpur':'jabalpur','Kolkata (WB)':'kolkata','Ludhiana':'ludhiana','Mumbai (CC)':'mumbai','Mysuru':'mysuru','Namakkal':'namakkal','Pune':'pune','Raipur':'raipur','Surat':'surat','Vijayawada':'vijayawada','Vizag':'visakhapatnam','W.Godavari':'west-godavari','Warangal':'warangal','Allahabad (CC)':'allahabad','Bhopal':'bhopal','Indore (CC)':'indore','Kanpur (CC)':'kanpur','Luknow (CC)':'lucknow','Muzaffurpur (CC)':'muzaffarpur','Nagpur':'nagpur','Patna':'patna','Ranchi (CC)':'ranchi','Varanasi (CC)':'varanasi'}

def parse_month(slug, ym):
    url=f'https://eggratetoday.com/egg-rate-history/{slug}/{ym}'
    r=requests.get(url,headers=HEAD,timeout=40)
    if r.status_code!=200: return []
    soup=BeautifulSoup(r.text,'html.parser'); text=soup.get_text('\n',strip=True)
    # target table from Daily Egg Prices section
    rows=[]; table=None
    for t in soup.find_all('table'):
        if 'Date' in t.get_text(' ',strip=True) and 'NECC Price' in t.get_text(' ',strip=True): table=t; break
    if not table: return []
    for tr in table.find_all('tr'):
        cells=[re.sub(r'\s+',' ',c.get_text(' ',strip=True)) for c in tr.find_all(['th','td'])]
        if len(cells)>=2 and re.match(r'^[A-Za-z]+ \d{1,2}, 2026$',cells[0]):
            v=re.search(r'([0-9]+(?:\.[0-9]+)?)',cells[1].replace(',',''))
            if v:
                rows.append({'date':cells[0],'rate':float(v.group(1))})
    return rows

def main():
    hist=json.loads((DATA/'history.json').read_text())
    months=[f'2026-{m:02d}' for m in range(1,9)]
    for city,slug in ALIAS.items():
        c=hist.setdefault('markets',{}).setdefault(city,{'months':[],'daily':[]})
        daily={x['date']:x for x in c.get('daily',[])}
        monthly={x['month']:x for x in c.get('months',[])}
        got=0
        for ym in months:
            try: rows=parse_month(slug,ym)
            except Exception as e: print('skip',city,ym,e); rows=[]
            if rows:
                got+=len(rows)
                for x in rows: daily[x['date']]=x
                vals=[x['rate'] for x in rows]
                monthly[ym]={'month':ym,'avg':round(sum(vals)/len(vals),2),'high':max(vals),'low':min(vals)}
            time.sleep(.12)
        c['daily']=sorted(daily.values(),key=lambda x:x['date'])
        c['months']=sorted(monthly.values(),key=lambda x:x['month'])
        print(city, 'rows',got)
    hist['history_backfill']='2026-01 through 2026-08; public NECC historical mirror used for bootstrap, with official E2NECC as the live source.'
    (DATA/'history.json').write_text(json.dumps(hist,indent=2,ensure_ascii=False),encoding='utf-8')
if __name__=='__main__': main()
