(() => {
	'use strict';

	const provider = document.getElementById('form-challenge-driver');
	if (!provider) return;

	function showSelected() {
		document.querySelectorAll('tr.form-challenge-provider').forEach((row) => {
			const hidden = !row.classList.contains(`form-challenge-provider-${provider.value}`);
			row.hidden = hidden;
			row.classList.toggle('hidden', hidden);
		});
	}

	provider.addEventListener('change', showSelected);
	showSelected();
})();
