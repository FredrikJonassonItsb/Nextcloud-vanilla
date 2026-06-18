/**
 * Hubs Start — channel display map (CLIENT-SIDE PRESENTATION ONLY).
 *
 * IMPORTANT: this module does NOT classify recipients. The authoritative channel
 * classification (the @sdk / @personlig / @gruppbox / @fax / @sms / .securemail
 * suffix logic) lives server-side in sdkmc's ChannelClassificationService and is
 * returned to the client already resolved (see api.searchRecipients/classifyRecipient
 * and Summary.items[].channel). This file only maps a resolved channel id to its
 * Academy-correct Swedish label, icon and colour token, so every component renders
 * channels identically.
 *
 * Terminology is locked to Hubs Akademi — do NOT invent new wording.
 */

import { translate as t } from '@nextcloud/l10n'

import IconSdk from 'vue-material-design-icons/Bank.vue'
import IconInternal from 'vue-material-design-icons/Forum.vue'
import IconSecure from 'vue-material-design-icons/MessageTextLock.vue'
import IconFax from 'vue-material-design-icons/Fax.vue'
import IconSms from 'vue-material-design-icons/CellphoneMessage.vue'
import IconUnknown from 'vue-material-design-icons/HelpCircleOutline.vue'

/**
 * Canonical channel ids. These match the `channel` field returned by the server.
 * @enum {string}
 */
export const CHANNELS = {
	SDK: 'sdk',
	INTERNAL: 'internal',
	SECURE: 'secure',
	FAX: 'fax',
	SMS: 'sms',
	UNKNOWN: 'unknown',
}

/** Map server channel id → mail message-type id (for the composer deep-link). */
export const CHANNEL_TO_MESSAGE_TYPE = {
	sdk: 'sdk_message',
	internal: 'internal_message',
	secure: 'secure_email',
	fax: 'fax_message',
	sms: 'sms_message',
}

/**
 * Presentation descriptor for a channel. Labels use Academy terminology.
 * @param {string} channel one of CHANNELS
 * @return {{ id: string, label: string, icon: object, colorVar: string }}
 */
export function channelMeta(channel) {
	switch (channel) {
	case CHANNELS.SDK:
		return { id: channel, label: t('hubs_start', 'SDK-Meddelande'), icon: IconSdk, colorVar: '--hs-channel-sdk' }
	case CHANNELS.INTERNAL:
		return { id: channel, label: t('hubs_start', 'Internpost'), icon: IconInternal, colorVar: '--hs-channel-internal' }
	case CHANNELS.SECURE:
		return { id: channel, label: t('hubs_start', 'Säker E-post'), icon: IconSecure, colorVar: '--hs-channel-secure' }
	case CHANNELS.FAX:
		return { id: channel, label: t('hubs_start', 'Fax'), icon: IconFax, colorVar: '--hs-channel-fax' }
	case CHANNELS.SMS:
		return { id: channel, label: t('hubs_start', 'SMS'), icon: IconSms, colorVar: '--hs-channel-sms' }
	default:
		return { id: CHANNELS.UNKNOWN, label: t('hubs_start', 'Okänd kanal'), icon: IconUnknown, colorVar: '--hs-channel-unknown' }
	}
}

/** All channels in canonical display order (used for filter tabs). */
export const CHANNEL_ORDER = [CHANNELS.SDK, CHANNELS.SECURE, CHANNELS.INTERNAL, CHANNELS.FAX, CHANNELS.SMS]

export default { CHANNELS, CHANNEL_TO_MESSAGE_TYPE, CHANNEL_ORDER, channelMeta }
