// calendar-sms.js

// TODO: Add lable or btnLabel to every btn we injected bellow

import { getRequestToken } from '@nextcloud/auth'

(function() {
	console.debug('[INTENT] Starting Calendar SMS injection…')

	const requestToken = getRequestToken()

	const styles = `
		.property-title-time-picker__time-pickers__inner > button,
		.property-repeat__summary,
		.property-select property-alarm-new,
		#attachments,
		.invitees-list-button-group,
		.app-full__header__details-calendar,
		.app-full-body__right > .property-select,
		.app-full-body__right > .property-select-multiple,
		.app-full-body__right > .property-color,
		.app-full-footer__right, .property-alarm-list,
		.app-full__header__top .app-full__actions__inner {
			display: none !important;
		}

		.v-popper__popper li.action {
			display: none !important;
		}


		.v-popper__popper li.action:has(button) {
			display: block !important;
		}

		.app-full-body {
			margin-top: 30px;
		}

		.event-popover__inner .calendar-picker-header__picker,
		.event-popover__inner .property-title,
		.event-popover__inner .property-title-time-picker,
		.event-popover__inner .app-full__header__details,
		.event-popover__inner .property-text,
		.event-popover__inner .button-vue--vue-primary,
		.event-popover__inner .dots-horizontal-icon,
		.event-popover__inner .invitees-list,
		.event-popover__inner .button-vue--icon-and-text,
		.event-popover__inner .action-item--tertiary {
			display: none !important;
		}

		.sms-notify-btn.button-vue {
			z-index: 100000;
			display: inline-flex; align-items: center; justify-content: center;
			gap: 8px; height: 36px; padding: 0 14px; border-radius: var(--border-radius, 10px);
			background-color: var(--color-primary-element-light, rgba(0,0,0,0.12));
			border: 1px solid transparent; color: var(--color-main-text);
			font: inherit; font-size: 14px; line-height: 1.2; cursor: pointer;
			transition: background-color .15s ease, transform .02s ease;
			pointer-events: auto !important;
		}
		.sms-notify-btn * { cursor: pointer; pointer-events: auto !important; }
		.sms-notify-btn:hover { background-color: var(--color-primary-element-light-hover, rgba(0,0,0,0.18)); }
		.sms-notify-btn:active { transform: translateY(1px); }
		.sms-notify-btn .button-vue__text { white-space: nowrap; }

		.sms-notify-btn.is-active {
			background-color: var(--color-primary-element, rgba(0,0,0,0.22));
			border-color: var(--color-border-dark, transparent);
		}

		.bankid-menu-item,
		.securemail-menu-item {
			margin-left: 5px;
			margin-top: 2px;
		}

		.bankid-menu-item button,
		.securemail-menu-item button {
			background: none !important;
			border: none !important;
			width: 100%;
			text-align: left;
			padding: 0;
			font: inherit;
			display: flex;
			align-items: center;
		}

		.bankid-menu-item button:hover,
		.securemail-menu-item button:hover {
			background-color: var(--color-background-hover, rgba(0, 0, 0, 0.05)) !important;
		}

		.bankid-menu-item .action-button__icon,
		.securemail-menu-item .action-button__icon {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			width: 34px;
			height: 34px;
			flex-shrink: 0;
			opacity: 0.7;
		}

		.bankid-menu-item .material-design-icon svg,
		.securemail-menu-item .material-design-icon svg {
			width: 16px;
			height: 16px;
		}

		.bankid-menu-item .action-button__text,
		.securemail-menu-item .action-button__text {
			flex: 1;
		}

		/* Custom SMS Modal using native dialog */
		.sms-modal-dialog {
			position: fixed;
			top: 50%;
			left: 50%;
			transform: translate(-50%, -50%);
			margin: 0;
			border: none;
			border-radius: var(--border-radius-large, 12px);
			padding: 0;
			max-width: 400px;
			width: 90%;
			box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
		}

		.sms-modal-dialog::backdrop {
			background-color: rgba(0, 0, 0, 0.5);
		}

		.sms-modal-container {
			background: var(--color-main-background, #fff);
			padding: 24px;
		}

		@keyframes slideIn {
			from { transform: translateY(-20px); opacity: 0; }
			to { transform: translateY(0); opacity: 1; }
		}

		.sms-modal-header {
			font-size: 18px;
			font-weight: 600;
			color: var(--color-main-text);
			margin-bottom: 16px;
		}

		.sms-modal-body {
			margin-bottom: 20px;
		}

		.sms-modal-label {
			display: block;
			font-size: 14px;
			color: var(--color-text-maxcontrast);
			margin-bottom: 8px;
		}

		.sms-modal-input,
		.ssn-input,
		.securemail-ssn-input,
		.securemail-mailbox-select {
			width: 100%;
			padding: 10px 12px;
			font-size: 14px;
			border: 2px solid var(--color-border-dark, #ddd);
			border-radius: var(--border-radius, 8px);
			background: var(--color-main-background, #fff);
			color: var(--color-main-text);
			box-sizing: border-box;
			transition: border-color 0.2s ease;
		}

		.sms-modal-input:focus,
		.ssn-input:focus,
		.securemail-ssn-input:focus,
		.securemail-mailbox-select:focus {
			outline: none;
			border-color: var(--color-primary-element, #0082c9);
		}

		.sms-modal-section {
			margin-top: 20px;
		}

		.sms-modal-checkboxes {
			display: flex;
			flex-direction: column;
			gap: 12px;
			margin-top: 8px;
		}

		.sms-modal-checkbox-item {
			display: flex;
			align-items: center;
			gap: 8px;
		}

		.sms-modal-checkbox-item input[type="checkbox"] {
			width: 18px;
			height: 18px;
			cursor: pointer;
		}

		.sms-modal-checkbox-item label {
			font-size: 14px;
			color: var(--color-main-text);
			cursor: pointer;
		}

		.sms-modal-footer {
			display: flex;
			gap: 12px;
			justify-content: flex-end;
		}

		.sms-modal-btn {
			padding: 10px 20px;
			font-size: 14px;
			font-weight: 500;
			border: none;
			border-radius: var(--border-radius, 8px);
			cursor: pointer;
			transition: all 0.2s ease;
		}

		.sms-modal-btn-cancel {
			background: var(--color-background-dark, #f0f0f0);
			color: var(--color-main-text);
		}

		.sms-modal-btn-cancel:hover {
			background: var(--color-background-darker, #e0e0e0);
		}

		.sms-modal-btn-confirm {
			background: var(--color-primary-element, #0082c9);
			color: white;
		}

		.sms-modal-btn-confirm:hover {
			background: var(--color-primary-element-hover, #006aa3);
		}

		.sms-modal-btn-confirm:disabled {
			opacity: 0.5;
			cursor: not-allowed;
		}

		#mailbox-select {
			height: 42px;
		}
	`

	const localSmsIntents = new Map() // email -> { phone, active }
	const localBankIdIntents = new Map() // email -> { requireBankId, ssn, visibility }
	const localSecureMailIntents = new Map() // email -> { inviteViaSecureMail }

	let currentModalId = null
	let userMailboxes = [] // { accountId, name, email }
	const shownBankIdModalFor = new Set() // Track which attendees we've shown the modal for
	let inviteesListObserver = null // MutationObserver for the invitees list
	let observedInviteesList = null // Track which DOM element we're currently observing
	function clearLocalState() {
		console.debug('[INTENT] Clearing local state - modal closed')
		localSmsIntents.clear()
		localBankIdIntents.clear()
		localSecureMailIntents.clear()
		shownBankIdModalFor.clear()

		// Disconnect the invitees list observer
		disconnectInviteesObserverIfExistis()
	}

	function trackModalLifecycle() {
		const modal = document.querySelector('.event-popover') || document.querySelector('.app-full')
		if (modal && !currentModalId) {
			// New modal opened
			currentModalId = modal.id || Math.random().toString(36)
			console.debug('[INTENT] Modal opened, tracking lifecycle')
		} else if (!modal && currentModalId) {
			// Modal closed
			console.debug('[INTENT] Modal closed, clearing local state')
			clearLocalState()
			currentModalId = null
		}
	}
	function addStyles() {
		const existing = document.getElementById('sms-injection-styles')
		if (existing) existing.remove()

		const style = document.createElement('style')
		style.id = 'sms-injection-styles'
		style.textContent = styles
		document.head.appendChild(style)
	}

	function notify(text, type = 'success') {
		if (window.OC?.Notification?.showTemporary) {
			window.OC.Notification.showTemporary(text, { type })
		}
	}

	// ============================
	// Fetch user mailboxes
	// ============================
	async function fetchUserMailboxes() {
		try {
			const response = await fetch('/apps/sdkmc/api/v2/sdkmc/user-mailboxes', {
				method: 'GET',
				headers: {
					requesttoken: requestToken || '',
				},
			})
			if (response.ok) {
				const mailboxes = await response.json()
				userMailboxes = Array.isArray(mailboxes) ? mailboxes : []
				console.debug('[INTENT] Fetched user mailboxes:', userMailboxes)
			} else {
				console.error('[INTENT] Failed to fetch user mailboxes:', response.status)
			}
		} catch (error) {
			console.error('[INTENT] Error fetching user mailboxes:', error)
		}
	}

	// ============================
	// SMS Intent API functions
	// ============================
	async function apiStoreIntent(email, name, phone) {
		const response = await fetch('/apps/sdkmc/api/v2/calendar/event-sms-intent', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: requestToken || window.OC?.requestToken,
			},
			body: JSON.stringify({
				attendee_email: email,
				attendee_name: name,
				phone_number: phone,
			}),
		})
		return response.json()
	}

	async function apiDeleteIntent(email) {
		const response = await fetch('/apps/sdkmc/api/v2/calendar/event-sms-intent/delete', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: requestToken || window.OC?.requestToken,
			},
			body: JSON.stringify({ attendee_email: email }),
		})
		return response.json()
	}

	// ============================
	// BankID Intent API functions
	// ============================
	async function apiStoreBankIdIntent(email, ssnNumber = null, visibilityOptions = {}) {
		const payload = {
			attendee_email: email,
		}

		if (ssnNumber) {
			payload.ssn_number = ssnNumber
		}

		// Add visibility options with defaults
		payload.show_first_name = visibilityOptions.showFirstName !== undefined ? visibilityOptions.showFirstName : true
		payload.show_last_name = visibilityOptions.showLastName !== undefined ? visibilityOptions.showLastName : true
		payload.show_ssn = visibilityOptions.showSsn !== undefined ? visibilityOptions.showSsn : true

		const response = await fetch('/apps/sdkmc/api/v2/calendar/event-bankid-intent', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: requestToken || '',
			},
			body: JSON.stringify(payload),
		})
		return response.json()
	}

	async function apiDeleteBankIdIntent(email) {
		const response = await fetch('/apps/sdkmc/api/v2/calendar/event-bankid-intent/delete', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: requestToken || '',
			},
			body: JSON.stringify({ attendee_email: email }),
		})
		return response.json()
	}

	// =====================================
	// Secure Mail Invitation API functions
	// =====================================
	async function apiStoreSecureMailIntent(email, ssn, accountId = null) {
		const payload = {
			attendee_email: email,
			ssn_number: ssn,
		}

		if (accountId !== null) {
			payload.account_id = accountId
		}

		const response = await fetch('/apps/sdkmc/api/v2/calendar/event-securemail-invite-intent', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: requestToken || '',
			},
			body: JSON.stringify(payload),
		})
		return response.json()
	}

	async function apiDeleteSecureMailIntent(email) {
		const response = await fetch('/apps/sdkmc/api/v2/calendar/event-securemail-invite-intent/delete', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: requestToken || '',
			},
			body: JSON.stringify({ attendee_email: email }),
		})
		return response.json()
	}

	// ============================
	// Custom SMS Modal using native dialog
	// ============================
	function openSmsPrompt(attendeeEmail, onOk) {
		// Create dialog element (native focus trap)
		const dialog = document.createElement('dialog')
		dialog.className = 'sms-modal-dialog'
		dialog.setAttribute('role', 'dialog')
		dialog.setAttribute('aria-modal', 'true')
		dialog.setAttribute('aria-labelledby', 'sms-modal-title')

		// Create modal container
		const modal = document.createElement('div')
		modal.className = 'sms-modal-container'

		// Header
		const header = document.createElement('div')
		header.className = 'sms-modal-header'
		header.id = 'sms-modal-title'
		header.textContent = t('sdkmc', 'Send SMS to {name}?').replace('{name}', attendeeEmail)

		// Body
		const body = document.createElement('div')
		body.className = 'sms-modal-body'

		const label = document.createElement('label')
		label.className = 'sms-modal-label'
		label.textContent = t('sdkmc', 'Number for notification')

		const input = document.createElement('input')
		input.type = 'tel'
		input.className = 'sms-modal-input'
		input.placeholder = '+46701234567'
		input.autocomplete = 'tel'

		body.appendChild(label)
		body.appendChild(input)

		// Footer
		const footer = document.createElement('div')
		footer.className = 'sms-modal-footer'

		const cancelBtn = document.createElement('button')
		cancelBtn.className = 'sms-modal-btn sms-modal-btn-cancel'
		cancelBtn.textContent = t('sdkmc', 'Cancel')
		cancelBtn.type = 'button'

		const confirmBtn = document.createElement('button')
		confirmBtn.className = 'sms-modal-btn sms-modal-btn-confirm'
		confirmBtn.textContent = t('sdkmc', 'Send')
		confirmBtn.type = 'submit'
		confirmBtn.autofocus = true

		footer.appendChild(cancelBtn)
		footer.appendChild(confirmBtn)

		// Assemble modal
		modal.appendChild(header)
		modal.appendChild(body)
		modal.appendChild(footer)
		dialog.appendChild(modal)

		// Close modal function
		function closeModal() {
			dialog.close()
			dialog.remove()
		}

		// Event handlers
		cancelBtn.addEventListener('click', (e) => {
			e.preventDefault()
			closeModal()
		})

		dialog.addEventListener('click', (e) => {
			// Close when clicking backdrop
			if (e.target === dialog) {
				closeModal()
			}
		})

		confirmBtn.addEventListener('click', (e) => {
			e.preventDefault()

			const value = input.value.trim()
			if (value) {
				onOk?.(value)
				closeModal()
			} else {
				notify(t('sdkmc', 'Please enter a valid phone number'), 'error')
				input.focus()
			}
		})

		// Keyboard support
		dialog.addEventListener('keydown', (e) => {
			if (e.key === 'Enter' && e.target === input) {
				e.preventDefault()
				confirmBtn.click()
			}
		})

		// Add to DOM
		document.body.appendChild(dialog)

		// Show modal using showModal() for native focus trap
		dialog.showModal()
	}

	// ============================
	// BankID modal with SSN and visibility options
	// ============================
	function openBankIdPrompt(attendeeEmail, onOk, existingSettings = null) {
		// Create dialog element (native focus trap)
		const dialog = document.createElement('dialog')
		dialog.className = 'sms-modal-dialog'
		dialog.setAttribute('role', 'dialog')
		dialog.setAttribute('aria-modal', 'true')
		dialog.setAttribute('aria-labelledby', 'bankid-modal-title')

		// Create modal container
		const modal = document.createElement('div')
		modal.className = 'sms-modal-container'

		// Header
		const header = document.createElement('div')
		header.className = 'sms-modal-header'
		header.id = 'bankid-modal-title'
		header.textContent = t('sdkmc', 'Configure Attendee: {email}').replace('{email}', attendeeEmail)

		// Body
		const body = document.createElement('div')
		body.className = 'sms-modal-body'

		// Main BankID Checkbox (at top)
		const mainCheckboxItem = document.createElement('div')
		mainCheckboxItem.className = 'sms-modal-checkbox-item'
		mainCheckboxItem.style.marginBottom = '20px'
		mainCheckboxItem.style.paddingBottom = '16px'
		mainCheckboxItem.style.borderBottom = '1px solid var(--color-border-dark, #ddd)'

		const mainCheckbox = document.createElement('input')
		mainCheckbox.type = 'checkbox'
		mainCheckbox.id = 'bankid-require-main'
		mainCheckbox.checked = existingSettings?.requireBankId !== undefined ? existingSettings.requireBankId : true

		const mainLabel = document.createElement('label')
		mainLabel.setAttribute('for', 'bankid-require-main')
		mainLabel.textContent = t('sdkmc', 'Require BankID authentication')
		mainLabel.style.fontWeight = '600'

		mainCheckboxItem.appendChild(mainCheckbox)
		mainCheckboxItem.appendChild(mainLabel)

		// Add helper text
		const helperText = document.createElement('div')
		helperText.style.fontSize = '13px'
		helperText.style.color = 'var(--color-text-maxcontrast)'
		helperText.style.marginTop = '8px'
		helperText.style.marginLeft = '26px'
		helperText.textContent = t('sdkmc', 'When enabled, this attendee must authenticate with BankID to access the calendar event conversation')
		mainCheckboxItem.appendChild(helperText)

		body.appendChild(mainCheckboxItem)

		// BankID Settings Section (initially visible if checkbox is checked)
		const settingsSection = document.createElement('div')
		settingsSection.className = 'sms-modal-section'
		settingsSection.style.marginTop = '0'

		// SSN Input (optional)
		const ssnLabel = document.createElement('label')
		ssnLabel.className = 'sms-modal-label'
		ssnLabel.textContent = t('sdkmc', 'Tie this invitation to specific SSN? (optional)')

		const ssnInput = document.createElement('input')
		ssnInput.type = 'text'
		ssnInput.className = 'ssn-input'
		ssnInput.placeholder = 'YYYYMMDD-XXXX'
		ssnInput.autocomplete = 'off'
		if (existingSettings?.ssn) {
			ssnInput.value = existingSettings.ssn
		}

		settingsSection.appendChild(ssnLabel)
		settingsSection.appendChild(ssnInput)

		// Visibility Section
		const visibilitySection = document.createElement('div')
		visibilitySection.className = 'sms-modal-section'

		const visibilityLabel = document.createElement('div')
		visibilityLabel.className = 'sms-modal-label'
		visibilityLabel.textContent = t('sdkmc', 'Guest name visibility in the chat')

		const checkboxesContainer = document.createElement('div')
		checkboxesContainer.className = 'sms-modal-checkboxes'

		// First Name Checkbox
		const firstNameItem = document.createElement('div')
		firstNameItem.className = 'sms-modal-checkbox-item'

		const firstNameCheckbox = document.createElement('input')
		firstNameCheckbox.type = 'checkbox'
		firstNameCheckbox.id = 'bankid-show-firstname'
		firstNameCheckbox.checked = existingSettings?.showFirstName !== undefined ? existingSettings.showFirstName : true

		const firstNameLabel = document.createElement('label')
		firstNameLabel.setAttribute('for', 'bankid-show-firstname')
		firstNameLabel.textContent = t('sdkmc', 'First name')

		firstNameItem.appendChild(firstNameCheckbox)
		firstNameItem.appendChild(firstNameLabel)

		// Last Name Checkbox
		const lastNameItem = document.createElement('div')
		lastNameItem.className = 'sms-modal-checkbox-item'

		const lastNameCheckbox = document.createElement('input')
		lastNameCheckbox.type = 'checkbox'
		lastNameCheckbox.id = 'bankid-show-lastname'
		lastNameCheckbox.checked = existingSettings?.showLastName !== undefined ? existingSettings.showLastName : true

		const lastNameLabel = document.createElement('label')
		lastNameLabel.setAttribute('for', 'bankid-show-lastname')
		lastNameLabel.textContent = t('sdkmc', 'Last name')

		lastNameItem.appendChild(lastNameCheckbox)
		lastNameItem.appendChild(lastNameLabel)

		// SSN Checkbox
		const ssnVisibilityItem = document.createElement('div')
		ssnVisibilityItem.className = 'sms-modal-checkbox-item'

		const ssnVisibilityCheckbox = document.createElement('input')
		ssnVisibilityCheckbox.type = 'checkbox'
		ssnVisibilityCheckbox.id = 'bankid-show-ssn'
		ssnVisibilityCheckbox.checked = existingSettings?.showSsn !== undefined ? existingSettings.showSsn : false

		const ssnVisibilityLabel = document.createElement('label')
		ssnVisibilityLabel.setAttribute('for', 'bankid-show-ssn')
		ssnVisibilityLabel.textContent = t('sdkmc', 'SSN')

		ssnVisibilityItem.appendChild(ssnVisibilityCheckbox)
		ssnVisibilityItem.appendChild(ssnVisibilityLabel)

		// Assemble checkboxes
		checkboxesContainer.appendChild(firstNameItem)
		checkboxesContainer.appendChild(lastNameItem)
		checkboxesContainer.appendChild(ssnVisibilityItem)

		visibilitySection.appendChild(visibilityLabel)
		visibilitySection.appendChild(checkboxesContainer)

		settingsSection.appendChild(visibilitySection)
		body.appendChild(settingsSection)

		// Function to toggle settings section based on main checkbox
		function updateSettingsState() {
			const isEnabled = mainCheckbox.checked
			ssnInput.disabled = !isEnabled
			firstNameCheckbox.disabled = !isEnabled
			lastNameCheckbox.disabled = !isEnabled
			ssnVisibilityCheckbox.disabled = !isEnabled

			// Visual feedback
			settingsSection.style.opacity = isEnabled ? '1' : '0.5'
			settingsSection.style.pointerEvents = isEnabled ? 'auto' : 'none'
		}

		// Initial state
		updateSettingsState()

		// Listen to checkbox changes
		mainCheckbox.addEventListener('change', updateSettingsState)

		// Footer
		const footer = document.createElement('div')
		footer.className = 'sms-modal-footer'

		const saveBtn = document.createElement('button')
		saveBtn.className = 'sms-modal-btn sms-modal-btn-confirm'
		saveBtn.textContent = t('sdkmc', 'Apply Settings')
		saveBtn.type = 'submit'
		saveBtn.autofocus = true

		footer.appendChild(saveBtn)

		// Assemble modal
		modal.appendChild(header)
		modal.appendChild(body)
		modal.appendChild(footer)
		dialog.appendChild(modal)

		// Close modal function
		function closeModal() {
			dialog.close()
			dialog.remove()
		}

		saveBtn.addEventListener('click', (e) => {
			e.preventDefault()

			const requireBankId = mainCheckbox.checked
			const ssnValue = ssnInput.value.trim()
			const visibilityOptions = {
				showFirstName: firstNameCheckbox.checked,
				showLastName: lastNameCheckbox.checked,
				showSsn: ssnVisibilityCheckbox.checked,
			}

			onOk?.(requireBankId, ssnValue || null, visibilityOptions)
			closeModal()
		})

		// Keyboard support
		dialog.addEventListener('keydown', (e) => {
			if (e.key === 'Enter' && (e.target === ssnInput || e.target.type === 'checkbox')) {
				e.preventDefault()
				saveBtn.click()
			}
		})

		// Add to DOM
		document.body.appendChild(dialog)

		// Show modal using showModal() for native focus trap
		dialog.showModal()
	}

	// ============================
	// SMS button
	// ============================
	function setButtonActive(btn, isActive, attendeeEmail) {
		const textEl = btn.querySelector('.button-vue__text')
		const normalizedEmail = attendeeEmail.toLowerCase()

		if (isActive) {
			btn.classList.add('is-active')
			if (textEl) textEl.textContent = t('sdkmc', 'Cancel SMS')
			btn.setAttribute('aria-pressed', 'true')
			localSmsIntents.set(normalizedEmail, { active: true })
		} else {
			btn.classList.remove('is-active')
			if (textEl) textEl.textContent = t('sdkmc', 'Notify via SMS')
			btn.setAttribute('aria-pressed', 'false')
			localSmsIntents.delete(normalizedEmail)
		}
	}

	function createSmsButton(attendeeEmail) {
		const button = document.createElement('button')
		button.type = 'button'
		button.className = 'button-vue button-vue--size-small button-vue--text-only button-vue--vue-secondary sms-notify-btn'
		button.setAttribute('aria-label', t('sdkmc', 'Notify via SMS'))

		const wrapper = document.createElement('span')
		wrapper.className = 'button-vue__wrapper'
		const textSpan = document.createElement('span')
		textSpan.className = 'button-vue__text'
		wrapper.appendChild(textSpan)
		button.appendChild(wrapper)

		// Check local state for this attendee
		const localIntent = localSmsIntents.get(attendeeEmail.toLowerCase())
		setButtonActive(button, localIntent?.active || false, attendeeEmail)

		button.addEventListener('click', async (e) => {
			e.preventDefault()
			e.stopPropagation()

			const isActive = button.classList.contains('is-active')

			if (isActive) {
				// Cancel intent
				try {
					const res = await apiDeleteIntent(attendeeEmail)
					if (res?.ok) {
						setButtonActive(button, false, attendeeEmail)
						notify(t('sdkmc', 'SMS notification cancelled'), 'success')
					} else {
						notify(t('sdkmc', 'Failed to cancel'), 'error')
					}
				} catch (error) {
					console.error('Failed to cancel SMS intent:', error)
					notify(t('sdkmc', 'Failed to cancel'), 'error')
				}
				return
			}

			// Create new intent
			openSmsPrompt(attendeeEmail, async (phone) => {
				try {
					const res = await apiStoreIntent(attendeeEmail, attendeeEmail, phone)
					if (res?.ok) {
						setButtonActive(button, true, attendeeEmail)
						notify(
							t('sdkmc', 'Will notify {email} via SMS after saving').replace('{email}', attendeeEmail),
							'success',
						)
					} else {
						notify(t('sdkmc', 'Failed to store SMS preference'), 'error')
					}
				} catch (error) {
					console.error('Failed to store SMS intent:', error)
					notify(t('sdkmc', 'Failed to store SMS preference'), 'error')
				}
			})
		})

		return button
	}

	// ============================
	// BankID menu item (modal version)
	// ============================
	function createBankIdMenuItem(attendeeEmail, isActive = false) {
		const listItem = document.createElement('li')
		listItem.className = 'action bankid-menu-item'
		listItem.setAttribute('role', 'presentation')
		listItem.setAttribute('data-bankid-email', attendeeEmail)

		const button = document.createElement('button')
		button.className = 'action-button'
		button.setAttribute('role', 'menuitem')
		button.type = 'button'

		const iconWrapper = document.createElement('span')
		iconWrapper.className = 'action-button__icon'
		iconWrapper.setAttribute('aria-hidden', 'true')

		// Use material design icon for BankID
		const iconSpan = document.createElement('span')
		iconSpan.className = 'material-design-icon icon-bankid icon icon-vue'
		iconSpan.innerHTML = '<svg fill="currentColor" width="20" height="20" viewBox="0 0 24 24"><path d="M12,1L3,5V11C3,16.55 6.84,21.74 12,23C17.16,21.74 21,16.55 21,11V5L12,1M12,5A3,3 0 0,1 15,8A3,3 0 0,1 12,11A3,3 0 0,1 9,8A3,3 0 0,1 12,5M17.13,17C15.92,18.85 14.11,20.24 12,20.92C9.89,20.24 8.08,18.85 6.87,17C6.53,16.5 6.24,16 6,15.47C6,13.82 8.71,12.47 12,12.47C15.29,12.47 18,13.79 18,15.47C17.76,16 17.47,16.5 17.13,17Z"></path></svg>'

		iconWrapper.appendChild(iconSpan)

		const text = document.createElement('span')
		text.className = 'action-button__text'
		text.textContent = t('sdkmc', 'BankID Settings')

		button.appendChild(iconWrapper)
		button.appendChild(text)
		listItem.appendChild(button)

		// Store active state
		listItem.dataset.isActive = isActive ? 'true' : 'false'

		button.addEventListener('click', async (e) => {
			e.preventDefault()
			e.stopPropagation()

			const normalizedEmail = attendeeEmail.toLowerCase()
			const existingSettings = localBankIdIntents.get(normalizedEmail)
			// Open modal to configure BankID
			openBankIdPrompt(attendeeEmail, async (requireBankId, ssn, visibilityOptions) => {
				try {
					if (requireBankId) {
						// Store BankID requirement
						const res = await apiStoreBankIdIntent(attendeeEmail, ssn, visibilityOptions)
						if (res?.ok) {
							localBankIdIntents.set(normalizedEmail, {
								requireBankId: true,
								ssn,
								...visibilityOptions,
							})
							listItem.dataset.isActive = 'true'
							notify(
								t('sdkmc', 'Will require BankID for {email} after saving').replace('{email}', attendeeEmail),
								'success',
							)
						} else {
							notify(t('sdkmc', 'Failed to store BankID requirement'), 'error')
						}
					} else {
						const settingsBeforeModalLoad = localBankIdIntents.get(normalizedEmail)
						if (settingsBeforeModalLoad?.requireBankId) { // if item exised and was true
							const res = await apiDeleteBankIdIntent(attendeeEmail)
							if (res?.ok) {
								localBankIdIntents.set(normalizedEmail, {
									requireBankId: false,
									ssn,
									...visibilityOptions,
								})
								listItem.dataset.isActive = 'false'
								notify(
									t('sdkmc', 'BankID requirement removed for {email}').replace('{email}', attendeeEmail),
									'success',
								)
							} else {
								notify(t('sdkmc', 'Failed to remove BankID requirement'), 'error')
							}
						} else {
							localBankIdIntents.set(normalizedEmail, {
								requireBankId: false,
								ssn,
								...visibilityOptions,
							})
						}
						// Remove BankID requirement

					}
				} catch (error) {
					console.error('Failed to update BankID intent:', error)
					notify(t('sdkmc', 'Failed to update BankID requirement'), 'error')
				}
			}, existingSettings)
		})

		return listItem
	}

	// ============================
	// Secure Mail modal with SSN input
	// ============================
	function openSecureMailPrompt(attendeeEmail, onOk) {
		// Create dialog element (native focus trap)
		const dialog = document.createElement('dialog')
		dialog.className = 'sms-modal-dialog'
		dialog.setAttribute('role', 'dialog')
		dialog.setAttribute('aria-modal', 'true')
		dialog.setAttribute('aria-labelledby', 'securemail-modal-title')

		// Create modal container
		const modal = document.createElement('div')
		modal.className = 'sms-modal-container'

		// Header
		const header = document.createElement('div')
		header.className = 'sms-modal-header'
		header.id = 'securemail-modal-title'
		header.textContent = t('sdkmc', 'Invite {name} via Securemail?').replace('{name}', attendeeEmail)

		// Body
		const body = document.createElement('div')
		body.className = 'sms-modal-body'

		// SSN Input
		const ssnLabel = document.createElement('label')
		ssnLabel.className = 'sms-modal-label'
		ssnLabel.textContent = t('sdkmc', 'SSN (Social Security Number)')

		const ssnInput = document.createElement('input')
		ssnInput.type = 'text'
		ssnInput.className = 'securemail-ssn-input'
		ssnInput.placeholder = 'YYYYMMDD-XXXX'
		ssnInput.autocomplete = 'off'

		body.appendChild(ssnLabel)
		body.appendChild(ssnInput)

		// Mailbox Selection (From address)
		if (userMailboxes.length > 0) {
			const mailboxLabel = document.createElement('label')
			mailboxLabel.className = 'sms-modal-label'
			mailboxLabel.style.marginTop = '16px'
			mailboxLabel.style.display = 'block'
			mailboxLabel.textContent = t('sdkmc', 'Send invitation from')

			const mailboxSelect = document.createElement('select')
			mailboxSelect.className = 'securemail-mailbox-select'
			mailboxSelect.id = 'mailbox-select'

			userMailboxes.forEach(mailbox => {
				const option = document.createElement('option')
				option.value = mailbox.accountId
				option.textContent = `${mailbox.name} (${mailbox.email})`
				mailboxSelect.appendChild(option)
			})

			body.appendChild(mailboxLabel)
			body.appendChild(mailboxSelect)
		}

		// Footer
		const footer = document.createElement('div')
		footer.className = 'sms-modal-footer'

		const cancelBtn = document.createElement('button')
		cancelBtn.className = 'sms-modal-btn sms-modal-btn-cancel'
		cancelBtn.textContent = t('sdkmc', 'Cancel')
		cancelBtn.type = 'button'

		const confirmBtn = document.createElement('button')
		confirmBtn.className = 'sms-modal-btn sms-modal-btn-confirm'
		confirmBtn.textContent = t('sdkmc', 'Confirm')
		confirmBtn.type = 'submit'
		confirmBtn.autofocus = true

		footer.appendChild(cancelBtn)
		footer.appendChild(confirmBtn)

		// Assemble modal
		modal.appendChild(header)
		modal.appendChild(body)
		modal.appendChild(footer)
		dialog.appendChild(modal)

		// Close modal function
		function closeModal() {
			dialog.close()
			dialog.remove()
		}

		// Event handlers
		cancelBtn.addEventListener('click', (e) => {
			e.preventDefault()
			closeModal()
		})

		dialog.addEventListener('click', (e) => {
			// Close when clicking backdrop
			if (e.target === dialog) {
				closeModal()
			}
		})

		confirmBtn.addEventListener('click', (e) => {
			e.preventDefault()

			const ssnValue = ssnInput.value.trim()
			if (ssnValue) {
				let accountId = null
				const mailboxSelect = dialog.querySelector('#mailbox-select')
				if (mailboxSelect) {
					accountId = parseInt(mailboxSelect.value, 10)
				}
				onOk?.(ssnValue, accountId)
				closeModal()
			} else {
				notify(t('sdkmc', 'Please enter a valid SSN'), 'error')
				ssnInput.focus()
			}
		})

		// Keyboard support
		dialog.addEventListener('keydown', (e) => {
			if (e.key === 'Enter' && (e.target === ssnInput || e.target.id === 'mailbox-select')) {
				e.preventDefault()
				confirmBtn.click()
			}
		})

		// Add to DOM
		document.body.appendChild(dialog)

		// Show modal using showModal() for native focus trap
		dialog.showModal()
	}

	// ============================
	// Secure Mail menu item (matching Remove attendee structure)
	// ============================
	function createSecureMailMenuItem(attendeeEmail, isActive = false) {
		const listItem = document.createElement('li')
		listItem.className = 'action securemail-menu-item'
		listItem.setAttribute('role', 'presentation')
		listItem.setAttribute('data-securemail-email', attendeeEmail)

		const button = document.createElement('button')
		button.className = 'action-button'
		button.setAttribute('role', 'menuitem')
		button.type = 'button'

		const iconWrapper = document.createElement('span')
		iconWrapper.className = 'action-button__icon'
		iconWrapper.setAttribute('aria-hidden', 'true')

		// Use material design icon for lock
		const iconSpan = document.createElement('span')
		iconSpan.className = 'material-design-icon icon-lock-open icon icon-vue'
		iconSpan.innerHTML = '<svg fill="currentColor" width="20" height="20" viewBox="0 0 24 24"><path d="M18,8A2,2 0 0,1 20,10V20A2,2 0 0,1 18,22H6C4.89,22 4,21.1 4,20V10A2,2 0 0,1 6,8H15V6A3,3 0 0,0 12,3A3,3 0 0,0 9,6H7A5,5 0 0,1 12,1A5,5 0 0,1 17,6V8H18M12,17A2,2 0 0,0 14,15A2,2 0 0,0 12,13A2,2 0 0,0 10,15A2,2 0 0,0 12,17Z"></path></svg>'

		iconWrapper.appendChild(iconSpan)

		const text = document.createElement('span')
		text.className = 'action-button__text'

		// Set text based on active state
		if (isActive) {
			text.textContent = t('sdkmc', 'Cancel Securemail')
		} else {
			text.textContent = t('sdkmc', 'Invite via Securemail')
		}

		button.appendChild(iconWrapper)
		button.appendChild(text)
		listItem.appendChild(button)

		// Store active state
		listItem.dataset.isActive = isActive ? 'true' : 'false'

		button.addEventListener('click', async (e) => {
			e.preventDefault()
			e.stopPropagation()

			const currentlyActive = listItem.dataset.isActive === 'true'
			const normalizedEmail = attendeeEmail.toLowerCase()

			if (currentlyActive) {
				// Cancel Securemail invitation
				try {
					const res = await apiDeleteSecureMailIntent(attendeeEmail)
					if (res?.ok) {
						localSecureMailIntents.delete(normalizedEmail)
						listItem.dataset.isActive = 'false'
						text.textContent = t('sdkmc', 'Invite via Securemail')
						notify(
							t('sdkmc', 'Securemail invitation cancelled for {email}').replace('{email}', attendeeEmail),
							'success',
						)
					} else {
						notify(t('sdkmc', 'Failed to cancel Securemail invitation'), 'error')
					}
				} catch (error) {
					console.error('Failed to cancel Securemail intent:', error)
					notify(t('sdkmc', 'Failed to cancel Securemail invitation'), 'error')
				}
			} else {
				// Open modal to get SSN and mailbox selection
				openSecureMailPrompt(attendeeEmail, async (ssn, accountId) => {
					try {
						const res = await apiStoreSecureMailIntent(attendeeEmail, ssn, accountId)
						if (res?.ok) {
							localSecureMailIntents.set(normalizedEmail, { inviteViaSecureMail: true, ssn, accountId })
							listItem.dataset.isActive = 'true'
							text.textContent = t('sdkmc', 'Cancel Securemail')
							notify(
								t('sdkmc', 'Will invite {email} via Securemail after saving').replace('{email}', attendeeEmail),
								'success',
							)
						} else {
							notify(t('sdkmc', 'Failed to store Securemail preference'), 'error')
						}
					} catch (error) {
						console.error('Failed to store Securemail intent:', error)
						notify(t('sdkmc', 'Failed to store Securemail preference'), 'error')
					}
				})
			}
		})

		return listItem
	}

	// Helper function to determine if an attendee is external
	function isExternalAttendee(email) {
		const input = document.createElement('input')
		input.type = 'email'
		input.value = email
		return input.checkValidity()
	}

	function getAttendeeEmailFromContext(menu) {
		// Check for data attribute first
		const attendeeEmail = menu.closest('.v-popper__popper')?.getAttribute('data-attendee-email')
		if (attendeeEmail) return attendeeEmail

		// Fallback: try to find from DOM context
		const attendeeItem = document.querySelector('.invitees-list-item:hover, .invitees-list-item.active')
		if (attendeeItem) {
			const displayNameDiv = attendeeItem.querySelector('.invitees-list-item__displayname')
			const email = displayNameDiv?.textContent?.trim()
			if (email) return email
		}

		return null
	}

	function injectBankIdMenuItems() {
		const openMenus = document.querySelectorAll('.v-popper__popper--shown ul[role="menu"]')

		openMenus.forEach(menu => {
			if (menu.querySelector('.bankid-menu-item')) return

			const attendeeEmail = getAttendeeEmailFromContext(menu)
			if (!attendeeEmail) return

			// Only inject for external attendees
			if (!isExternalAttendee(attendeeEmail)) return

			const normalizedEmail = attendeeEmail.toLowerCase()
			const localIntent = localBankIdIntents.get(normalizedEmail)
			const isActive = localIntent?.requireBankId || false

			const bankIdItem = createBankIdMenuItem(attendeeEmail, isActive)
			menu.appendChild(bankIdItem)
		})
	}

	function injectSecureMailInvitationItems() {
		const openMenus = document.querySelectorAll('.v-popper__popper--shown ul[role="menu"]')
		openMenus.forEach(menu => {
			if (menu.querySelector('.securemail-menu-item')) return

			const attendeeEmail = getAttendeeEmailFromContext(menu)
			if (!attendeeEmail) return

			// Only inject for external attendees
			if (!isExternalAttendee(attendeeEmail)) return

			const normalizedEmail = attendeeEmail.toLowerCase()
			const localIntent = localSecureMailIntents.get(normalizedEmail)
			const isActive = localIntent?.inviteViaSecureMail || false

			const secureMailItem = createSecureMailMenuItem(attendeeEmail, isActive)
			menu.appendChild(secureMailItem)
		})
	}

	function injectSmsButtons() {
		const attendeeItems = document.querySelectorAll('.invitees-list-item')

		attendeeItems.forEach((item, index) => {
			// skip first item (the organizer)
			if (index === 0) return
			if (item.querySelector('.sms-notify-btn')) return

			const displayNameDiv = item.querySelector('.invitees-list-item__displayname')
			if (!displayNameDiv) return

			const attendeeEmail = displayNameDiv.textContent?.trim()
			if (!attendeeEmail) return

			// Only inject for external attendees
			if (!isExternalAttendee(attendeeEmail)) return

			const smsButton = createSmsButton(attendeeEmail)
			const actionsContainer = item.querySelector('.invitees-list-item__actions')
			if (!actionsContainer) return

			actionsContainer.insertAdjacentElement('afterbegin', smsButton)
		})
	}

	// ============================
	// Detect new attendees and auto-show BankID modal
	// ============================
	function detectAndHandleNewAttendees() {
		// Check if we're in edit mode (not readonly/preview mode)
		const modal = document.querySelector('.event-popover') || document.querySelector('.app-full')
		if (!modal) {
			// No modal found, disconnect observer if it exists
			disconnectInviteesObserverIfExistis()
			return
		}

		// If the modal has readonly classes, it's a preview - don't show BankID modal
		const isReadonly = modal.querySelector('.property-title--readonly, .property-title-time-picker--readonly')
		if (isReadonly) {
			disconnectInviteesObserverIfExistis()
			return
		}
		// nor if it doesn't have search box
		const hasInviteesSearch = modal.querySelector('.invitees-search__vselect')
		if (!hasInviteesSearch) {
			disconnectInviteesObserverIfExistis()
			return
		}

		// Find the invitees list element (works for both popover and full-screen)
		const inviteesList = modal.querySelector('.invitees-list')
		if (!inviteesList) return

		// Check if we're already observing this exact list element
		if (inviteesListObserver && observedInviteesList === inviteesList) {
			return
		}

		// If we have an observer but it's watching a different (or detached) element, disconnect it
		if (inviteesListObserver && observedInviteesList !== inviteesList) {
			console.debug('[INTENT] Detected new invitees list, recreating observer')
			disconnectInviteesObserverIfExistis()
		}

		console.debug('[INTENT] Setting up invitees list observer')

		// Create a MutationObserver to watch for new attendee items
		inviteesListObserver = new MutationObserver((mutations) => {
			mutations.forEach((mutation) => {
				// Check for added nodes
				mutation.addedNodes.forEach((node) => {
					console.warn('MUTATION detected ', node.nodeType === Node.ELEMENT_NODE, node.classList?.contains('invitees-list-item'))
					// Only process element nodes with the invitees-list-item class
					if (node.nodeType === Node.ELEMENT_NODE && node.classList?.contains('invitees-list-item')) {
						handleNewAttendeeItem(node)
					}
				})
			})
		})

		// Start observing the invitees list
		inviteesListObserver.observe(inviteesList, {
			childList: true,
			subtree: false,
		})

		// Store reference to the list we're observing
		observedInviteesList = inviteesList

		console.debug('[INTENT] Invitees list observer active')
	}

	function handleNewAttendeeItem(item) {
		const displayNameDiv = item.querySelector('.invitees-list-item__displayname')
		if (!displayNameDiv) return

		const attendeeEmail = displayNameDiv.textContent?.trim()
		if (!attendeeEmail) return

		// Skip organizer (check for organizer hint)
		const isOrganizer = item.querySelector('.invitees-list-item__organizer-hint')
		if (isOrganizer) return

		// Only handle external attendees
		if (!isExternalAttendee(attendeeEmail)) return

		const normalizedEmail = attendeeEmail.toLowerCase()

		// Check if this is a new attendee we haven't shown the modal for
		if (!shownBankIdModalFor.has(normalizedEmail)) {
			console.debug('[INTENT] New attendee detected:', attendeeEmail)

			// Mark as shown to avoid showing multiple times
			shownBankIdModalFor.add(normalizedEmail)

			// Show BankID modal after a short delay to ensure DOM is ready
			setTimeout(() => {
				const existingSettings = localBankIdIntents.get(normalizedEmail)
				openBankIdPrompt(attendeeEmail, async (requireBankId, ssn, visibilityOptions) => {
					try {
						if (requireBankId) {
							// Store BankID requirement
							const res = await apiStoreBankIdIntent(attendeeEmail, ssn, visibilityOptions)
							if (res?.ok) {
								localBankIdIntents.set(normalizedEmail, {
									requireBankId: true,
									ssn,
									...visibilityOptions,
								})
								notify(
									t('sdkmc', 'Will require BankID for {email} after saving').replace('{email}', attendeeEmail),
									'success',
								)
							} else {
								notify(t('sdkmc', 'Failed to store BankID requirement'), 'error')
							}
						} else {
							localBankIdIntents.set(normalizedEmail, {
								requireBankId: false,
								ssn,
								...visibilityOptions,
							})
						}
					} catch (error) {
						console.error('Failed to update BankID intent:', error)
						notify(t('sdkmc', 'Failed to update BankID requirement'), 'error')
					}
				}, existingSettings)
			}, 100)
		}
	}

	function handleMenuClick() {
		// delay to allow menu to render
		setTimeout(() => {
			injectBankIdMenuItems()
			injectSecureMailInvitationItems()
		}, 50)
	}

	// ============================
	// Location field and Save/Update button control
	// ============================
	function setupLocationAndSaveButtonControl() {
		console.debug('[INTENT] Setting up location field monitoring and Save/Update button control')

		// Find the modal (full view)
		const modal = document.querySelector('.app-full') || document.querySelector('.event-popover')
		if (!modal) {
			console.debug('[INTENT] Modal not found for location monitoring')
			return
		}

		// Check if already set up to prevent duplicate listeners
		if (modal.dataset.locationListenerSetup === 'true') {
			console.debug('[INTENT] Location monitoring already active')
			return
		}

		// Find the Save/Update button using the save-buttons class and primary button with check icon
		const saveButtonsContainer = modal.querySelector('.save-buttons')
		if (!saveButtonsContainer) {
			console.debug('[INTENT] Save buttons container not found')
			return
		}

		const saveUpdateButton = saveButtonsContainer.querySelector('button.button-vue--vue-primary .check-icon')?.closest('button')
		if (!saveUpdateButton) {
			console.debug('[INTENT] Save/Update button not found')
			return
		}

		// Mark as set up
		modal.dataset.locationListenerSetup = 'true'

		console.debug('[INTENT] Found Save/Update button')

		// Track previous state to prevent infinite loop
		let previousButtonState = null

		// Function to get location value from the modal
		function getLocationValue() {
			// Try to find location input or button
			const locationInput = modal.querySelector('input[placeholder*="ocation"]')
			                      || modal.querySelector('input[placeholder*="location"]')
			                      || modal.querySelector('input[placeholder*="Location"]')

			if (locationInput) {
				return locationInput.value?.trim() || ''
			}

			// Check for location property button/field
			const locationProperty = modal.querySelector('[class*="property-location"]')
			if (locationProperty) {
				const locationText = locationProperty.querySelector('input')?.value?.trim()
				                     || locationProperty.textContent?.trim() || ''
				return locationText
			}

			return ''
		}

		// Function to check if location button/field is disabled
		function isLocationDisabled() {
			const locationInput = modal.querySelector('input[placeholder*="ocation"]')
			                      || modal.querySelector('input[placeholder*="location"]')
			                      || modal.querySelector('input[placeholder*="Location"]')

			if (locationInput) {
				return locationInput.disabled || locationInput.readOnly
			}

			// Check for disabled location property
			const locationProperty = modal.querySelector('[class*="property-location"]')
			if (locationProperty) {
				const btn = locationProperty.querySelector('button')
				return btn?.disabled || false
			}

			return false
		}

		// Function to update Save/Update button state based on location
		function updateSaveButtonState() {
			const locationValue = getLocationValue()
			const locationDisabled = isLocationDisabled()

			let shouldBeDisabled = false
			if (locationDisabled) {
				shouldBeDisabled = false
			} else {
				if (locationValue === '') {
					shouldBeDisabled = true
				} else {
					shouldBeDisabled = false
				}
			}

			// Only update if state has changed
			if (previousButtonState === shouldBeDisabled) {
				return
			}

			previousButtonState = shouldBeDisabled

			console.debug('[INTENT] Location value:', locationValue)
			console.debug('[INTENT] Location disabled:', locationDisabled)

			// Apply the state change
			if (shouldBeDisabled) {
				saveUpdateButton.disabled = true
				saveUpdateButton.style.opacity = '0.5'
				saveUpdateButton.style.cursor = 'not-allowed'
				console.debug('[INTENT] Save/Update button DISABLED (no location value)')
			} else {
				saveUpdateButton.disabled = false
				saveUpdateButton.style.opacity = ''
				saveUpdateButton.style.cursor = ''
				console.debug('[INTENT] Save/Update button ENABLED')
			}
		}

		// Initial check
		updateSaveButtonState()

		// watch for changes to location field
		const locationInput = modal.querySelector('input[placeholder*="ocation"]')
		                      || modal.querySelector('input[placeholder*="location"]')
		                      || modal.querySelector('input[placeholder*="Location"]')

		if (locationInput) {
			locationInput.addEventListener('input', updateSaveButtonState)
			locationInput.addEventListener('change', updateSaveButtonState)
			locationInput.addEventListener('blur', updateSaveButtonState)
		}

		// watch for DOM changes in the modal (for dynamic updates)
		const locationObserver = new MutationObserver(() => {
			updateSaveButtonState()
		})

		locationObserver.observe(modal, {
			childList: true,
			subtree: true,
			characterData: true,
			attributes: true,
			attributeFilter: ['disabled', 'readonly', 'value'],
		})
	}

	function addEventListeners() {
		function expandEventPopoverToFullView() {
			const popoverButtons = document.querySelector('.event-popover__buttons')
			if (popoverButtons) {
				const secondaryButton = popoverButtons.querySelector('.button-vue--vue-secondary')
				if (secondaryButton) {
					console.debug('[INTENT] Found secondary button, simulating click to expand to full view')
					secondaryButton.click()

					// After expanding to full view, setup location field monitoring and button controls
					setTimeout(() => {
						setupLocationAndSaveButtonControl()
					}, 300)

					return true
				}
			}

			return false
		}

		const popoverObserver = new MutationObserver((mutations) => {
			for (const mutation of mutations) {
				// Handle newly added nodes
				if (mutation.type === 'childList') {
					for (const node of mutation.addedNodes) {
						if (node.nodeType === 1) {
							// Check immediately if it has the class
							if (node.classList?.contains('v-popper--some-open')) {
								setTimeout(expandEventPopoverToFullView, 50)
								setTimeout(expandEventPopoverToFullView, 50) // double call because of Event edit modal needs 2 clicks
								return
							}
						}
					}
				}

				// Handle class being added to existing elements
				if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
					const target = mutation.target
					if (target.classList?.contains('v-popper--some-open')) {
						setTimeout(expandEventPopoverToFullView, 50)
						setTimeout(expandEventPopoverToFullView, 50) // double call because of Event edit modal needs 2 clicks
						return
					}
				}
			}
		})

		// Observe only direct children of body - much cheaper!
		popoverObserver.observe(document.body, {
			childList: true,
			subtree: false,
			attributes: true,
			attributeFilter: ['class'],
		})

		document.addEventListener('click', (e) => {
			if (e.target.closest('.action-item__menutoggle, .three-dot-menu, [role="button"]')) {
				handleMenuClick()
			}
		})
	}

	function disconnectInviteesObserverIfExistis() {
		if (inviteesListObserver) {
			inviteesListObserver.disconnect()
			inviteesListObserver = null
			observedInviteesList = null
		}
	}

	// config
	const TARGETS = '.invitees-list, .invitees-list-item, .event-popover__inner, .v-popper__popper'
	const DEBOUNCE_MS = 150

	let observer
	const debounce = (fn, ms) => {
		let timeoutId
		return (...args) => {
			clearTimeout(timeoutId)
			timeoutId = setTimeout(() => fn(...args), ms)
		}
	}

	const reinject = debounce(() => {
		trackModalLifecycle()
		if (document.querySelector(TARGETS)) {
			injectSmsButtons()
			injectBankIdMenuItems()
			injectSecureMailInvitationItems()
			detectAndHandleNewAttendees()
		}
	}, DEBOUNCE_MS)

	function initialize() {
		console.debug('INITIALIZE')
		addStyles()
		addEventListeners()
		injectSmsButtons()

		// Fetch user mailboxes for securemail invitation
		fetchUserMailboxes()

		if (observer) observer.disconnect()
		observer = new MutationObserver(reinject)
		observer.observe(document.body, { childList: true, subtree: true })

		console.info('[INTENT] Calendar SMS + BankID + Securemail injection ready')
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initialize, { once: true })
	} else {
		initialize()
	}
})()
