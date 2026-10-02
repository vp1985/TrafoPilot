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
module.exports = { prepareArtifacts, privateScreenshot, requireDetail };
async function main() {
  const { chromium } = require('playwright');
  const input = JSON.parse(fs.readFileSync(0, 'utf8'));
  if (!/^lx-http-fixture-[a-f0-9]{16}$/.test(input.login)) throw Error('REFUSING_NON_FIXTURE_USER');
  const base = 'http://127.0.0.1:8081';
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
    await page.goto(base + '/custom/hwoslexware/index.php');
    if (await page.locator('input[name="password"]').count()) throw Error('DEV_LOGIN_FAILED');
    await page.getByRole('button', { name: 'Verbindung testen', exact: true }).click();
    await page.getByText('GET-Verbindung bestätigt: Holger Testzentrum', { exact: true }).waitFor();
    if (!(await page.getByText('Read-only-Secret: injiziert', { exact: true }).count())) throw Error('WEB_SECRET_MISSING');
    await privateScreenshot(page, input.artifacts, 'overview-desktop.png');
    await page.getByRole('link', { name: 'Spiegel und Statistiken', exact: true }).click();
    await page.getByText('TrafoPilot – Lexware-Spiegel', { exact: true }).first().waitFor();
    await privateScreenshot(page, input.artifacts, 'resources-desktop.png');
    const detail = page.locator('a[href^="resource.php?id="]').first();
    await requireDetail(detail);
    {
      await detail.click();
      await page.getByText('Geschützter vollständiger API-Payload', { exact: true }).waitFor();
      await privateScreenshot(page, input.artifacts, 'resource-desktop.png');
    }
    await page.goto(base + '/custom/hwoslexware/issues.php');
    await page.getByText('TrafoPilot – Lexware-Konflikte', { exact: true }).first().waitFor();
    await privateScreenshot(page, input.artifacts, 'issues-desktop.png');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(base + '/custom/hwoslexware/index.php');
    await privateScreenshot(page, input.artifacts, 'overview-mobile.png');
    if (errors.length) throw Error('BROWSER_SCRIPT_ERROR');
    console.log('PASS: authenticated Chromium navigation, GET connection via mounted web secret, desktop/mobile screenshots');
  } finally { await browser.close(); }
}
if (require.main === module) main().catch(error => { console.error(/^[A-Z_]+$/.test(error.message) ? error.message : 'DEV_VISUAL_BROWSER_FAILED'); process.exitCode = 1; });
