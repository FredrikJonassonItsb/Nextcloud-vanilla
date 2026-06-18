const plugins = []

export function registerEditorPlugin(plugin) {
	plugins.push(plugin)
}

export function getRegisteredPlugins() {
	return [...plugins]
}
