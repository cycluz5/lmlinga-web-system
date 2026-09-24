# Outline CTA Contrast — Post-Fix

## Previous
- Border: `#9FDDAD` (rgba accent @0.55 on white)
- Border contrast: **1.56:1** FAIL (threshold 3:1)

## After (computed)

| Check | FG hex | BG hex | Ratio | Threshold | Result |
|---|---|---|---:|---:|---|
| NORMAL text vs background | #146C2E | #FFFFFF | 6.53 | 4.5 | PASS |
| NORMAL border vs background | #146C2E | #FFFFFF | 6.53 | 3 | PASS |
| NORMAL icon vs background | #146C2E | #FFFFFF | 6.53 | 4.5 | PASS |
| HOVER text vs background | #146C2E | #F0FDF4 | 6.24 | 4.5 | PASS |
| HOVER border vs background | #157347 | #F0FDF4 | 5.61 | 3 | PASS |
| HOVER icon vs background | #146C2E | #F0FDF4 | 6.24 | 4.5 | PASS |
| FOCUS text vs background | #146C2E | #F0FDF4 | 6.24 | 4.5 | PASS |
| FOCUS border vs background | #146C2E | #F0FDF4 | 6.24 | 3 | PASS |
| FOCUS outline vs surrounding white | #146C2E | #FFFFFF | 6.53 | 3 | PASS |
