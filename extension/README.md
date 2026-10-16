# Piece Analyzer — Browser Extension

This browser extension allows you to extract and submit LEGO set prices directly to Piece Analyzer from any supported retailer (**Galaxus, Digitec, Brack, Toppreise, LEGO.com, Amazon.de, Amazon.com**) without any Content Security Policy (CSP) blocking.

---

### How to Install

#### Chrome / Brave / Microsoft Edge:
1. Open your browser and navigate to `chrome://extensions` (or `edge://extensions` / `brave://extensions`).
2. Enable **Developer mode** (toggle switch in the top-right corner).
3. Click **Load unpacked** (top-left).
4. Select the `extension/` folder in this repository.
5. The **Piece Analyzer** brick icon will appear in your extensions toolbar.

#### Firefox:
1. Navigate to `about:debugging#/runtime/this-firefox`.
2. Click **Load Temporary Add-on...**
3. Select the `extension/manifest.json` file.

---

### Setup & Usage

1. Click the Piece Analyzer extension icon in your browser toolbar.
2. Click the ⚙️ icon to configure your settings:
   - **Piece Analyzer Server URL**: e.g. `https://piece-analyzer.be-we.ch`
   - **Contributor API Token**: Find your token at `https://piece-analyzer.be-we.ch/bookmarklet` or under your Profile.
   - Click **Save Settings**.
3. Browse to any product or offer page on **Galaxus**, **LEGO.com**, **Brack**, or **Toppreise**.
4. Click the extension icon — it will automatically detect the set number and price!
5. Click **Record Price** to submit!
