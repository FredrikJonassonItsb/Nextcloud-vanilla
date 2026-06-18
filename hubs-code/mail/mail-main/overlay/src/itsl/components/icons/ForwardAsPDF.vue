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
			<path d="m24.026 18.806-4.361-4.361v2.492c-4.361.623-6.23 3.738-6.853 6.853 1.557-2.18 3.738-3.177 6.853-3.177v2.554z" style="stroke-width:.623022">
				<title v-if="title">{{ title }}</title>
			</path>
			<path d="M14.428.76H2.575C1.644.76.882 1.522.882 2.453v11.853c0 .932.762 1.694 1.693 1.694h11.853c.932 0 1.694-.762 1.694-1.694V2.453c0-.931-.762-1.693-1.694-1.693M6.385 7.956c0 .678-.593 1.27-1.27 1.27h-.847v1.694h-1.27V5.84h2.117c.677 0 1.27.592 1.27 1.27v.846m4.233 1.694c0 .677-.592 1.27-1.27 1.27H7.232V5.84h2.116c.678 0 1.27.592 1.27 1.27v2.54m3.387-2.54h-1.27v.846h1.27v1.27h-1.27v1.694h-1.27V5.84h2.54v1.27m-5.503 0h.846v2.54h-.846V7.11m-4.234 0h.847v.846h-.847Z" style="stroke-width:.846671">
				<title v-if="title">{{ title }}</title>
			</path>
		</svg>
	</span>
</template>

<script>
export default {
	name: 'ForwardAsPDF',
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
