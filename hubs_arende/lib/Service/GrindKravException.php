<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Service;

/**
 * Typad grind-kravsignal: en utredningskedje-grind (A7/A9) föll och övergången
 * nekades därför att ett obligatoriskt grind-val saknas medan flaggan är på.
 *
 * Bär MASKINLÄSBART vilken grind som saknar sitt underlag så
 * {@see \OCA\HubsArende\Controller\ArendeController::steg()} kan svara
 * {grindKravs:true, grind:<nyckel>} och frontenden öppna RÄTT dialog — i stället
 * för att gissa via textmatchning på felmeddelandet. Det löser tvetydigheten på
 * kanten forhandsbedomning→utredning som bär TVÅ grindar (A7 skyddsbedomning +
 * A9a inleda): utan nyckeln öppnade GUI:t skyddsbedömnings-overriden fast det var
 * inleda-beslutet som saknades (tyst 400-loop, E2E 2026-07-14).
 *
 * Ärver \InvalidArgumentException så befintlig 400-mappning i controllern håller
 * oförändrad för anropare som inte läser grind-nyckeln.
 */
class GrindKravException extends \InvalidArgumentException {
	/**
	 * @param string $grind grind-nyckel: skyddsbedomning|inleda|inte_inleda|kommunicering|avslut
	 * @param string $message människoläsbart felmeddelande (PII-fritt)
	 */
	public function __construct(
		public readonly string $grind,
		string $message,
	) {
		parent::__construct($message);
	}
}
