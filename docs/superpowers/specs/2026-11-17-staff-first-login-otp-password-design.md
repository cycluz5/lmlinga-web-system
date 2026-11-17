# Staff first-login: emailed code + new password

## Goal
When an admin creates a health-worker account (or resets its password):
- the given email must be proven real;
- the worker must replace the admin-set temporary password the first time they open the account.

## Decisions
- The welcome email carries the username and the temporary password.
- The forced change applies to new accounts and admin password resets only. Legacy accounts are untouched.
- No separate change-password OTP system. The existing first-login email-verification step does both jobs.

## Design
- `temp_password_issued_at` (nullable timestamp, on `users` and `user_management`) marks "new account or admin reset". Legacy rows stay NULL and are never forced. The migration is idempotent (`hasTable` / `hasColumn`).
- `HealthWorkerAccountService`
  - create, and update with a changed `hw_password`: set `must_change_password = true` and `temp_password_issued_at = now()`, and call `StaffEmailVerificationService::markPending()`.
  - an admin reset is not forced on the last usable Admin (same typo-lockout guard as the email re-verification rule).
  - `sendCredentialsEmail()` runs after the transaction commits and sends `StaffAccountCredentialsMail` (reset variant for resets). A failure keeps the account, logs `staff.credentials_email.delivery_failed` and adds a warning to the flash message. The success message names the address.
- First login: correct temporary password -> pending -> `/staff/verify-email`. When `temp_password_issued_at` is set the screen also asks for New password and Confirm password.
  - the new password is validated first (`StrongPassword`, confirmation, different from the temporary password) so a typo does not spend the code;
  - the code is then verified with the existing service (5 minutes, 5 attempts, cooldown, hourly cap);
  - on success, in one transaction: new password saved, `must_change_password = false`, `temp_password_issued_at = NULL`, email verified. The worker is then signed in and sent to the dashboard;
  - a wrong or expired code changes nothing.
- `EnsurePasswordIsCurrent` is a narrowed safety net: an authenticated staff user still holding `temp_password_issued_at` is logged out and re-marked pending (JSON/offline requests get a 403). If `LMLINGA_STAFF_EMAIL_VERIFICATION` is off there is no code step, so the user is redirected to `password.change.required` (no OTP). The change-password page clears the marker.

## Known limitations
- With `LMLINGA_STAFF_EMAIL_VERIFICATION=false` the email is not proven real; the worker is only forced to change the temporary password on the change-password page.
- The temporary password is emailed in clear text (user decision). It stops working once the worker sets their own password.
- Mail delivery is not transactional with the account: if the email fails, the admin must fix the address or reset the password to resend.

## Tests
`tests/Feature/HealthWorkerFirstLoginPasswordOtpTest.php` covers create/reset email, first-login OTP, new password, wrong/expired code, same-as-temporary rejection, legacy accounts, ERD mode, mail failure and the middleware safety net. `HealthWorkerCreateLoginFlowTest` follows the new flow.
