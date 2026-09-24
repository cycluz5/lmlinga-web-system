/**
 * Death Phase 1 — FINAL CLOSEOUT evidence capture (evidence-only; no app source changes).
 * Produces responsive screenshots, navigation proof, contrast, keyboard, and DOM reports.
 */
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createHash } from 'node:crypto';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '..');
const base = 'http://127.0.0.1:8765';
const hh = 'HH-151';
const mb = 'MB-002'; // Kristine — used for create/view/edit flow
const mbFresh = 'MB-001'; // separate member for no-record/navigation proof when MB-002 has session record
const q = 'role=bhw&v=death-final-closeout';

const urls = {
  member: (id) => `${base}/household-profiling/${hh}/members/${id}?${q}`,
  death: (id) => `${base}/household-profiling/${hh}/members/${id}/death?${q}`,
  create: (id) => `${base}/household-profiling/${hh}/members/${id}/death/create?${q}`,
  edit: (id) => `${base}/household-profiling/${hh}/members/${id}/death/edit?${q}`,
};

const viewports = [
  { w: 1440, h: 900 },
  { w: 1366, h: 768 },
  { w: 820, h: 1180 },
  { w: 768, h: 1024 },
  { w: 390, h: 844 },
  { w: 360, h: 800 },
];

const inventory = {
  screenshots: [],
  navigation: [],
  responsiveChecks: {},
  contrast: [],
  keyboard: {},
  dom: {},
  errors: [],
};

function out(...parts) {
  return path.join(root, ...parts);
}

function ensureDir(p) {
  fs.mkdirSync(p, { recursive: true });
}

function relFromRoot(abs) {
  return path.relative(root, abs).replace(/\\/g, '/');
}

function srgbToLinear(c) {
  const v = c / 255;
  return v <= 0.04045 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
}

function relativeLuminance(r, g, b) {
  return 0.2126 * srgbToLinear(r) + 0.7152 * srgbToLinear(g) + 0.0722 * srgbToLinear(b);
}

function parseCssColor(css) {
  if (!css) return null;
  const m = css.match(/rgba?\(\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)(?:\s*,\s*([\d.]+))?\s*\)/i);
  if (!m) return null;
  return {
    r: Number(m[1]),
    g: Number(m[2]),
    b: Number(m[3]),
    a: m[4] !== undefined ? Number(m[4]) : 1,
  };
}

function rgbToHex({ r, g, b }) {
  const h = (n) => Math.round(n).toString(16).padStart(2, '0');
  return `#${h(r)}${h(g)}${h(b)}`.toUpperCase();
}

function contrastRatio(fg, bg) {
  const L1 = relativeLuminance(fg.r, fg.g, fg.b);
  const L2 = relativeLuminance(bg.r, bg.g, bg.b);
  const lighter = Math.max(L1, L2);
  const darker = Math.min(L1, L2);
  return (lighter + 0.05) / (darker + 0.05);
}

function evaluateContrast(label, fgCss, bgCss, threshold = 4.5) {
  const fg = parseCssColor(fgCss);
  const bg = parseCssColor(bgCss);
  if (!fg || !bg) {
    return {
      label,
      foregroundCss: fgCss,
      backgroundCss: bgCss,
      foregroundHex: null,
      backgroundHex: null,
      ratio: null,
      threshold,
      result: 'FAIL — could not parse colors',
    };
  }
  // If fg is semi-transparent, blend over bg for approximate displayed color
  let displayFg = fg;
  if (fg.a < 1) {
    displayFg = {
      r: fg.r * fg.a + bg.r * (1 - fg.a),
      g: fg.g * fg.a + bg.g * (1 - fg.a),
      b: fg.b * fg.a + bg.b * (1 - fg.a),
      a: 1,
    };
  }
  const ratio = contrastRatio(displayFg, bg);
  const pass = ratio >= threshold;
  return {
    label,
    foregroundCss: fgCss,
    backgroundCss: bgCss,
    foregroundHex: rgbToHex(displayFg),
    backgroundHex: rgbToHex(bg),
    ratio: Number(ratio.toFixed(2)),
    threshold,
    result: pass ? 'PASS' : 'FAIL',
  };
}

async function shot(page, relPath, w, h) {
  await page.setViewportSize({ width: w, height: h });
  await page.waitForTimeout(150);
  await page.evaluate(() => window.scrollTo(0, 0));
  const abs = out(relPath);
  ensureDir(path.dirname(abs));
  await page.screenshot({ path: abs, fullPage: false });
  inventory.screenshots.push(relFromRoot(abs));
  return abs;
}

