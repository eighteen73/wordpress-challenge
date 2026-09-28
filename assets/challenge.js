(() => {
	'use strict';

	let counter = 0;
	const selectors = '.e73-challenge:not([data-initialised])';

	function tokenField(form) {
		let input = form.querySelector('input[name="e73_challenge_token"]');
		if (!input) {
			input = document.createElement('input');
			input.type = 'hidden';
			input.name = 'e73_challenge_token';
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
		widget.dispatchEvent(new CustomEvent('e73-challenge-solved', { bubbles: true, detail: { token } }));
	}

	function initialise(widget) {
		const provider = widget.dataset.challenge;
		const form = widget.closest('form');
		const target = widget.querySelector('.e73-challenge-widget');
		if (provider === 'cap') {
			const cap = widget.querySelector('cap-widget');
			if (widget.dataset.floating === 'true') {
				const button = form?.querySelector('#place_order, .gform_button, button[type="submit"], input[type="submit"]') ||
					(widget.dataset.context === 'wc_checkout' ? document.querySelector('.wc-block-components-checkout-place-order-button') : null);
				if (!button) return;
				cap.id = `e73-cap-${++counter}`;
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

	document.addEventListener('submit', (event) => {
		const form = event.target;
		const widget = form.querySelector('.e73-challenge');
		if (!widget) return;
		// The Checkout Block has its own place-order flow and Store API token transport.
		if (widget.dataset.context === 'wc_checkout' && form.closest('.wc-block-checkout')) return;
		const input = tokenField(form);
		if (widget.dataset.challenge === 'recaptcha_v3' && !form.dataset.e73Submitting) {
			event.preventDefault();
			event.stopImmediatePropagation();
			if (!window.grecaptcha?.execute) return;
			const submitter = event.submitter;
			window.grecaptcha.ready(() => {
				window.grecaptcha.execute(widget.dataset.sitekey, { action: widget.dataset.context })
					.then((token) => {
						setToken(widget, token);
						form.dataset.e73Submitting = 'true';
						form.requestSubmit(submitter || undefined);
						window.setTimeout(() => {
							delete form.dataset.e73Submitting;
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
			const widget = document.querySelector('.woocommerce-checkout .e73-challenge');
			if (!widget) return;
			setToken(widget, '');
			if (widget.dataset.challenge === 'cap') widget.querySelector('cap-widget')?.reset();
			if (widget.dataset.challenge === 'turnstile') window.turnstile?.reset(widget.dataset.widgetId);
			if (widget.dataset.challenge === 'recaptcha_v2') window.grecaptcha?.reset(Number(widget.dataset.widgetId));
		});
	}
	window.e73Challenge = { scan, setToken };
})();
