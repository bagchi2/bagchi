import json, re
from datetime import date, timedelta
from html import escape
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
INDEX = ROOT / 'index.html'
CITY_DIR = ROOT / 'cities'
BASE = 'https://bagchi.in/tools/egg-rate-today/'
SOURCE = 'https://www.e2necc.com/home/eggprice'
DATA = json.loads((ROOT / 'data/latest.json').read_text(encoding='utf-8'))
MARKETS = DATA.get('markets', [])


def money(x):
    return f"₹{float(x):,.2f}"


def clean_history(m):
    rows = [x for x in (m.get('history') or []) if isinstance(x, dict) and x.get('date') and x.get('rate') is not None]
    return sorted(rows, key=lambda x: x['date'])


def market_stats(m):
    h = clean_history(m)
    rates = [float(x['rate']) for x in h]
    if not rates:
        rates = [float(m['currentRate'])]
    return {
        'first_date': h[0]['date'] if h else m.get('currentDate', DATA.get('date', '')),
        'last_date': h[-1]['date'] if h else m.get('currentDate', DATA.get('date', '')),
        'low': min(rates),
        'high': max(rates),
        'count': len(h),
        'source_type': m.get('source_type', 'suggested').title(),
    }


def article_paragraphs(m, home=False):
    if home:
        valid = [x for x in MARKETS if x.get('currentRate') is not None]
        low = min(valid, key=lambda x: float(x['currentRate']))
        high = max(valid, key=lambda x: float(x['currentRate']))
        avg = sum(float(x['currentRate']) for x in valid) / len(valid)
        intro_name = 'India'
        r = None
        current_line = (
            f"The current board covers {len(valid)} reference centres. The lowest displayed market is {low['name']} at {money(low['currentRate'])} per egg, while the highest is {high['name']} at {money(high['currentRate'])}. The simple average of the displayed centre values is {money(avg)} per egg; that figure is a comparison aid, not an official national weighted index."
        )
        paragraphs = [
            f"Egg Rate Today is designed for people who want the egg rate today without hunting through a long market sheet. The India dashboard brings the published E2NECC-linked figures into one readable screen and keeps the important distinctions visible. Instead of presenting a single number as if it represented every local market, the site shows individual centres, source categories, quantity calculations, historical observations and direct market links. That makes the page useful for quick checking as well as for a more careful look at market movement.",
            f"{current_line} The spread between markets is exactly why a city-level view matters. A buyer in one region can face a very different commercial environment from a distributor in another region even on the same date. Egg Rate Today therefore treats the market centre as the primary reference point and the national overview as a navigation and comparison layer.",
            "E2NECC publishes a daily rate sheet with suggested egg prices and, on the source page, a separate prevailing-price section. The site keeps the source terminology rather than blending the two concepts silently. Most importantly, a suggested benchmark should not be mistaken for a compulsory retail tariff. Actual selling prices can change because of location, transport, packaging, grade, demand, supply, negotiations and other commercial conditions. This is why every page keeps a clear source and disclaimer context near the data.",
            "Date handling is also important. A monthly sheet can contain blank cells for dates that have not yet been published, and a blank cell is not a zero-rate observation. Egg Rate Today stores only the numbers it can actually identify in the source table. Historical records are tied to calendar dates, and missing observations remain missing. This is deliberately less flashy than inventing a continuous line, but it is much safer for a market-information site where readers may use numbers in real decisions.",
            "The quantity conversions on this site are transparent arithmetic. Thirty eggs are the per-egg reference multiplied by 30, one hundred eggs are the same rate multiplied by 100, and a 210-egg peti is calculated using the same per-egg figure. These calculations should not be read as separate quotations from E2NECC. They are convenience values generated so that a visitor can move from a single-piece benchmark to a practical order size without opening a calculator in another tab.",
            "Historical charts add a second layer of usefulness. A headline rate can tell you where the market stands today, but a chart can show whether the number has been stable, climbing, falling or moving through short-lived spikes. Egg Rate Today uses the historical observations collected by the project to build those trends. On individual market pages, the same source design is reused so the visual language stays consistent while the selected market changes. The goal is familiar navigation with market-specific information.",
            "For buyers and sellers, the strongest habit is to compare like with like. Use the same source category, the same market centre and the same date when assessing changes. A month-to-month comparison can be useful, but a single-day comparison between two different regions does not necessarily explain why one market is higher. Local logistics, production patterns and nearby demand can all influence the commercial price that eventually reaches a shop, restaurant or wholesale buyer.",
            "The home page is intentionally connected to the city pages. Every market name in the live board is a direct link, and the directory section provides another route to the same pages. Each city page then links back to the main dashboard and to the rest of the market directory. This internal-linking structure is helpful for people who arrive on a city page from a search engine: they can immediately move to the wider India board instead of getting trapped on a single article.",
            "The project also separates website calculations from source observations. A displayed tray price, peti price or recommended commercial amount may involve arithmetic or user-entered assumptions. Those values are not rewritten into the historical source record. The underlying history remains the historical rate, while the calculator works on top of it. Keeping those layers separate makes it easier to update the site each day without corrupting the original historical series.",
            "For commercial users, the calculator is intended as a planning tool rather than a promise of final margin. Freight, handling, breakage, local taxes and other costs may differ from the simple freight-per-egg field. A distributor can use the calculator to model a scenario, but the final selling decision should still be based on the actual logistics and commercial terms available for the order. The calculator is most valuable when its assumptions are realistic and changed deliberately rather than left at a default forever.",
            "Search users also benefit from dedicated city pages because a generic national page cannot answer every local query well. A reader searching for an egg rate today in Kolkata, Chennai, Delhi or another listed centre reaches a page with the centre name in the title, description and body. That page carries the current rate, quantity calculations, historical table, chart, FAQ and links back to the market directory. The data hierarchy stays consistent, so visitors do not have to learn a new layout for each location.",
            "The data workflow is built for daily maintenance. A scheduled automation visits the E2NECC source, processes the current sheet, updates the local JSON dataset and regenerates the HTML pages. A separate backfill job can move month by month from January 2026 through the current month to populate the historical archive. A validation guard prevents a suspiciously tiny scrape from silently replacing the stored dataset. That is important because source websites can change their HTML structure without warning.",
            "Source attribution is deliberately visible. The official E2NECC page is linked from the website, the source URL is stored in the JSON data, and the site repeats the benchmark disclaimer so that a reader can distinguish published reference information from a local retail quotation. The intent is transparency, not to present the independent website as the authority that created the underlying prices. Egg Rate Today is an information layer built around the source material.",
            "A practical way to use the site is simple: check the date, read the market name, note the source category, inspect the historical trend, and only then use the quantity calculator for an order. If the number will appear in a formal quotation or purchasing document, verify the source again on the publication date and add the logistics assumptions that are specific to the transaction. This approach keeps the site useful while respecting the difference between a reference benchmark and a final negotiated price.",
            "The project is also structured to remain maintainable. The visual design comes from the provided Egg Rate dashboard, while the data files, scrapers and page builder are kept separate inside the same project folder. That separation means a new daily rate can update the content without forcing a redesign. It also means future changes—such as another market, a new historical view or a new calculator option—can be made without rewriting the entire site from scratch.",
            "In practical terms, Egg Rate Today is meant to be a dependable daily reference rather than a novelty chart. The combination of current market values, historical observations, transparent calculations, city pages, internal navigation and source notes gives the visitor enough context to interpret the number rather than simply copying it. The best number on a market website is not the one with the most dramatic headline; it is the one a reader can understand, date, trace and use responsibly."
        ]
        return intro_name, paragraphs

    name = m['name']
    r = float(m['currentRate'])
    stats = market_stats(m)
    change = float(m.get('change24hPct', 0) or 0)
    direction = 'higher' if change > 0 else 'lower' if change < 0 else 'unchanged'
    change_text = (' by ' + format(abs(change), '.2f') + '% on the stored 24-hour comparison.' if change else ' on the stored 24-hour comparison.')
    paragraphs = [
        f"{name} egg rate today is the central figure on this market page, but the number is presented with the surrounding information that makes it meaningful. Egg Rate Today shows the current reference at {money(r)} per egg and then converts that figure into practical quantities such as 30 eggs, 100 eggs and 210 eggs. The purpose is simple: a reader should be able to understand the market rate quickly and still have enough context to avoid confusing a source benchmark with a final local selling price.",
        f"The latest stored observation for {name} is {money(r)} per egg. On the current day-to-day comparison used by the site, the market is marked {direction}{change_text} This directional figure should be read alongside the source date and centre name rather than as a promise that a shop price moved by exactly the same amount. The market board is intended to make that distinction visible rather than hide it behind a single headline percentage.",
        f"E2NECC publishes market information by centre and distinguishes suggested prices from prevailing prices on its source page. The current {name} record is labelled as {stats['source_type']} in the local dataset. That source label is retained because it matters when comparing observations across dates. A reference price is not automatically a retail price, and a retail quote can include transport, handling, packaging, local demand, taxes or other commercial factors that are outside the published benchmark.",
        f"The historical series for {name} currently contains {stats['count']} stored observations in the local dataset, beginning on {stats['first_date']} and ending on {stats['last_date']}. Within those stored observations, the lowest recorded value is {money(stats['low'])} and the highest is {money(stats['high'])}. These figures are useful for context because they show the range that the stored series has covered. They are not a forecast, and they should not be treated as a guarantee that the market must return to any particular level.",
        "Date discipline is especially important on a daily egg-rate website. The E2NECC sheet can include empty cells for dates that are not yet published, so the backfill process does not convert an empty cell into a false zero. Each historical point is tied to the calendar date represented by the source table. If an observation is absent, the history keeps the gap instead of fabricating a number merely to make the graph look continuous.",
        f"The 30-egg calculation for {name} is based on the current reference rate: {money(r * 30)}. The 100-egg calculation is {money(r * 100)}, while a 210-egg peti works out to {money(r * 210)}. These are mathematical conversions of the displayed per-egg figure. They should therefore be understood as convenience calculations rather than separate prices independently published by E2NECC. This distinction becomes particularly important when the local retail price has additional costs built into it.",
        "The chart on this page is designed to answer a different question from the headline rate. Instead of asking only what the price is today, it lets the reader see what happened over time. A line that rises steadily tells a different story from a line that jumps sharply and then falls back. Historical context can help a buyer decide whether today's level looks routine or unusual, while a trader can use the same visual to identify a period worth checking more closely.",
        f"A useful comparison is to place {name} beside another centre on the same dates and using the same source category. That avoids one of the most common mistakes on price websites: comparing a suggested price in one market with a prevailing local figure in another and treating the difference as if it were purely regional. Egg Rate Today keeps city pages connected so a visitor can move from {name} to another market in a click and keep the same site structure while making that comparison.",
        "The city page also acts as a compact research note. A person arriving from a search result can see the price first, then read the quantity conversions, check the historical table, inspect the graph and move back to the national dashboard. This layout is intentional. People often search for a single city price but then immediately want to know whether the rate is rising, how it compares with another hub, or whether the change is part of a broader movement.",
        "For retailers and distributors, the commercial calculator is a planning layer on top of the source rate. It can combine quantity, freight and a chosen margin to estimate a landed or billing scenario. The calculation should not replace the actual cost sheet for a purchase because freight, loading, unloading, breakage, credit terms and local expenses can vary. It is best used to test assumptions quickly and then replaced by verified transaction costs before a commercial quote is finalized.",
        f"The source page also provides a reason to keep the disclaimer visible: suggested prices are intended as reference information and are not mandatory selling prices. Egg Rate Today therefore avoids calling the {name} figure an official retail price. The page calls it a reference rate and keeps a source link to E2NECC. That wording is not just a legal footnote; it changes how the number should be interpreted by someone who is deciding what to pay or what to charge.",
        "The internal-link structure is another deliberate part of the page. The home market board links directly to this city page, while this page links back to the complete city directory. Related markets are therefore never more than a click away. For search visitors, that creates a logical route through the site; for regular users, it makes daily checking faster. A strong information architecture matters because the best market data is less useful when the reader cannot move easily between related pages.",
        "The underlying daily process is automated through the project scripts. A scheduled workflow fetches the E2NECC source, validates the structure, updates the latest JSON snapshot and regenerates the HTML. The historical backfill uses the month and year controls on the E2NECC page to process January 2026 through the current month. The scraper includes guard checks so an unexpected source change is more likely to stop the update than silently publish an incomplete dataset.",
        f"For {name}, the safest workflow is therefore to read the current rate, confirm the publication date, inspect the historical range and then use the quantity figures for the transaction you actually have. If you are comparing another city, open it from the market directory rather than changing the reference date or source category. This produces a cleaner comparison and reduces the risk of drawing a conclusion from two numbers that were never intended to be compared directly.",
        "The purpose of Egg Rate Today is not to make the market look more certain than it is. Prices move, publication dates can differ, and local retail conditions can change faster than a central reference sheet. A well-designed tracker should make those boundaries visible. By preserving the source context, separating calculations from observations and keeping historical data tied to real dates, the site is intended to be more useful for everyday checking, publishing, buying and planning than a simple static number would be.",
        f"In summary, {name} egg rate today is {money(r)} per egg in the current stored dataset, with a history that can be reviewed rather than guessed. Use the chart to understand movement, the table to inspect exact dates, the calculator to model quantities, and the market directory to compare centres. Always remember that E2NECC reference data is a benchmark rather than a mandatory retail tariff. That combination of current context and historical evidence is the core reason this city page exists."
    ]
    return name, paragraphs


