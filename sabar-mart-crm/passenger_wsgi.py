"""Entry point for Phusion Passenger (cPanel "Setup Python App" on MilesWeb shared hosting).

cPanel settings: Application startup file = passenger_wsgi.py, Application Entry point = application.
Passenger speaks WSGI, so the FastAPI (ASGI) app is wrapped with a2wsgi.
Configuration comes from the environment variables set in the cPanel Python App screen.
"""

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from a2wsgi import ASGIMiddleware  # noqa: E402

from sabar_mart_crm.api import app  # noqa: E402

application = ASGIMiddleware(app)