async function overflowCheck(page) {
  return page.evaluate(() => {
    const doc = document.documentElement;
    return {
      scrollWidth: doc.scrollWidth,
      clientWidth: doc.clientWidth,
      overflowX: doc.scrollWidth > doc.clientWidth + 1,
    };
  });
}

async function memberMetaCheck(page) {
  return page.evaluate(() => {
    const dl = document.querySelector('[data-death-member-meta]');
    const items = [...(dl?.querySelectorAll('.lml-death__member-item') || [])];
    const tops = items.map((el) => Math.round(el.getBoundingClientRect().top));
    const stacked =
      tops.length >= 3 && tops[1] > tops[0] + 6 && tops[2] > tops[1] + 6;
    return {
      flexDirection: dl ? getComputedStyle(dl).flexDirection : null,
      stacked,
      tops,
    };
  });
}

async function seedRecord(page, memberId) {
  await page.goto(urls.create(memberId), { waitUntil: 'networkidle' });
  const mode = await page.locator('[data-lml-death]').getAttribute('data-lml-death-mode');
  if (mode === 'edit') {
    await page.fill('#lml-death-cause', 'Closeout cause');
    await page.fill('#lml-death-date', '2026-05-01');
    await Promise.all([
      page.waitForURL(/\/death(?:\?|$)/),
      page.click('[data-death-save]'),
    ]);
    return;
  }
  if (mode === 'create') {
    await page.fill('#lml-death-cause', 'Closeout cause');
    await page.fill('#lml-death-date', '2026-05-01');
    await Promise.all([
      page.waitForURL(/\/death(?:\?|$)/),
      page.click('[data-death-save]'),
    ]);
  }
}

async function ensureEmptyMember(page, memberId) {
  await page.goto(urls.death(memberId), { waitUntil: 'networkidle' });
  const mode = await page.locator('[data-lml-death]').getAttribute('data-lml-death-mode');
  return mode === 'empty' ? memberId : null;
}

async function captureResponsive(page) {
  // Prefer MB-001 for ALIVE if empty; else MB-002 after clearing isn't possible — use whichever empty.
  let aliveId = (await ensureEmptyMember(page, mbFresh)) || (await ensureEmptyMember(page, mb));
  if (!aliveId) {
    // Both have records in this browser context — still capture ALIVE from a fresh context later;
    // for now capture current empty if any, otherwise use MB-001 URL state as-is.
    aliveId = mbFresh;
  }

  // Seed MB-002 for view/edit
  await seedRecord(page, mb);

  const states = [
    {
      key: 'alive',
      prefix: '01-alive',
      goto: async () => {
        await page.goto(urls.death(aliveId), { waitUntil: 'networkidle' });
      },
      wait: '[data-death-no-record], [data-lml-death-mode="empty"]',
    },
    {
      key: 'create',
      prefix: '02-create',
      goto: async () => {
        // Use a member without record for create form; if MB-001 empty use create; else MB-002 edit redirected — force create via empty member
        const empty = (await ensureEmptyMember(page, mbFresh)) || aliveId;
        await page.goto(urls.create(empty), { waitUntil: 'networkidle' });
      },
      wait: '[data-death-form], [data-lml-death-mode="create"]',
    },
    {
      key: 'view',
      prefix: '03-view',
      goto: async () => {
        await page.goto(urls.death(mb), { waitUntil: 'networkidle' });
      },
      wait: '[data-death-recorded], [data-lml-death-mode="view"]',
    },
    {
      key: 'edit',
      prefix: '04-edit',
      goto: async () => {
        await page.goto(urls.edit(mb), { waitUntil: 'networkidle' });
      },
      wait: '[data-death-form], [data-lml-death-mode="edit"]',
    },
  ];

  for (const state of states) {
    for (const vp of viewports) {
      await state.goto();
      await page.waitForSelector(state.wait, { timeout: 15000 }).catch(() => {});
      const file = `01-responsive/${state.prefix}-${vp.w}x${vp.h}.png`;
      await shot(page, file, vp.w, vp.h);
      const overflow = await overflowCheck(page);
      const meta = await memberMetaCheck(page);
      const mode = await page.locator('[data-lml-death]').getAttribute('data-lml-death-mode');
      inventory.responsiveChecks[`${state.key}-${vp.w}x${vp.h}`] = {
        mode,
        url: page.url(),
        overflow,
        memberMeta: meta,
      };
    }
  }
}