def article_html(m, home=False):
    title = 'Egg Rate Today in India — Complete Market Guide' if home else f'{m["name"]} Egg Rate Today — Complete Market Guide'
    _, paras = article_paragraphs(m, home)
    out = [f'<section class="card section ert-article"><div class="section-title">📚 {escape(title)}</div>']
    heads = ['Understanding the source and the number','How dates and historical records are handled','Quantity calculations and practical buying sizes','Reading the chart and comparing markets','Using the calculator responsibly','Why the source disclaimer matters','Internal navigation and search intent','Daily automation and data quality','A practical way to use this market page','Final market perspective']
    for i, p in enumerate(paras):
        if i in (2,4,6,8,10,12,14):
            out.append(f'<h3>{heads[min(i//2-1, len(heads)-1)]}</h3>')
        out.append(f'<p>{escape(p)}</p>')
    out.append('</section>')
    return ''.join(out)


def faq(m, home=False):
    if home:
        valid = [x for x in MARKETS if x.get('currentRate') is not None]
        low = min(valid, key=lambda x: float(x['currentRate']))
        high = max(valid, key=lambda x: float(x['currentRate']))
        return [
            ('What is egg rate today in India?', f"Egg Rate Today currently shows a market reference range from {money(low['currentRate'])} at {low['name']} to {money(high['currentRate'])} at {high['name']} among the displayed centres."),
            ('What is the lowest egg rate today on this site?', f"The lowest displayed centre is {low['name']} at {money(low['currentRate'])} per egg in the current stored dataset."),
            ('What is the highest egg rate today on this site?', f"The highest displayed centre is {high['name']} at {money(high['currentRate'])} per egg in the current stored dataset."),
            ('How is the 30-egg price calculated?', 'The website multiplies the per-egg reference by 30. It is a calculation, not a separate quoted source price.'),
            ('Does E2NECC egg rate mean the final retail price?', 'No. E2NECC describes its suggested values as reference information, and actual local or retail prices can differ.'),
            ('Does the website update every day?', 'The included scheduled workflow is configured to scrape the E2NECC source, update the data files and rebuild the pages daily.'),
            ('Can I open a separate page for each market?', 'Yes. Every market name in the home page table links to its dedicated Egg Rate Today page.')
        ]
    r = float(m['currentRate'])
    return [
        (f'What is {m["name"]} egg rate today?', f"The current stored {m['name']} rate is {money(r)} per egg."),
        (f'What is the {m["name"]} price for 30 eggs?', f"At the current stored rate, 30 eggs calculate to {money(r*30)}."),
        (f'What is the {m["name"]} price for 100 eggs?', f"At the current stored rate, 100 eggs calculate to {money(r*100)}."),
        (f'What is the {m["name"]} price for 210 eggs?', f"At the current stored rate, 210 eggs calculate to {money(r*210)}."),
        (f'Is the {m["name"]} E2NECC suggested rate mandatory?', 'No. E2NECC states that suggested prices are reference information and are not mandatory selling prices.'),
        (f'Does this {m["name"]} page show historical prices?', 'Yes. The page includes the stored historical table and an interactive trend chart for the available source observations.'),
    ]


