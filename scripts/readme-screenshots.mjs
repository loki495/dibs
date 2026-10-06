import { chromium } from 'playwright';
const base = process.env.BASE_URL, out = process.env.OUT_DIR, task = process.env.DETAIL_TASK;
const browser = await chromium.launch();
const shots = [
  ['light-desktop', 'light', 1440, 900, false], ['dark-desktop', 'dark', 1440, 900, false],
  ['detail-panel', 'light', 1440, 1050, true],
  ['light-mobile', 'light', 390, 844, false], ['dark-mobile', 'dark', 390, 844, false],
];
for (const [name, theme, width, height, detail] of shots) {
  const ctx = await browser.newContext({ viewport: { width, height }, colorScheme: theme, deviceScaleFactor: 1, isMobile: width < 500, hasTouch: width < 500 });
  await ctx.addInitScript(t => { try { localStorage.setItem('todo-theme', t); } catch {} }, theme);
  const page = await ctx.newPage();
  await page.goto(base, { waitUntil: 'networkidle' });
  await page.getByRole('link', { name: /^Personal Projects/ }).or(page.getByRole('button', { name: /^Personal Projects/ })).first().click();
  await page.waitForLoadState('networkidle');
  await page.getByText('Expand all').first().click();
  await page.waitForLoadState('networkidle');
  if (detail) {
    await page.getByText(task, { exact: false }).first().click();
    await page.waitForSelector('[data-claim-liveness]');
    await page.waitForLoadState('networkidle');
  }
  await page.waitForTimeout(800);
  await page.screenshot({ path: `${out}/screenshot-${name}.png` });
  await ctx.close();
}
await browser.close();
