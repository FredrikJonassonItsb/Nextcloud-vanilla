<template>
	<NcModal
		label-id="loa3-auth-title"
		:can-close="true"
		@close="$emit('close', false)">
		<div class="loa3-modal">
			<div class="loa3-modal__header">
				<h2 id="loa3-auth-title">
					{{ t('sdkmc', 'Authentication Required') }}
				</h2>
			</div>

			<div class="loa3-modal__content">
				<div class="loa3-modal__icon">
					<LockIcon :size="48" />
				</div>

				<p class="loa3-modal__message">
					{{ t('sdkmc', 'This file requires a stronger authentication method. Do you want to authenticate using a LOA-3 method?') }}
				</p>

				<div class="loa3-modal__info">
					<p class="loa3-modal__info-text">
						{{ t('sdkmc', 'LOA-3 (Level of Assurance 3) provides enhanced security through multi-factor authentication.') }}
					</p>
				</div>
			</div>

			<div class="loa3-modal__buttons">
				<NcButton
					type="tertiary"
					@click="$emit('close', false)">
					{{ t('sdkmc', 'Cancel') }}
				</NcButton>

				<NcButton
					type="primary"
					@click="$emit('close', true)">
					<template #icon>
						<LockIcon :size="16" />
					</template>
					{{ t('sdkmc', 'Authenticate with LOA-3') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcModal, NcButton } from '@nextcloud/vue'
import LockIcon from 'vue-material-design-icons/Lock.vue'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'Loa3Modal',

	components: {
		NcModal,
		NcButton,
		LockIcon,
	},

	emits: ['close'],

	methods: {
		t,
	},
}
</script>

<style lang="scss" scoped>
.loa3-modal {
	padding: 24px;
	min-width: 440px;
	max-width: 560px;

	display: flex;
	flex-direction: column;

	&__header {
		text-align: center;
		margin-bottom: 24px;

		h2 {
			margin: 0;
			font-size: 20px;
			color: var(--color-main-text);
			font-weight: 600;
		}
	}

	&__content {
		text-align: center;
		display: flex;
		flex-direction: column;
		align-items: center;
		margin-bottom: 24px;
		gap: 16px;
	}

	&__icon {
		color: var(--color-warning);
	}

	&__message {
		font-size: 16px;
		line-height: 1.6;
		margin: 0;
		color: var(--color-main-text);
		max-width: 440px;
	}

	&__info {
		background-color: var(--color-background-hover);
		border-radius: var(--border-radius);
		padding: 12px 16px;
		margin-top: 8px;
		max-width: 440px;

		&-text {
			font-size: 14px;
			color: var(--color-text-maxcontrast);
			margin: 0;
			line-height: 1.5;
			text-align: left;
		}
	}

	&__buttons {
		display: flex;
		justify-content: flex-end;
		gap: 12px;
		margin-top: auto;
	}
}

@media (max-width: 480px) {
	.loa3-modal {
		min-width: auto;
		max-width: 90vw;

		&__buttons {
			flex-direction: column-reverse;

			button {
				width: 100%;
			}
		}
	}
}
</style>