def faq_html(m, home=False):
    return '<section class="card section ert-faq"><div class="section-title">❓ Egg Rate Today FAQ</div>' + ''.join(
        f'<details><summary>{escape(q)}</summary><p class="section-sub" style="line-height:1.7;margin-top:8px">{escape(a)}</p></details>' for q, a in faq(m, home)
    ) + '</section>'


def links(prefix, current=None):
    items = []
    for m in sorted(MARKETS, key=lambda x: x['name'].lower()):
        cls = ' ert-current-city' if current == m['id'] else ''
        items.append(f'<a class="ert-city-link{cls}" href="{prefix}cities/{m["id"]}.html">🥚 {escape(m["name"])} — Egg Rate Today</a>')
    return '<section class="card section ert-links-section"><div class="section-title">🧭 All City Egg Rate Today Pages</div><p class="section-sub">Every market below is linked directly, so the entire Egg Rate Today directory stays connected.</p><div class="ert-links-grid">' + ''.join(items) + '</div></section>'


def history_html(m):
    rows = list(reversed(clean_history(m)))
    if not rows:
        return '<section class="card section ert-history"><div class="section-title">📅 Historical Egg Rate Table</div><p class="section-sub">Historical observations are not available yet for this market.</p></section>'
    return '<section class="card section ert-history"><div class="section-title">📅 Historical Egg Rate Table — ' + escape(m['name']) + '</div><p class="section-sub">Source observations stored by date. Empty source cells are not converted into zero values.</p><div class="ert-history-table"><table><thead><tr><th>Date</th><th>Rate / Egg</th><th>30 Eggs</th><th>100 Eggs</th><th>210 Eggs</th></tr></thead><tbody>' + ''.join(
        f'<tr><td>{escape(x["date"])}</td><td>{money(x["rate"])}</td><td>{money(x["rate"]*30)}</td><td>{money(x["rate"]*100)}</td><td>{money(x["rate"]*210)}</td></tr>' for x in rows
    ) + '</tbody></table></div></section>'