async function captureNavigation(page) {
  // Fresh context member for clean ALIVE path: MB-001 if empty
  const navMember = (await ensureEmptyMember(page, mbFresh)) || mbFresh;

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(urls.member(navMember), { waitUntil: 'networkidle' });
  await page.waitForSelector('[data-hh-member-death]');
  const deathHref = await page.getAttribute('[data-hh-member-death]', 'href');
  inventory.navigation.push({ step: '1-member-death-view-link', href: deathHref, url: page.url() });
  await page.locator('[data-hh-member-death]').scrollIntoViewIfNeeded();
  await shot(page, '02-navigation/01-member-death-view-link-1440x900.png', 1440, 900);

  await Promise.all([
    page.waitForURL(/\/death(?:\?|$)/),
    page.click('[data-hh-member-death]'),
  ]);
  await page.waitForSelector('[data-lml-death]');
  inventory.navigation.push({
    step: '2-after-view-click',
    url: page.url(),
    mode: await page.locator('[data-lml-death]').getAttribute('data-lml-death-mode'),
  });
  await shot(page, '02-navigation/02-death-index-after-view-1440x900.png', 1440, 900);

  // If empty, click Record; if view (record exists), still document and continue with create URL separately
  const mode = await page.locator('[data-lml-death]').getAttribute('data-lml-death-mode');
  if (mode === 'empty') {
    const ctaHref = await page.getAttribute('[data-death-record-cta]', 'href');
    inventory.navigation.push({ step: '3-record-cta-href', href: ctaHref });
    await Promise.all([
      page.waitForURL(/\/death\/create/),
      page.click('[data-death-record-cta]'),
    ]);
    inventory.navigation.push({ step: '4-create-page', url: page.url() });
    await shot(page, '02-navigation/03-after-record-cta-create-1440x900.png', 1440, 900);
    await page.fill('#lml-death-cause', 'Nav closeout cause');
    await page.fill('#lml-death-date', '2026-05-10');
    await Promise.all([
      page.waitForURL(/\/death(?:\?|$)/),
      page.click('[data-death-save]'),
    ]);
  } else {
    // Already has record — open create evidence via dedicated member seed path
    await seedRecord(page, mb);
  }

  await page.goto(urls.death(mb), { waitUntil: 'networkidle' });
  inventory.navigation.push({
    step: '5-existing-record-view',
    url: page.url(),
    mode: await page.locator('[data-lml-death]').getAttribute('data-lml-death-mode'),
  });
  await shot(page, '02-navigation/04-existing-record-view-1440x900.png', 1440, 900);

  const editHref = await page.getAttribute('[data-death-edit]', 'href');
  inventory.navigation.push({ step: '6-edit-href', href: editHref });
  await Promise.all([
    page.waitForURL(/\/death\/edit/),
    page.click('[data-death-edit]'),
  ]);
  inventory.navigation.push({
    step: '7-edit-page',
    url: page.url(),
    mode: await page.locator('[data-lml-death]').getAttribute('data-lml-death-mode'),
  });
  await shot(page, '02-navigation/05-after-edit-click-1440x900.png', 1440, 900);
}

