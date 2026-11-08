/**
 * Death request reject-form double-submit guard.
 */

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { pathToFileURL } from 'node:url';
import path from 'path';

const moduleUrl = pathToFileURL(
    path.resolve('resources/js/pages/death-requests.js')
).href;

const { guardRejectDoubleSubmit } = await import(moduleUrl);

function makeForm({ valid = true, submitDisabled = false } = {}) {
    const submit = { disabled: submitDisabled };
    const form = {
        dataset: {},
        checkValidity: () => valid,
        querySelector: () => submit,
        addEventListener(type, handler) {
            form.handler = handler;
        },
    };

    return { form, submit };
}

describe('guardRejectDoubleSubmit', () => {
    it('disables the reject button after a valid submit starts', () => {
        const { form, submit } = makeForm({ valid: true });
        guardRejectDoubleSubmit(form);

        let prevented = false;
        form.handler({
            preventDefault() {
                prevented = true;
            },
        });

        assert.equal(prevented, false);
        assert.equal(submit.disabled, true);
    });

    it('does not disable the button when HTML validation fails', () => {
        const { form, submit } = makeForm({ valid: false });
        guardRejectDoubleSubmit(form);

        form.handler({
            preventDefault() {},
        });

        assert.equal(submit.disabled, false);
    });

    it('blocks a second submit after the button is disabled', () => {
        const { form, submit } = makeForm({ valid: true });
        guardRejectDoubleSubmit(form);
        form.handler({ preventDefault() {} });

        let prevented = false;
        form.handler({
            preventDefault() {
                prevented = true;
            },
        });

        assert.equal(submit.disabled, true);
        assert.equal(prevented, true);
    });
});