def safe_date_plus_one(iso):
    try:
        return (date.fromisoformat(iso) + timedelta(days=1)).isoformat()
    except Exception:
        return iso


def schema(m, home=False):
    graph = []
    valid = [float(x['currentRate']) for x in MARKETS if x.get('currentRate') is not None]
    today = DATA.get('date', '')
    if home:
        low = min(valid) if valid else 0
        high = max(valid) if valid else 0
        graph.append({
            '@type': 'Product',
            'name': 'Egg Rate Today India',
            'brand': {'@type': 'Brand', 'name': 'egg rate today'},
            'description': 'India egg-rate reference dashboard based on E2NECC-linked data.',
            'offers': {'@type': 'AggregateOffer', 'priceCurrency': 'INR', 'lowPrice': f'{low:.2f}', 'highPrice': f'{high:.2f}', 'offerCount': len(MARKETS), 'url': BASE}
        })
        graph.append({'@type': 'ItemList', 'name': 'Egg Rate Today City Pages', 'itemListElement': [
            {'@type': 'ListItem', 'position': i + 1, 'name': x['name'] + ' Egg Rate Today', 'url': BASE + 'cities/' + x['id'] + '.html'} for i, x in enumerate(MARKETS)
        ]})
    else:
        r = float(m['currentRate'])
        url = BASE + 'cities/' + m['id'] + '.html'
        graph.append({
            '@type': 'Product',
            'name': f'{m["name"]} Egg Rate Today',
            'brand': {'@type': 'Brand', 'name': 'egg rate today'},
            'description': f'Current {m["name"]} egg rate reference and quantity prices.',
            'offers': {'@type': 'Offer', 'url': url, 'priceCurrency': 'INR', 'price': f'{r:.2f}', 'availability': 'https://schema.org/InStock', 'priceValidUntil': safe_date_plus_one(m.get('currentDate') or today)}
        })
        graph.append({
            '@type': 'Place',
            'name': m['name'],
            'address': {'@type': 'PostalAddress', 'addressLocality': m['name'], 'addressRegion': m['state'], 'addressCountry': 'IN'}
        })
        graph.append({
            '@type': 'BreadcrumbList',
            'itemListElement': [
                {'@type': 'ListItem', 'position': 1, 'name': 'Egg Rate Today', 'item': BASE},
                {'@type': 'ListItem', 'position': 2, 'name': m['name'] + ' Egg Rate Today', 'item': url}
            ]
        })
    graph.append({
        '@type': 'Rating',
        'name': 'Data freshness rating',
        'ratingValue': '5',
        'bestRating': '5',
        'worstRating': '1',
        'description': 'Editorial freshness indicator for source synchronization; not a customer review rating.'
    })
    graph.append({'@type': 'FAQPage', 'mainEntity': [
        {'@type': 'Question', 'name': q, 'acceptedAnswer': {'@type': 'Answer', 'text': a}} for q, a in faq(m, home)
    ]})
    body = ' '.join(article_paragraphs(m, home)[1])
    graph.append({
        '@type': 'Article',
        'headline': 'Egg Rate Today India' if home else f'{m["name"]} Egg Rate Today',
        'description': 'Human-readable Egg Rate Today market guide.',
        'datePublished': '2026-08-26',
        'dateModified': today,
        'author': {'@type': 'Organization', 'name': 'egg rate today'},
        'publisher': {'@type': 'Organization', 'name': 'egg rate today'},
        'mainEntityOfPage': {'@type': 'WebPage', '@id': BASE if home else BASE + 'cities/' + m['id'] + '.html'},
        'wordCount': len(re.findall(r"\b[\w’'-]+\b", body)),
        'articleBody': body
    })
    return {'@context': 'https://schema.org', '@graph': graph}


