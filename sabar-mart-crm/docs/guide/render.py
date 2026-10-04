import asyncio, pathlib
from playwright.async_api import async_playwright
HERE = pathlib.Path(__file__).parent
FOOTER = """<div style="width:100%;font-size:8px;color:#8a94a6;font-family:'Liberation Sans',Arial;padding:0 16mm;display:flex;justify-content:space-between">
<span>Sabar Mart CRM · User guide</span><span><span class="pageNumber"></span> / <span class="totalPages"></span></span></div>"""
async def main():
    async with async_playwright() as pw:
        b = await pw.chromium.launch()
        pg = await b.new_page()
        await pg.goto((HERE / "guide.html").as_uri(), wait_until="networkidle")
        await pg.pdf(path=str(HERE / "Sabar-Mart-CRM-User-Guide.pdf"), format="A4", print_background=True,
                     display_header_footer=True, header_template="<div></div>", footer_template=FOOTER,
                     margin={"top": "18mm", "bottom": "20mm", "left": "16mm", "right": "16mm"})
        await b.close()
asyncio.run(main())
