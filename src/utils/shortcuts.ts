import { t } from '@nextcloud/l10n'

/**
 * What the app can be told to do, and how (§15.7, §14.2).
 *
 * Every drag view used to explain itself in a paragraph of permanent body
 * text — seven of them across the app — which is what a design does when its
 * affordances are not self-evident, and still left the keyboard undocumented
 * anywhere a user could find it. This is the one list, kept beside the code
 * that implements it so it cannot quietly drift: each line below is something
 * `useDrag`, `useTimeDrag` or a view's own handler actually does.
 */

export interface Shortcut {
	/** Keys or gesture, shown as a chip. */
	keys: string
	what: string
}

export interface ShortcutGroup {
	/** The route this belongs to, or null for the ones that work anywhere. */
	route: string | null
	title: string
	shortcuts: Shortcut[]
}

export function shortcutGroups(): ShortcutGroup[] {
	return [
		{
			route: null,
			title: t('chronos', 'Anywhere'),
			shortcuts: [
				{ keys: '?', what: t('chronos', 'Show this list') },
				{ keys: t('chronos', 'Click a project'), what: t('chronos', 'Work in it, and look at it') },
				{ keys: t('chronos', 'Ctrl or ⌘ and click'), what: t('chronos', 'Look at another project as well') },
				{ keys: t('chronos', 'Type in the search box'), what: t('chronos', 'Find a task by name; finished work is hidden until asked for') },
			],
		},
		{
			route: 'board',
			title: t('chronos', 'Board'),
			shortcuts: [
				{ keys: t('chronos', 'Drag a card'), what: t('chronos', 'Move it to another column, or up and down one') },
				{ keys: 'Space', what: t('chronos', 'Pick the focused card up, and drop it again') },
				{ keys: '← →', what: t('chronos', 'Move it between columns') },
				{ keys: '↑ ↓', what: t('chronos', 'Move it within a column') },
				{ keys: 'Esc', what: t('chronos', 'Put it back') },
				{ keys: t('chronos', 'Ctrl, ⌘ or shift and click'), what: t('chronos', 'Pick more than one card; dragging one moves them all') },
				{ keys: 'Ctrl + Z', what: t('chronos', 'Undo the last move, status and position') },
				{ keys: t('chronos', 'Double-click a card'), what: t('chronos', 'Open its details') },
				{ keys: t('chronos', 'The + above a column'), what: t('chronos', 'Add a card in that column’s status') },
				{ keys: t('chronos', 'A card’s own menu'), what: t('chronos', 'Edit it, give it a subtask, or delete it') },
			],
		},
		{
			route: 'timeline',
			title: t('chronos', 'Timeline'),
			shortcuts: [
				{ keys: t('chronos', 'Drag a bar'), what: t('chronos', 'Move it, keeping its length') },
				{ keys: t('chronos', 'Drag an edge'), what: t('chronos', 'Change when it starts or is due') },
				{ keys: t('chronos', 'Drag the dot on the right'), what: t('chronos', 'Onto another task, to make that task wait for this one') },
				{ keys: t('chronos', 'Click a dependency line'), what: t('chronos', 'Remove that dependency') },
				{ keys: 'Space', what: t('chronos', 'Pick the focused bar up, and drop it again') },
				{ keys: '← →', what: t('chronos', 'Move it by one step') },
				{ keys: t('chronos', 'Shift and ← →'), what: t('chronos', 'Stretch its end') },
				{ keys: 'Alt', what: t('chronos', 'Drag without snapping') },
				{ keys: 'Esc', what: t('chronos', 'Put it back') },
				{ keys: t('chronos', 'Double-click an empty row'), what: t('chronos', 'Give that task a day to live in') },
			],
		},
		{
			route: 'editor',
			title: t('chronos', 'Session editor'),
			shortcuts: [
				{ keys: t('chronos', 'Drag a session'), what: t('chronos', 'Move it, and its breaks with it') },
				{ keys: t('chronos', 'Drag an edge'), what: t('chronos', 'Change when it started or ended') },
				{ keys: t('chronos', 'Drag a break'), what: t('chronos', 'Move it inside its session') },
				{ keys: 'Space', what: t('chronos', 'Pick the focused block up, and drop it again') },
				{ keys: '← →', what: t('chronos', 'Move it by one step') },
				{ keys: t('chronos', 'Double-click a session'), what: t('chronos', 'Type its exact times') },
				{ keys: t('chronos', 'Right-click a session'), what: t('chronos', 'Add a break to it') },
			],
		},
		{
			route: 'roadmap',
			title: t('chronos', 'Roadmap'),
			shortcuts: [
				{ keys: t('chronos', 'Click a line’s name'), what: t('chronos', 'Open that project’s timeline') },
				{ keys: t('chronos', 'Double-click a lane'), what: t('chronos', 'Put a milestone on that project, at that date') },
				{ keys: t('chronos', 'Click a diamond'), what: t('chronos', 'Rename, move or delete that milestone') },
			],
		},
		{
			route: 'tasks',
			title: t('chronos', 'Tasks'),
			shortcuts: [
				{ keys: t('chronos', 'Double-click a row'), what: t('chronos', 'Open its details') },
				{ keys: t('chronos', 'The tick box'), what: t('chronos', 'Complete a task, or reopen it') },
				{ keys: t('chronos', 'Click the name'), what: t('chronos', 'Rename it where it is') },
				{ keys: t('chronos', 'A row’s own menu'), what: t('chronos', 'Add a subtask, promote a subproject, or delete') },
			],
		},
	]
}

/** The groups with the one for this view first, because that is what is wanted. */
export function groupsFor(route: string | null): ShortcutGroup[] {
	const groups = shortcutGroups()

	return [
		...groups.filter((group) => group.route === route && route !== null),
		...groups.filter((group) => group.route === null),
		...groups.filter((group) => group.route !== route && group.route !== null),
	]
}