def head(m, home=False):
    if home:
        title = 'Egg Rate Today — Today Egg Rate in India | NECC Egg Price'
        desc = 'Egg rate today across India with E2NECC reference prices, city-wise rates, tray calculations, historical charts, market tables and daily updates.'
        keys = 'egg rate today, today egg rate, egg price today, NECC egg rate, India egg price, city egg rate today'
        url = BASE
    else:
        title = f'{m["name"]} Egg Rate Today — Today Egg Price & NECC Rate'
        desc = f'{m["name"]} egg rate today with current E2NECC-linked price, 30 eggs, 100 eggs, 210 eggs, historical table, trend chart, FAQ and market guide.'
        keys = f'egg rate today, {m["name"]} egg rate today, {m["name"]} egg price today, {m["name"]} NECC egg rate'
        url = BASE + 'cities/' + m['id'] + '.html'
    return (
        f'<title>{escape(title)}</title>\n'
        f'<meta name="description" content="{escape(desc)}">\n'
        f'<meta name="keywords" content="{escape(keys)}">\n'
        f'<meta name="robots" content="index,follow,max-image-preview:large">\n'
        f'<link rel="canonical" href="{url}">\n'
        f'<meta property="og:site_name" content="egg rate today"><meta property="og:type" content="website">'
        f'<meta property="og:title" content="{escape(title)}"><meta property="og:description" content="{escape(desc)}"><meta property="og:url" content="{url}">'
        f'<meta name="twitter:card" content="summary_large_image"><meta name="twitter:title" content="{escape(title)}"><meta name="twitter:description" content="{escape(desc)}">'
        f'<script type="application/ld+json">{json.dumps(schema(m, home), ensure_ascii=False)}</script>'
    )


