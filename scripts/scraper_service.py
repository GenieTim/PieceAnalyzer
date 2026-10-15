#!/usr/bin/env python3
"""
Lightweight HTTP Scraper Daemon (Python Camoufox / Playwright)

Can run as an independent microservice or sidecar container in Coolify / Docker Compose.
Exposes simple REST endpoints:
  GET  /healthz -> {"status": "ok"}
  POST /scrape  -> {"url": "https://...", "options": {...}} -> {"items": [...]}
"""

import os
import sys
import json
from http.server import HTTPServer, BaseHTTPRequestHandler
from pathlib import Path

# Import scraper engine
sys.path.insert(0, str(Path(__file__).parent))
try:
    from camoufox_scraper import scrape_url
except ImportError:
    from scripts.camoufox_scraper import scrape_url


class ScraperHTTPRequestHandler(BaseHTTPRequestHandler):
    def do_GET(self):
        if self.path in ('/healthz', '/health', '/'):
            self.send_response(200)
            self.send_header('Content-Type', 'application/json')
            self.end_headers()
            self.wfile.write(b'{"status":"ok","engine":"camoufox"}\n')
        else:
            self.send_response(404)
            self.end_headers()

    def do_POST(self):
        if self.path == '/scrape':
            content_length = int(self.headers.get('Content-Length', 0))
            body = self.rfile.read(content_length).decode('utf-8')
            try:
                data = json.loads(body)
                url = data.get('url')
                if not url:
                    self.send_response(400)
                    self.send_header('Content-Type', 'application/json')
                    self.end_headers()
                    self.wfile.write(b'{"error":"Missing url field"}\n')
                    return

                options = data.get('options', {})
                items = scrape_url(url, options=options)

                self.send_response(200)
                self.send_header('Content-Type', 'application/json')
                self.end_headers()
                self.wfile.write(json.dumps({'status': 'success', 'items': items}).encode('utf-8'))
            except Exception as e:
                self.send_response(500)
                self.send_header('Content-Type', 'application/json')
                self.end_headers()
                self.wfile.write(json.dumps({'error': str(e)}).encode('utf-8'))
        else:
            self.send_response(404)
            self.end_headers()

    def log_message(self, format, *args):
        sys.stderr.write(f"[ScraperDaemon] {self.address_string()} - {format % args}\n")


def run(port=8000):
    server_address = ('', port)
    httpd = HTTPServer(server_address, ScraperHTTPRequestHandler)
    print(f"[ScraperDaemon] Listening on port {port} (POST /scrape, GET /healthz)...")
    httpd.serve_forever()


if __name__ == '__main__':
    port = int(os.getenv('PORT', '8000'))
    run(port)
