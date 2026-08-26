import json,re,requests
from bs4 import BeautifulSoup
from datetime import date
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]; SITE=ROOT/'tools'/'egg-rate-today'; DATA=SITE/'data'
SOURCE='https://www.e2necc.com/home/eggprice'
HEAD={'User-Agent':'Mozilla/5.0 (compatible; BagchiEggRateBot/1.0)'}

def clean(x): return re.sub(r'\s+',' ',x).strip()
def parse_source():
    html=requests.get(SOURCE,headers=HEAD,timeout=40).text
    soup=BeautifulSoup(html,'html.parser')
    markets=[]; inside=False
    for table in soup.find_all('table'):
        rows=table.find_all('tr')
        for tr in rows:
            cells=[clean(c.get_text(' ',strip=True)) for c in tr.find_all(['th','td'])]
            if not cells: continue
            first=cells[0]
            if 'NECC SUGGESTED EGG PRICES' in first.upper(): inside=True; continue
            if 'Prevailing Prices' in first: inside=False
            if not inside or len(cells)<2: continue
            name=first
            if name in ('---','Name Of Zone / Day') or not name: continue
            nums=[]
            for c in cells[1:]:
                if re.fullmatch(r'\d+(?:\.\d+)?',c): nums.append(float(c))
                elif c=='-': nums.append(None)
            vals=[x for x in nums if x is not None]
            if vals:
                v=vals[-1]
                if 100<=v<=2000: markets.append({'name':name,'rate':int(v) if v.is_integer() else v})
    # de-duplicate
    out=[]; seen=set()
    for m in markets:
        k=m['name'].lower()
        if k not in seen: seen.add(k); out.append(m)
    if len(out)<15: raise RuntimeError(f'Official parser only found {len(out)} markets.')
    return out

def main():
    m=parse_source(); today=date.today().isoformat()
    latest={'source':'E2NECC','source_url':SOURCE,'date':today,'markets':m}
    (DATA/'latest.json').write_text(json.dumps(latest,indent=2,ensure_ascii=False),encoding='utf-8')
    hist=json.loads((DATA/'history.json').read_text()) if (DATA/'history.json').exists() else {'source_note':'','markets':{}}
    for item in m:
        c=hist.setdefault('markets',{}).setdefault(item['name'],{'months':[],'daily':[]})
        # store 100-egg rate in daily history as per official source
        daily=c.setdefault('daily',[]); daily[:]=[x for x in daily if x.get('date')!=today]; daily.append({'date':today,'rate':item['rate']/100})
        daily.sort(key=lambda x:x['date']); c['daily']=daily[-3650:]
    hist['last_official_update']=today
    (DATA/'history.json').write_text(json.dumps(hist,indent=2,ensure_ascii=False),encoding='utf-8')
    print(f'Updated official E2NECC rates for {len(m)} markets: {today}')
if __name__=='__main__': main()