def strip_generated(html):
    patterns = [
        r'\n<section class="card section ert-links-section">.*?</section>\n',
        r'\n<section class="card section ert-article">.*?</section>\n',
        r'\n<section class="card section ert-faq">.*?</section>\n',
        r'\n<section class="card section ert-history">.*?</section>\n',
    ]
    for pattern in patterns:
        html = re.sub(pattern, '\n', html, flags=re.S)
    return html


def insert_before_notice(html, block):
    idx = html.find('  <!-- NOTICE -->')
    if idx < 0:
        idx = html.find('  <!-- MODAL -->')
    if idx < 0:
        return html + '\n' + block
    return html[:idx] + block + '\n\n' + html[idx:]


def replace_bootstrap(html):
    marker = 'window.__EGG_BOOTSTRAP__='
    start = html.find(marker)
    if start < 0:
        return html
    end = html.find('</script>', start)
    if end < 0:
        return html
    return html[:start] + marker + json.dumps(DATA, separators=(',', ':'), ensure_ascii=False) + ';' + html[end:]


def patch_runtime(js_html):
    # Add a reliable city-link prefix for both home and city pages.
    needle = "const JSON_HISTORY = ROOT + 'data/history.json';"
    if 'const CITY_LINK_PREFIX = ROOT + \'cities/\';' not in js_html:
        js_html = js_html.replace(needle, needle + "\n  const CITY_LINK_PREFIX = ROOT + 'cities/';")
    # Strict-mode calculator declaration fix.
    js_html = js_html.replace(
        'base=qty*rate;freight=qty*state.calcFreightPerEgg;landed=base+freight;margin=landed*(state.calcMarginPct/100);finalv=landed+margin;retail=qty?finalv/qty:0;',
        'const base=qty*rate;const freight=qty*state.calcFreightPerEgg;const landed=base+freight;const margin=landed*(state.calcMarginPct/100);const finalv=landed+margin;const retail=qty?finalv/qty:0;'
    )
    return js_html


