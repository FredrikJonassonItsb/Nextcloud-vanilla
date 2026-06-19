/**
 * Tests for api.skapaArende — "Ta emot & starta förhandsbedömning".
 *
 * Regression guard for the bug where "Ta emot" created the case but NEVER tagged
 * the source message, so it stayed untagged in the INBOX and reappeared in
 * "Att ta emot" (and showed no tag in the message UI). skapaArende must, after a
 * successful create, tag the source message in the USER session
 * (case:{hubsCaseId} + behandlad) via sdkmc's per-message tag route.
 *
 * The shared @nextcloud/axios mock is restored to its benign default in afterEach
 * so we don't pollute other suites.
 */
import axios from '@nextcloud/axios'
import { skapaArende } from '../../src/services/api.js'

const RAD = { id: 'inf:99', korg: { label: 'Orosanmälan' } }

beforeEach(() => {
	axios.post.mockResolvedValue({ data: { ocs: { data: { hubsCaseId: 'CASE-1', id: 'CASE-1' } } } })
	axios.put.mockResolvedValue({ data: {} })
})
afterEach(() => {
	// Restore the shared mock to the default empty-success envelope.
	axios.post.mockResolvedValue({ data: {} })
	axios.put.mockResolvedValue({ data: {} })
})

describe('skapaArende — tags the source message on Ta emot', () => {
	it('creates the case, then tags the message case: + behandlad (inf: stripped → db id)', async () => {
		const nytt = await skapaArende(RAD)
		expect(nytt.hubsCaseId).toBe('CASE-1')
		expect(axios.post).toHaveBeenCalledTimes(1)
		expect(axios.post.mock.calls[0][0]).toContain('apps/hubs_arende/api/v1/arende')
		const putUrls = axios.put.mock.calls.map((c) => c[0])
		expect(putUrls).toEqual(expect.arrayContaining([
			expect.stringContaining('/apps/sdkmc/api/messages/99/tags/case%3ACASE-1'),
			expect.stringContaining('/apps/sdkmc/api/messages/99/tags/behandlad'),
		]))
		expect(nytt.taggSatt).toBe(true)
	})

	it('does NOT tag when the engine returns an error envelope (no hubsCaseId)', async () => {
		axios.post.mockResolvedValue({ data: { ocs: { data: { error: 'okand_arendetyp' } } } })
		const r = await skapaArende(RAD)
		expect(r.error).toBe('okand_arendetyp')
		expect(axios.put).not.toHaveBeenCalled()
	})

	it('a tag failure does not undo the created case (best-effort, taggSatt=false)', async () => {
		axios.put.mockRejectedValue(new Error('imap down'))
		const nytt = await skapaArende(RAD)
		expect(nytt.hubsCaseId).toBe('CASE-1')
		expect(nytt.taggSatt).toBe(false)
	})
})
