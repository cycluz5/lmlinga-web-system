# Contrast Report — Death Phase 1 Final Closeout

Method: WCAG 2.x relative luminance contrast from computed CSS colors (Playwright getComputedStyle).
Threshold for normal text / UI component text: 4.5:1 (WCAG AA).

| Check | FG hex | BG hex | Ratio | Threshold | Result |
|---|---|---|---:|---:|---|
| Record death information — text vs background | #146C2E | #FFFFFF | 6.53 | 4.5 | PASS |
| Record death information — icon vs background | #146C2E | #FFFFFF | 6.53 | 4.5 | PASS |
| Record death information — border vs background | #146C2E | #FFFFFF | 6.53 | 3 | PASS |

> **Post-fix (2026-08-10):** Previous border `#9FDDAD` @ 1.56:1 FAIL was replaced with module token `--death-accent-text` (`#146C2E`). See `09-outline-contrast-fix/`.

| DEATH INFORMATION heading vs panel/page background | #146C2E | #FFFFFF | 6.53 | 4.5 | PASS |
| Save button — normal | #FFFFFF | #157347 | 5.87 | 4.5 | PASS |
| Save button — hover | #FFFFFF | #0F5132 | 9.36 | 4.5 | PASS |
| Save button — focus-visible colors | #FFFFFF | #0F5132 | 9.36 | 4.5 | PASS |
| Edit button — normal | #FFFFFF | #0F5132 | 9.36 | 4.5 | PASS |
| Edit button — hover | #FFFFFF | #0F5132 | 9.36 | 4.5 | PASS |