base = INDEX.read_text(encoding='utf-8')
base = strip_generated(base)
# Replace brand name everywhere in the source HTML while retaining the supplied visual mark.
base = base.replace('EggRate.io', 'egg rate today').replace('Egg<span>Rate</span>.io', 'egg rate today')
head_re = re.compile(r'<title>.*?(?=<style>)', re.S)
base = head_re.sub(head(MARKETS[0], True) + '\n', base, count=1)
base = base.replace('  <!-- ERT:INTERLINKS -->', links('./'))
base = base.replace('  <!-- ERT:ARTICLE -->', article_html(MARKETS[0], True) + faq_html(MARKETS[0], True))
base = insert_before_notice(base, '')
base = replace_bootstrap(base)
base = patch_runtime(base)
if 'class="card section ert-links-section"' not in base:
    base = insert_before_notice(base, links('./'))
if 'class="card section ert-article"' not in base:
    base = insert_before_notice(base, article_html(MARKETS[0], True) + faq_html(MARKETS[0], True))
INDEX.write_text(base, encoding='utf-8')

for m in MARKETS:
    city = strip_generated(base)
    city = city.replace('data-page-root="./" data-market-id="all-india"', f'data-page-root="../" data-market-id="{m["id"]}"')
    city = head_re.sub(head(m, False) + '\n', city, count=1)
    city = city.replace('  <!-- ERT:INTERLINKS -->', links('../', current=m['id']))
    city = city.replace('  <!-- ERT:ARTICLE -->', history_html(m) + article_html(m, False) + faq_html(m, False))
    city = replace_bootstrap(city)
    city = patch_runtime(city)
    # If markers have already been consumed by previous builds, insert generated blocks before the first notice.
    if 'class="card section ert-links-section"' not in city:
        city = insert_before_notice(city, links('../', current=m['id']))
    if 'class="card section ert-article"' not in city:
        city = insert_before_notice(city, history_html(m) + article_html(m, False) + faq_html(m, False))
    (CITY_DIR / f'{m["id"]}.html').write_text(city, encoding='utf-8')

urls = [BASE] + [BASE + 'cities/' + m['id'] + '.html' for m in MARKETS]
(ROOT / 'sitemap.xml').write_text(
    '<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' + ''.join(
        f'<url><loc>{u}</loc><changefreq>daily</changefreq><priority>{"1.0" if u == BASE else "0.8"}</priority></url>' for u in urls
    ) + '</urlset>', encoding='utf-8'
)
(ROOT / 'robots.txt').write_text(f'User-agent: *\nAllow: /\nSitemap: {BASE}sitemap.xml\n', encoding='utf-8')
(ROOT / 'llms.txt').write_text(
    '# egg rate today\n\nMain URL: ' + BASE + '\nSource: ' + SOURCE + '\n\nCity pages:\n' + ''.join(
        f'- {m["name"]}: {BASE}cities/{m["id"]}.html\n' for m in MARKETS
    ), encoding='utf-8'
)
print('built', len(MARKETS), 'market pages')
