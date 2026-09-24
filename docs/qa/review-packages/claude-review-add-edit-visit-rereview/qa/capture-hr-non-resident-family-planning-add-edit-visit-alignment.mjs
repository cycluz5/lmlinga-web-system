/**
 * Add Visit / Edit Visit — Figma alignment evidence.
 */
import { chromium } from 'playwright';
import path from 'path';
import { fileURLToPath } from 'url';
import fs from 'fs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.resolve(
  __dirname,
  '../docs/qa/screenshots/health-records-non-resident-family-planning-add-edit-visit-actions-banner'
);
fs.mkdirSync(outDir, { recursive: true });

const base = process.env.FP_NR_CAPTURE_BASE || 'http://127.0.0.1:8765';
const role = 'role=bns';

const viewports = [
  { name: '1440x900', width: 1440, height: 900 },
  { name: '820x1180', width: 820, height: 1180 },
  { name: '390x844', width: 390, height: 844 },
];

const pages = [
  {
    key: 'add',
    path: `/health-records/family-planning/non-residents/roselyn-a-mendoza/visits/create?${role}`,
  },
  {
    key: 'edit',
    path: `/health-records/family-planning/non-residents/roselyn-a-mendoza/visits/NR-FP-001/edit?${role}`,
  },
];

const browser = await chromium.launch({ headless: true });