async function captureContrast(page) {
  await page.setViewportSize({ width: 1440, height: 900 });
  inventory.contrast = [];

  // Outlined CTA on ALIVE — must run while member still has no record
  const aliveId = (await ensureEmptyMember(page, mbFresh)) || mbFresh;
  await page.goto(urls.death(aliveId), { waitUntil: 'networkidle' });
  await page.waitForSelector('[data-death-record-cta], [data-lml-death-mode="empty"]', { timeout: 15000 });
  const outlineColors = await page.evaluate(() => {
    const btn = document.querySelector('[data-death-record-cta]');
    if (!btn) return null;
    const cs = getComputedStyle(btn);
    const icon = btn.querySelector('i');
    const iconCs = icon ? getComputedStyle(icon) : null;
    // Prefer panel/button local background over transparent ancestors
    let bgEl = btn;
    let bg = cs.backgroundColor;
    while (bgEl && (bg === 'rgba(0, 0, 0, 0)' || bg === 'transparent')) {
      bgEl = bgEl.parentElement;
      if (!bgEl) break;
      bg = getComputedStyle(bgEl).backgroundColor;
    }
    if (!bg || bg === 'rgba(0, 0, 0, 0)' || bg === 'transparent') {
      bg = 'rgb(255, 255, 255)';
    }
    return {
      color: cs.color,
      background: bg,
      border: cs.borderTopColor,
      iconColor: iconCs?.color ?? null,
      mode: document.querySelector('[data-lml-death]')?.getAttribute('data-lml-death-mode'),
    };
  });
  if (outlineColors) {
    inventory.contrast.push(
      evaluateContrast('Record death information — text vs background', outlineColors.color, outlineColors.background)
    );
    if (outlineColors.iconColor) {
      inventory.contrast.push(
        evaluateContrast('Record death information — icon vs background', outlineColors.iconColor, outlineColors.background)
      );
    }
    if (outlineColors.border) {
      inventory.contrast.push(
        evaluateContrast('Record death information — border vs background', outlineColors.border, outlineColors.background, 3.0)
      );
    }
  } else {
    inventory.errors.push('Contrast: outlined Record CTA not found (empty state missing)');
  }

  // Heading — sample against local panel background when possible
  const headingColors = await page.evaluate(() => {
    const h = document.querySelector('.lml-death__panel-title, #lml-death-section-title');
    if (!h) return null;
    const cs = getComputedStyle(h);
    let bgEl = h.parentElement;
    let bg = bgEl ? getComputedStyle(bgEl).backgroundColor : null;
    while (bgEl && (bg === 'rgba(0, 0, 0, 0)' || bg === 'transparent')) {
      bgEl = bgEl.parentElement;
      if (!bgEl) break;
      bg = getComputedStyle(bgEl).backgroundColor;
    }
    return { color: cs.color, background: bg || getComputedStyle(document.body).backgroundColor || 'rgb(255,255,255)' };
  });
  if (headingColors) {
    inventory.contrast.push(
      evaluateContrast('DEATH INFORMATION heading vs panel/page background', headingColors.color, headingColors.background)
    );
  }

  // Save (create)
  await page.goto(urls.create(aliveId), { waitUntil: 'networkidle' });
  // If redirected to edit because record exists, use edit save
  let saveSel = '[data-death-save]';
  await page.waitForSelector(saveSel, { timeout: 10000 }).catch(() => {});
  const saveNormal = await page.evaluate((sel) => {
    const btn = document.querySelector(sel);
    if (!btn) return null;
    const cs = getComputedStyle(btn);
    return { color: cs.color, background: cs.backgroundColor };
  }, saveSel);
  if (saveNormal) {
    inventory.contrast.push(
      evaluateContrast('Save button — normal', saveNormal.color, saveNormal.background)
    );
  }

  // Hover Save
  if (await page.locator(saveSel).count()) {
    await page.hover(saveSel);
    await page.waitForTimeout(100);
    const saveHover = await page.evaluate((sel) => {
      const btn = document.querySelector(sel);
      if (!btn) return null;
      const cs = getComputedStyle(btn);
      return { color: cs.color, background: cs.backgroundColor };
    }, saveSel);
    if (saveHover) {
      inventory.contrast.push(
        evaluateContrast('Save button — hover', saveHover.color, saveHover.background)
      );
    }
    // Focus-visible
    await page.focus(saveSel);
    await page.waitForTimeout(80);
    const saveFocus = await page.evaluate((sel) => {
      const btn = document.querySelector(sel);
      if (!btn) return null;
      const cs = getComputedStyle(btn);
      return {
        color: cs.color,
        background: cs.backgroundColor,
        outline: cs.outline,
        outlineWidth: cs.outlineWidth,
        boxShadow: cs.boxShadow,
      };
    }, saveSel);
    if (saveFocus) {
      inventory.contrast.push(
        evaluateContrast('Save button — focus-visible colors', saveFocus.color, saveFocus.background)
      );
      inventory.keyboard.saveFocusStyles = saveFocus;
      await shot(page, '03-accessibility/focus-save-1440x900.png', 1440, 900);
    }
  }

  // Edit button on view
  await seedRecord(page, mb);
  await page.goto(urls.death(mb), { waitUntil: 'networkidle' });
  const editNormal = await page.evaluate(() => {
    const btn = document.querySelector('[data-death-edit]');
    if (!btn) return null;
    const cs = getComputedStyle(btn);
    return { color: cs.color, background: cs.backgroundColor };
  });
  if (editNormal) {
    inventory.contrast.push(
      evaluateContrast('Edit button — normal', editNormal.color, editNormal.background)
    );
  }
  if (await page.locator('[data-death-edit]').count()) {
    await page.hover('[data-death-edit]');
    await page.waitForTimeout(100);
    const editHover = await page.evaluate(() => {
      const btn = document.querySelector('[data-death-edit]');
      if (!btn) return null;
      const cs = getComputedStyle(btn);
      return { color: cs.color, background: cs.backgroundColor };
    });
    if (editHover) {
      inventory.contrast.push(
        evaluateContrast('Edit button — hover', editHover.color, editHover.background)
      );
    }
    await page.focus('[data-death-edit]');
    await page.waitForTimeout(80);
    const editFocus = await page.evaluate(() => {
      const btn = document.querySelector('[data-death-edit]');
      if (!btn) return null;
      const cs = getComputedStyle(btn);
      return {
        color: cs.color,
        background: cs.backgroundColor,
        outline: cs.outline,
        outlineWidth: cs.outlineWidth,
        boxShadow: cs.boxShadow,
      };
    });
    inventory.keyboard.editFocusStyles = editFocus;
    await shot(page, '03-accessibility/focus-edit-1440x900.png', 1440, 900);
  }
}

