# Health-record encryption at rest (AES-256-GCM)

## What is encrypted

Registry: `app/Support/AtRestColumns.php` (single source of truth).

| Table | Columns |
|---|---|
| `medical_history` | all 6 condition flags, `other_medical_history` |
| `disability_type` | all 5 flags, `other_disability_specify` |
| `family_history` | all 12 flags |
| `past_medical_history` | all 12 flags |
| `red_flags_assessment` | all 13 flags |
| `visual_screening` | `no_screening_past_year`, `has_blurred_vision`, `blurred_vision_details` |
| `risk_assessment` | tobacco, alcohol, diet, activity, height, weight, waist, systolic, diastolic, `blood_pressure_status` |
| `death_records` | `cause_of_death`, `death_certificate_no` (Registry Number) |
| `hiv_screening`, `syphilis_screening`, `hepatitis_b_screening` | `result` |

Plaintext on purpose: ids, foreign keys, timestamps, `date_of_death`, `verification_status`,
`death_certificate_file_path`, `family_planning.remarks`, `death_records.rejection_reason`.

Stored format: `lmlinga:v2:<keyId>:<base64(nonce | ciphertext | tag)>`, bound to `table|column`.

## Behaviour changes

- `risk_assessment.blood_pressure_status` is no longer a MySQL generated column; the app computes it
  with the same rules and labels (`RiskAssessmentErdMode::bloodPressureStatusLabel()`).
- Lifestyle answer lists are fixed in code (`RiskAssessmentErdMode::allowedValues()`), not read from MySQL enums.
- The Death Records "Cause of Death" filter matches in PHP (case-insensitive).
- In phpMyAdmin these columns show `lmlinga:v2:...`; edit them through the app only.

## Key

- `LMLINGA_AT_REST_KEY` in `.env` (must differ from `APP_KEY`). **Back it up outside the server.**
  Losing it makes these columns unreadable; pages show `[Decryption unavailable]`.
- Generate: `php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"`
- Rotation: move the old key to `LMLINGA_AT_REST_PREVIOUS_KEYS=k1=base64:...`, set a new key with a new
  `LMLINGA_AT_REST_KEY_ID` (e.g. `k2`). Old rows keep decrypting; rows are re-encrypted with the new key when saved.

## Deploying

This database was built from an SQL import, so most migration files are **not** recorded in `migrations`.
Never run plain `php artisan migrate` — it would create legacy tables and switch the app out of ERD mode.
Run only these, by path:

```bash
php artisan migrate --path=database/migrations/2026_09_24_100000_decrypt_family_planning_remarks_and_death_rejection_reason.php --force
php artisan migrate --path=database/migrations/2026_09_24_110000_encrypt_health_record_columns_at_rest.php --force
php artisan lmlinga:at-rest:verify
```

Back up the database first. `lmlinga:at-rest:verify` must end with
"All registered health-record columns are encrypted and decryptable."

## Rollback

```bash
php artisan migrate:rollback --path=database/migrations/2026_09_24_110000_encrypt_health_record_columns_at_rest.php --force
php artisan migrate:rollback --path=database/migrations/2026_09_24_100000_decrypt_family_planning_remarks_and_death_rejection_reason.php --force
```

The first decrypts every row and restores the original column types, including the generated
`blood_pressure_status`. The second re-encrypts `family_planning.remarks` and `death_records.rejection_reason`.
Roll back the code together with the schema.
