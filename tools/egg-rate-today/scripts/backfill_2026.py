import asyncio,datetime,json,re
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[1];URL='https://www.e2necc.com/home/eggprice'
MONTHS=['January','February','March','April','May','June','July','August','September','October','November','December']
def clean(x): return re.sub(r'\s+',' ',x).strip()
async def find_select(page,targets):
    sels=page.locator('select')
    for i in range(await sels.count()):
        texts=[clean(x).lower() for x in await sels.nth(i).locator('option').all_text_contents()]
        if any(t.lower() in texts for t in targets): return sels.nth(i)
    return None
async def choose_daily(page):
    loc=page.get_by_text('Daily Rate Sheet',exact=True)
    if await loc.count():
        try: await loc.first().click(timeout=3000);await page.wait_for_timeout(700);return
        except: pass
    radios=page.locator('input[type=radio]')
    for i in range(await radios.count()):
        val=(await radios.nth(i).get_attribute('value') or '').lower()
        if 'daily' in val:
            try: await radios.nth(i).check();await page.wait_for_timeout(700);return
            except: pass
async def parse_month(page,year,month,today):
    await page.goto(URL,wait_until='domcontentloaded',timeout=90000);await page.wait_for_timeout(800)
    ms=await find_select(page,[MONTHS[month-1]]);ys=await find_select(page,[str(year)])
    if not ms or not ys: raise RuntimeError('Month/Year selector not found')
    try: await ys.select_option(label=str(year))
    except: await ys.select_option(str(year))
    await page.wait_for_timeout(500)
    try: await ms.select_option(label=MONTHS[month-1])
    except:
        for i in range(await ms.locator('option').count()):
            txt=clean(await ms.locator('option').nth(i).inner_text())
            if MONTHS[month-1].lower() in txt.lower():
                await ms.select_option(await ms.locator('option').nth(i).get_attribute('value'));break
    await page.wait_for_timeout(1200);await choose_daily(page)
    rows=page.locator('table tr');section=None;out=[]
    for i in range(await rows.count()):
        c=[clean(x) for x in await rows.nth(i).locator('th,td').all_text_contents()]
        if not c: continue
        label=c[0]
        if label=='NECC SUGGESTED EGG PRICES': section='suggested';continue
        if label=='Prevailing Prices': section='prevailing';continue
        if section not in ('suggested','prevailing'): continue
        nums=[float(x)/100 if re.fullmatch(r'\d+(?:\.\d+)?',x) else None for x in c[1:]]
        for day,v in enumerate(nums[:-1],1):
            if v is None: continue
            dt=datetime.date(year,month,day)
            if dt<=today: out.append((label,section,dt.isoformat(),round(v,4)))
    return out
async def main():
    today=datetime.date.today();months=[];cur=datetime.date(2026,1,1)
    while cur<=today.replace(day=1):
        months.append((cur.year,cur.month));nxt=cur.replace(day=28)+datetime.timedelta(days=4);cur=nxt.replace(day=1)
    allrows=[]
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True);page=await browser.new_page(viewport={'width':1400,'height':900})
        for y,m in months:
            try:
                rows=await parse_month(page,y,m,today);allrows.extend(rows);print(MONTHS[m-1],y,len(rows))
            except Exception as e: print('WARN',y,m,e)
        await browser.close()
    if len(allrows)<200: raise RuntimeError(f'Backfill guard: only {len(allrows)} observations collected')
    latest=json.loads((ROOT/'data/latest.json').read_text(encoding='utf-8'))
    lookup={(m['name'],m.get('source_type','suggested')):m for m in latest['markets']};series={}
    for n,s,d,r in allrows: series.setdefault((n,s),{})[d]={'date':d,'rate':r}
    for key,vals in series.items():
        m=lookup.get(key)
        if m:
            m['history']=sorted(vals.values(),key=lambda x:x['date']);m['currentRate']=m['history'][-1]['rate'];m['currentDate']=m['history'][-1]['date']
    latest['date']=today.isoformat();latest['lastSourcePublication']=max(m.get('currentDate','2026-01-01') for m in latest['markets'])
    (ROOT/'data/latest.json').write_text(json.dumps(latest,indent=2,ensure_ascii=False),encoding='utf-8')
    h={'source':'E2NECC','sourceUrl':URL,'rangeStart':'2026-01-01','rangeEnd':today.isoformat(),'completeHistory':True,'recordsByMarket':{m['id']:m.get('history',[]) for m in latest['markets']},'recordsByName':{m['name']:m.get('history',[]) for m in latest['markets']}}
    (ROOT/'data/history.json').write_text(json.dumps(h,indent=2,ensure_ascii=False),encoding='utf-8');print('Backfill markets',len(series))
if __name__=='__main__': asyncio.run(main())