async function captureKeyboard(page) {
  const log = [];
  await page.setViewportSize({ width: 1440, height: 900 });

  const aliveId = (await ensureEmptyMember(page, mbFresh)) || mbFresh;
  await page.goto(urls.death(aliveId), { waitUntil: 'networkidle' });
  const aliveMode = await page.locator('[data-lml-death]').getAttribute('data-lml-death-mode');
  if (aliveMode !== 'empty') {
    inventory.errors.push(`Keyboard no-record expected empty mode, got ${aliveMode}`);
  }

  // NO RECORD keyboard — start inside Death content so sidebar does not consume the Tab budget
  const noRecord = { steps: [], mode: aliveMode };
  const back = page.locator('.lml-death__back, [data-death-back]').first();
  if (await back.count()) {
    await back.focus();
    noRecord.backReached = true;
    const backInfo = await page.evaluate(() => {
      const el = document.activeElement;
      const cs = getComputedStyle(el);
      return {
        tag: el?.tagName?.toLowerCase(),
        className: typeof el?.className === 'string' ? el.className : '',
        outlineWidth: cs.outlineWidth,
        outline: cs.outline,
      };
    });
    noRecord.steps.push({ phase: 'back-focused', ...backInfo });
    noRecord.backFocusVisible =
      (backInfo.outlineWidth && backInfo.outlineWidth !== '0px') ||
      (backInfo.className || '').includes('lml-focus-ring');
  }

  for (let i = 0; i < 20; i++) {
    await page.keyboard.press('Tab');
    const info = await page.evaluate(() => {
      const el = document.activeElement;
      if (!el) return null;
      const cs = getComputedStyle(el);
      return {
        tag: el.tagName.toLowerCase(),
        id: el.id || null,
        className: typeof el.className === 'string' ? el.className : '',
        dataAttrs: [...el.attributes]
          .filter((a) => a.name.startsWith('data-'))
          .map((a) => a.name)
          .join(','),
        text: (el.innerText || el.getAttribute('aria-label') || '').trim().slice(0, 80),
        outline: cs.outline,
        outlineWidth: cs.outlineWidth,
        boxShadow: cs.boxShadow,
      };
    });
    noRecord.steps.push(info);
    if (info?.dataAttrs?.includes('data-death-record-cta') || info?.className?.includes('lml-death__btn--outline')) {
      noRecord.recordCtaReached = true;
      noRecord.recordCtaFocusVisible =
        (info.outlineWidth && info.outlineWidth !== '0px') ||
        (info.boxShadow && info.boxShadow !== 'none') ||
        (info.className || '').includes('lml-focus-ring');
      await shot(page, '03-accessibility/focus-record-cta-1440x900.png', 1440, 900);
      break;
    }
    if (info?.className?.includes('lml-death__back') || info?.dataAttrs?.includes('data-death-back')) {
      noRecord.backReached = true;
    }
  }
  inventory.keyboard.noRecord = noRecord;
  log.push(
    'NO RECORD: mode=' +
      aliveMode +
      '; backReached=' +
      !!noRecord.backReached +
      '; Record CTA reached=' +
      !!noRecord.recordCtaReached +
      '; CTA focusVisible=' +
      !!noRecord.recordCtaFocusVisible
  );

  // CREATE keyboard
  await page.goto(urls.create(aliveId), { waitUntil: 'networkidle' });
  const create = { fields: {} };
  const createTargets = [
    { key: 'cause', match: (el) => el.id === 'lml-death-cause' },
    { key: 'date', match: (el) => el.id === 'lml-death-date' },
    { key: 'chooseFile', match: (el) => el.getAttribute?.('data-death-choose-file') != null || el.className?.includes?.('lml-death__choose-file') || el.id === 'lml-death-certificate' },
    { key: 'save', match: (el) => el.getAttribute?.('data-death-save') != null },
  ];
  for (let i = 0; i < 20; i++) {
    await page.keyboard.press('Tab');
    const info = await page.evaluate(() => {
      const el = document.activeElement;
      if (!el) return null;
      return {
        id: el.id || null,
        tag: el.tagName.toLowerCase(),
        className: typeof el.className === 'string' ? el.className : '',
        dataDeathSave: el.getAttribute('data-death-save'),
        dataChoose: el.getAttribute('data-death-choose-file'),
        forAttr: el.getAttribute('for'),
      };
    });
    if (!info) continue;
    if (info.id === 'lml-death-cause') create.fields.cause = true;
    if (info.id === 'lml-death-date') create.fields.date = true;
    if (info.id === 'lml-death-certificate' || info.dataChoose != null || info.className.includes('choose-file') || info.forAttr === 'lml-death-certificate') {
      create.fields.chooseFile = true;
    }
    if (info.dataDeathSave != null) create.fields.save = true;
  }
  inventory.keyboard.create = create;
  log.push('CREATE fields reachable: ' + JSON.stringify(create.fields));

  // VIEW keyboard — readonly not tabbable; Edit is
  await seedRecord(page, mb);
  await page.goto(urls.death(mb), { waitUntil: 'networkidle' });
  const view = await page.evaluate(() => {
    const readonlys = [...document.querySelectorAll('.lml-death__readonly')];
    return {
      readonlyCount: readonlys.length,
      readonlyTags: readonlys.map((el) => el.tagName.toLowerCase()),
      readonlyTabindex: readonlys.map((el) => el.getAttribute('tabindex')),
      readonlyContentEditable: readonlys.map((el) => el.getAttribute('contenteditable')),
      editPresent: !!document.querySelector('[data-death-edit]'),
    };
  });
  // Tab until Edit or 15 times
  view.editReached = false;
  view.readonlyFocused = false;
  for (let i = 0; i < 15; i++) {
    await page.keyboard.press('Tab');
    const info = await page.evaluate(() => {
      const el = document.activeElement;
      return {
        className: typeof el?.className === 'string' ? el.className : '',
        tag: el?.tagName?.toLowerCase(),
        dataEdit: el?.getAttribute?.('data-death-edit'),
      };
    });
    if (info.className.includes('lml-death__readonly')) view.readonlyFocused = true;
    if (info.dataEdit != null) {
      view.editReached = true;
      break;
    }
  }
  inventory.keyboard.view = view;
  log.push('VIEW: readonly not inputs; editReached=' + view.editReached + '; readonlyFocused=' + view.readonlyFocused);

  // EDIT keyboard
  await page.goto(urls.edit(mb), { waitUntil: 'networkidle' });
  const edit = { fields: {} };
  for (let i = 0; i < 20; i++) {
    await page.keyboard.press('Tab');
    const info = await page.evaluate(() => {
      const el = document.activeElement;
      return {
        id: el?.id || null,
        dataSave: el?.getAttribute?.('data-death-save'),
        dataChoose: el?.getAttribute?.('data-death-choose-file'),
        forAttr: el?.getAttribute?.('for'),
        className: typeof el?.className === 'string' ? el.className : '',
      };
    });
    if (info.id === 'lml-death-cause') edit.fields.cause = true;
    if (info.id === 'lml-death-date') edit.fields.date = true;
    if (info.id === 'lml-death-certificate' || info.dataChoose != null || info.forAttr === 'lml-death-certificate' || info.className.includes('choose-file')) {
      edit.fields.chooseFile = true;
    }
    if (info.dataSave != null) edit.fields.save = true;
  }
  inventory.keyboard.edit = edit;
  log.push('EDIT fields reachable: ' + JSON.stringify(edit.fields));

  ensureDir(out('03-accessibility'));
  fs.writeFileSync(out('03-accessibility', 'keyboard-log.txt'), log.join('\n') + '\n\n' + JSON.stringify(inventory.keyboard, null, 2));
}

