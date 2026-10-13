#!/usr/bin/env python3
"""
Piece Analyzer - Asset Generator Script
Generates all web favicons, touch icons, PWA icons, and .ico files
directly from the master vector source: public/logo.svg.

Requirements:
- rsvg-convert (librsvg)
- magick / convert (ImageMagick)
"""

import os
import sys
import shutil
import subprocess

PROJECT_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PUBLIC_DIR = os.path.join(PROJECT_ROOT, "public")
LOGO_SVG = os.path.join(PUBLIC_DIR, "logo.svg")

def check_dependencies():
    missing = []
    if not shutil.which("rsvg-convert"):
        missing.append("rsvg-convert (install via `brew install librsvg`)")
    if not shutil.which("magick") and not shutil.which("convert"):
        missing.append("ImageMagick (install via `brew install imagemagick`)")
    
    if missing:
        print("❌ Error: Missing required CLI tools:")
        for m in missing:
            print(f"   - {m}")
        sys.exit(1)

def run_cmd(cmd, desc):
    print(f"  • {desc}...")
    res = subprocess.run(cmd, shell=True, capture_output=True, text=True)
    if res.returncode != 0:
        print(f"    ❌ Failed: {res.stderr.strip()}")
        sys.exit(res.returncode)

def main():
    print("🧱 Piece Analyzer Asset Generator")
    print(f"   Source: {LOGO_SVG}")
    print(f"   Output: {PUBLIC_DIR}\n")

    if not os.path.exists(LOGO_SVG):
        print(f"❌ Error: Source file not found: {LOGO_SVG}")
        sys.exit(1)

    check_dependencies()

    magick_bin = "magick" if shutil.which("magick") else "convert"

    # 1. Favicon SVG (Direct copy of master logo.svg)
    favicon_svg = os.path.join(PUBLIC_DIR, "favicon.svg")
    print("1. Creating favicon.svg...")
    shutil.copyfile(LOGO_SVG, favicon_svg)

    # 2. App Icon SVG (Squircle background container for Apple Touch & Android PWA)
    print("2. Generating app-icon.svg (squircle container)...")
    app_icon_svg = os.path.join(PUBLIC_DIR, "app-icon.svg")
    with open(LOGO_SVG, "r", encoding="utf-8") as f:
        logo_content = f.read()

    # Extract the inner SVG elements from logo.svg to wrap them in an app squircle
    # Find start and end of inner content
    start_idx = logo_content.find("<svg")
    if start_idx != -1:
        svg_tag_end = logo_content.find(">", start_idx) + 1
        end_idx = logo_content.rfind("</svg>")
        inner_content = logo_content[svg_tag_end:end_idx]
    else:
        inner_content = logo_content

    squircle_svg_content = f"""<?xml version="1.0" encoding="utf-8"?>
<svg viewBox="0 0 512 512" width="512" height="512" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
  <defs>
    <linearGradient id="app_bg_grad" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="#0B1120"/>
      <stop offset="100%" stop-color="#1E293B"/>
    </linearGradient>
  </defs>
  <!-- Squircle rounded background for touch/app icons -->
  <rect width="512" height="512" rx="116" fill="url(#app_bg_grad)"/>
  <g transform="translate(25.6, 25.6) scale(0.9)">
    {inner_content}
  </g>
</svg>
"""
    with open(app_icon_svg, "w", encoding="utf-8") as f:
        f.write(squircle_svg_content)

    # 3. Render Favicon PNGs (16x16, 32x32, 48x48)
    print("3. Rendering browser favicon PNGs...")
    run_cmd(f"rsvg-convert -w 16 -h 16 '{favicon_svg}' -o '{PUBLIC_DIR}/favicon-16x16.png'", "favicon-16x16.png")
    run_cmd(f"rsvg-convert -w 32 -h 32 '{favicon_svg}' -o '{PUBLIC_DIR}/favicon-32x32.png'", "favicon-32x32.png")
    run_cmd(f"rsvg-convert -w 48 -h 48 '{favicon_svg}' -o '{PUBLIC_DIR}/favicon-48x48.png'", "favicon-48x48.png")

    # 4. Multi-resolution favicon.ico
    print("4. Generating multi-resolution favicon.ico...")
    run_cmd(
        f"{magick_bin} '{PUBLIC_DIR}/favicon-16x16.png' '{PUBLIC_DIR}/favicon-32x32.png' '{PUBLIC_DIR}/favicon-48x48.png' '{PUBLIC_DIR}/favicon.ico'",
        "favicon.ico (16, 32, 48px)"
    )

    # 5. Apple Touch & Android PWA PNGs
    print("5. Rendering touch and PWA icons...")
    run_cmd(f"rsvg-convert -w 180 -h 180 '{app_icon_svg}' -o '{PUBLIC_DIR}/apple-touch-icon.png'", "apple-touch-icon.png (180x180)")
    run_cmd(f"rsvg-convert -w 192 -h 192 '{app_icon_svg}' -o '{PUBLIC_DIR}/android-chrome-192x192.png'", "android-chrome-192x192.png (192x192)")
    run_cmd(f"rsvg-convert -w 512 -h 512 '{app_icon_svg}' -o '{PUBLIC_DIR}/android-chrome-512x512.png'", "android-chrome-512x512.png (512x512)")

    print("\n✅ All assets generated successfully in public/:\n")
    for fname in [
        "logo.svg",
        "favicon.svg",
        "favicon.ico",
        "favicon-16x16.png",
        "favicon-32x32.png",
        "favicon-48x48.png",
        "apple-touch-icon.png",
        "android-chrome-192x192.png",
        "android-chrome-512x512.png",
        "app-icon.svg"
    ]:
        p = os.path.join(PUBLIC_DIR, fname)
        size_kb = os.path.getsize(p) / 1024.0
        print(f"   • {fname:<26} ({size_kb:6.1f} KB)")

if __name__ == "__main__":
    main()
