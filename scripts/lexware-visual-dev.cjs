// DEV-only browser driver. Credentials arrive through stdin and are never logged.
const fs = require('node:fs');
const path = require('node:path');
function openArtifactDirectory(directory) {
  const parts = path.resolve(directory).split(path.sep).filter(Boolean);
  if (!parts.length) throw Error('UNSAFE_ARTIFACT_DIRECTORY');
  const flags = fs.constants.O_RDONLY | fs.constants.O_DIRECTORY | fs.constants.O_NOFOLLOW;
  let parent = fs.openSync('/', flags);
  try {
    for (let i = 0; i < parts.length; i++) {
      const leaf = i === parts.length - 1;
      const target = `/proc/self/fd/${parent}/${parts[i]}`;
      if (leaf) {
        try { fs.mkdirSync(target, {mode: 0o700}); }
        catch (error) { if (error.code !== 'EEXIST') throw error; }
      }
      const named = fs.lstatSync(target);
      const child = fs.openSync(target, flags);
      const info = fs.fstatSync(child);
      if (!named.isDirectory() || named.isSymbolicLink() || named.dev !== info.dev || named.ino !== info.ino || named.uid !== info.uid ||
          (leaf && (info.uid !== process.getuid() || (info.mode & 0o777) !== 0o700)) ||
          (!leaf && (info.uid !== 0 && info.uid !== process.getuid() ||
            (info.mode & 0o022) && !(info.uid === 0 && (info.mode & 0o1000))))) {
        fs.closeSync(child);
        throw Error('UNSAFE_ARTIFACT_DIRECTORY');
      }
      fs.closeSync(parent);
      parent = child;
    }
    const result = parent;
    parent = null;
    return result;
  } finally { if (parent !== null) fs.closeSync(parent); }
}
function prepareArtifacts(directory) {
  fs.closeSync(openArtifactDirectory(directory));
}
async function privateScreenshot(page, directory, name) {
  const dir = openArtifactDirectory(directory);
  try {
    const info = fs.fstatSync(dir);
    if (info.uid !== process.getuid() || (info.mode & 0o777) !== 0o700) throw Error('UNSAFE_ARTIFACT_DIRECTORY');
    const fd = fs.openSync(`/proc/self/fd/${dir}/${name}`, fs.constants.O_WRONLY | fs.constants.O_CREAT | fs.constants.O_EXCL | fs.constants.O_NOFOLLOW, 0o600);
    try { fs.writeFileSync(fd, await page.screenshot({fullPage: true})); }
    finally { fs.closeSync(fd); }
  } finally { fs.closeSync(dir); }
}
async function requireDetail(detail) {
  if (!(await detail.count())) throw Error('RESOURCE_DETAIL_MISSING');
}
async function requirePopulatedHistory(page) {
  for (const text of ['Projektion: conflict','Nicht mehr vorhanden: 1','archiviert','removed','historical payload','historical-file','Dolibarr','vorheriger Dolibarr-Zustand']) {
    if (!(await page.getByText(text, {exact: false}).count())) throw Error('POPULATED_HISTORY_MISSING');
  }
}
async function requireUnclippedHistory(page) {
  for (const text of ['Projektion: conflict', 'Nicht mehr vorhanden: 1', 'archiviert', 'removed', 'Payload-Versionen', 'Dateiversionen', 'Statushistorie', 'Konfliktlösungen']) {
    const indicator = page.getByText(text, {exact: false}).first();
    if (!(await indicator.isVisible())) throw Error('HISTORY_INDICATOR_INVISIBLE');
    await indicator.scrollIntoViewIfNeeded();
    const unclipped = await indicator.evaluate(element => {
      const rect = element.getBoundingClientRect();
      if (rect.width <= 0 || rect.height <= 0 || rect.left < -2 || rect.right > innerWidth + 2) return false;
      for (let parent = element.parentElement; parent; parent = parent.parentElement) {
        const style = getComputedStyle(parent), bounds = parent.getBoundingClientRect();
        if (/(hidden|clip|auto|scroll)/.test(style.overflowX) && (rect.left < bounds.left - 2 || rect.right > bounds.right + 2)) return false;
        if (/(hidden|clip|auto|scroll)/.test(style.overflowY) && (rect.top < bounds.top - 2 || rect.bottom > bounds.bottom + 2)) return false;
      }
      return true;
    });
    if (!unclipped) throw Error('HISTORY_INDICATOR_CLIPPED');
  }
}
module.exports = { prepareArtifacts, privateScreenshot, requireDetail, requirePopulatedHistory, requireUnclippedHistory };
async function main() {
  const { chromium } = require('playwright');
  const input = JSON.parse(fs.readFileSync(0, 'utf8'));
  if (!/^lx-http-fixture-[a-f0-9]{16}$/.test(input.login)) throw Error('REFUSING_NON_FIXTURE_USER');
  const base = 'http://127.0.0.1:8081';
  const moduleUrl = input.moduleUrl || '/custom/hwoslexware';
  if (!moduleUrl.startsWith('/') || /[?#]|\.\./.test(moduleUrl)) throw Error('INVALID_MODULE_URL');
  prepareArtifacts(input.artifacts);
  const browser = await chromium.launch({ headless: true });
  try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    const errors = [];
    page.on('pageerror', () => errors.push('BROWSER_SCRIPT_ERROR'));
    await page.goto(base + '/index.php');
    await page.locator('input[name="username"]').fill(input.login);
    await page.locator('input[name="password"]').fill(input.password);
    await Promise.all([page.waitForNavigation(), page.locator('input[type="submit"]').click()]);
    await page.goto(base + moduleUrl + '/index.php');
    if (await page.locator('input[name="password"]').count()) throw Error('DEV_LOGIN_FAILED');
    if (await page.locator('button[value="dry"],button[value="full"]').count()) throw Error('NON_ADMIN_SYNC_CONTROLS_VISIBLE');
    await privateScreenshot(page, input.artifacts, 'overview-desktop.png');
    await page.getByRole('link', { name: 'Spiegel und Statistiken', exact: true }).click();
    await page.getByText('TrafoPilot – Lexware-Spiegel', { exact: true }).first().waitFor();
    await privateScreenshot(page, input.artifacts, 'resources-desktop.png');
    const detail = page.locator('a[href="resource.php?id=' + input.resourceId + '"]').first();
    await requireDetail(detail);
    {
      await detail.click();
      await page.getByText('Geschützter vollständiger API-Payload', { exact: true }).waitFor();
      for (const heading of ['Payload-Versionen','Dateiversionen','Statushistorie','Konfliktlösungen','Auditprotokoll']) {
        await page.getByRole('heading', {name: heading, exact: true}).waitFor();
      }
      await page.locator('details').evaluateAll(items => items.forEach(item => item.open = true));
      await requirePopulatedHistory(page);
      await requireUnclippedHistory(page);
      if (await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 2)) throw Error('HISTORY_LAYOUT_OVERFLOW');
      await page.getByRole('alert').waitFor();
      if (input.readOnly && await page.locator('button[value="Lexware"]').count()) throw Error('READER_RESOLUTION_CONTROLS_VISIBLE');
      await page.evaluate(() => window.scrollTo(0, 0));
      await privateScreenshot(page, input.artifacts, 'resource-desktop.png');
      await page.goto(base + moduleUrl + '/object.php?type=product&id=' + input.nativeId);
      await page.getByRole('alert').waitFor();
      await privateScreenshot(page, input.artifacts, 'native-conflict.png');
    }
    await page.goto(base + moduleUrl + '/issues.php');
    await page.getByText('TrafoPilot – Lexware-Konflikte', { exact: true }).first().waitFor();
    await privateScreenshot(page, input.artifacts, 'issues-desktop.png');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(base + moduleUrl + '/index.php');
    await privateScreenshot(page, input.artifacts, 'overview-mobile.png');
    await page.goto(base + moduleUrl + '/resource.php?id=' + input.resourceId);
    await page.locator('details').evaluateAll(items => items.forEach(item => item.open = true));
    await requirePopulatedHistory(page);
    await requireUnclippedHistory(page);
    if (await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 2)) throw Error('HISTORY_LAYOUT_OVERFLOW');
    await page.evaluate(() => window.scrollTo(0, 0));
    await privateScreenshot(page, input.artifacts, 'resource-mobile.png');
    if (errors.length) throw Error('BROWSER_SCRIPT_ERROR');
    console.log('PASS: authenticated Chromium navigation, history headings, non-admin controls, desktop/mobile screenshots; no live Lexware request');
  } finally { await browser.close(); }
}
if (require.main === module) main().catch(error => { console.error(/^[A-Z_]+$/.test(error.message) ? error.message : 'DEV_VISUAL_BROWSER_FAILED'); process.exitCode = 1; });
