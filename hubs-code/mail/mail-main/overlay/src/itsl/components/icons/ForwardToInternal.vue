<template>
	<span v-bind="$attrs"
		:aria-hidden="title ? null : 'true'"
		:aria-label="title"
		class="material-design-icon calendar-clock-icon"
		role="img"
		@click="$emit('click', $event)">
		<svg :fill="fillColor"
			class="material-design-icon__svg"
			:width="size"
			:height="size"
			viewBox="0 0 24 24">
			<path d="m24.026 18.806-4.361-4.361v2.492c-4.361.623-6.23 3.738-6.853 6.853 1.558-2.18 3.738-3.177 6.853-3.177v2.554z" style="stroke-width:.623">
				<title v-if="title">{{ title }}</title>
			</path>
			<path d="M12.312 8.976V1.582A.762.822 0 0 0 11.55.76H1.644a.762.822 0 0 0-.762.822v11.502L3.93 9.798h7.62a.762.822 0 0 0 .762-.822m3.048-4.93h-1.524v7.395H3.93v1.643a.762.822 0 0 0 .762.822h8.382l3.048 3.286V4.868a.762.822 0 0 0-.762-.822Z" style="stroke-width:.791237">
				<title v-if="title">{{ title }}</title>
			</path>
		</svg>
	</span>
</template>

<script>
export default {
	name: 'ForwardToInternal',
	props: {
	  title: {
			type: String,
	  },
	  fillColor: {
			type: String,
			default: 'currentColor',
	  },
	  size: {
			type: Number,
			default: 24,
	  },
	},
	emits: ['click'],
}
</script>
