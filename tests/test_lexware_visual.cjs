const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const driver = require('../scripts/lexware-visual-dev.cjs');
test('artifact directory rejects links, insecure modes and files; screenshots are private', async () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'lx-visual-test-'));
  try {
    const dir = path.join(root, 'artifacts');
    driver.prepareArtifacts(dir);
    assert.equal(fs.statSync(dir).mode & 0o777, 0o700);
    await driver.privateScreenshot({screenshot: async () => Buffer.from('fixture')}, dir, 'fixture.png');
    assert.equal(fs.statSync(path.join(dir, 'fixture.png')).mode & 0o777, 0o600);
    await assert.rejects(driver.privateScreenshot({screenshot: async () => Buffer.from('replacement')}, dir, 'fixture.png'));
    const link = path.join(root, 'link'); fs.symlinkSync(dir, link);
    assert.throws(() => driver.prepareArtifacts(link));
    fs.chmodSync(dir, 0o755); assert.throws(() => driver.prepareArtifacts(dir));
    const file = path.join(root, 'file'); fs.writeFileSync(file, 'fixture');
    assert.throws(() => driver.prepareArtifacts(file));
    fs.chmodSync(dir, 0o700);
    const lstat = fs.lstatSync;
    fs.lstatSync = (...args) => { const info = lstat(...args); info.uid = process.getuid() + 1; return info; };
    try { assert.throws(() => driver.prepareArtifacts(dir)); } finally { fs.lstatSync = lstat; }
  } finally { fs.rmSync(root, {recursive:true, force:true}); }
});
test('resource detail is required', async () => {
  await assert.rejects(driver.requireDetail({count: async () => 0}), /RESOURCE_DETAIL_MISSING/);
});
test('visual checks remain local and support the staged feature module', () => {
  const source = fs.readFileSync(path.join(__dirname, '../scripts/lexware-visual-dev.cjs'), 'utf8');
  assert.doesNotMatch(source, /name: 'Verbindung testen'/, 'UI proof must not call live Lexware');
  assert.match(source, /input\.moduleUrl/, 'visual proof must target staged code');
});
test('history screenshots require populated conflict/removal/history evidence', async () => {
  assert.equal(typeof driver.requirePopulatedHistory, 'function');
  await assert.rejects(driver.requirePopulatedHistory({getByText: () => ({count: async () => 0})}), /POPULATED_HISTORY_MISSING/);
});
test('mobile evidence includes populated resource/history and clipping checks', () => {
  const source = fs.readFileSync(path.join(__dirname, '../scripts/lexware-visual-dev.cjs'), 'utf8');
  const mobile = source.split('await page.setViewportSize({ width: 390, height: 844 });')[1];
  assert.match(mobile, /resource-mobile\.png/, 'private populated mobile resource/history screenshot required');
  assert.match(mobile, /requireUnclippedHistory/, 'mobile removed/history/conflict indicators must be checked for clipping');
  assert.match(mobile, /scrollTo\(0, 0\)/, 'full-page evidence must reset sticky native navigation before capture');
});
test('history indicator checks fail closed for hidden or clipped evidence', async () => {
  const page = (visible, unclipped) => ({getByText: () => ({first: () => ({isVisible: async () => visible, scrollIntoViewIfNeeded: async () => {}, evaluate: async () => unclipped})})});
  await assert.rejects(driver.requireUnclippedHistory(page(false, true)), /HISTORY_INDICATOR_INVISIBLE/);
  await assert.rejects(driver.requireUnclippedHistory(page(true, false)), /HISTORY_INDICATOR_CLIPPED/);
  await driver.requireUnclippedHistory(page(true, true));
});
