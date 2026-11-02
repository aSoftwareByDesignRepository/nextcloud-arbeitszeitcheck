/**
 * Selection state for the teams “add people” dialog.
 * The result list is replaced on every search. Selected people live here
 * so a later query cannot drop them, and a late response cannot paint
 * over a newer one.
 *
 * @license AGPL-3.0-or-later
 */
(function (root) {
	'use strict';

	function normalizeUid(uid) {
		return String(uid == null ? '' : uid).trim();
	}

	function normalizeLabel(label, id) {
		const name = String(label == null ? '' : label).trim();
		return name !== '' ? name : id;
	}

	function createBulkPeopleSelection() {
		const selected = new Map();
		let busy = false;
		let searchGen = 0;

		function remember(id, label) {
			if (!selected.has(id)) {
				selected.set(id, normalizeLabel(label, id));
			}
		}

		return {
			toggle(uid, label, checked) {
				if (busy) {
					return false;
				}
				const id = normalizeUid(uid);
				if (!id) {
					return false;
				}
				if (checked) {
					remember(id, label);
				} else {
					selected.delete(id);
				}
				return true;
			},

			remove(uid) {
				if (busy) {
					return false;
				}
				const id = normalizeUid(uid);
				if (!id || !selected.has(id)) {
					return false;
				}
				selected.delete(id);
				return true;
			},

			has(uid) {
				return selected.has(normalizeUid(uid));
			},

			label(uid) {
				return selected.get(normalizeUid(uid)) || '';
			},

			entries() {
				return Array.from(selected.entries());
			},

			ids() {
				return Array.from(selected.keys());
			},

			size() {
				return selected.size;
			},

			clear() {
				selected.clear();
			},

			/**
			 * Checkboxes currently on screen. People who are selected but
			 * absent from this list stay selected.
			 *
			 * @param {Array<{uid: string, checked: boolean, label?: string}>} boxes
			 */
			syncVisible(boxes) {
				if (busy) {
					return;
				}
				(boxes || []).forEach(function (box) {
					const id = normalizeUid(box && box.uid);
					if (!id) {
						return;
					}
					if (box.checked) {
						remember(id, box.label);
					} else if (selected.has(id)) {
						selected.delete(id);
					}
				});
			},

			beginSearch() {
				searchGen += 1;
				return searchGen;
			},

			isCurrentSearch(token) {
				return token === searchGen;
			},

			tryBeginSubmit() {
				if (busy || selected.size === 0) {
					return false;
				}
				busy = true;
				return true;
			},

			endSubmit() {
				busy = false;
			},

			isBusy() {
				return busy;
			},
		};
	}

	root.ArbeitszeitCheckTeamBulkSelection = {
		createBulkPeopleSelection: createBulkPeopleSelection,
	};
}(typeof window !== 'undefined' ? window : globalThis));
