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
