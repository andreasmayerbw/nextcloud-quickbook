/**
 * Quick Booking: "One-time appointment schedules" in the Calendar app.
 *
 * Loaded on the Calendar's own page, without changing the Calendar's code:
 *  - adds a "One-time appointment schedules" section below "Appointment schedules" in the
 *    navigation, built from copies of the Calendar's own caption and list entries so it looks
 *    and behaves the same (actions: Preview, Copy link, Duplicate, Edit, Delete),
 *  - hides one-time schedules from the Calendar's own list,
 *  - opens an editor modelled on the Calendar's "Create appointment schedule" form, with dates
 *    instead of weekly availability.
 * When the Calendar's page looks different than expected, nothing is added and the Calendar
 * works as before.
 */
(function () {
	'use strict'

	const APP = 'quickbook'
	const t = (text, vars) => window.t(APP, text, vars, undefined, { escape: false })
	const n = (singular, plural, count, vars) => window.n(APP, singular, plural, count, vars, { escape: false })
	const apiUrl = window.OC.generateUrl('/apps/quickbook/api/v1/one-time')
	const browserTz = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'

	const CALENDAR_LIST = '.appointment-config-list:not(.qb-onetime)'
	const BOOKING_LINK = 'a[href*="/apps/calendar/appointment/"]'

	/** Material Design icons, as used by the Calendar app */
	const ICONS = {
		openInNew: 'M14,3V5H17.59L7.76,14.83L9.17,16.24L19,6.41V10H21V3M19,19H5V5H12V3H5C3.89,3 3,3.9 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V12H19V19Z',
		link: 'M3.9,12C3.9,10.29 5.29,8.9 7,8.9H11V7H7A5,5 0 0,0 2,12A5,5 0 0,0 7,17H11V15.1H7C5.29,15.1 3.9,13.71 3.9,12M8,13H16V11H8V13M17,7H13V8.9H17C18.71,8.9 20.1,10.29 20.1,12C20.1,13.71 18.71,15.1 17,15.1H13V17H17A5,5 0 0,0 22,12A5,5 0 0,0 17,7Z',
		duplicate: 'M11,17H4A2,2 0 0,1 2,15V3A2,2 0 0,1 4,1H16V3H4V15H11V13L15,16L11,19V17M19,21V7H8V13H6V7A2,2 0 0,1 8,5H19A2,2 0 0,1 21,7V21A2,2 0 0,1 19,23H8A2,2 0 0,1 6,21V19H8V21H19Z',
		pencil: 'M14.06,9L15,9.94L5.92,19H5V18.08L14.06,9M17.66,3C17.41,3 17.15,3.1 16.96,3.29L15.13,5.12L18.88,8.87L20.71,7.04C21.1,6.65 21.1,6 20.71,5.63L18.37,3.29C18.17,3.09 17.92,3 17.66,3M14.06,6.19L3,17.25V21H6.75L17.81,9.94L14.06,6.19Z',
		trash: 'M9,3V4H4V6H5V19A2,2 0 0,0 7,21H17A2,2 0 0,0 19,19V6H20V4H15V3H9M7,6H17V19H7V6M9,8V17H11V8H9M13,8V17H15V8H13Z',
		check: 'M21,7L9,19L3.5,13.5L4.91,12.09L9,16.17L19.59,5.59L21,7Z',
		close: 'M19,6.41L17.59,5L12,10.59L6.41,5L5,6.41L10.59,12L5,17.59L6.41,19L12,13.41L17.59,19L19,17.59L13.41,12L19,6.41Z',
		plus: 'M19,13H13V19H11V13H5V11H11V5H13V11H19V13Z',
		chevronDown: 'M7.41,8.58L12,13.17L16.59,8.58L18,10L12,16L6,10L7.41,8.58Z',
		dots: 'M16,12A2,2 0 0,1 18,10A2,2 0 0,1 20,12A2,2 0 0,1 18,14A2,2 0 0,1 16,12M10,12A2,2 0 0,1 12,10A2,2 0 0,1 14,12A2,2 0 0,1 12,14A2,2 0 0,1 10,12M4,12A2,2 0 0,1 6,10A2,2 0 0,1 8,12A2,2 0 0,1 6,14A2,2 0 0,1 4,12Z',
		calendarCheck: 'M19,19H5V8H19M19,3H18V1H16V3H8V1H6V3H5C3.89,3 3,3.9 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V5A2,2 0 0,0 19,3M16.53,11.06L15.47,10L10.59,14.88L8.47,12.76L7.41,13.82L10.59,17L16.53,11.06Z',
		checkboxBlank: 'M19,3H5C3.89,3 3,3.89 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V5C21,3.89 20.1,3 19,3M19,5V19H5V5H19Z',
		checkboxMarked: 'M10,17L5,12L6.41,10.58L10,14.17L17.59,6.58L19,8M19,3H5C3.89,3 3,3.89 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V5C21,3.89 20.1,3 19,3Z',
	}

	// ---------------------------------------------------------------- DOM helpers

	/** h('button', { class: 'x', onclick: fn }, 'text', child) */
	function h(tag, attrs, ...children) {
		const node = document.createElement(tag)
		for (const [key, value] of Object.entries(attrs || {})) {
			if (value === false || value === null || value === undefined) {
				continue
			}
			if (key.startsWith('on') && typeof value === 'function') {
				node.addEventListener(key.slice(2), value)
			} else if (key === 'class') {
				node.className = value
			} else if (key === 'value') {
				node.value = value
			} else if (value === true) {
				node.setAttribute(key, '')
			} else {
				node.setAttribute(key, String(value))
			}
		}
		for (const child of children.flat(Infinity)) {
			if (child === null || child === undefined || child === false) {
				continue
			}
			node.append(child instanceof Node ? child : document.createTextNode(String(child)))
		}
		return node
	}

	function icon(path, size = 20) {
		const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg')
		svg.setAttribute('viewBox', '0 0 24 24')
		svg.setAttribute('width', String(size))
		svg.setAttribute('height', String(size))
		svg.setAttribute('fill', 'currentColor')
		const p = document.createElementNS('http://www.w3.org/2000/svg', 'path')
		p.setAttribute('d', path)
		svg.append(p)
		return h('span', { class: 'material-design-icon', 'aria-hidden': 'true', role: 'img' }, svg)
	}

	function stripIds(node) {
		node.removeAttribute?.('id')
		node.querySelectorAll?.('[id]').forEach((el) => el.removeAttribute('id'))
		node.querySelectorAll?.('[aria-controls]').forEach((el) => el.removeAttribute('aria-controls'))
		return node
	}

	/**
	 * Scope attributes (data-v-...) of the Nextcloud button component used on this page, so our
	 * buttons get exactly the same look as the Calendar's. Null when there is no button to copy from.
	 */
	function buttonScope() {
		const wrapper = document.querySelector('.button-vue .button-vue__wrapper')
		if (!wrapper) {
			return null
		}
		return [...wrapper.attributes].map((a) => a.name).filter((name) => name.startsWith('data-v-'))
	}

	/** A Nextcloud button: variant primary, secondary or tertiary */
	function button({ variant = 'secondary', text, iconPath, label, onclick, type = 'button', href, disabled, extraClass = '' }) {
		const scope = buttonScope()
		const kind = text ? (iconPath ? 'icon-and-text' : 'text-only') : 'icon-only'
		const classes = scope
			? `button-vue button-vue--size-normal button-vue--${variant} button-vue--${kind} ${extraClass}`
			: `qb-button qb-button--${variant} qb-button--${kind} ${extraClass}`
		const attrs = { class: classes, 'aria-label': label || null, title: label && !text ? label : null, onclick, disabled }
		const node = href
			? h('a', { ...attrs, href, target: '_blank', rel: 'noopener noreferrer' })
			: h('button', { ...attrs, type })
		const wrapper = h('span', { class: 'button-vue__wrapper' },
			iconPath ? h('span', { class: 'button-vue__icon' }, icon(iconPath)) : null,
			h('span', { class: 'button-vue__text' }, text || ''),
		)
		node.append(wrapper)
		if (scope) {
			for (const el of [node, ...node.querySelectorAll('span')]) {
				scope.forEach((name) => el.setAttribute(name, ''))
			}
		}
		return node
	}

	function toast(kind, message) {
		const fn = window.OCP?.Toast?.[kind]
		if (fn) {
			fn(message)
		} else {
			window.OC.Notification?.showTemporary?.(message)
		}
	}

	function formatMinutes(minutes) {
		if (minutes === 0) {
			return n('{duration} minute', '{duration} minutes', 0, { duration: 0 })
		}
		if (minutes % (7 * 24 * 60) === 0) {
			const weeks = minutes / (7 * 24 * 60)
			return n('{duration} week', '{duration} weeks', weeks, { duration: weeks })
		}
		if (minutes % (24 * 60) === 0) {
			const days = minutes / (24 * 60)
			return n('{duration} day', '{duration} days', days, { duration: days })
		}
		if (minutes % 60 === 0) {
			const hours = minutes / 60
			return n('{duration} hour', '{duration} hours', hours, { duration: hours })
		}
		return n('{duration} minute', '{duration} minutes', minutes, { duration: minutes })
	}

	// ---------------------------------------------------------------- data

	let data = null // { schedules, otherNames, calendars, talk, choices }
	let loading = null
	/** Names of one-time schedules seen in this session: the Calendar's list keeps old names until reload */
	const oneTimeNames = new Set()

	async function request(method, url, payload) {
		const response = await fetch(url, {
			method,
			credentials: 'same-origin',
			headers: {
				Accept: 'application/json',
				'Content-Type': 'application/json',
				requesttoken: window.OC.requestToken || document.head.dataset.requesttoken || '',
			},
			body: payload === undefined ? undefined : JSON.stringify(payload),
		})
		const body = await response.json().catch(() => ({}))
		return { response, body }
	}

	function load() {
		loading = loading || request('GET', apiUrl)
			.then(({ response, body }) => {
				if (!response.ok) {
					throw new Error(String(response.status))
				}
				data = body
				body.schedules.forEach((schedule) => oneTimeNames.add(schedule.name))
				sync()
				return body
			})
			.finally(() => {
				loading = null
			})
		return loading
	}

	function scheduleForToken(token) {
		return data?.schedules.find((schedule) => schedule.token === token) || null
	}

	// ---------------------------------------------------------------- navigation

	let calendarEntryCount = -1

	/** Brings the navigation in line with the data: own section present, one-time schedules hidden in the Calendar's list */
	function sync() {
		const calendarList = document.querySelector(CALENDAR_LIST)
		if (!calendarList || !data) {
			return
		}

		// The Calendar's list changed (schedule added or removed there): names may have changed
		const count = calendarList.querySelectorAll('.app-navigation-entry-wrapper').length
		if (calendarEntryCount !== -1 && count !== calendarEntryCount && !loading) {
			calendarEntryCount = count
			load().catch(() => {})
			return
		}
		calendarEntryCount = count

		const normalNames = new Set(data.otherNames)
		for (const entry of calendarList.querySelectorAll('.app-navigation-entry-wrapper')) {
			const name = entry.querySelector('.app-navigation-entry__name')?.textContent.trim() || ''
			// A name that a weekly schedule also has stays visible; Edit is still caught below
			const hide = oneTimeNames.has(name) && !normalNames.has(name)
			const display = hide ? 'none' : ''
			if (entry.style.display !== display) {
				entry.style.display = display
			}
		}

		let section = document.querySelector('.qb-onetime')
		if (!section || section.previousElementSibling !== calendarList) {
			section?.remove()
			section = buildSection(calendarList)
			if (!section) {
				return
			}
			renderedKey = ''
			calendarList.after(section)
		}
		renderEntries(section, calendarList)
	}

	function buildSection(calendarList) {
		const caption = calendarList.querySelector('.appointment-config-list__caption')
		if (!caption) {
			return null
		}
		const ownCaption = stripIds(caption.cloneNode(true))
		ownCaption.classList.add('qb-onetime__caption')
		const name = ownCaption.querySelector('.app-navigation-caption__name')
		if (name) {
			name.textContent = t('One-time appointment schedules')
		}
		// The Calendar shows "Create new" only when appointments are possible (user has an email address)
		const create = ownCaption.querySelector('.app-navigation-caption__actions button')
		create?.addEventListener('click', (event) => {
			event.preventDefault()
			openEditor(null, false)
		})
		return h('div', { class: 'appointment-config-list qb-onetime' }, ownCaption, h('div', { class: 'qb-onetime__entries' }))
	}

	let renderedKey = ''

	function renderEntries(section, calendarList) {
		const container = section.querySelector('.qb-onetime__entries')
		const sorted = [...data.schedules].sort((a, b) => a.name.localeCompare(b.name))
		const key = JSON.stringify(sorted.map((s) => [s.id, s.name, s.bookingUrl]))
		if (key === renderedKey) {
			return
		}
		renderedKey = key
		const template = calendarList.querySelector('.app-navigation-entry-wrapper')
		container.replaceChildren(...sorted.map((schedule) => h('div', {}, entry(schedule, template))))
	}

	/** A list entry: a copy of the Calendar's own entry, or a plain one when there is none to copy */
	function entry(schedule, template) {
		let node
		let toggle
		if (template) {
			node = stripIds(template.cloneNode(true))
			node.removeAttribute('style')
			node.querySelectorAll('.active').forEach((el) => el.classList.remove('active'))
			const link = node.querySelector('.app-navigation-entry-link')
			link?.setAttribute('title', schedule.name)
			link?.addEventListener('click', (event) => event.preventDefault())
			const name = node.querySelector('.app-navigation-entry__name')
			if (name) {
				name.textContent = schedule.name
			}
			toggle = node.querySelector('.action-item__menutoggle')
		}
		if (!node || !toggle) {
			toggle = button({ variant: 'tertiary', iconPath: ICONS.dots, label: t('Actions'), extraClass: 'qb-entry__toggle' })
			node = h('li', { class: 'qb-entry' },
				icon(ICONS.calendarCheck),
				h('span', { class: 'qb-entry__name', title: schedule.name }, schedule.name),
				toggle,
			)
		}
		toggle.setAttribute('aria-haspopup', 'menu')
		toggle.setAttribute('aria-expanded', 'false')
		toggle.addEventListener('click', (event) => {
			event.preventDefault()
			event.stopPropagation()
			openMenu(toggle, schedule)
		})
		return node
	}

	// ---------------------------------------------------------------- entry menu

	let menu = null

	function closeMenu(focusToggle) {
		if (!menu) {
			return
		}
		menu.toggle.setAttribute('aria-expanded', 'false')
		menu.node.remove()
		if (focusToggle) {
			menu.toggle.focus()
		}
		document.removeEventListener('pointerdown', menu.outside, true)
		document.removeEventListener('keydown', menu.keys, true)
		window.removeEventListener('resize', menu.resize)
		menu = null
	}

	function openMenu(toggle, schedule) {
		if (menu) {
			const same = menu.toggle === toggle
			closeMenu(false)
			if (same) {
				return
			}
		}
		const item = (iconPath, text, action, href) => h('li', { class: 'qb-menu__item', role: 'presentation' },
			h(href ? 'a' : 'button', {
				class: 'qb-menu__action',
				role: 'menuitem',
				type: href ? null : 'button',
				href: href || null,
				target: href ? '_blank' : null,
				rel: href ? 'noopener noreferrer' : null,
				onclick: () => {
					closeMenu(false)
					action?.()
				},
			}, icon(iconPath), h('span', { class: 'qb-menu__text' }, text)),
		)
		const node = h('ul', { class: 'qb-menu', role: 'menu', 'aria-label': schedule.name },
			item(ICONS.openInNew, t('Preview'), null, schedule.bookingUrl),
			navigator.clipboard ? item(ICONS.link, t('Copy link'), () => copyLink(schedule.bookingUrl)) : null,
			item(ICONS.duplicate, t('Duplicate'), () => openEditor(schedule, true)),
			item(ICONS.pencil, t('Edit'), () => openEditor(schedule, false)),
			item(ICONS.trash, t('Delete'), () => deleteSchedule(schedule)),
		)
		document.body.append(node)
		const rect = toggle.getBoundingClientRect()
		// Below the button, or above it when there is no room (like Nextcloud's menus)
		const top = rect.bottom + 4 + node.offsetHeight > window.innerHeight - 8
			? Math.max(8, rect.top - 4 - node.offsetHeight)
			: rect.bottom + 4
		node.style.top = `${top}px`
		node.style.left = `${Math.max(8, Math.min(rect.left + rect.width / 2 - 24, window.innerWidth - node.offsetWidth - 8))}px`
		toggle.setAttribute('aria-expanded', 'true')
		node.querySelector('[role="menuitem"]').focus()

		menu = {
			node,
			toggle,
			outside: (event) => {
				if (!node.contains(event.target) && !toggle.contains(event.target)) {
					closeMenu(false)
				}
			},
			keys: (event) => {
				const items = [...node.querySelectorAll('[role="menuitem"]')]
				const index = items.indexOf(document.activeElement)
				if (event.key === 'Escape') {
					event.preventDefault()
					closeMenu(true)
				} else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
					event.preventDefault()
					items[(index + (event.key === 'ArrowDown' ? 1 : items.length - 1)) % items.length].focus()
				} else if (event.key === 'Tab') {
					closeMenu(false)
				}
			},
			resize: () => closeMenu(false),
		}
		document.addEventListener('pointerdown', menu.outside, true)
		document.addEventListener('keydown', menu.keys, true)
		window.addEventListener('resize', menu.resize)
	}

	async function copyLink(url) {
		try {
			await navigator.clipboard.writeText(url)
			toast('success', t('Appointment link was copied to clipboard'))
		} catch (e) {
			toast('error', t('Appointment link could not be copied to clipboard'))
		}
	}

	async function deleteSchedule(schedule) {
		try {
			const { response } = await request('DELETE', `${apiUrl}/${schedule.id}`)
			if (!response.ok && response.status !== 404) {
				throw new Error(String(response.status))
			}
			data.schedules = data.schedules.filter((s) => s.id !== schedule.id)
			sync()
		} catch (e) {
			toast('error', t('Could not delete the appointment schedule'))
		}
	}

	// ---------------------------------------------------------------- Calendar's own menus

	// A one-time schedule can still show in the Calendar's list (same name as a weekly one):
	// its Edit and Duplicate open this editor, the Calendar's would turn it into a weekly schedule.
	document.addEventListener('click', (event) => {
		const target = event.target instanceof Element ? event.target : null
		const item = target?.closest('[role="menu"]:not(.qb-menu) [role="menuitem"]')
		if (!item) {
			return
		}
		const isEdit = item.querySelector('.pencil-outline-icon')
		const isDuplicate = item.querySelector('.content-duplicate-icon')
		const calendarMenu = item.closest('[role="menu"]')
		const link = (isEdit || isDuplicate) ? calendarMenu.querySelector(BOOKING_LINK) : null
		const schedule = link ? scheduleForToken(new URL(link.href, window.location.href).pathname.split('/').filter(Boolean).pop()) : null
		if (!schedule) {
			return
		}
		event.preventDefault()
		event.stopImmediatePropagation()
		const trigger = document.getElementById(calendarMenu.getAttribute('aria-labelledby') || '')
		if (trigger?.getAttribute('aria-expanded') === 'true') {
			trigger.click()
		}
		openEditor(schedule, Boolean(isDuplicate))
	}, true)

	// ---------------------------------------------------------------- form controls

	let fieldId = 0
	const nextId = (prefix) => `qb-${prefix}-${++fieldId}`

	/** Text field with the label on the border, like the Calendar's */
	function textField({ name, label, value, maxlength, disabled, required }) {
		const id = nextId(name)
		return h('div', { class: 'appointment-config-modal__form__row qb-text-field' + (disabled ? ' qb-text-field--disabled' : '') },
			h('input', { id, name, type: 'text', value, maxlength, disabled, required, placeholder: ' ' }),
			h('label', { for: id }, label),
		)
	}

	/** Label above the control, like the Calendar's selects */
	function labelled(className, label, control) {
		const id = control.id || nextId('field')
		control.id = id
		return h('div', { class: className }, h('label', { for: id }, label), control)
	}

	function select(name, options, value, disabled) {
		return h('select', { name, class: 'qb-select', disabled },
			options.map((option) => h('option', { value: option.value, selected: String(option.value) === String(value) }, option.label)))
	}

	function checkbox({ name, label, checked, onchange }) {
		const id = nextId(name)
		return h('span', { class: 'qb-checkbox' },
			h('input', { id, name, type: 'checkbox', class: 'qb-checkbox__input', checked, onchange }),
			h('label', { for: id, class: 'qb-checkbox__label' },
				h('span', { class: 'qb-checkbox__icon qb-checkbox__icon--off' }, icon(ICONS.checkboxBlank, 24)),
				h('span', { class: 'qb-checkbox__icon qb-checkbox__icon--on' }, icon(ICONS.checkboxMarked, 24)),
				label,
			),
		)
	}

	function colorDot(calendar) {
		const color = /^#[0-9a-f]{3,8}$/i.test(calendar.color) ? calendar.color : 'var(--color-primary-element)'
		return h('span', { class: 'qb-dot', style: `background-color: ${color}`, 'aria-hidden': 'true' })
	}

	/**
	 * Calendar picker like the Calendar's: a dropdown with the calendars and their colours,
	 * for one calendar or several.
	 */
	function calendarPicker({ calendars, selected, multiple, labelId, onchange }) {
		let value = [...selected]
		let open = false
		let active = 0
		let popup = null
		const listId = nextId('list')

		const box = h('div', {
			class: 'qb-picker' + (multiple ? ' qb-picker--multiple' : ''),
			role: 'combobox',
			tabindex: '0',
			'aria-haspopup': 'listbox',
			'aria-expanded': 'false',
			'aria-controls': listId,
			'aria-labelledby': labelId,
		})

		function renderBox() {
			const chosen = calendars.filter((calendar) => value.includes(calendar.uri))
			box.replaceChildren(
				h('span', { class: 'qb-picker__values' },
					multiple
						? chosen.map((calendar) => h('span', { class: 'qb-chip' },
							colorDot(calendar),
							h('span', { class: 'qb-chip__name' }, calendar.name),
							h('button', {
								type: 'button',
								class: 'qb-chip__remove',
								'aria-label': t('Remove {calendar}', { calendar: calendar.name }),
								onclick: (event) => {
									event.stopPropagation()
									toggleValue(calendar.uri)
								},
							}, icon(ICONS.close, 16))))
						: chosen.map((calendar) => h('span', { class: 'qb-picker__single' }, colorDot(calendar), calendar.name)),
				),
				h('span', { class: 'qb-picker__chevron' }, icon(ICONS.chevronDown)),
			)
		}

		function toggleValue(uri) {
			value = multiple
				? (value.includes(uri) ? value.filter((v) => v !== uri) : [...value, uri])
				: [uri]
			renderBox()
			onchange([...value])
			if (open) {
				renderPopup()
			}
		}

		function renderPopup() {
			popup.replaceChildren(...calendars.map((calendar, i) => h('li', {
				id: `${listId}-${i}`,
				role: 'option',
				class: 'qb-picker__option' + (i === active ? ' qb-picker__option--active' : ''),
				'aria-selected': value.includes(calendar.uri) ? 'true' : 'false',
				onpointerdown: (event) => event.preventDefault(),
				onclick: () => {
					active = i
					toggleValue(calendar.uri)
					if (!multiple) {
						close()
					}
				},
			}, colorDot(calendar), h('span', { class: 'qb-picker__option-name' }, calendar.name),
			value.includes(calendar.uri) ? icon(ICONS.check) : null)))
			box.setAttribute('aria-activedescendant', `${listId}-${active}`)
		}

		function place() {
			const rect = box.getBoundingClientRect()
			popup.style.left = `${rect.left}px`
			popup.style.width = `${rect.width}px`
			const below = window.innerHeight - rect.bottom
			if (below < 200 && rect.top > below) {
				popup.style.top = ''
				popup.style.bottom = `${window.innerHeight - rect.top + 4}px`
			} else {
				popup.style.bottom = ''
				popup.style.top = `${rect.bottom + 4}px`
			}
		}

		function show() {
			if (open || calendars.length === 0) {
				return
			}
			open = true
			active = Math.max(0, calendars.findIndex((calendar) => value.includes(calendar.uri)))
			popup = h('ul', { id: listId, class: 'qb-picker__popup', role: 'listbox', 'aria-multiselectable': multiple ? 'true' : null })
			box.closest('dialog').append(popup)
			renderPopup()
			place()
			box.setAttribute('aria-expanded', 'true')
			document.addEventListener('pointerdown', outside, true)
		}

		function close() {
			if (!open) {
				return
			}
			open = false
			popup.remove()
			popup = null
			box.setAttribute('aria-expanded', 'false')
			box.removeAttribute('aria-activedescendant')
			document.removeEventListener('pointerdown', outside, true)
		}

		function outside(event) {
			if (!box.contains(event.target) && !popup?.contains(event.target)) {
				close()
			}
		}

		box.addEventListener('click', () => (open ? close() : show()))
		box.addEventListener('keydown', (event) => {
			if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
				event.preventDefault()
				if (!open) {
					show()
					return
				}
				active = (active + (event.key === 'ArrowDown' ? 1 : calendars.length - 1)) % calendars.length
				renderPopup()
			} else if (event.key === 'Enter' || event.key === ' ') {
				event.preventDefault()
				if (!open) {
					show()
					return
				}
				toggleValue(calendars[active].uri)
				if (!multiple) {
					close()
				}
			} else if (event.key === 'Escape' && open) {
				// Close the list, not the dialog
				event.preventDefault()
				event.stopPropagation()
				close()
			} else if (event.key === 'Tab') {
				close()
			}
		})
		box.closePopup = close
		renderBox()
		return box
	}

	// ---------------------------------------------------------------- editor

	async function openEditor(schedule, duplicate) {
		let info
		try {
			info = data || await load()
		} catch (e) {
			toast('error', t('One-time appointment schedules could not be loaded. Please try again later.'))
			return
		}

		const isNew = !schedule || duplicate
		const writable = info.calendars.filter((calendar) => calendar.writable)
		const form = {
			name: schedule?.name || '',
			location: schedule?.location || '',
			createTalkRoom: Boolean(schedule?.createTalkRoom),
			description: schedule?.description || '',
			lengthMinutes: schedule?.lengthMinutes || 15,
			incrementMinutes: schedule?.incrementMinutes || 15,
			calendarUri: schedule?.calendarUri || writable[0]?.uri || '',
			conflictCalendarUris: [...(schedule?.conflictCalendarUris || [])],
			visibility: schedule?.visibility || 'PRIVATE',
			timezone: schedule?.timezone || browserTz,
			days: schedule ? schedule.days.map((day) => ({ ...day })) : [{ date: '', from: '09:00', to: '17:00' }],
			preparationMinutes: schedule?.preparationMinutes || 0,
			followupMinutes: schedule?.followupMinutes || 0,
			timeBeforeNextSlotMinutes: schedule?.timeBeforeNextSlotMinutes || 0,
			dailyMax: schedule?.dailyMax ?? null,
		}
		let enablePreparation = form.preparationMinutes > 0
		let enableFollowup = form.followupMinutes > 0
		let error = ''
		let rateLimited = false
		let saving = false
		let saved = null

		const dialog = h('dialog', { class: 'qb-modal', 'aria-labelledby': 'qb-modal-title' })
		document.body.append(dialog)
		// The Calendar's keyboard shortcuts keep the browser from closing the dialog on Escape
		dialog.addEventListener('keydown', (event) => {
			// An open calendar list stops its own Escape before it gets here
			if (event.key === 'Escape') {
				event.preventDefault()
				dialog.close()
			}
		})
		dialog.addEventListener('close', () => {
			dialog.remove()
			if (saved) {
				load().catch(() => {})
			}
		})

		function readFields() {
			const value = (name) => dialog.querySelector(`[name="${name}"]`)?.value ?? ''
			const number = (name) => {
				const raw = value(name).trim()
				return raw === '' ? null : Number(raw)
			}
			form.name = value('name')
			form.location = value('location')
			form.description = value('description')
			form.lengthMinutes = number('lengthMinutes') ?? 0
			form.incrementMinutes = Number(value('incrementMinutes'))
			form.visibility = value('visibility')
			form.createTalkRoom = Boolean(dialog.querySelector('[name="createTalkRoom"]')?.checked)
			form.days = [...dialog.querySelectorAll('.qb-date-row')].map((row) => ({
				date: row.querySelector('[name="date"]').value,
				from: row.querySelector('[name="from"]').value,
				to: row.querySelector('[name="to"]').value,
			}))
			enablePreparation = Boolean(dialog.querySelector('[name="enablePreparation"]')?.checked)
			enableFollowup = Boolean(dialog.querySelector('[name="enableFollowup"]')?.checked)
			form.preparationMinutes = enablePreparation ? Number(value('preparationMinutes')) : 0
			form.followupMinutes = enableFollowup ? Number(value('followupMinutes')) : 0
			form.timeBeforeNextSlotMinutes = Number(value('timeBeforeNextSlotMinutes'))
			form.dailyMax = number('dailyMax')
		}

		async function save() {
			readFields()
			if (!dialog.querySelector('form').reportValidity()) {
				return
			}
			saving = true
			error = ''
			rateLimited = false
			render()
			try {
				const { response, body } = isNew
					? await request('POST', apiUrl, form)
					: await request('PUT', `${apiUrl}/${schedule.id}`, form)
				if (response.ok) {
					saved = body.schedule
				} else if (response.status === 422) {
					error = body.message || t('Please check your input.')
				} else if (response.status === 429) {
					rateLimited = true
				} else if (response.status === 503) {
					error = t('One-time appointment schedules are temporarily unavailable.')
				} else {
					error = t('Could not save. Please try again.')
				}
			} catch (e) {
				error = t('Could not reach the server. Please check your connection and try again.')
			} finally {
				saving = false
				render()
			}
		}

		function slotCount(day) {
			if (!day.from || !day.to || !form.lengthMinutes || !form.incrementMinutes) {
				return 0
			}
			const [fh, fm] = day.from.split(':').map(Number)
			const [th, tm] = day.to.split(':').map(Number)
			const minutes = (th * 60 + tm) - (fh * 60 + fm)
			return minutes < form.lengthMinutes ? 0 : Math.floor((minutes - form.lengthMinutes) / form.incrementMinutes) + 1
		}

		function countText(day) {
			const count = slotCount(day)
			return count > 0 ? n('{count} slot', '{count} slots', count, { count }) : ''
		}

		function updateCounts() {
			readFields()
			dialog.querySelectorAll('.qb-date-row').forEach((row, i) => {
				row.querySelector('.qb-date-row__count').textContent = countText(form.days[i])
			})
		}

		function dateRow(day, index) {
			const label = t('Date {number}', { number: index + 1 })
			return h('li', { class: 'qb-date-row' },
				h('input', { type: 'date', name: 'date', class: 'qb-input', required: true, value: day.date, 'aria-label': label, min: '2000-01-01', max: '2100-12-31' }),
				h('span', { class: 'qb-date-row__times' },
					h('input', { type: 'time', name: 'from', class: 'qb-input', required: true, step: 300, value: day.from, 'aria-label': t('{date}: from', { date: label }) }),
					h('span', { class: 'qb-date-row__to' }, t('to')),
					h('input', { type: 'time', name: 'to', class: 'qb-input', required: true, step: 300, value: day.to, 'aria-label': t('{date}: to', { date: label }) }),
					button({
						variant: 'tertiary',
						iconPath: ICONS.trash,
						label: t('Delete {date}', { date: label }),
						disabled: form.days.length === 1,
						onclick: () => {
							readFields()
							form.days.splice(index, 1)
							render()
						},
					}),
				),
				h('span', { class: 'qb-date-row__count' }, countText(day)),
			)
		}

		function pickers() {
			const bookingLabel = nextId('booking-label')
			const conflictLabel = nextId('conflict-label')
			const conflictBox = h('div', { class: 'calendar-select' })
			const renderConflicts = () => {
				conflictBox.replaceChildren(
					h('label', { id: conflictLabel }, t('Calendars to check for conflicts')),
					calendarPicker({
						calendars: info.calendars.filter((calendar) => calendar.uri !== form.calendarUri),
						selected: form.conflictCalendarUris.filter((uri) => uri !== form.calendarUri),
						multiple: true,
						labelId: conflictLabel,
						onchange: (value) => {
							form.conflictCalendarUris = value
						},
					}),
				)
			}
			renderConflicts()
			return h('div', { class: 'appointment-config-modal__form__row appointment-config-modal__form__row--wrapped' },
				h('div', { class: 'calendar-select' },
					h('label', { id: bookingLabel }, t('Booking calendar')),
					calendarPicker({
						calendars: writable,
						selected: [form.calendarUri],
						multiple: false,
						labelId: bookingLabel,
						onchange: ([uri]) => {
							form.calendarUri = uri
							// The booking calendar is always checked for conflicts
							form.conflictCalendarUris = form.conflictCalendarUris.filter((u) => u !== uri)
							renderConflicts()
						},
					}),
				),
				conflictBox,
			)
		}

		function checkedDuration(name, enableName, label, enabled, value) {
			const durationSelect = select(name, info.choices.buffers.map((m) => ({ value: m, label: formatMinutes(m) })), value, !enabled)
			return h('div', { class: 'qb-checked-duration' },
				checkbox({
					name: enableName,
					label,
					checked: enabled,
					onchange: (event) => {
						durationSelect.disabled = !event.target.checked
					},
				}),
				durationSelect,
			)
		}

		function viewForm() {
			const row = (...children) => h('div', { class: 'appointment-config-modal__form__row' }, ...children)
			const wrapped = (...children) => h('div', { class: 'appointment-config-modal__form__row appointment-config-modal__form__row--wrapped' }, ...children)
			const descriptionId = nextId('description')

			return h('form', {
				class: 'appointment-config-modal__form',
				onsubmit: (event) => {
					event.preventDefault()
					save()
				},
				oninput: (event) => {
					if (['from', 'to', 'lengthMinutes'].includes(event.target.name)) {
						updateCounts()
					}
				},
				onchange: (event) => {
					if (event.target.name === 'incrementMinutes') {
						updateCounts()
					}
				},
			},
			h('div', { class: 'appointment-config-modal__form__title' },
				h('h2', { id: 'qb-modal-title' }, isNew ? t('Create one-time appointment schedule') : t('Edit one-time appointment schedule'))),
			error ? h('p', { class: 'qb-note qb-note--error', role: 'alert' }, error) : null,
			writable.length === 0 ? h('p', { class: 'qb-note qb-note--error', role: 'alert' }, t('You need a calendar you can write to, to take appointments.')) : null,

			h('fieldset', {},
				textField({ name: 'name', label: t('Appointment name'), value: form.name, maxlength: 128, required: true }),
				textField({ name: 'location', label: t('Location'), value: form.location, maxlength: 255, disabled: info.talk && form.createTalkRoom }),
				info.talk
					? row(
						checkbox({
							name: 'createTalkRoom',
							label: t('Create a Talk room'),
							checked: form.createTalkRoom,
							// Like the Calendar: the Talk link takes the place of the location
							onchange: (event) => {
								const location = dialog.querySelector('[name="location"]')
								location.disabled = event.target.checked
								location.parentElement.classList.toggle('qb-text-field--disabled', event.target.checked)
							},
						}),
						h('span', { class: 'qb-talk-description' }, t('A unique link will be generated for every booked appointment and sent via the confirmation email')),
					)
					: null,
				row(
					h('label', { for: descriptionId }, t('Description')),
					h('textarea', { id: descriptionId, name: 'description', class: 'qb-textarea', rows: 2, maxlength: 2000, value: form.description }),
				),
				wrapped(
					labelled('qb-duration', t('Duration (minutes)'), h('input', { name: 'lengthMinutes', class: 'qb-input', type: 'number', min: 1, max: 1440, required: true, value: form.lengthMinutes })),
					labelled('duration-select', t('Increments'), select('incrementMinutes', info.choices.increments.map((m) => ({ value: m, label: formatMinutes(m) })), form.incrementMinutes)),
				),
				pickers(),
				h('div', { class: 'appointment-config-modal__form__row appointment-config-modal__form__row--local' },
					labelled('visibility-select', t('Visibility'), select('visibility', [
						{ value: 'PUBLIC', label: t('Public – shown on profile page') },
						{ value: 'PRIVATE', label: t('Private – only accessible via link') },
					], form.visibility))),
			),

			h('fieldset', {},
				h('header', {}, h('h3', {}, t('Booking dates'))),
				h('p', { class: 'qb-hint' }, t('Times are in {timezone}.', { timezone: form.timezone })),
				h('ul', { class: 'qb-date-list' }, form.days.map(dateRow)),
				h('div', { class: 'qb-add-date' },
					button({
						variant: 'secondary',
						iconPath: ICONS.plus,
						text: t('Add date'),
						disabled: form.days.length >= 100,
						onclick: () => {
							readFields()
							const last = form.days[form.days.length - 1]
							form.days.push({ date: '', from: last.from, to: last.to })
							render()
							const dates = dialog.querySelectorAll('.qb-date-row [name="date"]')
							dates[dates.length - 1].focus()
						},
					}),
				),
			),

			h('fieldset', {},
				h('header', {}, h('h3', {}, t('Planning restrictions'))),
				wrapped(
					checkedDuration('preparationMinutes', 'enablePreparation', t('Buffer before the event'), enablePreparation, form.preparationMinutes),
					checkedDuration('followupMinutes', 'enableFollowup', t('Buffer after the event'), enableFollowup, form.followupMinutes),
				),
				wrapped(
					labelled('duration-select', t('Minimum time before next slot'), select('timeBeforeNextSlotMinutes', info.choices.notice.map((m) => ({ value: m, label: formatMinutes(m) })), form.timeBeforeNextSlotMinutes)),
					labelled('qb-number', t('Max slots per day'), h('input', { name: 'dailyMax', class: 'qb-input', type: 'number', min: 1, max: 1000, value: form.dailyMax ?? '' })),
				),
			),

			rateLimited ? h('p', { class: 'qb-note qb-note--warning', role: 'alert' }, t('It seems a rate limit has been reached. Please try again later.')) : null,
			button({
				variant: 'primary',
				type: 'submit',
				iconPath: ICONS.check,
				text: isNew ? t('Save') : t('Update'),
				disabled: saving || writable.length === 0,
				extraClass: 'appointment-config-modal__submit-button',
			}),
			)
		}

		function viewConfirmation() {
			return h('div', { class: 'qb-confirmation' },
				h('div', { class: 'qb-confirmation__icon' }, icon(ICONS.check, 64)),
				h('h2', { id: 'qb-modal-title', class: 'qb-confirmation__title' },
					isNew ? t('One-time appointment schedule successfully created') : t('One-time appointment schedule successfully updated')),
				h('div', { class: 'qb-confirmation__buttons' },
					button({ variant: 'secondary', text: t('Preview'), href: saved.bookingUrl }),
					navigator.clipboard ? button({ variant: 'secondary', text: t('Copy link'), onclick: () => copyLink(saved.bookingUrl) }) : null,
				),
			)
		}

		function render() {
			dialog.querySelectorAll('.qb-picker').forEach((picker) => picker.closePopup?.())
			dialog.replaceChildren(
				h('div', { class: 'appointment-config-modal qb-modal__content' }, saved ? viewConfirmation() : viewForm()),
				button({ variant: 'tertiary', iconPath: ICONS.close, label: t('Close'), extraClass: 'qb-modal__close', onclick: () => dialog.close() }),
			)
			if (saved) {
				dialog.querySelector('.qb-confirmation__buttons a, .qb-confirmation__buttons button')?.focus()
			} else if (error || rateLimited) {
				dialog.querySelector('.qb-note')?.scrollIntoView({ block: 'nearest' })
			}
		}

		render()
		dialog.showModal()
		dialog.querySelector('[name="name"]').focus()
	}

	// ---------------------------------------------------------------- start

	function watch() {
		const navigation = document.querySelector('.app-navigation')
		let pending = false
		const observer = new MutationObserver(() => {
			if (!pending) {
				pending = true
				requestAnimationFrame(() => {
					pending = false
					sync()
				})
			}
		})
		observer.observe(navigation || document.body, { childList: true, subtree: true })
		if (!navigation) {
			// The Calendar renders its navigation later: watch only that part once it is there
			const waitForNavigation = setInterval(() => {
				if (document.querySelector('.app-navigation')) {
					clearInterval(waitForNavigation)
					observer.disconnect()
					watch()
				}
			}, 500)
		}
	}

	load().catch(() => {})
	watch()
})()
