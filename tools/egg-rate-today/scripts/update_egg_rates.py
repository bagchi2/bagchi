#!/usr/bin/env python3
"""Fetch today's NECC suggested egg rates and append a verified daily snapshot."""
import json, re, sys
from datetime import datetime
from pathlib import Path
import requests
from bs4 import BeautifulSoup

ROOT=Path(__file__).resolve().parents[1]
DATA=ROOT/'data'; LATEST=DATA/'latest.json'; HISTORY=DATA/'history.json'
SOURCE='https://www.e2necc.com/home/eggprice'
META=[
('ahmedabad','Ahmedabad','Gujarat','West'),('ajmer','Ajmer','Rajasthan','North'),('allahabad','Allahabad (CC)','Uttar Pradesh','North'),('barwala','Barwala','Haryana','North'),('bengaluru','Bengaluru (CC)','Karnataka','South'),('bhopal','Bhopal','Madhya Pradesh','Central'),('brahmapur','Brahmapur (OD)','Odisha','East'),('chennai','Chennai (CC)','Tamil Nadu','South'),('chittoor','Chittoor','Andhra Pradesh','South'),('delhi','Delhi (CC)','Delhi NCR','North'),('e-godavari','E.Godavari','Andhra Pradesh','South'),('hospet','Hospet','Karnataka','South'),('hyderabad','Hyderabad','Telangana','South'),('indore','Indore (CC)','Madhya Pradesh','Central'),('jabalpur','Jabalpur','Madhya Pradesh','Central'),('kanpur','Kanpur (CC)','Uttar Pradesh','North'),('kolkata','Kolkata (WB)','West Bengal','East'),('ludhiana','Ludhiana','Punjab','North'),('luknow','Lucknow (CC)','Uttar Pradesh','North'),('mumbai','Mumbai (CC)','Maharashtra','West'),('muzaffurpur','Muzaffarpur (CC)','Bihar','East'),('mysuru','Mysuru','Karnataka','South'),('nagpur','Nagpur','Maharashtra','Central'),('namakkal','Namakkal','Tamil Nadu','South'),('patna','Patna','Bihar','East'),('pune','Pune','Maharashtra','West'),('raipur','Raipur','Chhattisgarh','Central'),('ranchi','Ranchi (CC)','Jharkhand','East'),('surat','Surat','Gujarat','West'),('varanasi','Varanasi (CC)','Uttar Pradesh','North'),('vijayawada','Vijayawada','Andhra Pradesh','South'),('vizag','Vizag','Andhra Pradesh','South'),('w-godavari','W.Godavari','Andhra Pradesh','South'),('warangal','Warangal','Telangana','South')]
ALIASES={re.sub(r'[^a-z0-9]','',n.lower()):i for i,n,_,_ in META}
for i,n,_,_ in META: ALIASES[re.sub(r'[^a-z0-9]','',i.lower())]=i

def money_num(s):
    s=str(s).replace(',','').replace('₹','').strip()
    try:return float(s)
    except:return None

def parse_tables(html):
    soup=BeautifulSoup(html,'html.parser'); out={}
    for table in soup.find_all('table'):
        rows=[]
        for tr in table.find_all('tr'):
            cells=[re.sub(r'\s+',' ',c.get_text(' ',strip=True)) for c in tr.find_all(['th','td'])]
            if cells: rows.append(cells)
        if len(rows)<5: continue
        for row in rows[1:]:
            name=row[0] if row else ''
            key=re.sub(r'[^a-z0-9]','',name.lower())
            mid=ALIASES.get(key)
            if not mid: continue
            nums=[]
            for cell in row[1:]:
                v=money_num(cell)
                if v is not None: nums.append(v)
            if not nums: continue
            # E2NECC commonly expresses the sheet in rupees per 100 eggs. Use the latest numeric daily value.
            val=nums[-1]
            if 100 <= val <= 2000: val=val/100.0
            if 1 <= val <= 20: out[mid]=round(val,2)
    return out

def fetch():
    r=requests.get(SOURCE,headers={'User-Agent':'Mozilla/5.0 (compatible; EggRateIndia/1.0)'},timeout=40)
    r.raise_for_status(); return parse_tables(r.text)

def main():
    rates=fetch()
    if len(rates)<10: raise RuntimeError(f'Only {len(rates)} markets parsed; refusing to overwrite good data. E2NECC HTML may have changed.')
    latest=json.loads(LATEST.read_text()) if LATEST.exists() else {'markets':[]}
    old={m['id']:m for m in latest.get('markets',[])}
    today=datetime.now().astimezone().strftime('%Y-%m-%d')
    markets=[]
    for i,n,s,z in META:
        if i not in rates and i not in old: continue
        cur=rates.get(i,old.get(i,{}).get('rate'))
        prev=old.get(i,{}).get('rate',cur)
        ch=round(cur-prev,2); pct=round((ch/prev*100),2) if prev else 0
        markets.append({'id':i,'name':n,'state':s,'zone':z,'rate':cur,'previous_rate':prev,'change24h':ch,'change24hPct':pct,'status':'up' if ch>0 else 'down' if ch<0 else 'flat'})
    avg=round(sum(m['rate'] for m in markets)/len(markets),2)
    oldavg=latest.get('national_avg',avg); nav=round(avg-oldavg,2)
    latest={'source':'E2NECC','source_url':SOURCE,'date':today,'fetched_at':datetime.now().astimezone().isoformat(),'national_avg':avg,'national_previous_rate':oldavg,'national_change':nav,'national_change_pct':round(nav/oldavg*100,2) if oldavg else 0,'national_status':'up' if nav>0 else 'down' if nav<0 else 'flat','markets':markets}
    LATEST.write_text(json.dumps(latest,indent=2,ensure_ascii=False)+'\n')
    hist=json.loads(HISTORY.read_text()) if HISTORY.exists() else {'records':[]}
    rec={m['id']:m['rate'] for m in markets}
    hist['records']=[r for r in hist.get('records',[]) if r.get('date')!=today]
    hist['records'].append({'date':today,'markets':rec,'national_avg':avg,'source':'E2NECC'})
    hist['records']=sorted(hist['records'],key=lambda x:x['date'])
    hist['coverage_start']=hist['records'][0]['date'] if hist['records'] else None
    hist['coverage_end']=hist['records'][-1]['date'] if hist['records'] else None
    HISTORY.write_text(json.dumps(hist,indent=2,ensure_ascii=False)+'\n')
    print(f'Updated {len(markets)} markets for {today}')
if __name__=='__main__': main()
