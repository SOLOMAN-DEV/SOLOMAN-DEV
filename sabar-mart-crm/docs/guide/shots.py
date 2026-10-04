"""Capture real screenshots of the Sabar Mart CRM API screen (/docs) for the user guide."""

import asyncio
import json
import pathlib
import sys

from playwright.async_api import async_playwright

BASE = "http://127.0.0.1:8820"
HERE = pathlib.Path(__file__).parent
IMG = HERE / "img"
ASSETS = {
    "swagger-ui-bundle.js": (HERE / "package" / "swagger-ui-bundle.js", "application/javascript"),
    "swagger-ui.css": (HERE / "package" / "swagger-ui.css", "text/css"),
}

CSS = """
  .swagger-ui .responses-wrapper table.responses-table:not(.live-responses-table),
  .swagger-ui .responses-wrapper .responses-inner > div:not(:first-child) > h4 { display: none !important; }
  .swagger-ui .topbar { display: none; }
  .swagger-ui .highlight-code > .microlight { max-height: 400px !important; overflow: hidden !important; }
  .swagger-ui textarea.body-param__text { min-height: 0 !important; height: 130px !important; }
  .swagger-ui .opblock .opblock-section-header { padding: 6px 20px; }
  body { background: #ffffff; }
"""


async def route_cdn(route):
    name = route.request.url.rsplit("/", 1)[-1]
    if name in ASSETS:
        path, ctype = ASSETS[name]
        await route.fulfill(body=path.read_bytes(), content_type=ctype)
    else:
        await route.fulfill(status=204, body=b"")


class Docs:
    def __init__(self, page):
        self.page = page

    async def open(self):
        await self.page.goto(f"{BASE}/docs")
        await self.page.wait_for_selector(".opblock", timeout=20000)
        await self.page.add_style_tag(content=CSS)

    async def login(self, key, shot=None):
        p = self.page
        await p.click(".btn.authorize")
        modal = p.locator(".modal-ux")
        await modal.wait_for()
        logout = modal.locator("button", has_text="Logout")
        if await logout.count():
            await logout.first.click()
        await modal.locator("input").first.fill(key)
        if shot:
            await modal.locator(".modal-ux-inner").screenshot(path=str(IMG / shot))
        await modal.locator("button.authorize").first.click()
        await modal.locator("button", has_text="Close").first.click()

    async def call(self, op_dom_id, shot, params=None, body=None, hide_curl=True, clip_height=None,
                   show_headers=False, compact=True):
        p = self.page
        block = p.locator(f"#{op_dom_id}")
        if not await block.locator(".opblock-body").count():
            await block.locator(".opblock-summary").first.click()
        try_out = block.locator(".try-out__btn")
        await try_out.wait_for()
        if (await try_out.inner_text()).strip() == "Try it out":
            await try_out.click()
        await block.locator(".btn.execute").wait_for()
        for name, value in (params or {}).items():
            await block.locator(f'tr[data-param-name="{name}"] input').fill(str(value))
        if body is not None:
            await block.locator("textarea.body-param__text").fill(json.dumps(body, indent=2))
        await block.locator(".btn.execute").click()
        await block.locator(".live-responses-table .response").first.wait_for(timeout=15000)
        await p.wait_for_timeout(300)
        if hide_curl:
            await p.add_style_tag(content=f"#{op_dom_id} .curl-command {{ display: none; }}")
        if not show_headers:
            await block.evaluate("""el => el.querySelectorAll('h5').forEach(h => {
                if (h.textContent.trim() === 'Response headers') {
                    h.style.display = 'none';
                    if (h.nextElementSibling) h.nextElementSibling.style.display = 'none';
                }})""")
        compact_style = None
        if compact:  # keep the title bar, request URL and answer; drop the input form
            compact_style = await p.add_style_tag(content=(
                f"#{op_dom_id} .opblock-section, #{op_dom_id} .execute-wrapper, #{op_dom_id} .btn-group,"
                f"#{op_dom_id} .responses-wrapper > .opblock-section-header {{ display: none !important; }}"
                f"#{op_dom_id} .responses-inner {{ padding-top: 4px !important; }}"))
        await block.scroll_into_view_if_needed()
        await block.screenshot(path=str(IMG / shot))
        if compact_style:
            await compact_style.evaluate("el => el.remove()")  # the same panel may be used again later
        # collapse again so the page stays short
        await block.locator(".opblock-summary").first.click()
        print("captured", shot)


