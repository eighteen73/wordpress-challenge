(() => {
	'use strict';

	let counter = 0;
	let gravityFormsRegistered = false;
	const selectors = '.form-challenge:not([data-initialised])';

	function tokenField(form) {
		let input = form.querySelector('input[name="form_challenge_token"]');
		if (!input) {
			input = document.createElement('input');
			input.type = 'hidden';
			input.name = 'form_challenge_token';
			form.append(input);
		}
		return input;
	}

	function setToken(widget, token) {
		widget.dataset.token = token;
		const form = widget.closest('form');
		if (form) {
			tokenField(form).value = token;
		}
		widget.dispatchEvent(new CustomEvent('form-challenge-solved', { bubbles: true, detail: { token } }));
	}

	function initialise(widget) {
		const provider = widget.dataset.challenge;
		const form = widget.closest('form');
		const target = widget.querySelector('.form-challenge-widget');
		if (provider === 'cap') {
			const cap = widget.querySelector('cap-widget');
			if (widget.dataset.floating === 'true') {
				const button = form?.querySelector('#place_order, .gform_button, button[type="submit"], input[type="submit"]') ||
					(widget.dataset.context === 'wc_checkout' ? document.querySelector('.wc-block-components-checkout-place-order-button') : null);
				if (!button) return;
				cap.id = `form-challenge-cap-${++counter}`;
				button.dataset.capFloating = `#${cap.id}`;
			}
			cap.addEventListener('solve', (event) => setToken(widget, event.detail.token));
			cap.addEventListener('reset', () => setToken(widget, ''));
		} else if (provider === 'turnstile') {
			if (!window.turnstile?.render) return;
			widget.dataset.widgetId = window.turnstile.render(target, {
				sitekey: widget.dataset.sitekey,
				callback: (token) => setToken(widget, token),
				'expired-callback': () => setToken(widget, ''),
				'error-callback': () => setToken(widget, ''),
			});
		} else if (provider === 'recaptcha_v2') {
			if (!window.grecaptcha?.render) return;
			widget.dataset.widgetId = window.grecaptcha.render(target, {
				sitekey: widget.dataset.sitekey,
				callback: (token) => setToken(widget, token),
				'expired-callback': () => setToken(widget, ''),
			});
		}
		if (form) tokenField(form);
		widget.dataset.initialised = 'true';
	}

	function scan() {
		document.querySelectorAll(selectors).forEach(initialise);
	}

	function registerGravityForms() {
		if (gravityFormsRegistered || !window.gform?.utils?.addAsyncFilter) return;
		window.gform.utils.addAsyncFilter('gform/submission/pre_submission', async (data) => {
			if (data.abort || !['submit', 'next'].includes(data.submissionType)) return data;
			const widget = data.form.querySelector('.form-challenge[data-challenge="recaptcha_v3"]');
			if (!widget) return data;
			// A Next request can become the final submission when following pages are hidden.
			// Generate a fresh token for each forward request; PHP verifies only the final page.
			setToken(widget, '');
			try {
				if (!window.grecaptcha?.ready || !window.grecaptcha?.execute) throw new Error('reCAPTCHA unavailable');
				await new Promise((resolve) => window.grecaptcha.ready(resolve));
				const token = await window.grecaptcha.execute(widget.dataset.sitekey, { action: widget.dataset.context });
				if (!token) throw new Error('reCAPTCHA returned no token');
				setToken(widget, token);
			} catch {
				data.abort = true;
				window.alert('Verification failed. Please try again.');
			}
			return data;
		});
		gravityFormsRegistered = true;
	}

	document.addEventListener('gform/theme/scripts_loaded', registerGravityForms);
	registerGravityForms();

	document.addEventListener('submit', (event) => {
		const form = event.target;
		const widget = form.querySelector('.form-challenge');
		if (!widget) return;
		// The Checkout Block has its own place-order flow and Store API token transport.
		if (widget.dataset.context === 'wc_checkout' && form.closest('.wc-block-checkout')) return;
		// Gravity Forms owns submission and retry handling through its asynchronous filter.
		if (gravityFormsRegistered && widget.dataset.context.startsWith('gf_') && widget.dataset.challenge === 'recaptcha_v3') return;
		const input = tokenField(form);
		if (widget.dataset.challenge === 'recaptcha_v3' && !form.dataset.formChallengeSubmitting) {
			event.preventDefault();
			event.stopImmediatePropagation();
			if (!window.grecaptcha?.execute) return;
			const submitter = event.submitter;
			window.grecaptcha.ready(() => {
				window.grecaptcha.execute(widget.dataset.sitekey, { action: widget.dataset.context })
					.then((token) => {
						setToken(widget, token);
						form.dataset.formChallengeSubmitting = 'true';
						form.requestSubmit(submitter || undefined);
						window.setTimeout(() => {
							delete form.dataset.formChallengeSubmitting;
							input.value = '';
						}, 1000);
					}).catch(() => window.alert('Verification failed. Please try again.'));
			});
			return;
		}
		if (!input.value) {
			event.preventDefault();
			event.stopImmediatePropagation();
			if (widget.dataset.challenge === 'cap' && widget.dataset.floating === 'true') {
				form.querySelector('[data-cap-floating]')?.click();
			} else {
				window.alert('Please complete verification before submitting.');
			}
		}
	}, true);

	// Footer scripts run before DOMContentLoaded; initialise Cap triggers before floating.js scans them.
	scan();
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', scan);
	new MutationObserver(scan).observe(document.documentElement, { childList: true, subtree: true });
	if (window.jQuery) {
		window.jQuery(document.body).on('checkout_error', () => {
			const widget = document.querySelector('.woocommerce-checkout .form-challenge');
			if (!widget) return;
			setToken(widget, '');
			if (widget.dataset.challenge === 'cap') widget.querySelector('cap-widget')?.reset();
			if (widget.dataset.challenge === 'turnstile') window.turnstile?.reset(widget.dataset.widgetId);
			if (widget.dataset.challenge === 'recaptcha_v2') window.grecaptcha?.reset(Number(widget.dataset.widgetId));
		});
	}
	window.formChallenge = { scan, setToken };
})();
