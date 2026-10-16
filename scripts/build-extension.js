#!/usr/bin/env node

const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const rootDir = path.resolve(__dirname, '..');
const srcExtractor = path.join(rootDir, 'assets/js/scraper/extractor.js');
const extExtractor = path.join(rootDir, 'extension/extractor.js');

console.log('[Extension Build] Syncing extractor.js...');
if (fs.existsSync(srcExtractor)) {
  fs.copyFileSync(srcExtractor, extExtractor);
  console.log('[Extension Build] Synced assets/js/scraper/extractor.js -> extension/extractor.js');
} else {
  console.warn('[Extension Build] Warning: source extractor.js not found at ' + srcExtractor);
}

// If PHP is available, package the zip files for Chrome and Firefox
try {
  console.log('[Extension Build] Packaging zip archives via Symfony console...');
  execSync('php bin/console app:extension:build', { cwd: rootDir, stdio: 'inherit' });
} catch (err) {
  console.warn('[Extension Build] Notice: php bin/console app:extension:build could not be executed directly (this will run in Docker/FrankenPHP):', err.message);
}
