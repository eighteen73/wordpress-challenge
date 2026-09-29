/* global wp, wc, formChallengeCheckout */
(() => {
	'use strict';

	if (!window.wp?.plugins || !window.wc?.blocksCheckout?.ExperimentalOrderMeta) return;
	const { createElement } = wp.element;
	const { ExperimentalOrderMeta } = wc.blocksCheckout;

	function Widget({ context }) {
		if (context !== 'woocommerce/checkout') return null;
		return createElement('div', { dangerouslySetInnerHTML: { __html: formChallengeCheckout.markup } });
	}

	wp.plugins.registerPlugin('form-challenge-checkout', {
		scope: 'woocommerce-checkout',
		render: () => createElement(ExperimentalOrderMeta, null, createElement(Widget)),
	});

	function extensionToken(token) {
		// WooCommerce exposes extension data on the checkout store for the Store API request.
		wp.data.dispatch('wc/store/checkout').__internalSetExtensionData('form-challenge', { token });
	}

	document.addEventListener('form-challenge-solved', (event) => {
		if (event.target.dataset.context === 'wc_checkout') {
			extensionToken(event.detail.token);
		}
	});

	// Verification tokens are single-use. A failed checkout needs a fresh solve.
	new MutationObserver((changes) => {
		if (!changes.some(({ addedNodes }) => Array.from(addedNodes).some((node) =>
			node.nodeType === 1 && (node.matches?.('.wc-block-components-notice-banner') || node.querySelector?.('.wc-block-components-notice-banner'))))) return;
		const widget = document.querySelector('.wc-block-checkout .form-challenge');
		if (!widget?.dataset.token) return;
		window.formChallenge.setToken(widget, '');
		if (widget.dataset.challenge === 'cap') widget.querySelector('cap-widget')?.reset();
		if (widget.dataset.challenge === 'turnstile') window.turnstile?.reset(widget.dataset.widgetId);
		if (widget.dataset.challenge === 'recaptcha_v2') window.grecaptcha?.reset(Number(widget.dataset.widgetId));
	}).observe(document.documentElement, { childList: true, subtree: true });

	let submitting = false;
	let solving = false;
	document.addEventListener('click', (event) => {
		const button = event.target.closest('.wc-block-components-checkout-place-order-button');
		const widget = document.querySelector('.wc-block-checkout .form-challenge');
		if (!button || !widget || widget.dataset.challenge !== 'recaptcha_v3' || submitting) return;
		event.preventDefault();
		event.stopImmediatePropagation();
		if (!window.grecaptcha?.execute || solving) return;
		solving = true;
		window.grecaptcha.ready(() => {
			window.grecaptcha.execute(widget.dataset.sitekey, { action: 'wc_checkout' }).then((token) => {
				window.formChallenge.setToken(widget, token);
				submitting = true;
				button.click();
				submitting = false;
			}).catch(() => window.alert('Verification failed. Please try again.'))
				.finally(() => { solving = false; });
		});
	}, true);
})();