async function measure(page) {
  return page.evaluate(() => {
    const doc = document.documentElement;
    const body = document.body;
    const pageOverflow =
      Math.max(doc.scrollWidth, body.scrollWidth) > doc.clientWidth + 1;

    const main =
      document.querySelector('.lml-dashboard__content') ||
      document.querySelector('#lml-dashboard-content') ||
      document.querySelector('main');
    const panel = document.querySelector('.lml-hr-fp-nr__form-panel--visit');
    const form = document.querySelector('.lml-hr-fp-nr__form');
    const split = document.querySelector('.lml-hr-fp-nr__form-split--visit');
    const visitCol = document.querySelector('.lml-hr-fp-nr__form-split-col:first-child');
    const commoditiesCol = document.querySelector('[data-hr-fp-nr-commodities]');
    const dateInput = document.querySelector('input[name="visited_at"]');
    const remarks = document.querySelector('textarea[name="remarks"]');
    const commodity = document.querySelector('[data-hr-fp-nr-commodity-name]');
    const quantity = document.querySelector('[data-hr-fp-nr-commodity-qty]');
    const actions =
      document.querySelector('.lml-hr-fp-nr__form-actions--visit-span') ||
      document.querySelector('.lml-hr-fp-nr__form-actions--centered');
    const actionEnd = document.querySelector('.lml-hr-fp-nr__form-actions-end');
    const banner = document.querySelector('.lml-hr-fp-nr__client-banner');
    const nameEl = document.querySelector('.lml-hr-fp-nr__client-name');
    const metaEl = document.querySelector('.lml-hr-fp-nr__client-meta');
    const heading = document.querySelector('.lml-hr-fp-nr__visit-form-heading');
    const addCommodity = document.querySelector('.lml-hr-fp-nr__add-commodity');
    const formBannerLegacy = document.querySelector('.lml-hr-fp-nr__form-banner');

    const mainRect = main?.getBoundingClientRect();
    const panelRect = panel?.getBoundingClientRect();
    const visitRect = visitCol?.getBoundingClientRect();
    const commoditiesRect = commoditiesCol?.getBoundingClientRect();
    const actionsRect = actions?.getBoundingClientRect();
    const headingRect = heading?.getBoundingClientRect();
    const bannerRect = banner?.getBoundingClientRect();
    const formStyles = form ? getComputedStyle(form) : null;
    const splitStyles = split ? getComputedStyle(split) : null;
    const actionsStyles = actions ? getComputedStyle(actions) : null;
    const panelStyles = panel ? getComputedStyle(panel) : null;

    const gap =
      visitRect && commoditiesRect && commoditiesRect.top - visitRect.top < 40
        ? Math.round(commoditiesRect.left - visitRect.right)
        : null;

    const cardsBottom = Math.max(
      visitRect?.bottom ?? 0,
      commoditiesRect?.bottom ?? 0
    );
    const actionsTop = actionsRect?.top ?? null;

    const visitHeading = visitCol?.querySelector('.lml-hr-fp-nr__subheading');
    const commoditiesHeading = commoditiesCol?.querySelector(
      '.lml-hr-fp-nr__subheading'
    );

    const cardHeadingAudit = (card, titleEl) => {
      if (!card || !titleEl) {
        return {
          borderTopContinuous: false,
          headingInsideCard: false,
          usesLegend: false,
          cardHeight: null,
        };
      }
      const cardRect = card.getBoundingClientRect();
      const titleRect = titleEl.getBoundingClientRect();
      const styles = getComputedStyle(card);
      const borderTop = Number.parseFloat(styles.borderTopWidth) || 0;
      const padTop = Number.parseFloat(styles.paddingTop) || 0;
      const headingInsideCard =
        titleRect.top >= cardRect.top + borderTop - 0.5 &&
        titleRect.bottom <= cardRect.bottom + 0.5 &&
        titleRect.left >= cardRect.left - 0.5 &&
        titleRect.right <= cardRect.right + 0.5 &&
        titleRect.top >= cardRect.top + borderTop + Math.min(padTop, 4) - 1;
      const usesLegend = titleEl.tagName === 'LEGEND';
      // Continuous top border = non-zero border + heading not a legend interrupting the edge
      const borderTopContinuous =
        borderTop > 0 && !usesLegend && titleRect.top > cardRect.top + borderTop - 0.5;

      return {
        borderTopContinuous,
        headingInsideCard,
        usesLegend,
        headingTag: titleEl.tagName.toLowerCase(),
        cardHeight: Math.round(cardRect.height),
        titleOffsetFromCardTop: Math.round(titleRect.top - cardRect.top),
      };
    };

    const visitCardAudit = cardHeadingAudit(visitCol, visitHeading);
    const commoditiesCardAudit = cardHeadingAudit(
      commoditiesCol,
      commoditiesHeading
    );

    const cardsTopAligned =
      visitRect && commoditiesRect
        ? Math.abs(visitRect.top - commoditiesRect.top) <= 1
        : false;

    const result = {
      pageOverflow,
      mainContentWidth: mainRect ? Math.round(mainRect.width) : null,
      formPanelWidth: panelRect ? Math.round(panelRect.width) : null,
      formCompositionHeight: panelRect ? Math.round(panelRect.height) : null,
      formContentWidth: form
        ? Math.round(form.getBoundingClientRect().width)
        : null,
      formPaddingLeft: formStyles
        ? Math.round(Number.parseFloat(formStyles.paddingLeft))
        : null,
      outerPanelBorder: panelStyles?.borderTopWidth ?? null,
      hasLegacyFormBanner: Boolean(formBannerLegacy),
      headingText: heading?.textContent?.replace(/\s+/g, ' ').trim() ?? null,
      headingTop: headingRect ? Math.round(headingRect.top) : null,
      visitInformationWidth: visitRect ? Math.round(visitRect.width) : null,
      visitInformationHeight: visitRect ? Math.round(visitRect.height) : null,
      commoditiesGivenWidth: commoditiesRect
        ? Math.round(commoditiesRect.width)
        : null,
      commoditiesGivenHeight: commoditiesRect
        ? Math.round(commoditiesRect.height)
        : null,
      visitCard: visitCardAudit,
      commoditiesCard: commoditiesCardAudit,
      cardsTopAligned,
      hasFieldsetCards: Boolean(
        document.querySelector(
          '.lml-hr-fp-nr__form-split--visit fieldset, .lml-hr-fp-nr__form-split--visit legend'
        )
      ),
      panelGap: gap,
      splitColumnGap: splitStyles
        ? Math.round(Number.parseFloat(splitStyles.columnGap || splitStyles.gap))
        : null,
      dateInputWidth: dateInput
        ? Math.round(dateInput.getBoundingClientRect().width)
        : null,
      dateInputHeight: dateInput
        ? Math.round(dateInput.getBoundingClientRect().height)
        : null,
      remarksWidth: remarks
        ? Math.round(remarks.getBoundingClientRect().width)
        : null,
      remarksHeight: remarks
        ? Math.round(remarks.getBoundingClientRect().height)
        : null,
      commodityWidth: commodity
        ? Math.round(commodity.getBoundingClientRect().width)
        : null,
      quantityWidth: quantity
        ? Math.round(quantity.getBoundingClientRect().width)
        : null,
      commodityQtyRatio:
        commodity && quantity
          ? Number(
              (
                commodity.getBoundingClientRect().width /
                (commodity.getBoundingClientRect().width +
                  quantity.getBoundingClientRect().width)
              ).toFixed(3)
            )
          : null,
      addCommodityHeight: addCommodity
        ? Math.round(addCommodity.getBoundingClientRect().height)
        : null,
      cardsToActionsGap:
        actionsTop != null ? Math.round(actionsTop - cardsBottom) : null,
      actionsJustify: actionsStyles?.justifyContent ?? null,
      actionsLeft: actionsRect && panelRect
        ? Math.round(actionsRect.left - panelRect.left)
        : null,
      actionsWidth: actionsRect ? Math.round(actionsRect.width) : null,
      actionGroupWidth: actionEnd
        ? Math.round(actionEnd.getBoundingClientRect().width)
        : null,
      clientBannerWidth: bannerRect ? Math.round(bannerRect.width) : null,
      clientBannerHeight: bannerRect ? Math.round(bannerRect.height) : null,
      formBannerLeftDelta:
        panelRect && bannerRect
          ? Math.round(panelRect.left - bannerRect.left)
          : null,
      formBannerRightDelta:
        panelRect && bannerRect
          ? Math.round(panelRect.right - bannerRect.right)
          : null,
      formMatchesBannerWidth:
        panelRect && bannerRect
          ? Math.abs(panelRect.width - bannerRect.width) <= 2
          : null,
      hasDeleteVisit: Boolean(
        document.querySelector('[data-hr-fp-nr-delete-visit]')
      ),
      actionsCentered:
        actionsStyles?.justifyContent === 'center' ||
        actionsStyles?.justifyContent === 'safe center',
      twoSeparatePanels:
        document.querySelectorAll(
          '.lml-hr-fp-nr__form-split--visit > .lml-hr-fp-nr__section-box'
        ).length === 2,
      formWrapperLeft: visitRect && commoditiesRect
        ? Math.round(Math.min(visitRect.left, commoditiesRect.left))
        : null,
      formWrapperRight: visitRect && commoditiesRect
        ? Math.round(Math.max(visitRect.right, commoditiesRect.right))
        : null,
      formWrapperCenterX: (() => {
        if (!visitRect || !commoditiesRect) return null;
        const left = Math.min(visitRect.left, commoditiesRect.left);
        const right = Math.max(visitRect.right, commoditiesRect.right);
        return Math.round((left + right) / 2);
      })(),
      actionGroupLeft: (() => {
        const btns = actions ? [...actions.querySelectorAll('.lml-hr-fp-nr__btn')] : [];
        if (!btns.length) return null;
        return Math.round(Math.min(...btns.map((b) => b.getBoundingClientRect().left)));
      })(),
      actionGroupRight: (() => {
        const btns = actions ? [...actions.querySelectorAll('.lml-hr-fp-nr__btn')] : [];
        if (!btns.length) return null;
        return Math.round(Math.max(...btns.map((b) => b.getBoundingClientRect().right)));
      })(),
      actionGroupCenterX: (() => {
        const btns = actions ? [...actions.querySelectorAll('.lml-hr-fp-nr__btn')] : [];
        if (!btns.length) return null;
        const left = Math.min(...btns.map((b) => b.getBoundingClientRect().left));
        const right = Math.max(...btns.map((b) => b.getBoundingClientRect().right));
        return Math.round((left + right) / 2);
      })(),
      actionFormCenterDelta: null,
      actionsInFormGrid: Boolean(
        actions?.classList.contains('lml-hr-fp-nr__form-actions--visit-span')
      ),
      clientNameColor: nameEl ? getComputedStyle(nameEl).color : null,
      clientDetailsColor: metaEl ? getComputedStyle(metaEl).color : null,
      clientNameFontSize: nameEl ? getComputedStyle(nameEl).fontSize : null,
      clientDetailsFontSize: metaEl ? getComputedStyle(metaEl).fontSize : null,
      clientBannerBg: banner ? getComputedStyle(banner).backgroundColor : null,
    };

    if (
      result.formWrapperCenterX != null &&
      result.actionGroupCenterX != null
    ) {
      result.actionFormCenterDelta = Math.abs(
        result.actionGroupCenterX - result.formWrapperCenterX
      );
    }

    return result;
  });
}

const measurements = [];

for (const vp of viewports) {
  for (const route of pages) {
    const page = await browser.newPage();
    await page.setViewportSize({ width: vp.width, height: vp.height });
    await page.goto(`${base}${route.path}`, {
      waitUntil: 'networkidle',
      timeout: 60000,
    });
    const file = path.join(outDir, `${route.key}-visit-${vp.name}.png`);
    await page.screenshot({ path: file, fullPage: true });
    const metrics = await measure(page);
    measurements.push({
      page: route.key,
      viewport: vp.name,
      ...metrics,
    });
    console.log(
      `saved ${file} formW=${metrics.formPanelWidth} centerDelta=${metrics.actionFormCenterDelta} nameColor=${metrics.clientNameColor} overflow=${metrics.pageOverflow}`
    );
    await page.close();
  }
}

fs.writeFileSync(
  path.join(outDir, 'layout-measurements.json'),
  `${JSON.stringify(measurements, null, 2)}\n`
);

await browser.close();
