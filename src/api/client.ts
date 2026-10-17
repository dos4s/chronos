import axios from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/**
 * The one HTTP client for the app and the dashboard widget (D8).
 *
 * No `OC.*` globals: the axios instance from the Nextcloud package adds the
 * request token and the router package builds the URL. A 409 is an error here,
 * where the old helper quietly treated it as success.
 */

const APP_BASE = '/apps/chronos'

export class ApiError extends Error {
	readonly code: string
	readonly status: number

	constructor(code: string, status: number) {
		super(`${code} (HTTP ${status})`)
		this.name = 'ApiError'
		this.code = code
		this.status = status
	}
}

type Method = 'get' | 'post' | 'put' | 'delete'

async function request<T>(
	method: Method,
	path: string,
	data?: unknown,
	params?: Record<string, string | number>,
): Promise<T> {
	try {
		const response = await axios.request<T>({
			method,
			url: generateUrl(APP_BASE + path),
			data,
			params,
		})
		return response.data
	} catch (error) {
		throw toApiError(error)
	}
}

function toApiError(error: unknown): ApiError {
	const response = (error as { response?: { status?: number, data?: unknown } }).response
	const status = response?.status ?? 0
	const body = response?.data
	const code = typeof body === 'object' && body !== null && typeof (body as { error?: unknown }).error === 'string'
		? (body as { error: string }).error
		: 'request_failed'
	return new ApiError(code, status)
}

export function get<T>(path: string, params?: Record<string, string | number>) {
	return request<T>('get', path, undefined, params)
}
export const post = <T>(path: string, data?: unknown) => request<T>('post', path, data)
export const put = <T>(path: string, data?: unknown) => request<T>('put', path, data)
export const del = <T>(path: string) => request<T>('delete', path)

/**
 * A message for each error the API can return, so a failed action explains
 * itself instead of saying "failed".
 */
export function messageFor(error: unknown): string {
	if (!(error instanceof ApiError)) {
		return t('chronos', 'Something went wrong')
	}

	switch (error.code) {
		case 'already_checked_in':
			return t('chronos', 'You are already checked in')
		case 'no_active_session':
			return t('chronos', 'No session is running')
		case 'already_paused':
			return t('chronos', 'This session is already paused')
		case 'not_paused':
			return t('chronos', 'This session is not paused')
		case 'invalid_times':
			return t('chronos', 'A session cannot end before it starts')
		case 'invalid_pauses':
		case 'too_many_pauses':
			return t('chronos', 'Those breaks could not be saved')
		case 'invalid_range':
		case 'range_too_large':
			return t('chronos', 'That date range is too large')
		case 'timestamp_unit':
			return t('chronos', 'This page is out of date — please reload')
		case 'not_found':
			return t('chronos', 'That session no longer exists')
		case 'project_not_found':
			return t('chronos', 'That project no longer exists')
		case 'project_read_only':
			return t('chronos', 'That list is shared with you read-only')
		case 'task_not_found':
			return t('chronos', 'That task no longer exists')
		case 'area_not_found':
		case 'unknown_area':
			return t('chronos', 'That area no longer exists')
		case 'name_required':
			return t('chronos', 'A name is needed')
		case 'name_too_long':
			return t('chronos', 'That name is too long')
		case 'invalid_color':
			return t('chronos', 'That colour is not a hex value like #0082c9')
		case 'project_own_parent':
			return t('chronos', 'A project cannot be part of itself')
		case 'project_cycle':
			return t('chronos', 'That would make the two projects part of each other')
		case 'module_disabled':
			return t('chronos', 'That part of Chronos is switched off')
		case 'workflow_not_found':
		case 'unknown_workflow':
			return t('chronos', 'That workflow no longer exists')
		case 'unknown_template':
			return t('chronos', 'That template does not exist')
		case 'status_not_found':
		case 'unknown_status':
		case 'invalid_status_key':
			return t('chronos', 'That status is not one of this workflow’s')
		case 'project_has_no_workflow':
		case 'workflow_unavailable':
			return t('chronos', 'This project has no workflow, so it has no statuses')
		case 'transition_not_allowed':
			return t('chronos', 'This workflow does not allow that move')
		case 'no_cards':
		case 'invalid_cards':
			return t('chronos', 'That card could not be moved')
		case 'too_many_cards':
			return t('chronos', 'That is too many cards to move at once')
		case 'comment_required':
			return t('chronos', 'This change needs a note')
		case 'comment_too_long':
			return t('chronos', 'That note is too long')
		case 'assignee_required':
			return t('chronos', 'That status needs the task to have an assignee')
		case 'forbidden':
		case 'not_authenticated':
			return t('chronos', 'You are not allowed to do that')
		default:
			return t('chronos', 'Something went wrong')
	}
}
