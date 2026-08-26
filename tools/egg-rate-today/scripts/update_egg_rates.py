import datetime
import calendar
import json
import re
from pathlib import Path

import requests
from bs4 import BeautifulSoup

SOURCE = 'https://www.e2necc.com/home/eggprice'
ROOT = Path(__file__).resolve().parents[1]
HEADERS = {'User-Agent': 'Mozilla/5.0 EggRateToday/2.0'}


def number(value):
    value = value.strip().replace(',', '')
    return float(value) if re.fullmatch(r'\d+(?:\.\d+)?', value) else None


def fetch_current():
    response = requests.get(SOURCE, headers=HEADERS, timeout=45)
    response.raise_for_status()
    soup = BeautifulSoup(response.text, 'html.parser')
    today = datetime.date.today()
    rows = []
    section = None
    for tr in soup.select('table tr'):
        cells = [re.sub(r'\s+', ' ', td.get_text(' ', strip=True)) for td in tr.find_all(['th', 'td'])]
        if not cells:
            continue
        label = cells[0]
        if label == 'NECC SUGGESTED EGG PRICES':
            section = 'suggested'
            continue
        if label == 'Prevailing Prices':
            section = 'prevailing'
            continue
        if section not in ('suggested', 'prevailing'):
            continue
        # Cells after the centre name map to day 1..31 followed by Average.
        day_values = cells[1:]
        if day_values:
            day_values = day_values[:-1]
        last_day = None
        last_rate = None
        for idx, raw in enumerate(day_values, start=1):
            n = number(raw)
            if n is not None:
                last_day = idx
                last_rate = round(n / 100, 4)
        if last_rate is not None and 1 <= last_day <= 31:
            actual_date = datetime.date(today.year, today.month, min(last_day, calendar.monthrange(today.year, today.month)[1]))
            if actual_date > today:
                continue
            rows.append({'name': label, 'rate': last_rate, 'source_type': section, 'currentDate': actual_date.isoformat()})
    if len(rows) < 30:
        raise RuntimeError(f'E2NECC parse guard: only {len(rows)} centre rows found')
    return rows


def main():
    latest = json.loads((ROOT / 'data/latest.json').read_text(encoding='utf-8'))
    rows = fetch_current()
    by_name = {m['name']: m for m in latest.get('markets', [])}
    latest_dates = []
    for row in rows:
        market = by_name.get(row['name'])
        if not market:
            continue
        market['source_type'] = row['source_type']
        market['currentRate'] = row['rate']
        market['currentDate'] = row['currentDate']
        latest_dates.append(row['currentDate'])
        history = market.setdefault('history', [])
        replaced = False
        for point in history:
            if point.get('date') == row['currentDate']:
                point['rate'] = row['rate']
                replaced = True
                break
        if not replaced:
            history.append({'date': row['currentDate'], 'rate': row['rate']})
        market['history'] = sorted(history, key=lambda x: x['date'])
    latest['date'] = datetime.date.today().isoformat()
    latest['lastSourcePublication'] = max(latest_dates) if latest_dates else latest.get('lastSourcePublication')
    latest['source'] = 'E2NECC'
    latest['sourceUrl'] = SOURCE
    (ROOT / 'data/latest.json').write_text(json.dumps(latest, indent=2, ensure_ascii=False), encoding='utf-8')
    hist = {
        'source': 'E2NECC',
        'sourceUrl': SOURCE,
        'rangeStart': '2026-01-01',
        'rangeEnd': latest.get('date'),
        'completeHistory': False,
        'recordsByMarket': {m['id']: sorted(m.get('history', []), key=lambda x: x['date']) for m in latest.get('markets', [])},
    }
    (ROOT / 'data/history.json').write_text(json.dumps(hist, indent=2, ensure_ascii=False), encoding='utf-8')
    print(f'Updated {len(rows)} E2NECC rows; latest source publication {latest.get("lastSourcePublication")}')


if __name__ == '__main__':
    main()
