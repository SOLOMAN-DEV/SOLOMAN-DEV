# User guide sources

`../Sabar-Mart-CRM-User-Guide.pdf` is built from these files:

- `guide.html`: the text, the diagrams (inline SVG) and the page layout
- `img/`: real screenshots of the CRM's `/docs` screen, running with the demo data
- `shots.py`: captures the screenshots with Playwright
- `render.py`: renders `guide.html` to an A4 PDF with Chromium

To regenerate the guide after the CRM changes:

```bash
pip install playwright
# 1. start the CRM with demo data and one key per role (from sabar-mart-crm/)
SABAR_CRM_SEED_DEMO=1 SABAR_CRM_API_KEYS="demo-admin:admin:admin.rao,demo-support:support_agent:priya.support,demo-finance:finance:arjun.finance,demo-vendor:vendor_manager:meera.vendors,demo-affiliate:affiliate_manager:kabir.affiliates,demo-marketing:marketing:sana.marketing,demo-store:system:storefront" \
  uvicorn sabar_mart_crm.api:app --port 8820 &
# 2. Swagger UI assets, served locally to the browser (no CDN needed)
cd docs/guide && npm pack swagger-ui-dist@5 && tar xzf swagger-ui-dist-*.tgz
# 3. screenshots, then the PDF (restart the CRM before re-running shots.py: it changes the demo data)
python shots.py && python render.py && mv Sabar-Mart-CRM-User-Guide.pdf ..
```
