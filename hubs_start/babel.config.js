// Used by jest (babel-jest) to transpile JS for the jsdom test environment.
// Targets the current Node so async/await and ESM-style imports work under jest.
module.exports = {
	presets: [
		['@babel/preset-env', {
			targets: {
				node: 'current',
			},
		}],
	],
}
