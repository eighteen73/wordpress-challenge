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
	let alerts = 0;
	let fail = false;
	const input = { value: '' };
	const form = {
		querySelector: (selector) => selector.startsWith('.form-challenge') ? widget : input,
		closest: () => null,
	};
	const widget = {
		dataset: { challenge: 'recaptcha_v3', context: 'gf_12', sitekey: 'site' },
		closest: () => form,
		dispatchEvent: () => {},
	};
	const gform = { utils: { addAsyncFilter: (name, callback) => {
		assert.equal(name, 'gform/submission/pre_submission');
		filter = callback;
		registrations++;
	} } };
	const window = {
		alert: () => { alerts++; },
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
	for (const submissionType of ['previous', 'save-continue', 'send-link']) {
		await filter({ form, submissionType, abort: false });
	}
	await filter({ form, submissionType: 'submit', abort: true });
	assert.equal(solves, beforeNavigation, 'back navigation, save and aborted requests do not execute v3');
	await filter({ form, submissionType: 'next', abort: false });
	assert.equal(solves, beforeNavigation + 1, 'Next carries a token if conditional pages make it the final submission');

	listeners.submit({ target: form, preventDefault: () => assert.fail('native handler must leave GF in control') });
	fail = true;
	const failed = await filter({ form, submissionType: 'submit', abort: false });
	assert.equal(failed.abort, true, 'provider failures abort the GF pipeline');
	assert.equal(input.value, '', 'a failed solve cannot reuse the previous token');
	assert.equal(alerts, 1);
	fail = false;
	assert.equal((await filter({ form, submissionType: 'submit', abort: false })).abort, false, 'a failed solve can be retried');
	delete window.grecaptcha;
	assert.equal((await filter({ form, submissionType: 'submit', abort: false })).abort, true, 'a missing provider aborts cleanly');
}

(async () => {
	await verify(false);
	await verify(true);
	console.log('Gravity Forms v3 submission and retry checks passed.');
})().catch((error) => {
	console.error(error);
	process.exitCode = 1;
});
