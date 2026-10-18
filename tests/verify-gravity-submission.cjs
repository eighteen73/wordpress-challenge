// Exercise the v3 integration through Gravity Forms' async pipeline, without native submit events.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

async function verify(lateLoad) {
	const listeners = {};
	let filter;
	let registrations = 0;
	let solves = 0;
	let error;
	let focused = 0;
	let fail = false;
	const cap = { style: {}, solve: async () => {
		if (!fail) window.formChallenge.setToken(widget, 'cap-token');
	} };
	const input = { value: '' };
	const form = {
		querySelector: (selector) => selector.startsWith('.form-challenge') ? widget : input,
		querySelectorAll: () => [widget],
		closest: () => null,
	};
	const widget = {
		dataset: { challenge: 'recaptcha_v3', context: 'gf_12', sitekey: 'site' },
		closest: (selector) => selector === 'form' ? form : null,
		querySelector: (selector) => selector === 'cap-widget' ? cap : error,
		append: (element) => { error = element; },
		dispatchEvent: () => {},
	};
	const gform = { utils: { addAsyncFilter: (name, callback) => {
		assert.equal(name, 'gform/submission/pre_submission');
		filter = callback;
		registrations++;
	} } };
	const window = {
		alert: () => assert.fail('verification must use inline errors'),
		grecaptcha: {
			ready: (callback) => callback(),
			execute: async (sitekey, { action }) => {
				assert.equal(sitekey, 'site');
				assert.equal(action, 'gf_12');
				solves++;
				if (fail) throw new Error('Provider unavailable');
				return `token-${solves}`;
			},
		},
	};
	if (!lateLoad) window.gform = gform;
	vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../assets/challenge.js'), 'utf8'), {
		window,
		document: {
			documentElement: {}, readyState: 'complete',
			querySelectorAll: () => [],
			createElement: () => ({ setAttribute: () => {}, focus: () => { focused++; } }),
			addEventListener: (name, callback) => { listeners[name] = callback; },
		},
		CustomEvent: class {},
		MutationObserver: class { observe() {} },
	});
	window.gform = gform;
	listeners['gform/theme/scripts_loaded']();
	listeners['gform/theme/scripts_loaded']();
	assert.equal(registrations, 1, 'register once regardless of script loading order');

	// All three GF transports must receive a token before their own submission code runs.
	for (const submissionMethod of ['postback', 'iframe', 'ajax']) {
		const data = { form, submissionType: 'submit', submissionMethod, abort: false };
		assert.equal(await filter(data), data);
		assert.equal(data.abort, false);
		assert.equal(input.value, `token-${solves}`);
		const token = input.value;
		await filter({ ...data });
		assert.notEqual(input.value, token, 'validation retries obtain a fresh token');
	}
	const beforeNavigation = solves;
	await filter({ form, submissionType: 'previous', abort: false });
	await filter({ form, submissionType: 'submit', abort: true });
	assert.equal(solves, beforeNavigation, 'back navigation and aborted requests do not execute v3');
	await filter({ form, submissionType: 'next', abort: false });
	assert.equal(solves, beforeNavigation + 1, 'Next carries a token if conditional pages make it the final submission');
	for (const submissionType of ['save-continue', 'send-link']) {
		const previous = input.value;
		assert.equal((await filter({ form, submissionType, abort: false })).abort, false);
		assert.notEqual(input.value, previous, 'saving and emailing a resume link each require a fresh v3 token');
	}

	listeners.submit({ target: form, preventDefault: () => assert.fail('native handler must leave GF in control') });
	fail = true;
	const failed = await filter({ form, submissionType: 'submit', abort: false });
	assert.equal(failed.abort, true, 'provider failures abort the GF pipeline');
	assert.equal(input.value, '', 'a failed solve cannot reuse the previous token');
	assert.equal(error.textContent, 'Verification failed. Please try again.');
	assert.equal(error.hidden, false);
	assert.equal(focused, 1);
	fail = false;
	assert.equal((await filter({ form, submissionType: 'submit', abort: false })).abort, false, 'a failed solve can be retried');
	assert.equal(error.hidden, true, 'successful retry clears the inline error');
	delete window.grecaptcha;
	assert.equal((await filter({ form, submissionType: 'submit', abort: false })).abort, true, 'a missing provider aborts cleanly');

	widget.dataset.challenge = 'cap';
	widget.dataset.floating = 'false';
	for (const submissionType of ['submit', 'save-continue', 'send-link']) {
		window.formChallenge.setToken(widget, '');
		assert.equal((await filter({ form, submissionType, abort: false })).abort, true, 'visible Cap must be solved before each protected action');
		window.formChallenge.setToken(widget, 'visible-token');
		assert.equal((await filter({ form, submissionType, abort: false })).abort, false);
		assert.equal(input.value, 'visible-token');
		widget.dataset.floating = 'true';
		window.formChallenge.setToken(widget, '');
		assert.equal((await filter({ form, submissionType, abort: false })).abort, false, 'floating Cap supports keyboard submission, draft saving and resume emails');
		assert.equal(input.value, 'cap-token');
		widget.dataset.floating = 'false';
	}
	widget.dataset.floating = 'true';
	window.formChallenge.setToken(widget, '');
	fail = true;
	assert.equal((await filter({ form, submissionType: 'save-continue', abort: false })).abort, true, 'browser-side Cap failures still block saving');
	fail = false;
	assert.equal((await filter({ form, submissionType: 'save-continue', abort: false })).abort, false, 'Cap browser failures can be retried');
	const otherWidget = { ...widget, dataset: { token: 'other-page-token' } };
	window.formChallenge.setToken(otherWidget, '');
	assert.equal(input.value, 'cap-token', 'expiry on an inactive page does not clear the active token');
}

(async () => {
	await verify(false);
	await verify(true);
	console.log('Gravity Forms Cap/v3 submission, save/resume and inline retry checks passed.');
})().catch((error) => {
	console.error(error);
	process.exitCode = 1;
});
