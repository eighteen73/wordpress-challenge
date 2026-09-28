// Exercise both checkout error handlers without a browser or a checkout submission.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

for (const script of ['challenge.js', 'checkout.js']) {
	let resetCount = 0;
	let errorHandler;
	let observer;
	const widget = {
		dataset: { challenge: 'cap', token: 'consumed' },
		querySelector: () => ({ reset: () => { resetCount++; } }),
		closest: () => null,
		dispatchEvent: () => {},
	};
	const document = {
		body: {}, documentElement: {}, readyState: 'complete',
		querySelectorAll: () => [], querySelector: () => widget,
		addEventListener: () => {},
	};
	const window = {
		jQuery: () => ({ on: (event, callback) => { errorHandler = callback; } }),
		e73Challenge: { setToken: (element, token) => { element.dataset.token = token; } },
	};
	const wp = { plugins: { registerPlugin: () => {} }, element: { createElement: () => {} } };
	const wc = { blocksCheckout: { ExperimentalOrderMeta: () => {} } };
	Object.assign(window, { wp, wc });
	vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../assets', script), 'utf8'), {
		window, document, wp, wc,
		CustomEvent: class {},
		MutationObserver: class {
			constructor(callback) { observer = callback; }
			observe() {}
		},
	});
	if (script === 'challenge.js') {
		errorHandler();
	} else {
		observer([{ addedNodes: [{ nodeType: 1, matches: () => true }] }]);
	}
	assert.equal(widget.dataset.token, '', `${script}: clear consumed token`);
	assert.equal(resetCount, 1, `${script}: reset Cap so the customer can solve again`);
}
console.log('Classic and block checkout Cap reset checks passed.');
