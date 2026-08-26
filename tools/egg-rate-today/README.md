# egg rate today

Production-ready static Egg Rate Today website for:

https://bagchi.in/tools/egg-rate-today/

## Project structure

```text
tools/
└── egg-rate-today/
    ├── index.html
    ├── data/
    │   ├── latest.json
    │   └── history.json
    ├── cities/
    │   └── 34 market pages
    ├── scripts/
    │   ├── update_egg_rates.py
    │   ├── backfill_2026.py
    │   └── build_site.py
    └── .github/
        └── workflows/
            └── update-egg-rate.yml
```

No assets folder is required. CSS, UI and JavaScript remain inline in the HTML pages.

## What the package does

- Uses the supplied EggRate dashboard design as the page template.
- Uses the brand name **egg rate today** with the egg logo mark.
- Makes every market name on the home page clickable.
- Gives every market its own city page with the same dashboard design.
- Adds a full market directory on every page for internal linking.
- Adds historical table + SVG graph to every city page.
- Adds 1 egg, 12 eggs, 30 eggs, 100 eggs and 210 eggs calculations.
- Adds meta title, description, keywords, canonical and Open Graph tags.
- Adds Schema.org Product/Offer, Place, Rating, FAQPage, BreadcrumbList, ItemList and Article markup.
- Adds a 1,000+ word visible market guide on every page.
- Keeps the E2NECC source and disclaimer visible.

## Data source and automation

Source:
https://www.e2necc.com/home/eggprice

`update_egg_rates.py` fetches the current E2NECC page and updates the latest market snapshot.

`backfill_2026.py` uses Playwright/Chromium to operate the E2NECC month/year controls and read the Daily Rate Sheet from January 2026 through the current month. It stores real source observations only and does not generate synthetic historical values.

`build_site.py` regenerates the homepage, all city pages, sitemap, robots.txt and llms.txt.

The included workflow runs the backfill, current scraper and site builder daily and can also be started manually with GitHub Actions.

## Important GitHub Actions limitation

GitHub Actions normally discovers workflow files only from the repository-root `.github/workflows/` directory.

Because this project intentionally keeps `.github/workflows/update-egg-rate.yml` **inside** `tools/egg-rate-today/`, automatic execution will work only when `tools/egg-rate-today` is itself the repository root, or when the workflow is copied to the repository-root `.github/workflows/` directory.

The website itself remains fully compatible with the public subfolder:

https://bagchi.in/tools/egg-rate-today/

## Historical data note

The initial JSON snapshot contains the verified local dataset already available in the project. The first successful GitHub Actions backfill run populates the January 2026 through current-month daily archive from the E2NECC source. Missing source cells remain missing rather than being replaced with invented values.

## Structured-data note

The package includes a `Rating` schema for editorial data freshness. It is explicitly described as **not a customer review rating**. No fabricated customer reviews or star ratings are included.