async function captureDom(page) {
  await seedRecord(page, mb);
  await page.goto(urls.death(mb), { waitUntil: 'networkidle' });
  const viewDom = await page.evaluate(() => {
    const readonlys = [...document.querySelectorAll('.lml-death__readonly')];
    const headings = [...document.querySelectorAll('h1,h2,h3')].map((h) => ({
      tag: h.tagName.toLowerCase(),
      id: h.id || null,
      text: h.textContent.trim().slice(0, 80),
    }));
    const decorative = [...document.querySelectorAll('.lml-death [aria-hidden="true"]')].length;
    return {
      mode: document.querySelector('[data-lml-death]')?.getAttribute('data-lml-death-mode'),
      readonly: readonlys.map((el) => ({
        tag: el.tagName.toLowerCase(),
        className: el.className,
        tabindex: el.getAttribute('tabindex'),
        contentEditable: el.getAttribute('contenteditable'),
        isInput: el.tagName.toLowerCase() === 'input',
      })),
      headings,
      decorativeAriaHiddenCount: decorative,
    };
  });

  await page.goto(urls.create(mbFresh), { waitUntil: 'networkidle' }).catch(() => {});
  // If create redirected, open create for empty or edit form for file label check
  let formUrl = page.url();
  if (!/create|edit/.test(formUrl)) {
    await page.goto(urls.edit(mb), { waitUntil: 'networkidle' });
  }
  const formDom = await page.evaluate(() => {
    const file = document.querySelector('#lml-death-certificate');
    const label = document.querySelector('label[for="lml-death-certificate"]');
    return {
      mode: document.querySelector('[data-lml-death]')?.getAttribute('data-lml-death-mode'),
      fileInputPresent: !!file,
      fileAccept: file?.getAttribute('accept') || null,
      labelForFile: !!label,
      labelText: label?.textContent?.trim() || null,
      causeDescribedBy: document.querySelector('#lml-death-cause')?.getAttribute('aria-describedby') || null,
    };
  });

  inventory.dom = { view: viewDom, form: formDom };
  ensureDir(out('06-dom-verification'));
  fs.writeFileSync(out('06-dom-verification', 'dom-verification.json'), JSON.stringify(inventory.dom, null, 2));
}

