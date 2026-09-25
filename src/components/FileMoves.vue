<!--
  - SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<section v-if="moves.length > 0" class="sad-filemoves" :aria-label="t('share_audit_dashboard', 'File moves')">
		<div class="sad-filemoves__head">
			<h4 class="sad-filemoves__title">
				{{ t('share_audit_dashboard', 'File moves') }}
			</h4>
			<span class="sad-filemoves__sep" aria-hidden="true">·</span>
			<p class="sad-filemoves__sub">
				{{ t('share_audit_dashboard', 'Files of disabled accounts being handed to their new owner. This runs in the background and can take a while.') }}
			</p>
		</div>

		<!-- Queued moves only start when Nextcloud's background jobs run: with
		     them stopped a move waits for ever and looks like a fresh one. -->
		<NcNoteCard v-if="backgroundJobs && backgroundJobs.stalled" type="warning" class="sad-filemoves__cron">
			{{ cronMessage }}
		</NcNoteCard>

		<div class="sad-filemoves__wrapper">
			<table class="sad-filemoves__table">
				<caption class="hidden-visually">
					{{ t('share_audit_dashboard', 'The newest moves of files from disabled accounts, and how each is going.') }}
				</caption>
				<thead>
					<tr>
						<th scope="col">{{ t('share_audit_dashboard', 'From') }}</th>
						<th scope="col">{{ t('share_audit_dashboard', 'To') }}</th>
						<th scope="col">{{ t('share_audit_dashboard', 'What') }}</th>
						<th scope="col">{{ t('share_audit_dashboard', 'Status') }}</th>
						<th scope="col">{{ t('share_audit_dashboard', 'When') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="move in visibleMoves" :key="move.id">
						<td>
							<span class="sad-filemoves__name">{{ move.sourceDisplayName }}</span>
							<span v-if="move.sourceDisplayName !== move.sourceUid" class="sad-filemoves__uid">{{ move.sourceUid }}</span>
						</td>
						<td>
							<span class="sad-filemoves__name">{{ move.targetDisplayName }}</span>
							<span v-if="move.targetDisplayName !== move.targetUid" class="sad-filemoves__uid">{{ move.targetUid }}</span>
						</td>
						<td class="sad-filemoves__what">
							<span v-if="move.scope === 'account'">{{ t('share_audit_dashboard', 'Entire account') }}</span>
							<span v-else :title="move.path">{{ move.path }}</span>
							<span class="sad-filemoves__uid">
								{{ n('share_audit_dashboard', '%n share', '%n shares', move.shares) }}
							</span>
						</td>
						<td>
							<span class="sad-filemoves__status" :class="'sad-filemoves__status--' + move.status">
								{{ statusLabel(move.status) }}
							</span>
							<span v-if="move.status === 'failed' && move.error" class="sad-filemoves__error">
								{{ errorLabel(move.error) }}
							</span>
							<!-- A running move whose worker is not known to be alive keeps its
							     receiving account busy for ever; an admin can free it. -->
							<template v-if="canManage && move.status === 'running' && move.workerState !== 'alive'">
								<template v-if="releasing === move.id">
									<span class="sad-filemoves__error">
										{{ t('share_audit_dashboard', 'Mark this move as interrupted? Only do so if you are sure nothing is still moving its files: a second move into the same account could overwrite them.') }}
									</span>
									<span class="sad-filemoves__actions">
										<NcButton variant="error" :disabled="releaseBusy" @click="release(move.id)">
											{{ t('share_audit_dashboard', 'Confirm') }}
										</NcButton>
										<NcButton variant="tertiary" :disabled="releaseBusy" @click="releasing = null">
											{{ t('share_audit_dashboard', 'Cancel') }}
										</NcButton>
									</span>
								</template>
								<NcButton v-else
									variant="tertiary"
									class="sad-filemoves__release"
									@click="askRelease(move.id)">
									{{ t('share_audit_dashboard', 'Mark as interrupted') }}
								</NcButton>
							</template>
							<span v-if="releaseError && releasing === move.id" class="sad-filemoves__error">
								{{ releaseError }}
							</span>
						</td>
						<td>{{ formatDateTime(move.finishedAt || move.startedAt || move.createdAt) }}</td>
					</tr>
				</tbody>
			</table>
		</div>

		<NcButton v-if="moves.length > COLLAPSED_ROWS"
			variant="tertiary"
			class="sad-filemoves__more"
			@click="expanded = !expanded">
			{{ expanded
				? t('share_audit_dashboard', 'Show fewer')
				: n('share_audit_dashboard', 'Show %n older move', 'Show %n older moves', moves.length - COLLAPSED_ROWS) }}
		</NcButton>
	</section>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { fetchFileMoves, releaseFileMove } from '../services/api.js'
import { formatDateTime } from '../utils/format.js'

const POLL_MS = 8000
// With the background jobs stopped nothing is going to change soon.
const POLL_STALLED_MS = 30000
const COLLAPSED_ROWS = 5
const ACTIVE = ['queued', 'running']

// What the background job wrote when it gave up. Anything else is the Files
// app's own wording (not enough space, encrypted files, ...), which is shown as is.
const errorLabels = () => ({
	owner_missing: t('share_audit_dashboard', 'The account no longer exists.'),
	owner_not_disabled: t('share_audit_dashboard', 'The account is active again, so its files were left where they are.'),
	target_not_enabled: t('share_audit_dashboard', 'The new owner is no longer an enabled account.'),
	interrupted: t('share_audit_dashboard', 'The move was interrupted before it finished.'),
	unexpected_error: t('share_audit_dashboard', 'Unexpected error. The Nextcloud log has the details.'),
})

export default {
	name: 'FileMoves',
	components: { NcButton, NcNoteCard },
	// A move that finished (well or badly) changes which shares are orphans.
	emits: ['finished'],
	inject: {
		canManage: { default: true },
	},
	data() {
		return {
			moves: [],
			backgroundJobs: null,
			// The move an admin is being asked to confirm freeing, and how that went.
			releasing: null,
			releaseBusy: false,
			releaseError: '',
			expanded: false,
			timer: null,
			activeIds: [],
			COLLAPSED_ROWS,
		}
	},
	computed: {
		visibleMoves() {
			return this.expanded ? this.moves : this.moves.slice(0, COLLAPSED_ROWS)
		},
		cronMessage() {
			const lastRun = this.backgroundJobs?.lastRun
			if (!lastRun) {
				return t('share_audit_dashboard', 'Background jobs have never run on this server. A queued move only starts when they do: set up cron under Administration settings → Basic settings → Background jobs.')
			}
			return t('share_audit_dashboard', 'Background jobs have not run since {date}. A queued move only starts when they do: check that cron is set up under Administration settings → Basic settings → Background jobs.', { date: formatDateTime(lastRun) })
		},
	},
	mounted() {
		this.refresh()
	},
	beforeUnmount() {
		clearTimeout(this.timer)
	},
	methods: {
		t,
		n,
		formatDateTime,
		statusLabel(status) {
			const labels = {
				queued: t('share_audit_dashboard', 'Queued'),
				running: t('share_audit_dashboard', 'Moving…'),
				done: t('share_audit_dashboard', 'Done'),
				failed: t('share_audit_dashboard', 'Failed'),
			}
			return labels[status] ?? status
		},
		errorLabel(error) {
			return errorLabels()[error] ?? error
		},
		// Called by the parent right after it queues something. Polls by itself
		// after that, and only for as long as something is still going.
		async refresh() {
			clearTimeout(this.timer)
			try {
				const { items: moves, backgroundJobs } = await fetchFileMoves()
				this.backgroundJobs = backgroundJobs
				const nowActive = moves.filter((m) => ACTIVE.includes(m.status)).map((m) => m.id)
				const finished = this.activeIds.some((id) => !nowActive.includes(id))
				this.moves = moves
				this.activeIds = nowActive
				if (finished) {
					this.$emit('finished')
				}
			} catch (e) {
				// A failed poll only leaves the list as it was; the next one may work.
			}
			if (this.activeIds.length > 0) {
				this.timer = setTimeout(() => this.refresh(), this.backgroundJobs?.stalled ? POLL_STALLED_MS : POLL_MS)
			}
		},
		askRelease(id) {
			this.releaseError = ''
			this.releasing = id
		},
		async release(id) {
			this.releaseBusy = true
			this.releaseError = ''
			try {
				await releaseFileMove(id)
				this.releasing = null
				await this.refresh()
			} catch (e) {
				this.releaseError = e?.response?.data?.reason === 'alive'
					? t('share_audit_dashboard', 'That move is still running, so it cannot be marked as interrupted.')
					: t('share_audit_dashboard', 'Could not mark the move as interrupted.')
			} finally {
				this.releaseBusy = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.sad-filemoves {
	margin-top: 28px;
}

.sad-filemoves__head {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: 8px;
	margin-bottom: 8px;
}

.sad-filemoves__title {
	margin: 0;
	font-size: 15px;
}

.sad-filemoves__sep,
.sad-filemoves__sub {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.sad-filemoves__sub {
	margin: 0;
	max-width: none;
}

.sad-filemoves__wrapper {
	overflow-x: auto;
}

.sad-filemoves__table {
	width: 100%;
	border-collapse: collapse;
	font-size: 13px;
	// Nextcloud's own stylesheet makes every table nowrap, which sends a long
	// path or a confirmation sentence off the right edge of the screen.
	white-space: normal;

	th,
	td {
		text-align: left;
		padding: 8px 10px;
		border-bottom: 1px solid var(--color-border);
		vertical-align: top;
	}

	th {
		color: var(--color-text-maxcontrast);
		font-weight: 600;
		white-space: nowrap;
	}

	// The date and time stay on one line.
	td:last-child {
		white-space: nowrap;
	}
}

.sad-filemoves__uid {
	display: block;
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.sad-filemoves__what {
	max-width: 320px;
	overflow-wrap: anywhere;
}

.sad-filemoves__status {
	display: inline-block;
	font-size: 12px;
	font-weight: 600;
	padding: 1px 8px;
	border-radius: var(--border-radius, 6px);
	white-space: nowrap;
}

.sad-filemoves__status--queued {
	background-color: var(--sad-info);
	color: var(--sad-ink-on-solid);
}

.sad-filemoves__status--running {
	background-color: var(--sad-warning);
	color: var(--sad-warning-on);
}

.sad-filemoves__status--done {
	border: 1px solid var(--color-success);
	color: var(--color-success-text);
}

.sad-filemoves__status--failed {
	background-color: var(--sad-critical);
	color: var(--sad-ink-on-solid);
}

.sad-filemoves__error {
	display: block;
	margin-top: 4px;
	font-size: 12px;
	max-width: 320px;
	overflow-wrap: anywhere;
}

.sad-filemoves__more {
	margin-top: 6px;
}

.sad-filemoves__cron {
	margin-bottom: 10px;
}

.sad-filemoves__release {
	margin-top: 4px;
}

.sad-filemoves__actions {
	display: flex;
	gap: 8px;
	margin-top: 6px;
}
</style>
