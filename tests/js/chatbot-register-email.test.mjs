/**
 * Gmail-only registration check for resources/js/pages/chatbot-register.js
 *
 * Runtime: Node.js built-in test runner — no Vitest/Jest/Playwright.
 */

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const sourcePath = path.resolve('resources/js/pages/chatbot-register.js');
const { GMAIL_PATTERN, GMAIL_MESSAGE, isGmailAddress, normalizeEmail, initGmailOnlyRegistration } = await import(pathToFileURL(sourcePath).href);

describe('isGmailAddress', () => {
    it('accepts valid Gmail addresses after trimming and lowercasing', () => {
        for (const ok of ['juan.delacruz@gmail.com', 'resident2026@gmail.com', '  Juan.Dela@Gmail.COM  ', 'abcdef@gmail.com', 'a.b.c.d.e.f@gmail.com', `${'a'.repeat(30)}@gmail.com`]) {
            assert.equal(isGmailAddress(ok), true, ok);
        }
    });

    it('rejects other domains and look-alikes', () => {
        for (const bad of ['resident@example.test', 'resident@yahoo.com', 'resident@googlemail.com', 'resident@gmail.co', 'resident@gmail.com.ph',
            'resident@mail.gmail.com', 'gmail.com@yahoo.com', 'resident@gmail.com.evil.test', 'resident@gmailcom', 'resident@gmail..com']) {
            assert.equal(isGmailAddress(bad), false, bad);
        }
    });

    it('rejects malformed addresses and Gmail-invalid usernames', () => {
        for (const bad of ['', '   ', 'resident', 'resident@', '@gmail.com', 'res@ident@gmail.com', 'res ident@gmail.com', '.resident@gmail.com',
            'resident.@gmail.com', 'res..ident@gmail.com', 'abc@gmail.com', `${'a'.repeat(31)}@gmail.com`, 'resident+lmlinga@gmail.com',
            'resi_dent@gmail.com', 'resi-dent@gmail.com', null, undefined]) {
            assert.equal(isGmailAddress(bad), false, String(bad));
        }
    });

    it('normalizes by trimming and lowercasing', () => {
        assert.equal(normalizeEmail('  Juan@Gmail.Com '), 'juan@gmail.com');
    });

    it('uses the same pattern and message as the server', () => {
        const controller = readFileSync(path.resolve('app/Http/Controllers/Chatbot/ResidentRegistrationController.php'), 'utf8');
        assert.ok(controller.includes(`GMAIL_PATTERN = '${GMAIL_PATTERN.source.replace(/^/, '/')}/'`), 'pattern mirrors the PHP constant');
        assert.ok(controller.includes(`GMAIL_MESSAGE = '${GMAIL_MESSAGE}'`), 'message mirrors the PHP constant');
    });
});

function fakeForm(initialValue) {
    const handlers = {};
    const attrs = {};
    const classes = new Set();
    const errorEl = { id: 'email-gmail-error', textContent: '', hidden: true };
    const doc = { getElementById: () => null };
    const input = {
        value: initialValue,
        ownerDocument: doc,
        focused: false,
        classList: { add: (c) => classes.add(c), remove: (c) => classes.delete(c), contains: (c) => classes.has(c) },
        setAttribute: (k, v) => { attrs[k] = v; },
        getAttribute: (k) => attrs[k] ?? null,
        removeAttribute: (k) => { delete attrs[k]; },
        addEventListener: (type, fn) => { handlers[`input:${type}`] = fn; },
        focus() { this.focused = true; },
    };
    const form = {
        querySelector: (sel) => (sel === '[data-lml-register-email]' ? input : sel === '[data-lml-register-email-error]' ? errorEl : null),
        addEventListener: (type, fn) => { handlers[`form:${type}`] = fn; },
    };
    const submit = () => {
        const event = { defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } };
        handlers['form:submit'](event);
        return event;
    };
    return { form, input, errorEl, attrs, submit, typeInput: () => handlers['input:input']() };
}

describe('initGmailOnlyRegistration', () => {
    it('blocks submit and shows the message below the email field for a non-Gmail address', () => {
        const f = fakeForm('resident@yahoo.com');
        assert.equal(initGmailOnlyRegistration(f.form), true);
        const event = f.submit();
        assert.equal(event.defaultPrevented, true);
        assert.equal(f.errorEl.hidden, false);
        assert.equal(f.errorEl.textContent, GMAIL_MESSAGE);
        assert.equal(f.attrs['aria-invalid'], 'true');
        assert.match(f.attrs['aria-describedby'], /email-gmail-error/);
        assert.equal(f.input.classList.contains('is-invalid'), true);
        assert.equal(f.input.focused, true);
    });

    it('clears the message as soon as a valid Gmail address is typed', () => {
        const f = fakeForm('resident@example.test');
        initGmailOnlyRegistration(f.form);
        f.submit();
        f.input.value = 'resident.ok@gmail.com';
        f.typeInput();
        assert.equal(f.errorEl.hidden, true);
        assert.equal(f.errorEl.textContent, '');
        assert.equal(f.attrs['aria-invalid'], undefined);
        assert.equal(f.input.classList.contains('is-invalid'), false);
    });

    it('lets a valid Gmail address submit', () => {
        const f = fakeForm('  Resident.OK@Gmail.com ');
        initGmailOnlyRegistration(f.form);
        assert.equal(f.submit().defaultPrevented, false);
        assert.equal(f.errorEl.hidden, true);
    });

    it('does nothing on pages without the email field', () => {
        assert.equal(initGmailOnlyRegistration({ querySelector: () => null }), false);
        assert.equal(initGmailOnlyRegistration(null), false);
    });
});