async function main() {
  ensureDir(root);
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext();
  const page = await context.newPage();

  try {
    // Order matters: keep MB-001 empty until after empty-state contrast + keyboard evidence.
    await captureResponsive(page);
    await captureContrast(page);
    await captureKeyboard(page);
    await captureNavigation(page);
    await captureDom(page);
  } catch (err) {
    inventory.errors.push(String(err?.stack || err));
    throw err;
  } finally {
    await browser.close();
    ensureDir(out('07-manifest'));
    fs.writeFileSync(out('07-manifest', 'capture-inventory.json'), JSON.stringify(inventory, null, 2));
    fs.writeFileSync(out('03-accessibility', 'contrast-report.json'), JSON.stringify(inventory.contrast, null, 2));

    const contrastMd = [
      '# Contrast Report — Death Phase 1 Final Closeout',
      '',
      'Method: WCAG 2.x relative luminance contrast from computed CSS colors (Playwright getComputedStyle).',
      'Threshold for normal text / UI component text: 4.5:1 (WCAG AA).',
      '',
      '| Check | FG hex | BG hex | Ratio | Threshold | Result |',
      '|---|---|---|---:|---:|---|',
      ...inventory.contrast.map(
        (c) =>
          `| ${c.label} | ${c.foregroundHex || '—'} | ${c.backgroundHex || '—'} | ${c.ratio ?? '—'} | ${c.threshold} | ${c.result} |`
      ),
      '',
    ].join('\n');
    fs.writeFileSync(out('03-accessibility', 'contrast-report.md'), contrastMd);

    console.log(JSON.stringify({
      screenshotCount: inventory.screenshots.length,
      contrastChecks: inventory.contrast.length,
      navigationSteps: inventory.navigation.length,
      errors: inventory.errors,
    }, null, 2));
  }
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
