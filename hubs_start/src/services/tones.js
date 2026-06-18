/**
 * Hubs Start — tone tokens shared by the variant widgets.
 * tone ∈ info | warning | error | success | neutral  →  --hs-status-* CSS var.
 */

const TONE_VAR = {
	info: '--hs-status-info',
	warning: '--hs-status-warning',
	error: '--hs-status-error',
	success: '--hs-status-success',
	neutral: '--hs-status-neutral',
}

/** CSS var() expression for a tone (safe fallback to neutral). */
export function toneColor(tone) {
	return 'var(' + (TONE_VAR[tone] || TONE_VAR.neutral) + ')'
}

export default { toneColor }
