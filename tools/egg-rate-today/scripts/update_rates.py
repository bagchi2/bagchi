import json,re,requests
from datetime import datetime
from pathlib import Path
from bs4 import BeautifulSoup
SOURCE="https://www.e2necc.com/home/eggprice";ROOT=Path(__file__).resolve().parents[1]
def parse():
 html=requests.get(SOURCE,headers={"User-Agent":"Mozilla/5.0"},timeout=30).text;soup=BeautifulSoup(html,"html.parser");out=[]
 for table in soup.find_all("table"):
  for tr in table.find_all("tr"):
   c=[re.sub(r"\s+"," ",x.get_text(" ",strip=True)) for x in tr.find_all(["th","td"])]
   if len(c)<3 or c[0] in ("Name Of Zone / Day","---") or c[0].lower().startswith("necc suggested"):continue
   nums=[float(x) if re.fullmatch(r"\d+(?:\.\d+)?",x) else None for x in c[1:]]
   vals=[x for x in nums[:-1] if x is not None] if len(nums)>1 else [x for x in nums if x is not None]
   if vals and 100<=vals[-1]<=2000:out.append({"name":c[0],"rate":int(vals[-1]) if vals[-1].is_integer() else vals[-1],"type":"NECC suggested"})
 seen=set();return [x for x in out if not (x["name"].lower() in seen or seen.add(x["name"].lower()))]
m=parse()
if len(m)<10:raise RuntimeError("E2NECC parser found too few markets; source HTML may have changed.")
today=datetime.now().astimezone().strftime("%Y-%m-%d");latest={"source":"E2NECC","source_url":SOURCE,"date":today,"markets":m}
(ROOT/"data/latest.json").write_text(json.dumps(latest,indent=2,ensure_ascii=False))
h=json.loads((ROOT/"data/history.json").read_text()) if (ROOT/"data/history.json").exists() else {"records":[]};h["records"]=[r for r in h["records"] if r["date"]!=today]+[latest];h["records"]=h["records"][-365:];(ROOT/"data/history.json").write_text(json.dumps(h,indent=2,ensure_ascii=False))
print("Updated",len(m),"markets for",today)