def op(tag, op_id):
    return f"operations-{tag}-{op_id}"


async def main():
    IMG.mkdir(exist_ok=True)
    async with async_playwright() as pw:
        browser = await pw.chromium.launch()
        ctx = await browser.new_context(viewport={"width": 1100, "height": 900}, device_scale_factor=2)
        await ctx.route("https://cdn.jsdelivr.net/**", route_cdn)
        await ctx.route("https://fastapi.tiangolo.com/**", route_cdn)
        page = await ctx.new_page()
        d = Docs(page)
        await d.open()

        # Overview of the screen (sections collapsed)
        await page.screenshot(path=str(IMG / "01-docs-overview.png"), clip={"x": 0, "y": 0, "width": 1100, "height": 760})

        # Log in as support
        await d.login("demo-support", shot="02-authorize.png")
        await d.call(op("meta", "me_me_get"), "03-me-support.png", compact=False)
        await d.call(op("support", "support_queue_tickets_get"), "10-support-tickets.png", clip_height=900)
        await d.call(op("customers", "customer_profile_customers__customer_id__get"), "11-support-customer.png",
                     params={"customer_id": "C-1"}, show_headers=True)
        await d.call(op("refunds", "request_refund_orders__order_id__refunds_post"), "12-support-refund.png",
                     params={"order_id": "O-1"}, body={"amount": "500.00", "reason": "Item arrived scratched"}, compact=False)
        await d.call(op("privacy", "export_customer_customers__customer_id__export_get"), "40-privacy-export.png",
                     params={"customer_id": "C-1"}, clip_height=1000)

        # Marketing sees masked personal data, and is refused finance data
        await d.login("demo-marketing")
        await d.call(op("customers", "customer_profile_customers__customer_id__get"), "13-marketing-customer.png",
                     params={"customer_id": "C-1"})
        await d.call(op("finance", "vendor_payouts_vendors_payouts_get"), "14-forbidden.png", compact=False)

        # Finance
        await d.login("demo-finance")
        await d.call(op("refunds", "list_refunds_refunds_get"), "20-finance-refunds.png",
                     params={"status": "pending_review"})
        await d.call(op("refunds", "approve_refund_refunds__refund_id__approve_post"), "21-finance-approve.png",
                     params={"refund_id": "RF-000002"}, compact=False)
        await d.call(op("finance", "vendor_ledger_vendors__vendor_id__ledger_get"), "22-finance-ledger.png",
                     params={"vendor_id": "V-100"})
        await d.call(op("finance", "tax_report_finance_tax_report_get"), "23-finance-tax.png", clip_height=1000)
        await d.call(op("finance", "affiliate_payout_preview_affiliates__affiliate_id__payout_preview_get"),
                     "24-finance-affiliate-preview.png", params={"affiliate_id": "A-1"})

        # Vendor manager
        await d.login("demo-vendor")
        await d.call(op("internal", "task_queue_tasks_get"), "30-vendor-tasks.png",
                     params={"team": "vendor_success"}, clip_height=1000)
        await d.call(op("vendors", "vendor_scorecard_vendors__vendor_id__get"), "31-vendor-scorecard.png",
                     params={"vendor_id": "V-300"}, clip_height=1100)

        # Admin
        await d.login("demo-admin")
        await d.call(op("admin", "create_user_users_post"), "50-admin-create-user.png",
                     body={"username": "neha.support", "role": "support_agent"}, hide_curl=False)
        await d.call(op("admin", "audit_log_audit_get"), "51-admin-audit.png", params={"limit": "6"},
                     clip_height=1000)
        await d.call(op("internal", "analytics_analytics_get"), "52-admin-analytics.png", clip_height=1100)

        await browser.close()


if __name__ == "__main__":
    sys.exit(asyncio.run(main()))
