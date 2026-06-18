<template>
	<svg :width="size"
		:height="size"
		viewBox="0 0 24 24"
		xmlns="http://www.w3.org/2000/svg">
		<path id="path843"
			fill="currentColor"
			d="M 4,2 C 2.8954305,2 2,2.8954305 2,4 v 18 l 4,-4 h 14 c 1.104569,0 2,-0.895431 2,-2 V 11 H 17 C 15.89,11 15,10.11 15,9 V 2 H 4 m 2,4 h 7 V 8 H 6 V 6 m 0,3 h 7 v 2 H 6 V 9 m 0,3 h 8 v 2 H 6 Z" />
		<path id="path833"
			fill="currentColor"
			d="M 19.627518,6.5359802 18.030066,4.9385283 18.593168,4.3754265 19.627518,5.405783 22.25932,2.773981 22.822422,3.3410764 M 20.426244,0.1461726 16.831977,1.7436245 v 2.3961778 c 0,2.2164646 1.533554,4.2891587 3.594267,4.7923567 2.060713,-0.503198 3.594267,-2.5758921 3.594267,-4.7923567 V 1.7436245 Z"
			style="stroke-width:0.399363" />
	</svg>
</template>

<script setup>
defineProps({
	size: {
		type: Number,
		default: 24,
	},
})
</script>
