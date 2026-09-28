(() => {
	'use strict';

	const provider = document.getElementById('e73-challenge-driver');
	if (!provider) return;

	function showSelected() {
		document.querySelectorAll('tr.e73-challenge-provider').forEach((row) => {
			const hidden = !row.classList.contains(`e73-challenge-provider-${provider.value}`);
			row.hidden = hidden;
			row.classList.toggle('hidden', hidden);
		});
	}

	provider.addEventListener('change', showSelected);
	showSelected();
})();
