# LMLinga Dashboard — Independent Review Notes

## Review Status
READY FOR INDEPENDENT REVIEW
NOT PRODUCTION-FROZEN

## Scope
Review the Dashboard UI only.

## Final Dashboard Composition
- Dashboard title + description
- Five Overview cards:
  - Total Household
  - Total Residents
  - NHTS
  - Non NHTS
  - Non NHTS Poor
- Spot Mapping panel
- Household table
- Health Indicators panel

## Health Indicators
Current approved count: 13

1. Teenage Pregnant
2. Pregnant
3. Lactating
4. FP Current User
5. FP Unmet Needs
6. Normal Weight Children
7. Underweight Children
8. Overweight Children
9. Exclusively Breastfed Infants
10. Infants 0–11 Months
11. HH With Large Family Size
12. HH With Potable Water Source
13. HH With Sanitary Toilet

Complementary Food is intentionally removed.

## Indicator Meaning
- Teenage Pregnant: resident below 19 with an active maternal pregnancy record
- Pregnant: residents with active pregnancy records
- Lactating: residents currently breastfeeding
- FP Current User: active Family Planning users
- FP Unmet Needs: residents with missed Family Planning appointments
- Normal Weight Children: children age 0–5 with Normal nutritional status
- Underweight Children: children recorded as Underweight
- Overweight Children: children recorded as Overweight
- Exclusively Breastfed Infants: infants age 0–6 months receiving exclusive breastfeeding
- Infants 0–11 Months: residents aged 0–11 months
- HH With Large Family Size: households with 6 or more members
- HH With Potable Water Source: households flagged with safely managed potable water
- HH With Sanitary Toilet: households flagged with sanitary toilet facility

## Responsive Requirements
Desktop 1440:
- five Overview cards in one row
- map/table left
- Health Indicators right
- no page overflow

Laptop 1366:
- same desktop structure
- no clipping
- no page overflow

Tablet 820:
- stacked workspace
- map, table, and Health Indicators align to the same outer width
- two-column indicators

Mobile 390:
- centered informational content
- navigation remains normally aligned
- Overview cards use two columns with final card spanning
- Health Indicators use two columns with final sanitary-toilet tile spanning
- map, household table, and Health Indicators share the same left/right boundaries
- no page-level horizontal overflow

## Approved Styling
- LMLinga green visual system
- Health Indicator gradient/tinted backgrounds preserved
- colored semantic pictogram icons
- no circle or box around Health Indicator icons
- five Overview cards contain no icons
- Health Indicator header centered
- Spot Mapping title centered
- mobile informational contents centered
- navigation contents are NOT globally centered

## Current Width Evidence
390px:
- map = 358px
- table = 358px
- Health Indicators = 358px
- left = 16px
- right = 374px

820px:
- map = 772px
- table = 772px
- Health Indicators = 772px
- left = 24px
- right = 796px

Desktop remains intentionally side-by-side rather than equal-width.

## Important Review Constraint
Do not evaluate against obsolete Figma sidebar contents.
Review the current approved LMLinga navigation architecture.

Do not recommend reintroducing:
- Complementary Food
- Search field
- Overview card icons
- obsolete Figma Health Records submenu

## Review Goal
Determine whether the Dashboard is visually consistent, responsive, accessible, regression-safe, and ready for Production Freeze.
