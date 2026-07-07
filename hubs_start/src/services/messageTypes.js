/**
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Hubs Start — KANON-MAPPEN för meddelandetyper (innehållsvokabulären).
 *
 * Detta är den ENDA källan för svensk etikett + ikon per messageType.
 * Komponenter (KorgValjare, InflodeRad, EjKoppladRad, AttTaEmotRad, …) ska
 * importera härifrån — aldrig hålla egna lokala typ-mappar. Granskningen
 * 2026-07 hittade divergens (sdk_myndighet hette "Myndighetspost" på ett
 * ställe och "SDK-myndighet" på ett annat); KorgValjares varianter är kanon.
 *
 * Vokabulären är INNEHÅLLS-typer (ärendevokabulär), inte kanal-transporttyper
 * (internal_message/fax_message/… — de bor i channels.js). Lägg aldrig till
 * transporttyper här.
 *
 * Kontrakt: en okänd/tom typ ger null — ALDRIG ett eko av rå maskin-id i UI.
 * Anroparen väljer själv fallback (t.ex. kanal-etiketten eller "Okänd typ").
 */

import { translate as t } from '@nextcloud/l10n'

/**
 * De kända innehållstyperna (unionen av korg-vokabulären + sdkmc:s
 * ämnes-brygge-typer). En rad räknas som klassad ENBART när messageType
 * är en av dessa.
 */
export const KANDA_TYPER = new Set([
	'orosanmalan',
	'komplettering',
	'fraga',
	'remiss',
	'internpost',
	'fax',
	'sdk_myndighet',
	'skrap',
	// Innehållstyper som sdkmc:s ämnes-brygga (#19) kan härleda utöver korg-vokabulären.
	'bistandsansokan',
	'samverkan',
])

/**
 * Svensk etikett för en meddelandetyp.
 *
 * @param {?string} messageType maskin-id (t.ex. 'sdk_myndighet')
 * @return {?string} kanonisk svensk etikett, eller null för okänd/tom typ
 *                   (aldrig eko av maskin-id:t)
 */
export function typLabel(messageType) {
	switch (messageType) {
	case 'orosanmalan':
		return t('hubs_start', 'Orosanmälan')
	case 'komplettering':
		return t('hubs_start', 'Komplettering')
	case 'fraga':
		return t('hubs_start', 'Fråga')
	case 'remiss':
		return t('hubs_start', 'Remiss')
	case 'internpost':
		return t('hubs_start', 'Internpost')
	case 'fax':
		return t('hubs_start', 'Fax')
	case 'sdk_myndighet':
		return t('hubs_start', 'SDK-myndighet')
	case 'skrap':
		return t('hubs_start', 'Skräp')
	case 'bistandsansokan':
		return t('hubs_start', 'Ansökan om bistånd')
	case 'samverkan':
		return t('hubs_start', 'Samverkan')
	default:
		return null
	}
}

/**
 * Ikonnamn (vue-material-design-icons, samma namn som KorgValjares typer-lista)
 * för en meddelandetyp. Anroparen resolvar namnet till komponent (t.ex. via
 * services/icons.js eller lokal registrering).
 *
 * @param {?string} messageType maskin-id
 * @return {?string} ikonnamn-sträng, eller null för okänd/tom typ
 */
export function typIkon(messageType) {
	switch (messageType) {
	case 'orosanmalan':
		return 'AlertOctagonIcon'
	case 'komplettering':
		return 'FileDocumentPlusIcon'
	case 'fraga':
		return 'HelpCircleOutlineIcon'
	case 'remiss':
		return 'FileSendIcon'
	case 'internpost':
		return 'ForumIcon'
	case 'fax':
		return 'FaxIcon'
	case 'sdk_myndighet':
		return 'BankIcon'
	case 'skrap':
		return 'TrashCanOutlineIcon'
	case 'bistandsansokan':
		return 'CashMultipleIcon'
	case 'samverkan':
		return 'AccountGroupIcon'
	default:
		return null
	}
}

export default { KANDA_TYPER, typLabel, typIkon }
