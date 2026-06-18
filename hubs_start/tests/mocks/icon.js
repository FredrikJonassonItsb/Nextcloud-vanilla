/**
 * Stub for vue-material-design-icons/*.vue single-file components.
 *
 * jest does not transform .vue files inside node_modules, so importing the real
 * icon SFCs (e.g. vue-material-design-icons/Bank.vue) throws "Unexpected token".
 * channels.js only needs each icon to be a defined component reference — the unit
 * tests assert `channelMeta(...).icon` is defined, never what it renders — so a
 * trivial render-nothing component is sufficient. jest.config.js maps every
 * `vue-material-design-icons/*.vue` import here (see moduleNameMapper).
 */
module.exports = { name: 'IconStub', render: () => null }
