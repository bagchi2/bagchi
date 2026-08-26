# EggRate India
Static HTML/CSS/JavaScript egg-rate portal using E2NECC reference data.

## Automatic flow
E2NECC -> GitHub Actions -> JSON -> static website

## Setup
1. Upload this folder to GitHub.
2. Replace `YOUR-DOMAIN.com` in `scripts/generate_site.py` and `robots.txt`.
3. Enable GitHub Pages.
4. Run the `Update Egg Rates` workflow manually once.
5. The workflow then runs daily.

Includes responsive UI, market search, calculations for 1/30/100/210 eggs, market pages, history JSON, sitemap, robots.txt and llms.txt.

E2NECC states its suggested prices are reference/informational and not mandatory selling prices. Keep the disclaimer.
