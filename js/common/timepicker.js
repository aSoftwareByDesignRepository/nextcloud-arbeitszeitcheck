/**
 * 24-hour time picker for ArbeitszeitCheck.
 *
 * One field, two keyboard sections (hour, minute), and a clock button that
 * opens a two-column list — the same usage as the Material UI time picker.
 * The canonical value lives on the hidden input the form already submits.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */
(function () {
	'use strict';

	var L = function () {
		return window.ArbeitszeitCheckTimePickerLogic || null;
	};

	var openInstance = null;

	function text(el, value) {
		el.textContent = value == null ? '' : String(value);
	}

	function clockIcon() {
		var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
		svg.setAttribute('viewBox', '0 0 24 24');
		svg.setAttribute('aria-hidden', 'true');
		svg.setAttribute('focusable', 'false');
		svg.setAttribute('class', 'azc-timepicker__icon');
		var circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
		circle.setAttribute('cx', '12');
		circle.setAttribute('cy', '12');
		circle.setAttribute('r', '9');
		var hand = document.createElementNS('http://www.w3.org/2000/svg', 'path');
		hand.setAttribute('d', 'M12 7v5l3 2');
		svg.appendChild(circle);
		svg.appendChild(hand);
		return svg;
	}

	function sheetMode() {
		if (typeof window.matchMedia !== 'function') {
			return false;
		}
		return window.matchMedia('(max-width: 639.98px)').matches;
	}

	function TimePicker(root) {
		this.root = root;
		this.logic = L();
		this.hidden = root.querySelector('input[type="hidden"]');
		this.allowEmpty = root.getAttribute('data-allow-empty') === '1';
		this.minuteStep = this.readStep();
		this.hour = null;
		this.minute = null;
		this.hourBuffer = '';
		this.minuteBuffer = '';
		this.isOpen = false;
		this.popover = null;
		this.backdrop = null;
		this.onDocPointer = this.onDocPointer.bind(this);
		this.onDocKey = this.onDocKey.bind(this);
		this.onWindowMove = this.onWindowMove.bind(this);
		this.scrollRaf = 0;
		this.readInitial();
		this.buildField();
		this.renderSections();
		this.writeHidden(false);
		root.dataset.azcTimepickerReady = '1';
		root.__azcTimePicker = this;
	}

	TimePicker.prototype.readStep = function () {
		var fromHost = this.root.getAttribute('data-minute-step');
		var cfg = window.ArbeitszeitCheck && window.ArbeitszeitCheck.timeEntryForm;
		var raw = fromHost != null && fromHost !== '' ? fromHost : (cfg && cfg.minuteStep);
		return this.logic.normalizeStep(raw);
	};

	TimePicker.prototype.label = function (dataKey, bundleKey, fallback) {
		var fromData = this.root.dataset[dataKey];
		if (fromData) {
			return fromData;
		}
		var bundle = window.ArbeitszeitCheck && window.ArbeitszeitCheck.l10n;
		if (bundle && bundle[bundleKey]) {
			return bundle[bundleKey];
		}
		return fallback;
	};

	TimePicker.prototype.readInitial = function () {
		var parsed = this.hidden ? this.logic.parseHm(this.hidden.value) : null;
		if (!parsed && this.hidden) {
			parsed = this.logic.parseFlexible(this.hidden.value);
		}
		if (parsed) {
			this.hour = parsed.hour;
			this.minute = parsed.minute;
			return;
		}
		this.hour = null;
		this.minute = null;
	};

	TimePicker.prototype.buildField = function () {
		var control = document.createElement('div');
		control.className = 'azc-timepicker__control';
		control.setAttribute('role', 'group');
		var labelId = this.root.getAttribute('data-label-id');
		if (labelId) {
			control.setAttribute('aria-labelledby', labelId);
		}
		var described = this.root.getAttribute('data-described-by');
		if (described) {
			control.setAttribute('aria-describedby', described);
		}

		this.hourInput = this.sectionInput('hour', this.label('hourLabel', 'breakStartHour', 'Start hour'));
		this.minuteInput = this.sectionInput('minute', this.label('minuteLabel', 'breakStartMinute', 'Start minute'));
		var colon = document.createElement('span');
		colon.className = 'azc-timepicker__colon';
		colon.setAttribute('aria-hidden', 'true');
		text(colon, ':');

		this.openBtn = document.createElement('button');
		this.openBtn.type = 'button';
		this.openBtn.className = 'azc-timepicker__open';
		var hiddenIdForBtn = this.hidden && this.hidden.id ? this.hidden.id : '';
		if (hiddenIdForBtn) {
			this.openBtn.id = hiddenIdForBtn + '-open';
		}
		this.openBtn.setAttribute('aria-haspopup', 'dialog');
		this.openBtn.setAttribute('aria-expanded', 'false');
		this.openBtn.setAttribute('aria-label', this.label('openLabel', 'chooseTime', 'Choose time'));
		this.openBtn.appendChild(clockIcon());

		var self = this;
		this.openBtn.addEventListener('click', function (event) {
			event.preventDefault();
			if (self.isOpen) {
				self.close(true);
			} else {
				self.open();
			}
		});

		control.appendChild(this.hourInput);
		control.appendChild(colon);
		control.appendChild(this.minuteInput);
		control.appendChild(this.openBtn);
		this.root.insertBefore(control, this.root.firstChild);
		this.control = control;
	};

	TimePicker.prototype.sectionInput = function (section, ariaLabel) {
		var input = document.createElement('input');
		input.type = 'text';
		input.className = 'azc-timepicker__section';
		input.setAttribute('data-section', section);
		input.setAttribute('inputmode', 'numeric');
		input.setAttribute('autocomplete', 'off');
		input.setAttribute('spellcheck', 'false');
		input.setAttribute('maxlength', '5');
		input.setAttribute('role', 'spinbutton');
		input.setAttribute('aria-label', ariaLabel);
		input.setAttribute('aria-valuemin', '0');
		input.setAttribute('aria-valuemax', section === 'hour' ? '23' : '59');
		if (this.root.getAttribute('data-required') === '1') {
			input.setAttribute('aria-required', 'true');
		}
		var described = this.root.getAttribute('data-described-by');
		if (described) {
			input.setAttribute('aria-describedby', described);
		}
		var idAttr = section === 'hour' ? 'data-hour-id' : 'data-minute-id';
		var explicit = this.root.getAttribute(idAttr);
		var hiddenId = this.hidden && this.hidden.id ? this.hidden.id : '';
		input.id = explicit || (hiddenId ? hiddenId + '-' + section : '');
		var self = this;
		input.addEventListener('focus', function () {
			self.onSectionFocus(section, input);
		});
		input.addEventListener('keydown', function (event) {
			self.onSectionKey(section, event);
		});
		input.addEventListener('input', function () {
			self.onSectionInput(section, input);
		});
		input.addEventListener('blur', function () {
			if (section === 'hour') {
				self.hourBuffer = '';
			} else {
				self.minuteBuffer = '';
			}
			self.renderSections();
		});
		input.addEventListener('paste', function (event) {
			var data = event.clipboardData ? event.clipboardData.getData('text') : '';
			var full = self.logic.parseFlexible(String(data || ''));
			if (!full) {
				return;
			}
			event.preventDefault();
			self.assign(full.hour, full.minute, true);
		});
		return input;
	};

	TimePicker.prototype.onSectionFocus = function (section, input) {
		if (section === 'hour') {
			this.hourBuffer = '';
		} else {
			this.minuteBuffer = '';
		}
		var self = this;
		window.setTimeout(function () {
			if (document.activeElement === input && typeof input.select === 'function') {
				var buffer = section === 'hour' ? self.hourBuffer : self.minuteBuffer;
				if (buffer === '') {
					input.select();
				}
			}
		}, 0);
	};

	TimePicker.prototype.onSectionKey = function (section, event) {
		var key = event.key;
		if (key >= '0' && key <= '9') {
			event.preventDefault();
			this.suppressInput = true;
			this.applyDigit(section, key);
			var picker = this;
			window.setTimeout(function () {
				picker.suppressInput = false;
			}, 0);
			return;
		}
		if (key === 'ArrowUp' || key === 'ArrowDown') {
			event.preventDefault();
			this.step(section, key === 'ArrowUp' ? 1 : -1);
			return;
		}
		if (key === 'Home') {
			event.preventDefault();
			this.setSection(section, 0, true);
			return;
		}
		if (key === 'End') {
			event.preventDefault();
			this.setSection(section, this.logic.endValue(section, this.minuteStep), true);
			return;
		}
		if (key === 'ArrowRight' && section === 'hour') {
			event.preventDefault();
			this.minuteInput.focus();
			return;
		}
		if (key === 'ArrowLeft' && section === 'minute') {
			event.preventDefault();
			this.hourInput.focus();
			return;
		}
		if (key === 'Backspace' || key === 'Delete') {
			event.preventDefault();
			this.clearSection(section);
			return;
		}
		if (key === 'Enter') {
			event.preventDefault();
			if (this.isOpen) {
				this.chooseActive();
			} else {
				this.open();
			}
			return;
		}
		if (key === 'Escape' && this.isOpen) {
			event.preventDefault();
			this.close(true);
		}
	};

	TimePicker.prototype.onSectionInput = function (section, input) {
		if (this.suppressInput) {
			this.renderSections();
			return;
		}
		var previous = this.displayFor(section);
		if (input.value === previous) {
			return;
		}
		var rec = this.logic.reconcileRaw(input.value, previous);
		if (rec.type === 'full') {
			this.assign(rec.hour, rec.minute, true);
			return;
		}
		if (rec.type === 'clear') {
			this.clearSection(section);
			return;
		}
		if (rec.type === 'digit') {
			this.applyDigit(section, rec.digit);
			return;
		}
		this.renderSections();
	};

	TimePicker.prototype.displayFor = function (section) {
		var value = section === 'hour' ? this.hour : this.minute;
		if (value === null || value === undefined) {
			return '--';
		}
		return this.logic.pad2(value);
	};

	TimePicker.prototype.bufferFor = function (section) {
		return section === 'hour' ? this.hourBuffer : this.minuteBuffer;
	};

	TimePicker.prototype.setBuffer = function (section, buffer) {
		if (section === 'hour') {
			this.hourBuffer = buffer;
		} else {
			this.minuteBuffer = buffer;
		}
	};

	TimePicker.prototype.applyDigit = function (section, digit) {
		var result = this.logic.applySectionDigit(section, this.bufferFor(section), digit);
		if (!result) {
			this.renderSections();
			return;
		}
		this.setSection(section, result.value, false);
		this.setBuffer(section, result.buffer);
		this.renderSections();
		if (result.advance && section === 'hour') {
			this.minuteInput.focus();
		}
	};

	TimePicker.prototype.step = function (section, delta) {
		var current = section === 'hour' ? this.hour : this.minute;
		var next = this.logic.stepSection(section, current, delta, this.minuteStep);
		this.setBuffer(section, '');
		this.setSection(section, next, true);
	};

	TimePicker.prototype.clearSection = function (section) {
		this.setBuffer(section, '');
		if (!this.allowEmpty) {
			this.setSection(section, 0, true);
			return;
		}
		if (section === 'hour') {
			this.hour = null;
		} else {
			this.minute = null;
		}
		this.renderSections();
		this.writeHidden(true);
		this.clearInvalid();
	};

	TimePicker.prototype.setSection = function (section, value, render) {
		if (section === 'hour') {
			this.hour = value;
			this.hourBuffer = '';
		} else {
			this.minute = value;
			this.minuteBuffer = '';
		}
		if (render !== false) {
			this.renderSections();
		}
		this.writeHidden(true);
		this.clearInvalid();
		if (this.isOpen) {
			this.markSelected();
		}
	};

	TimePicker.prototype.assign = function (hour, minute, emit) {
		this.hour = hour;
		this.minute = minute;
		this.hourBuffer = '';
		this.minuteBuffer = '';
		this.renderSections();
		this.writeHidden(emit !== false);
		this.clearInvalid();
		if (this.isOpen) {
			this.markSelected();
		}
	};

	TimePicker.prototype.canonical = function () {
		if (this.hour === null || this.minute === null) {
			return '';
		}
		return this.logic.formatHm(this.hour, this.minute);
	};

	TimePicker.prototype.writeHidden = function (emit) {
		if (!this.hidden) {
			return;
		}
		var next = this.canonical();
		if (this.hidden.value === next) {
			return;
		}
		this.hidden.value = next;
		if (emit) {
			this.hidden.dispatchEvent(new Event('change', { bubbles: true }));
		}
	};

	TimePicker.prototype.renderSections = function () {
		this.paintSection(this.hourInput, 'hour', this.hour);
		this.paintSection(this.minuteInput, 'minute', this.minute);
	};

	TimePicker.prototype.paintSection = function (input, section, value) {
		var display = value === null || value === undefined ? '--' : this.logic.pad2(value);
		var buffer = this.bufferFor(section);
		if (input.value !== display) {
			input.value = display;
		}
		if (value === null || value === undefined) {
			input.removeAttribute('aria-valuenow');
			input.setAttribute('aria-valuetext', this.label('notSetLabel', 'timeNotSet', 'Not set'));
		} else {
			input.setAttribute('aria-valuenow', String(value));
			input.setAttribute('aria-valuetext', display);
		}
		if (document.activeElement === input && buffer !== '' && typeof input.setSelectionRange === 'function') {
			var end = display.length;
			input.setSelectionRange(end, end);
		}
	};

	TimePicker.prototype.clearInvalid = function () {
		this.root.setAttribute('aria-invalid', 'false');
		this.root.classList.remove('azc-timepicker--error');
		this.hourInput.setAttribute('aria-invalid', 'false');
		this.minuteInput.setAttribute('aria-invalid', 'false');
	};

	TimePicker.prototype.setInvalid = function (on) {
		var flag = on ? 'true' : 'false';
		this.root.setAttribute('aria-invalid', flag);
		this.root.classList.toggle('azc-timepicker--error', !!on);
		this.hourInput.setAttribute('aria-invalid', flag);
		this.minuteInput.setAttribute('aria-invalid', flag);
	};

	TimePicker.prototype.open = function () {
		if (this.isOpen) {
			return;
		}
		if (openInstance && openInstance !== this) {
			openInstance.close(false);
		}
		openInstance = this;
		this.isOpen = true;
		this.openBtn.setAttribute('aria-expanded', 'true');
		var sheet = sheetMode();
		if (sheet) {
			this.backdrop = document.createElement('div');
			this.backdrop.className = 'azc-timepicker__backdrop';
			this.backdrop.addEventListener('click', this.onDocPointer);
			document.body.appendChild(this.backdrop);
		}
		this.popover = document.createElement('div');
		this.popover.className = 'azc-timepicker__popover' + (sheet ? ' azc-timepicker__popover--sheet' : '');
		this.popover.setAttribute('role', 'dialog');
		this.popover.setAttribute('aria-modal', sheet ? 'true' : 'false');
		this.popover.setAttribute('aria-label', this.label('openLabel', 'chooseTime', 'Choose time'));
		this.popover.appendChild(this.column('hour'));
		this.popover.appendChild(this.column('minute'));
		if (this.allowEmpty || sheet) {
			var actions = document.createElement('div');
			actions.className = 'azc-timepicker__actions';
			if (this.allowEmpty) {
				var clearBtn = document.createElement('button');
				clearBtn.type = 'button';
				clearBtn.className = 'azc-timepicker__clear';
				text(clearBtn, this.label('clearLabel', 'clearTime', 'Clear time'));
				var self = this;
				clearBtn.addEventListener('click', function () {
					self.assign(null, null, true);
					self.close(true);
				});
				actions.appendChild(clearBtn);
			}
			if (sheet) {
				var done = document.createElement('button');
				done.type = 'button';
				done.className = 'azc-timepicker__done';
				text(done, this.label('doneLabel', 'timeDone', 'Done'));
				var picker = this;
				done.addEventListener('click', function () {
					picker.close(true);
				});
				actions.appendChild(done);
			}
			this.popover.appendChild(actions);
		}
		document.body.appendChild(this.popover);
		var popId = (this.hidden && this.hidden.id ? this.hidden.id : 'azc-time') + '-popover';
		this.popover.id = popId;
		this.openBtn.setAttribute('aria-controls', popId);
		this.position();
		document.addEventListener('pointerdown', this.onDocPointer, true);
		document.addEventListener('keydown', this.onDocKey, true);
		window.addEventListener('resize', this.onWindowMove);
		window.addEventListener('scroll', this.onWindowMove, true);
		var selected = this.popover.querySelector('[data-column="hour"] [aria-selected="true"]');
		if (selected) {
			selected.focus();
			this.scrollOption(selected);
		}
	};

	TimePicker.prototype.column = function (section) {
		var wrap = document.createElement('div');
		wrap.className = 'azc-timepicker__column';
		var heading = document.createElement('div');
		heading.className = 'azc-timepicker__heading';
		heading.id = (this.hidden && this.hidden.id ? this.hidden.id : 'azc-time') + '-' + section + '-heading';
		text(heading, section === 'hour'
			? this.label('hoursLabel', 'timeHours', 'Hours')
			: this.label('minutesLabel', 'timeMinutes', 'Minutes'));
		var list = document.createElement('div');
		list.className = 'azc-timepicker__list';
		list.setAttribute('role', 'listbox');
		list.setAttribute('data-column', section);
		list.setAttribute('aria-labelledby', heading.id);
		list.setAttribute('tabindex', '-1');
		var values = section === 'hour'
			? this.logic.hourChoices()
			: this.logic.minuteChoices(this.minute, this.minuteStep);
		var current = section === 'hour' ? this.hour : this.minute;
		var self = this;
		values.forEach(function (label) {
			var option = document.createElement('button');
			option.type = 'button';
			option.className = 'azc-timepicker__option';
			option.setAttribute('role', 'option');
			option.setAttribute('data-value', label);
			text(option, label);
			var selected = current !== null && self.logic.pad2(current) === label;
			option.setAttribute('aria-selected', selected ? 'true' : 'false');
			option.tabIndex = selected ? 0 : -1;
			option.addEventListener('pointerdown', function (event) {
				event.preventDefault();
			});
			option.addEventListener('click', function () {
				self.pick(section, parseInt(label, 10));
			});
			option.addEventListener('keydown', function (event) {
				self.onOptionKey(section, list, option, event);
			});
			list.appendChild(option);
		});
		if (!list.querySelector('[aria-selected="true"]') && list.firstChild) {
			list.firstChild.tabIndex = 0;
		}
		wrap.appendChild(heading);
		wrap.appendChild(list);
		return wrap;
	};

	TimePicker.prototype.pick = function (section, value) {
		this.setBuffer(section, '');
		if (section === 'hour') {
			this.hour = value;
			if (this.minute === null && !this.allowEmpty) {
				this.minute = 0;
			}
		} else {
			this.minute = value;
			if (this.hour === null && !this.allowEmpty) {
				this.hour = 0;
			}
		}
		this.renderSections();
		this.writeHidden(true);
		this.clearInvalid();
		if (section === 'hour') {
			this.markSelected();
			var minuteSelected = this.popover && this.popover.querySelector('[data-column="minute"] [aria-selected="true"]');
			var minuteFirst = this.popover && this.popover.querySelector('[data-column="minute"] .azc-timepicker__option');
			var target = minuteSelected || minuteFirst;
			if (target) {
				target.focus();
				this.scrollOption(target);
			}
			return;
		}
		this.close(true);
	};

	TimePicker.prototype.markSelected = function () {
		if (!this.popover) {
			return;
		}
		var self = this;
		['hour', 'minute'].forEach(function (section) {
			var current = section === 'hour' ? self.hour : self.minute;
			var label = current === null || current === undefined ? '' : self.logic.pad2(current);
			var options = self.popover.querySelectorAll('[data-column="' + section + '"] .azc-timepicker__option');
			options.forEach(function (option) {
				var on = option.getAttribute('data-value') === label;
				option.setAttribute('aria-selected', on ? 'true' : 'false');
				if (on) {
					option.tabIndex = 0;
				} else if (document.activeElement !== option) {
					option.tabIndex = -1;
				}
			});
		});
	};

	TimePicker.prototype.onOptionKey = function (section, list, option, event) {
		var key = event.key;
		var options = Array.prototype.slice.call(list.querySelectorAll('.azc-timepicker__option'));
		var index = options.indexOf(option);
		if (key === 'ArrowDown' || key === 'ArrowUp' || key === 'Home' || key === 'End') {
			event.preventDefault();
			event.stopPropagation();
			var next = index;
			if (key === 'ArrowDown') {
				next = Math.min(options.length - 1, index + 1);
			} else if (key === 'ArrowUp') {
				next = Math.max(0, index - 1);
			} else if (key === 'Home') {
				next = 0;
			} else {
				next = options.length - 1;
			}
			options.forEach(function (item, i) {
				item.tabIndex = i === next ? 0 : -1;
			});
			options[next].focus();
			this.scrollOption(options[next]);
			return;
		}
		if (key === 'Enter' || key === ' ') {
			event.preventDefault();
			event.stopPropagation();
			this.pick(section, parseInt(option.getAttribute('data-value'), 10));
			return;
		}
		if (key === 'Escape') {
			event.preventDefault();
			event.stopPropagation();
			this.close(true);
		}
	};

	TimePicker.prototype.chooseActive = function () {
		if (!this.popover) {
			return;
		}
		var active = this.popover.querySelector('.azc-timepicker__option:focus');
		if (!active) {
			return;
		}
		var list = active.closest('[data-column]');
		this.pick(list.getAttribute('data-column'), parseInt(active.getAttribute('data-value'), 10));
	};

	TimePicker.prototype.scrollOption = function (option) {
		if (option && typeof option.scrollIntoView === 'function') {
			option.scrollIntoView({ block: 'nearest' });
		}
	};

	TimePicker.prototype.position = function () {
		if (!this.popover || this.popover.classList.contains('azc-timepicker__popover--sheet')) {
			return;
		}
		var rect = this.control.getBoundingClientRect();
		var width = this.popover.offsetWidth || 220;
		var height = this.popover.offsetHeight || 280;
		var margin = 8;
		var left = rect.left;
		if (left + width > window.innerWidth - margin) {
			left = Math.max(margin, window.innerWidth - width - margin);
		}
		var top = rect.bottom + 4;
		if (top + height > window.innerHeight - margin && rect.top > height + margin) {
			top = rect.top - height - 4;
		}
		this.popover.style.left = left + 'px';
		this.popover.style.top = top + 'px';
	};

	TimePicker.prototype.onWindowMove = function () {
		var self = this;
		if (this.scrollRaf) {
			return;
		}
		this.scrollRaf = window.requestAnimationFrame(function () {
			self.scrollRaf = 0;
			if (self.isOpen) {
				self.position();
			}
		});
	};

	TimePicker.prototype.onDocPointer = function (event) {
		if (!this.isOpen) {
			return;
		}
		var target = event.target;
		if (this.popover && this.popover.contains(target)) {
			return;
		}
		if (this.control && this.control.contains(target)) {
			return;
		}
		this.close(false);
	};

	TimePicker.prototype.onDocKey = function (event) {
		if (!this.isOpen) {
			return;
		}
		if (event.key === 'Escape') {
			event.preventDefault();
			this.close(true);
			return;
		}
		if (event.key !== 'Tab' || !this.popover || !this.popover.classList.contains('azc-timepicker__popover--sheet')) {
			return;
		}
		var focusable = Array.prototype.filter.call(this.popover.querySelectorAll('button'), function (el) {
			return el.tabIndex >= 0 && !el.disabled;
		});
		if (focusable.length === 0) {
			return;
		}
		var first = focusable[0];
		var last = focusable[focusable.length - 1];
		if (event.shiftKey && document.activeElement === first) {
			event.preventDefault();
			last.focus();
		} else if (!event.shiftKey && document.activeElement === last) {
			event.preventDefault();
			first.focus();
		}
	};

	TimePicker.prototype.close = function (restoreFocus) {
		if (!this.isOpen) {
			return;
		}
		this.isOpen = false;
		if (openInstance === this) {
			openInstance = null;
		}
		this.openBtn.setAttribute('aria-expanded', 'false');
		this.openBtn.removeAttribute('aria-controls');
		document.removeEventListener('pointerdown', this.onDocPointer, true);
		document.removeEventListener('keydown', this.onDocKey, true);
		window.removeEventListener('resize', this.onWindowMove);
		window.removeEventListener('scroll', this.onWindowMove, true);
		if (this.scrollRaf) {
			window.cancelAnimationFrame(this.scrollRaf);
			this.scrollRaf = 0;
		}
		if (this.popover && this.popover.parentNode) {
			this.popover.parentNode.removeChild(this.popover);
		}
		if (this.backdrop && this.backdrop.parentNode) {
			this.backdrop.parentNode.removeChild(this.backdrop);
		}
		this.popover = null;
		this.backdrop = null;
		if (restoreFocus) {
			this.openBtn.focus();
		}
	};

	TimePicker.prototype.setValue = function (hhmm) {
		if (hhmm === '' || hhmm === null) {
			if (!this.allowEmpty) {
				return false;
			}
			this.assign(null, null, true);
			return true;
		}
		var parsed = this.logic.parseHm(String(hhmm)) || this.logic.parseFlexible(String(hhmm));
		if (!parsed) {
			return false;
		}
		this.assign(parsed.hour, parsed.minute, true);
		return true;
	};

	function enhance(root) {
		if (!root || root.dataset.azcTimepickerReady === '1') {
			return root ? root.__azcTimePicker : null;
		}
		if (!L()) {
			throw new Error('ArbeitszeitCheck time picker logic is not loaded');
		}
		if (!root.querySelector('input[type="hidden"]')) {
			throw new Error('Time picker host is missing its hidden input');
		}
		return new TimePicker(root);
	}

	function initInRoot(root) {
		var scope = root || document;
		var nodes = scope.querySelectorAll('[data-azc-timepicker]');
		nodes.forEach(function (node) {
			enhance(node);
		});
	}

	function create(spec) {
		var host = document.createElement('div');
		host.className = 'azc-timepicker';
		host.setAttribute('data-azc-timepicker', '');
		host.setAttribute('data-allow-empty', spec.allowEmpty ? '1' : '0');
		if (spec.required) {
			host.setAttribute('data-required', '1');
		}
		if (spec.labelId) {
			host.setAttribute('data-label-id', spec.labelId);
		}
		if (spec.describedBy) {
			host.setAttribute('data-described-by', spec.describedBy);
		}
		if (spec.hourLabel) {
			host.dataset.hourLabel = spec.hourLabel;
		}
		if (spec.minuteLabel) {
			host.dataset.minuteLabel = spec.minuteLabel;
		}
		if (spec.openLabel) {
			host.dataset.openLabel = spec.openLabel;
		}
		if (spec.hoursLabel) {
			host.dataset.hoursLabel = spec.hoursLabel;
		}
		if (spec.minutesLabel) {
			host.dataset.minutesLabel = spec.minutesLabel;
		}
		if (spec.doneLabel) {
			host.dataset.doneLabel = spec.doneLabel;
		}
		if (spec.notSetLabel) {
			host.dataset.notSetLabel = spec.notSetLabel;
		}
		if (spec.clearLabel) {
			host.dataset.clearLabel = spec.clearLabel;
		}
		if (spec.minuteStep != null) {
			host.setAttribute('data-minute-step', String(spec.minuteStep));
		}
		var hidden = document.createElement('input');
		hidden.type = 'hidden';
		if (spec.id) {
			hidden.id = spec.id;
		}
		if (spec.name) {
			hidden.name = spec.name;
		}
		if (spec.hiddenClass) {
			hidden.className = spec.hiddenClass;
		}
		if (spec.breakIndex != null) {
			hidden.setAttribute('data-break-index', String(spec.breakIndex));
		}
		hidden.value = spec.value || '';
		host.appendChild(hidden);
		var parent = spec.parent;
		if (parent) {
			parent.appendChild(host);
		}
		enhance(host);
		return host;
	}

	function instanceFrom(host) {
		if (!host) {
			return null;
		}
		var root = host.hasAttribute && host.hasAttribute('data-azc-timepicker')
			? host
			: (host.closest ? host.closest('[data-azc-timepicker]') : null);
		return root ? root.__azcTimePicker : null;
	}

	function setValue(host, hhmm) {
		var picker = instanceFrom(host);
		if (!picker) {
			return false;
		}
		return picker.setValue(hhmm);
	}

	function setInvalid(host, on) {
		var picker = instanceFrom(host);
		if (!picker) {
			return;
		}
		picker.setInvalid(!!on);
	}

	function commitAll(scope) {
		var root = scope || document;
		root.querySelectorAll('[data-azc-timepicker]').forEach(function (node) {
			var picker = node.__azcTimePicker;
			if (!picker) {
				return;
			}
			picker.hourBuffer = '';
			picker.minuteBuffer = '';
			picker.renderSections();
			picker.writeHidden(false);
		});
	}

	function boot() {
		initInRoot(document);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}

	window.ArbeitszeitCheckTimePicker = {
		enhance: enhance,
		initInRoot: initInRoot,
		create: create,
		setValue: setValue,
		setInvalid: setInvalid,
		commitAll: commitAll,
	};
})();
