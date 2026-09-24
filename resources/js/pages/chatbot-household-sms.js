/**
 * Resident AI Chatbot — SMS Verification OTP UI.
 * Wired to server SMS send/verify when data-lml-otp-server-submit is present.
 */

function prefersReducedMotion() {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

function formatCountdown(totalSeconds) {
    const safe = Math.max(0, totalSeconds);
    const minutes = String(Math.floor(safe / 60)).padStart(2, '0');
    const seconds = String(safe % 60).padStart(2, '0');
    return `${minutes}:${seconds}`;
}

function initSmsVerification(root) {
    const form = root.querySelector('[data-lml-sms-form]');
    const digits = Array.from(root.querySelectorAll('[data-lml-otp-digit]'));
    const errorEl = root.querySelector('[data-lml-otp-error]');
    const timerEl = root.querySelector('[data-lml-otp-timer]');
    const timerTextEl = root.querySelector('[data-lml-otp-timer-text]');
    const announcementEl = root.querySelector('[data-lml-otp-announcement]');
    const verifyBtn = root.querySelector('[data-lml-otp-verify]');
    const resendBtn = root.querySelector('[data-lml-otp-resend]');
    const alternativeBtn = root.querySelector('[data-lml-otp-alternative]');
    const toastEl = root.querySelector('[data-lml-sms-toast]');
    const statusUrl = root.dataset.statusUrl;
    const alternativeUrl = root.dataset.alternativeUrl;
    const resendSuccessMessage = root.dataset.resendSuccessMessage || 'New OTP sent.';
    const resendLocked = Boolean(
        root.dataset.smsPaused === 'true'
        || resendBtn?.hasAttribute('data-lml-otp-resend-locked')
    );

    if (!form || !verifyBtn || digits.length !== 6) {
        return;
    }

    const initialSeconds = Number.parseInt(root.dataset.otpSeconds || '0', 10);
    let remainingSeconds = Number.isFinite(initialSeconds) ? initialSeconds : 0;
    let timerId = null;
    let toastTimerId = null;
    let verifying = false;
    let hasHandledExpiry = false;
    const announcedThresholds = new Set();
    const lockTimerEl = root.querySelector('[data-lml-otp-lock-timer]');
    const lockTimerTextEl = root.querySelector('[data-lml-otp-lock-timer-text]');
    const serverResendBtn = root.querySelector('[data-lml-otp-resend-server]');
    const initialLockSeconds = Number.parseInt(root.dataset.otpLockSeconds || '0', 10);
    let remainingLockSeconds = Number.isFinite(initialLockSeconds) ? Math.max(0, initialLockSeconds) : 0;
    let lockTimerId = null;
    let verificationLocked = remainingLockSeconds > 0 || root.dataset.otpLocked === 'true';

    const countdownAnnouncements = new Map([
        [60, 'The verification code will expire in 1 minute.'],
        [30, '30 seconds remaining.'],
        [10, '10 seconds remaining.'],
        [0, 'The verification code has expired. Select Resend OTP to request a new code.'],
    ]);

    function showToast(message) {
        if (!toastEl) {
            return;
        }
        toastEl.hidden = false;
        toastEl.textContent = message;
        window.clearTimeout(toastTimerId);
        toastTimerId = window.setTimeout(
            () => {
                toastEl.hidden = true;
                toastEl.textContent = '';
            },
            prefersReducedMotion() ? 1800 : 2800
        );
    }

    function setError(message, invalidDigits = []) {
        if (!errorEl) {
            return;
        }
        if (!message) {
            errorEl.hidden = true;
            errorEl.textContent = '';
            digits.forEach((input) => {
                input.setAttribute('aria-invalid', 'false');
            });
            return;
        }
        errorEl.hidden = false;
        errorEl.textContent = message;
        digits.forEach((input) => {
            input.setAttribute('aria-invalid', invalidDigits.includes(input) ? 'true' : 'false');
        });
    }

    function getCode() {
        return digits.map((input) => input.value).join('');
    }

    function syncVerifyButtonState() {
        const hasValidCode = /^\d{6}$/.test(getCode());
        const canVerify = hasValidCode && remainingSeconds > 0 && !verifying && !verificationLocked;
        verifyBtn.disabled = !canVerify;

        if (!verifying) {
            verifyBtn.textContent = 'Verify';
            verifyBtn.setAttribute('aria-busy', 'false');
        }
    }

    function applyVerificationLockUi() {
        if (!verificationLocked) {
            if (lockTimerEl) {
                lockTimerEl.hidden = true;
            }
            if (timerEl) {
                timerEl.hidden = false;
            }
            if (serverResendBtn) {
                serverResendBtn.disabled = false;
                serverResendBtn.removeAttribute('title');
            }
            setInputsDisabled(remainingSeconds <= 0);
            syncVerifyButtonState();
            return;
        }

        if (timerEl) {
            timerEl.hidden = true;
        }
        if (lockTimerEl && lockTimerTextEl) {
            lockTimerEl.hidden = false;
            lockTimerTextEl.innerHTML =
                `You have reached the maximum number of attempts. Please try again in <strong data-lml-otp-lock-timer-value>${formatCountdown(remainingLockSeconds)}</strong>.`;
        }
        if (errorEl && remainingLockSeconds > 0) {
            errorEl.hidden = false;
            errorEl.textContent =
                `You have reached the maximum number of attempts. Please try again in ${formatCountdown(remainingLockSeconds)}.`;
        }
        if (serverResendBtn) {
            serverResendBtn.disabled = true;
            serverResendBtn.title = 'Verification is temporarily locked';
        }
        setInputsDisabled(true);
        verifyBtn.disabled = true;
    }

    function clearVerificationLock() {
        verificationLocked = false;
        remainingLockSeconds = 0;
        if (lockTimerId !== null) {
            window.clearInterval(lockTimerId);
            lockTimerId = null;
        }
        root.dataset.otpLocked = 'false';
        applyVerificationLockUi();
    }

    function startLockCountdown(seconds) {
        if (lockTimerId !== null) {
            window.clearInterval(lockTimerId);
            lockTimerId = null;
        }

        remainingLockSeconds = Math.max(0, seconds);
        verificationLocked = remainingLockSeconds > 0;
        root.dataset.otpLocked = verificationLocked ? 'true' : 'false';
        applyVerificationLockUi();

        if (!verificationLocked) {
            return;
        }

        lockTimerId = window.setInterval(() => {
            remainingLockSeconds -= 1;
            if (remainingLockSeconds <= 0) {
                clearVerificationLock();
                return;
            }
            applyVerificationLockUi();
        }, 1000);
    }

    function clearDigits() {
        digits.forEach((input) => {
            input.value = '';
            input.disabled = false;
        });
        setError('');
        syncVerifyButtonState();
        digits[0]?.focus();
    }

    function setInputsDisabled(disabled) {
        digits.forEach((input) => {
            input.disabled = disabled;
        });
    }

    function announceCountdownThreshold() {
        if (!announcementEl || announcedThresholds.has(remainingSeconds)) {
            return;
        }

        const message = countdownAnnouncements.get(remainingSeconds);
        if (!message) {
            return;
        }

        announcedThresholds.add(remainingSeconds);
        announcementEl.textContent = message;
    }

    function updateTimerUi() {
        if (!timerEl || !timerTextEl) {
            return;
        }

        announceCountdownThreshold();

        if (remainingSeconds <= 0) {
            timerEl.classList.add('is-expired');
            timerTextEl.innerHTML = '<strong>Code expired</strong>';
            if (resendBtn && !resendLocked) {
                resendBtn.disabled = false;
            }
            setInputsDisabled(true);
            syncVerifyButtonState();

            if (!hasHandledExpiry) {
                hasHandledExpiry = true;
                window.requestAnimationFrame(() => {
                    if (resendBtn && !resendLocked) {
                        resendBtn.focus();
                    }
                });
            }
            return;
        }

        timerEl.classList.remove('is-expired');
        timerTextEl.innerHTML =
            `The code will expire in <strong data-lml-otp-timer-value>${formatCountdown(remainingSeconds)}</strong>`;
        if (resendBtn && !resendLocked) {
            resendBtn.disabled = true;
        }
        setInputsDisabled(false);
        syncVerifyButtonState();
    }

    function stopTimer() {
        if (timerId !== null) {
            window.clearInterval(timerId);
            timerId = null;
        }
    }

    function startTimer(seconds) {
        stopTimer();
        remainingSeconds = seconds;
        hasHandledExpiry = false;
        announcedThresholds.clear();
        if (announcementEl) {
            announcementEl.textContent = '';
        }
        updateTimerUi();
        timerId = window.setInterval(() => {
            remainingSeconds -= 1;
            updateTimerUi();
            if (remainingSeconds <= 0) {
                stopTimer();
            }
        }, 1000);
    }

    function focusDigit(index) {
        const target = digits[index];
        if (!target || target.disabled) {
            return;
        }
        target.focus();
        target.select();
    }

    function tryVerify() {
        if (verifying || remainingSeconds <= 0 || verificationLocked) {
            return;
        }

        const code = getCode();
        if (!/^\d{6}$/.test(code)) {
            const invalidDigits = digits.filter((input) => !/^\d$/.test(input.value));
            setError('Please enter the complete 6-digit verification code.', invalidDigits);
            const firstInvalid = digits.indexOf(invalidDigits[0]);
            focusDigit(firstInvalid === -1 ? 0 : firstInvalid);
            syncVerifyButtonState();
            return;
        }

        /* SMS delivery/verify paused — never fake success or navigate. */
        if (resendLocked || root.dataset.smsPaused === 'true') {
            setError('SMS verification is temporarily unavailable. Please use Email.');
            syncVerifyButtonState();
            return;
        }

        setError('');
        verifying = true;
        stopTimer();
        setInputsDisabled(true);
        verifyBtn.textContent = 'Verifying…';
        verifyBtn.disabled = true;
        verifyBtn.setAttribute('aria-busy', 'true');
        if (resendBtn) {
            resendBtn.disabled = true;
        }

        /* UI-only fallback when SMS is not paused but server submit is not wired. */
        const delay = prefersReducedMotion() ? 120 : 450;
        window.setTimeout(() => {
            verifying = false;
            setInputsDisabled(false);
            verifyBtn.textContent = 'Verify';
            verifyBtn.setAttribute('aria-busy', 'false');
            syncVerifyButtonState();
        }, delay);
    }

    digits.forEach((input, index) => {
        input.addEventListener('input', (event) => {
            if (remainingSeconds <= 0 || verifying) {
                return;
            }

            const value = event.target.value.replace(/\D/g, '');
            event.target.value = value.slice(-1);
            setError('');

            if (event.target.value && index < digits.length - 1) {
                focusDigit(index + 1);
            }
            syncVerifyButtonState();
        });

        input.addEventListener('keydown', (event) => {
            if (verifying) {
                return;
            }

            if (event.key === 'Backspace') {
                event.preventDefault();
                if (input.value === '' && index > 0) {
                    digits[index - 1].value = '';
                    focusDigit(index - 1);
                } else {
                    input.value = '';
                }
                setError('');
                syncVerifyButtonState();
                return;
            }

            if (event.key === 'Delete') {
                event.preventDefault();
                input.value = '';
                setError('');
                syncVerifyButtonState();
                return;
            }

            if (event.key === 'ArrowLeft' && index > 0) {
                event.preventDefault();
                focusDigit(index - 1);
                return;
            }

            if (event.key === 'ArrowRight' && index < digits.length - 1) {
                event.preventDefault();
                focusDigit(index + 1);
                return;
            }

            if (event.key.length === 1 && !/\d/.test(event.key) && !event.ctrlKey && !event.metaKey) {
                event.preventDefault();
            }
        });

        input.addEventListener('paste', (event) => {
            if (remainingSeconds <= 0 || verifying) {
                event.preventDefault();
                return;
            }

            event.preventDefault();
            const pasted = (event.clipboardData || window.clipboardData)
                .getData('text')
                .replace(/\D/g, '')
                .slice(0, 6);

            if (!pasted) {
                return;
            }

            digits.forEach((digit, i) => {
                digit.value = pasted[i] || '';
            });
            setError('');
            syncVerifyButtonState();

            if (pasted.length === 6) {
                focusDigit(5);
            } else {
                focusDigit(pasted.length);
            }
        });

        input.addEventListener('focus', () => {
            input.select();
        });
    });

    if (resendBtn) {
        resendBtn.addEventListener('click', () => {
            if (resendBtn.disabled || verifying || resendLocked) {
                return;
            }
            /* Never toast a send success when SMS delivery is not active. */
            verifying = false;
            clearDigits();
            startTimer(initialSeconds);
        });
    }

    if (alternativeBtn) {
        alternativeBtn.addEventListener('click', () => {
            if (alternativeUrl) {
                window.location.assign(alternativeUrl);
                return;
            }
            showToast('Alternative verification is not available yet.');
        });
    }

    form.addEventListener('submit', (event) => {
        if (form.dataset.lmlOtpServerSubmit === 'true') {
            const submitter = event.submitter;
            if (
                submitter instanceof HTMLButtonElement
                && (
                    submitter.hasAttribute('data-lml-otp-resend-server')
                    || (submitter.getAttribute('formaction') || '').includes('/email/send')
                )
            ) {
                if (verificationLocked) {
                    event.preventDefault();
                    applyVerificationLockUi();
                }
                return;
            }

            if (verificationLocked) {
                event.preventDefault();
                applyVerificationLockUi();
                return;
            }

            const code = getCode();
            if (!/^\d{6}$/.test(code)) {
                event.preventDefault();
                const invalidDigits = digits.filter((input) => !/^\d$/.test(input.value));
                setError('Please enter the complete 6-digit verification code.', invalidDigits);
                const firstInvalid = digits.indexOf(invalidDigits[0]);
                focusDigit(firstInvalid === -1 ? 0 : firstInvalid);
                syncVerifyButtonState();
                return;
            }

            const hidden = form.querySelector('[data-lml-otp-value]');
            if (hidden) {
                hidden.value = code;
            }

            verifying = true;
            setInputsDisabled(true);
            verifyBtn.textContent = 'Verifying…';
            verifyBtn.disabled = true;
            verifyBtn.setAttribute('aria-busy', 'true');
            return;
        }

        event.preventDefault();
        tryVerify();
    });

    startTimer(remainingSeconds);
    if (verificationLocked) {
        startLockCountdown(remainingLockSeconds);
    }
    window.requestAnimationFrame(() => {
        if (!verificationLocked) {
            focusDigit(0);
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-lml-sms-verify]').forEach((root) => {
        initSmsVerification(root);
    });
});
