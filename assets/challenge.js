(() => {
	'use strict';

	let counter = 0;
	let gravityFormsRegistered = false;
	const selectors = '.form-challenge:not([data-initialised])';
	const failureMessage = 'Verification failed. Please try again.';

	function showError(widget, message = failureMessage, focus = true) {
		let error = widget.querySelector('.form-challenge-error');
		if (!error) {
			error = document.createElement('p');
			error.className = 'form-challenge-error';
			error.setAttribute('role', 'alert');
			error.tabIndex = -1;
			widget.append(error);
		}
		error.hidden = false;
		error.textContent = message;
		if (focus) error.focus();
	}

	function formWidget(form) {
		const widgets = Array.from(form.querySelectorAll('.form-challenge'));
		return widgets.find((widget) => {
			const page = widget.closest('.gform_page');
			return !page || page.getClientRects().length > 0;
		}) || widgets[0];
	}

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
		const previous = widget.dataset.token || '';
		widget.dataset.token = token;
		if (token) {
			const error = widget.querySelector('.form-challenge-error');
			if (error) error.hidden = true;
		}
		const form = widget.closest('form');
		if (form) {
			const input = tokenField(form);
			// An expiring widget on another GF page must not clear the active widget's token.
			if (token || input.value === previous) input.value = token;
		}
		widget.dispatchEvent(new CustomEvent('form-challenge-solved', { bubbles: true, detail: { token } }));
	}

	function initialise(widget) {
		const provider = widget.dataset.challenge;
		const form = widget.closest('form');
		const target = widget.querySelector('.form-challenge-widget');
		if (provider === 'cap') {
			let cap = widget.querySelector('cap-widget');
			// GF's AJAX confirmation sanitiser removes custom elements, but keeps data attributes.
			if (!cap && widget.dataset.capEndpoint) {
				cap = document.createElement('cap-widget');
				cap.setAttribute('data-cap-api-endpoint', widget.dataset.capEndpoint);
				widget.prepend(cap);
			}
			if (!cap) return;
			if (widget.dataset.floating === 'true') {
				const scope = widget.closest('.gform_page') || form;
				const buttons = Array.from(scope?.querySelectorAll('#place_order, .gform_button, .gform_save_link, [data-submission-type="submit"], button[type="submit"], input[type="submit"]') || [])
					.filter((button) => !['previous', 'next'].includes(button.dataset.submissionType));
				if (!buttons.length && widget.dataset.context === 'wc_checkout') {
					const button = document.querySelector('.wc-block-components-checkout-place-order-button');
					if (button) buttons.push(button);
				}
				if (!buttons.length) return;
				cap.id = `form-challenge-cap-${++counter}`;
				cap.style.display = 'none';
				buttons.forEach((button) => { button.dataset.capFloating = `#${cap.id}`; });
			}
			cap.addEventListener('solve', (event) => setToken(widget, event.detail.token));
			cap.addEventListener('reset', () => setToken(widget, ''));
			cap.addEventListener('error', () => {
				setToken(widget, '');
				showError(widget, failureMessage, false);
			});
		} else if (provider === 'turnstile') {
			if (!window.turnstile?.render) return;
			widget.dataset.widgetId = window.turnstile.render(target, {
				sitekey: widget.dataset.sitekey,
				callback: (token) => setToken(widget, token),
				'expired-callback': () => setToken(widget, ''),
				'error-callback': () => { setToken(widget, ''); showError(widget, failureMessage, false); },
			});
		} else if (provider === 'recaptcha_v2') {
			if (!window.grecaptcha?.render) return;
			widget.dataset.widgetId = window.grecaptcha.render(target, {
				sitekey: widget.dataset.sitekey,
				callback: (token) => setToken(widget, token),
				'expired-callback': () => setToken(widget, ''),
				'error-callback': () => { setToken(widget, ''); showError(widget, failureMessage, false); },
			});
		}
		if (form) tokenField(form);
		widget.dataset.initialised = 'true';
	}

	function scan() {
		// Conditional logic can turn an earlier page's Next button into the final submit.
		// Keep its challenge on that visible page even when Save and Continue is disabled.
		document.querySelectorAll('.gform_page [data-submission-type="submit"]').forEach((button) => {
			const page = button.closest('.gform_page');
			if (!page.getClientRects().length || page.querySelector('.form-challenge')) return;
			const source = button.closest('form')?.querySelector('.form-challenge');
			if (!source) return;
			const widget = source.cloneNode(false);
			delete widget.dataset.initialised;
			delete widget.dataset.widgetId;
			delete widget.dataset.token;
			if (['turnstile', 'recaptcha_v2'].includes(widget.dataset.challenge)) {
				const target = document.createElement('div');
				target.className = 'form-challenge-widget';
				widget.append(target);
			}
			button.before(widget);
		});
		document.querySelectorAll(selectors).forEach(initialise);
	}

	async function solveFloating(widget) {
		const cap = widget.querySelector('cap-widget');
		try {
			cap.style.display = 'block';
			await cap.solve();
		} catch { /* Missing assets and browser-side failures remain blocking. */ }
		if (!widget.dataset.token) {
			showError(widget);
			return false;
		}
		cap.style.display = 'none';
		return true;
	}

	function registerGravityForms() {
		if (gravityFormsRegistered || !window.gform?.utils?.addAsyncFilter) return;
		window.gform.utils.addAsyncFilter('gform/submission/pre_submission', async (data) => {
			if (data.abort || !['submit', 'next', 'save-continue', 'send-link'].includes(data.submissionType)) return data;
			scan();
			const widget = formWidget(data.form);
			if (!widget) return data;
			if (widget.dataset.challenge !== 'recaptcha_v3') {
				if (data.submissionType === 'next') return data;
				if (!widget.dataset.token && widget.dataset.challenge === 'cap' && widget.dataset.floating === 'true') {
					// Covers keyboard/programmatic GF submissions that did not click a floating trigger.
					await solveFloating(widget);
				}
				if (!widget.dataset.token) {
					data.abort = true;
					showError(widget, 'Please complete verification and try again.');
				} else {
					tokenField(data.form).value = widget.dataset.token;
				}
				return data;
			}
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
				showError(widget);
			}
			return data;
		});
		gravityFormsRegistered = true;
	}

	document.addEventListener('gform/theme/scripts_loaded', registerGravityForms);
	registerGravityForms();

	document.addEventListener('submit', (event) => {
		const form = event.target;
		const widget = formWidget(form);
		if (!widget) return;
		// The Checkout Block has its own place-order flow and Store API token transport.
		if (widget.dataset.context === 'wc_checkout' && form.closest('.wc-block-checkout')) return;
		// Gravity Forms owns submission and retry handling through its asynchronous filter.
		if (gravityFormsRegistered && widget.dataset.context.startsWith('gf_')) return;
		const input = tokenField(form);
		if (widget.dataset.challenge === 'recaptcha_v3' && !form.dataset.formChallengeSubmitting) {
			event.preventDefault();
			event.stopImmediatePropagation();
			if (!window.grecaptcha?.execute || !window.grecaptcha?.ready) {
				showError(widget);
				return;
			}
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
					}).catch(() => showError(widget));
			});
			return;
		}
		if (!input.value) {
			event.preventDefault();
			event.stopImmediatePropagation();
			if (widget.dataset.challenge === 'cap' && widget.dataset.floating === 'true') {
				if (form.dataset.formChallengeSolving) return;
				form.dataset.formChallengeSolving = 'true';
				solveFloating(widget).then((solved) => {
					if (solved) form.requestSubmit(event.submitter || undefined);
				}).finally(() => { delete form.dataset.formChallengeSolving; });
			} else {
				showError(widget, 'Please complete verification before submitting.');
			}
		}
	}, true);

	// Footer scripts run before DOMContentLoaded; initialise Cap triggers before floating.js scans them.
	scan();
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', scan);
	new MutationObserver(scan).observe(document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['data-submission-type'] });
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
	window.formChallenge = { scan, setToken, showError };
})();
