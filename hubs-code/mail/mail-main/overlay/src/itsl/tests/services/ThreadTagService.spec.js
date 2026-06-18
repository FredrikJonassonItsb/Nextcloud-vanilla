vi.mock('@nextcloud/axios', () => ({
	default: {
		put: vi.fn(() => Promise.resolve({ data: { success: true } })),
		delete: vi.fn(() => Promise.resolve({ data: { success: true } })),
	},
}))

vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((url) => url),
}))

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { setThreadTag, removeThreadTag, setThreadFlags } from '../../services/ThreadTagService.js'

describe('ThreadTagService', () => {
	afterEach(() => {
		vi.restoreAllMocks()
	})

	describe('setThreadTag', () => {
		it('PUTs to correct URL with { ids } body', async () => {
			const ids = [1, 2, 3]
			await setThreadTag(ids, '$label1')

			expect(generateUrl).toHaveBeenCalled()
			expect(axios.put).toHaveBeenCalledWith(
				expect.any(String),
				{ ids },
			)
		})

		it('URL-encodes imapLabel with special chars', async () => {
			await setThreadTag([1], '$follow_up')

			const urlArg = generateUrl.mock.calls[generateUrl.mock.calls.length - 1][0]
			expect(urlArg).toContain(encodeURIComponent('$follow_up'))
		})
	})

	describe('removeThreadTag', () => {
		it('DELETEs with { data: { ids } } body shape', async () => {
			const ids = [10, 20]
			await removeThreadTag(ids, '$tag_review')

			expect(axios.delete).toHaveBeenCalledWith(
				expect.any(String),
				{ data: { ids } },
			)
		})
	})

	describe('setThreadFlags', () => {
		it('PUTs to flags URL with { ids, flags } body', async () => {
			const ids = [5, 6]
			const flags = { flagged: true }
			await setThreadFlags(ids, flags)

			expect(axios.put).toHaveBeenCalledWith(
				expect.any(String),
				{ ids, flags },
			)
		})
	})

	describe('error propagation', () => {
		it('rejects when axios rejects', async () => {
			axios.put.mockRejectedValueOnce(new Error('Network error'))

			await expect(setThreadTag([1], '$label1')).rejects.toThrow('Network error')
		})
	})
})
