"""Error logging and alerting.

Alerts go to email (SMTP, e.g. your cPanel mailbox) and/or a chat webhook, whichever is
configured. Each distinct problem is sent at most once per ``SABAR_CRM_ALERT_COOLDOWN``
seconds per process, so a failing endpoint cannot flood your inbox.

    SABAR_CRM_ALERT_EMAIL=ops@sabarmart.com,dev@sabarmart.com
    SABAR_CRM_SMTP_HOST=mail.sabarmart.com      SABAR_CRM_SMTP_PORT=465  (465 = SSL, 587 = STARTTLS)
    SABAR_CRM_SMTP_USER=alerts@sabarmart.com    SABAR_CRM_SMTP_PASSWORD=...
    SABAR_CRM_SMTP_FROM=alerts@sabarmart.com    (defaults to SMTP_USER)
    SABAR_CRM_ALERT_WEBHOOK=https://chat.googleapis.com/v1/spaces/...   (Slack/Google Chat style {"text": ...})
    SABAR_CRM_ALERT_COOLDOWN=900

Alerts never include request bodies; error messages may include record IDs.
"""

from __future__ import annotations

import json
import logging
import os
import smtplib
import ssl
import threading
import time
import urllib.request
from dataclasses import dataclass, field
from email.message import EmailMessage

log = logging.getLogger("sabar_crm")


def configure_logging() -> None:
    """Log to stderr (Passenger writes it to the app's log file) unless logging is already set up."""
    if not logging.getLogger().handlers:
        logging.basicConfig(level=os.environ.get("SABAR_CRM_LOG_LEVEL", "INFO"),
                            format="%(asctime)s %(levelname)s %(name)s %(message)s")


@dataclass
class Alerter:
    emails: list[str] = field(default_factory=list)
    smtp_host: str | None = None
    smtp_port: int = 465
    smtp_user: str | None = None
    smtp_password: str | None = None
    smtp_from: str | None = None
    webhook: str | None = None
    cooldown: float = 900.0
    _last_sent: dict[str, float] = field(default_factory=dict, repr=False)
    _lock: threading.Lock = field(default_factory=threading.Lock, repr=False)
    sent: list[tuple[str, str]] = field(default_factory=list, repr=False)  # (subject, body) for inspection

    @classmethod
    def from_env(cls) -> Alerter:
        env = os.environ.get
        return cls(
            emails=[e.strip() for e in env("SABAR_CRM_ALERT_EMAIL", "").split(",") if e.strip()],
            smtp_host=env("SABAR_CRM_SMTP_HOST") or None,
            smtp_port=int(env("SABAR_CRM_SMTP_PORT", "465")),
            smtp_user=env("SABAR_CRM_SMTP_USER") or None,
            smtp_password=env("SABAR_CRM_SMTP_PASSWORD") or None,
            smtp_from=env("SABAR_CRM_SMTP_FROM") or env("SABAR_CRM_SMTP_USER") or None,
            webhook=env("SABAR_CRM_ALERT_WEBHOOK") or None,
            cooldown=float(env("SABAR_CRM_ALERT_COOLDOWN", "900")),
        )

    @property
    def enabled(self) -> bool:
        return bool((self.emails and self.smtp_host) or self.webhook)

    def send(self, key: str, subject: str, body: str, wait: bool = False) -> bool:
        """Send unless the same ``key`` was alerted within the cooldown. Returns True if sent."""
        log.error("ALERT %s: %s\n%s", key, subject, body)
        if not self.enabled:
            return False
        now = time.monotonic()
        with self._lock:
            last = self._last_sent.get(key)
            if last is not None and now - last < self.cooldown:
                return False
            self._last_sent[key] = now
            self.sent.append((subject, body))
        worker = threading.Thread(target=self._deliver, args=(f"[Sabar CRM] {subject}", body), daemon=True)
        worker.start()
        if wait:
            worker.join(timeout=30)
        return True

    def _deliver(self, subject: str, body: str) -> None:
        if self.emails and self.smtp_host:
            try:
                msg = EmailMessage()
                msg["Subject"], msg["From"], msg["To"] = subject, self.smtp_from or self.emails[0], ", ".join(self.emails)
                msg.set_content(body)
                if self.smtp_port == 465:
                    server: smtplib.SMTP = smtplib.SMTP_SSL(self.smtp_host, self.smtp_port, timeout=15,
                                                            context=ssl.create_default_context())
                else:
                    server = smtplib.SMTP(self.smtp_host, self.smtp_port, timeout=15)
                    server.starttls(context=ssl.create_default_context())
                with server:
                    if self.smtp_user:
                        server.login(self.smtp_user, self.smtp_password or "")
                    server.send_message(msg)
            except Exception:
                log.exception("could not send alert email")
        if self.webhook:
            try:
                req = urllib.request.Request(self.webhook, data=json.dumps({"text": f"*{subject}*\n{body}"}).encode(),
                                             headers={"Content-Type": "application/json"}, method="POST")
                urllib.request.urlopen(req, timeout=15).close()
            except Exception:
                log.exception("could not send alert webhook")
